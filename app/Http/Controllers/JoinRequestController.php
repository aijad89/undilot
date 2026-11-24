<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventJoinRequest;
use App\Support\CompanyCredentials;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A participant asking to join an event's draw, and the documents they sent.
 *
 * Asking is all a participant can do: the office reviews the request and, on
 * approval, puts the company on the draw list. Being on that list is still the
 * only thing that puts a company in the draw.
 */
class JoinRequestController extends Controller
{
    private const FILE_RULE = CompanyCredentials::FILE_RULE;

    private const MAX_FILES = 10;

    private const PRODUCT_IMAGES = 5;

    /** What a photo that is not JPG or PNG most often turns out to be. */
    private const PHOTO_TYPES = [
        'image/webp' => 'a WebP image',
        'image/heic' => 'a HEIC image (an iPhone photo)',
        'image/heif' => 'a HEIF image (an iPhone photo)',
        'image/avif' => 'an AVIF image',
        'image/gif' => 'a GIF image',
        'application/pdf' => 'a PDF',
    ];

    public function store(Request $request, Event $event)
    {
        $company = $request->user()->company;

        abort_unless($company && $company->is_active, 403, 'Your account has no active company.');

        if (! $event->acceptsJoinRequests()) {
            return back()->with('error', 'Registration for this event is not open.');
        }

        // On the list already: in the draw, or put there by the office and
        // waiting for HQ. Either way there is nothing to ask for.
        $row = $event->registrations()->where('company_id', $company->id)->first();
        if ($row) {
            return back()->with('error', $row->first_verified_at
                ? 'Your company is already in this draw.'
                : 'The office is adding your company to this draw. You will be told once HQ confirms it.');
        }

        $existing = $event->joinRequests()->where('company_id', $company->id)->first();
        // Only one with the office now blocks. An approved one whose company is
        // not on the list (checked above) was taken off it, and may ask again.
        if ($existing?->status === 'pending') {
            return back()->with('error', 'Your request is already with the office.');
        }

        // Save draft: whatever is filled in so far, checked for form but not for
        // completeness, and kept as a draft the office does not see.
        $isDraft = $request->boolean('draft');

        // Recognition, certificates and the contact person come from the
        // profile, kept once for every event. Sending needs it complete.
        if (! $isDraft && $missing = CompanyCredentials::missing($company)) {
            return back()->with('error', 'Complete your profile first: '.implode(', ', $missing).'.');
        }

        // A draft's payment files count as sent until replaced; a rejected
        // request's do not.
        $draftFiles = $existing?->status === 'draft'
            ? array_values(array_filter($existing->documents ?? [], fn ($doc) => str_starts_with($doc['path'], 'join-requests/')))
            : [];
        $draftWaived = $existing?->payment_method === 'waiver';

        // Paid: how, and the receipt — nothing else. Waiver: which category, and
        // supporting documents that prove it (the OKU certificate, the award
        // letter…), at least one.
        $waiver = $request->input('payment_method') === 'waiver';

        $held = $company->answersFor($existing);
        // The photo files on record before this save, so the ones it replaces
        // can go once nothing points at them.
        $before = [...CompanyCredentials::filePaths($company->profile()), ...CompanyCredentials::filePaths($existing?->answers ?? [])];

        $rules = [
            'declaration' => ['accepted'],
            'details_confirmed' => ['accepted'],
            'payment_method' => ['required', Rule::in(array_keys(EventJoinRequest::PAYMENT_METHODS))],
            'receipt' => $waiver ? ['exclude'] : ($draftFiles && ! $draftWaived ? $this->optional(self::FILE_RULE) : self::FILE_RULE),
            'waiver_category' => $waiver
                ? ['required', Rule::in(array_keys(EventJoinRequest::WAIVER_CATEGORIES))]
                : ['exclude'],
            'documents' => $waiver ? [$draftFiles && $draftWaived ? 'nullable' : 'required', 'array', 'max:'.self::MAX_FILES] : ['exclude'],
            'documents.*' => $waiver ? self::FILE_RULE : ['exclude'],
            // The photos on record stand until a new set is sent, and a set is
            // always five.
            'product_images' => [empty($held['product_images']) ? 'required' : 'nullable', 'array', 'size:'.self::PRODUCT_IMAGES],
            // Read from what is inside the file, not its name: a WebP or HEIC
            // photo saved as .jpg is common, and the message says which one.
            'product_images.*' => ['bail', 'required', 'file', 'max:5120', function ($attribute, $file, $fail) {
                $type = $file->getMimeType();
                if (! in_array($type, ['image/jpeg', 'image/png'], true)) {
                    $fail($file->getClientOriginalName().' is named like a photo but is really '
                        .(self::PHOTO_TYPES[$type] ?? $type).'. Save it as JPG, JPEG or PNG (or take a screenshot of it) and pick it again.');
                }
            }],
        ];

        // A draft: the same rules, nothing required.
        if ($isDraft) {
            $rules = array_map(fn ($rule) => array_values(array_filter(
                $this->optional($rule), fn ($part) => $part !== 'accepted'
            )), $rules);
        }

        $data = $request->validate($rules, [
            'declaration.accepted' => 'Tick the declaration to join.',
            'details_confirmed.accepted' => 'Check your company details and tick to confirm they are correct.',
            'payment_method.required' => 'Choose how you paid.',
            'receipt.required' => 'Attach your payment receipt.',
            'waiver_category.required' => 'Choose which waiver applies to you.',
            'documents.required' => 'Attach a document that shows you qualify for the waiver.',
            'product_images.required' => 'Upload '.self::PRODUCT_IMAGES.' photos of your products.',
            'product_images.size' => 'Upload exactly '.self::PRODUCT_IMAGES.' photos of your products.',
        ]);

        $photos = $request->hasFile('product_images')
            ? collect($request->file('product_images'))->map(fn ($file) => [
                'path' => $file->store("products/{$company->id}", 'local'),
                'original' => $file->getClientOriginalName(),
            ])->values()->all()
            : ($held['product_images'] ?? []);

        // Only a sent request's photos become the company's; a draft's stay
        // with the draft. The rest of the answers are the profile as it stands.
        if (! $isDraft) {
            $company->update(['credentials' => array_merge($company->credentials ?? [], ['product_images' => $photos])]);
        }
        $answers = $isDraft ? ['product_images' => $photos] : $company->profile(false);

        $keep = fn ($file, $name) => [
            'name' => $name,
            'path' => $file->store("join-requests/{$event->id}", 'local'),
            'original' => $file->getClientOriginalName(),
        ];

        // New files replace the draft's; none sent keeps the draft's own kind.
        $documents = match (true) {
            ! $request->filled('payment_method') => [],
            $waiver && $request->hasFile('documents') => collect($request->file('documents'))->map(fn ($file) => $keep($file, $file->getClientOriginalName()))->values()->all(),
            ! $waiver && $request->hasFile('receipt') => [$keep($request->file('receipt'), 'Payment receipt')],
            default => $waiver === $draftWaived ? $draftFiles : [],
        };

        // The certificates and photos sit with the other files, so the office
        // opens them from the same list. A draft holds its payment files only.
        foreach (['oku_cert' => 'OKU card', 'recognition_cert' => 'Recognition', 'classification_cert' => 'Classification certificate', 'quality_cert' => 'Quality compliance certificate'] as $key => $name) {
            if (! $isDraft && ! empty($answers[$key]['path'])) {
                $documents[] = ['name' => $name, 'path' => $answers[$key]['path'], 'original' => $answers[$key]['original']];
            }
        }
        foreach ($isDraft ? [] : $photos as $i => $image) {
            $documents[] = ['name' => 'Product photo '.($i + 1)] + $image;
        }

        // Asked again in place: its own old files go unless kept. The
        // certificates and photos are the company's, and stay.
        if ($existing) {
            Storage::disk('local')->delete(array_diff(
                array_filter(array_column($existing->documents ?? [], 'path'), fn ($path) => str_starts_with($path, 'join-requests/')),
                array_column($documents, 'path')
            ));
        }

        EventJoinRequest::updateOrCreate(
            ['event_id' => $event->id, 'company_id' => $company->id],
            [
                'user_id' => $request->user()->id,
                'payment_method' => $data['payment_method'] ?? null,
                'waiver_category' => $data['waiver_category'] ?? null,
                'documents' => $documents,
                // A copy, so a later change on the profile does not rewrite
                // what the office reviewed.
                'answers' => $answers,
                'status' => $isDraft ? 'draft' : 'pending',
            ] + ($isDraft ? [] : [
                // A draft keeps the last rejection, so its reason stays in
                // view until the request is sent again.
                'reject_reason' => null,
                'removed_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])
        );

        CompanyCredentials::deleteUnused($company, $before);

        return $isDraft
            // The two ticks are not stored with a draft; kept for the page it
            // returns to, so Join right after a save is not held back by them.
            ? back()->with('success', 'Draft saved. Come back to finish it any time before registration closes.')->with('step', $request->input('at'))
                ->withInput($request->only('declaration', 'details_confirmed'))
            : back()->with('success', 'Sent. The office will review your documents.');
    }

    /** A rule with `required` made `nullable`. */
    private function optional(array $rule): array
    {
        return array_map(fn ($part) => $part === 'required' ? 'nullable' : $part, $rule);
    }

    /**
     * One uploaded document, for its own company or an admin of that company's
     * zone. Either guard may ask, so the route sits outside both groups.
     */
    public function document(Request $request, EventJoinRequest $joinRequest, int $index)
    {
        return $this->serve($request, $joinRequest, $joinRequest->documents[$index] ?? null);
    }

    /**
     * One product photo as the request carries it in its answers: the photos
     * it was sent with, which stay with it after the company sends new ones.
     */
    public function photo(Request $request, EventJoinRequest $joinRequest, int $index)
    {
        return $this->serve($request, $joinRequest, $joinRequest->answers['product_images'][$index] ?? null);
    }

    /** The photos a request was sent with, on a page of their own. */
    public function photos(Request $request, EventJoinRequest $joinRequest)
    {
        // 404, not 403: another company's request does not exist from here.
        abort_unless($request->user()->company?->id === $joinRequest->company_id, 404);

        return view('request-photos', [
            'joinRequest' => $joinRequest->load('event'),
            'photos' => $joinRequest->answers['product_images'] ?? [],
        ]);
    }

    /**
     * A file of a request, for its own company or an admin of that company's
     * zone; nobody else learns whether it exists. A draft is the company's
     * alone until it is sent: the office sees requests from pending on.
     */
    private function serve(Request $request, EventJoinRequest $joinRequest, ?array $file)
    {
        $admin = $request->user('admin');
        $user = $request->user('web');

        abort_unless(
            ($admin && $joinRequest->status !== 'draft'
                && ($admin->hasGlobalAccess() || $admin->state === $joinRequest->company->state))
                || ($user && $user->company?->id === $joinRequest->company_id),
            404
        );

        abort_unless(! empty($file['path']) && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $file['original'] ?? basename($file['path']));
    }
}
