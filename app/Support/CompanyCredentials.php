<?php

namespace App\Support;

use App\Models\Company;
use App\Models\EventJoinRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * A company's recognition, certificates and the staff to contact — the part of the
 * MICF entry form that is the same for every event. Kept on the profile
 * (`companies.credentials`); a request to join copies it as it stood.
 *
 * Product photos live in the same JSON but are sent with each request to join,
 * not from the profile.
 */
final class CompanyCredentials
{
    /** Allowed per file: PDF or an image, 5 MB. */
    public const FILE_RULE = ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'];

    /** The recognitions that need proof on file. */
    public const RECOGNISED = ['adiguru', 'tokoh'];

    public const MESSAGES = [
        'recognition.required' => 'Choose the recognition you have received, or Not applicable.',
        'classification.required' => 'Say whether you hold a classification certificate.',
        'quality.required' => 'Choose your quality compliance standard, or Not applicable.',
        'classification_end.after_or_equal' => 'This certificate has expired. Renew it first.',
        'quality_end.after_or_equal' => 'This certificate has expired. Renew it first.',
        'recognition_file.required' => 'Upload proof of the recognition.',
        'classification_file.required' => 'Upload the certificate.',
        'quality_file.required' => 'Upload the certificate.',
        'staff.required' => 'Add at least one staff member to contact.',
        'staff.*.phone.regex' => 'Enter a Malaysian mobile number, e.g. 012-345 6789.',
    ];

    public const ATTRIBUTES = [
        'classification_number' => 'certificate number', 'quality_number' => 'certificate number',
        'classification_start' => 'valid from', 'quality_start' => 'valid from',
        'classification_end' => 'valid until', 'quality_end' => 'valid until',
        'staff.*.name' => 'staff name', 'staff.*.phone' => 'mobile number',
    ];

    /**
     * The rules for the profile's credential fields. The quality question is
     * asked only of a craft that has a standard (Batik, Songket, Tenun).
     */
    public static function rules(Request $request, Company $company): array
    {
        $held = $company->credentials ?? [];

        $rules = [
            'recognition' => ['required', Rule::in(array_keys(Company::RECOGNITIONS))],
            'classification' => ['required', Rule::in(['yes', 'no'])],
        ];

        // Proof of a recognition: the file on record counts while the answer is unchanged.
        if (in_array($request->input('recognition'), self::RECOGNISED, true)) {
            $kept = ! empty($held['recognition_cert']['path']) && ($held['recognition'] ?? null) === $request->input('recognition');
            $rules['recognition_file'] = $kept ? ['nullable', ...array_slice(self::FILE_RULE, 1)] : self::FILE_RULE;
        }

        if ($request->input('classification') === 'yes') {
            $rules += self::certificateRules($request, 'classification', $held['classification_cert'] ?? null);
        }

        if ($standard = $company->qualityStandard()) {
            $rules['quality'] = ['required', Rule::in([$standard, 'none'])];
            if ($request->input('quality') === $standard) {
                $rules += self::certificateRules($request, 'quality', $held['quality_cert'] ?? null);
            }
        }

        return $rules;
    }

    /** The staff to contact, saved with the contact details on their own. */
    public static function staffRules(): array
    {
        return [
            'staff' => ['required', 'array', 'max:20'],
            'staff.*.name' => ['required', 'string', 'max:100'],
            'staff.*.phone' => ['required', 'regex:/^\+?6?01\d[- ]?\d{3,4}[- ]?\d{4}$/'],
        ];
    }

    /**
     * The company's credentials with these answers, new certificate files
     * stored. The staff and product photos on record are kept as they are.
     */
    public static function build(Request $request, array $data, Company $company): array
    {
        $held = $company->credentials ?? [];

        // A certificate keeps the file on record unless a new one was sent, and
        // only while it is the same certificate (same number, same end date).
        $certificate = function (string $field) use ($data, $request, $held, $company) {
            $onFile = $held["{$field}_cert"] ?? [];
            $cert = [
                'number' => $data["{$field}_number"] ?? null,
                'start' => $data["{$field}_start"] ?? null,
                'end' => $data["{$field}_end"] ?? null,
            ];

            return $cert + match (true) {
                $request->hasFile("{$field}_file") => [
                    'path' => $request->file("{$field}_file")->store("certificates/{$company->id}", 'local'),
                    'original' => $request->file("{$field}_file")->getClientOriginalName(),
                ],
                ($onFile['number'] ?? null) === $cert['number'] && ($onFile['end'] ?? null) === $cert['end'] => array_intersect_key($onFile, array_flip(['path', 'original'])),
                default => [],
            };
        };

        $recognitionCert = match (true) {
            ! in_array($data['recognition'], self::RECOGNISED, true) => null,
            $request->hasFile('recognition_file') => [
                'path' => $request->file('recognition_file')->store("certificates/{$company->id}", 'local'),
                'original' => $request->file('recognition_file')->getClientOriginalName(),
            ],
            ($held['recognition'] ?? null) === $data['recognition'] => $held['recognition_cert'] ?? null,
            default => null,
        };

        return [
            'recognition' => $data['recognition'],
            'recognition_cert' => $recognitionCert,
            'classification' => $data['classification'],
            'classification_cert' => $data['classification'] === 'yes' ? $certificate('classification') : null,
            'quality' => $data['quality'] ?? null,
            'quality_cert' => in_array($data['quality'] ?? null, [null, 'none'], true) ? null : $certificate('quality'),
            'staff' => $held['staff'] ?? [],
            'product_images' => $held['product_images'] ?? [],
        ];
    }

    /**
     * What the profile still lacks before the company may ask to join, as
     * labels to show. Empty when it is complete. A certificate counts only
     * with its file and while it is in date.
     */
    public static function missing(Company $company): array
    {
        $held = $company->credentials ?? [];
        $valid = fn (?array $cert) => ! empty($cert['path'])
            && ! empty($cert['end'])
            && ! Carbon::parse($cert['end'])->endOfDay()->isPast();

        $missing = [];

        if (! isset(Company::RECOGNITIONS[$held['recognition'] ?? ''])
            || (in_array($held['recognition'], self::RECOGNISED, true) && empty($held['recognition_cert']['path']))) {
            $missing[] = 'recognition';
        }
        if (! in_array($held['classification'] ?? null, ['yes', 'no'], true)
            || ($held['classification'] === 'yes' && ! $valid($held['classification_cert'] ?? null))) {
            $missing[] = 'classification certificate';
        }
        if ($standard = $company->qualityStandard()) {
            $quality = $held['quality'] ?? null;
            if (! in_array($quality, [$standard, 'none'], true)
                || ($quality === $standard && ! $valid($held['quality_cert'] ?? null))) {
                $missing[] = 'quality compliance';
            }
        }
        if (empty($held['staff'])) {
            $missing[] = 'staff to contact';
        }

        return $missing;
    }

    /** The staff to contact, as one line: "Siti (012-345 6789), Ahmad (019-111 2222)". */
    public static function staffLine(?array $answers): string
    {
        return implode(', ', array_map(fn ($s) => "{$s['name']} ({$s['phone']})", $answers['staff'] ?? [])) ?: '—';
    }

    /** The certificate and product photo files a set of answers points at. */
    public static function filePaths(array $answers): array
    {
        return array_values(array_filter([
            $answers['recognition_cert']['path'] ?? null,
            $answers['classification_cert']['path'] ?? null,
            $answers['quality_cert']['path'] ?? null,
            ...array_column($answers['product_images'] ?? [], 'path'),
        ]));
    }

    /**
     * Deletes the files in $before that neither the company nor any of its
     * requests, on any event, still points at.
     */
    public static function deleteUnused(Company $company, array $before): void
    {
        $inUse = self::filePaths($company->fresh()->credentials ?? []);
        foreach (EventJoinRequest::where('company_id', $company->id)->get(['answers', 'documents']) as $jr) {
            array_push($inUse, ...self::filePaths($jr->answers ?? []), ...array_column($jr->documents ?? [], 'path'));
        }

        Storage::disk('local')->delete(array_values(array_diff(array_unique($before), $inUse)));
    }

    /**
     * The rules for one certificate: number, dates, and the file. Expired is
     * refused. The file on record still counts while it is the same certificate
     * (same number, same end date); a renewed one needs its new file.
     */
    private static function certificateRules(Request $request, string $field, ?array $onFile): array
    {
        $same = ! empty($onFile['path'])
            && $request->input("{$field}_number") === $onFile['number']
            && $request->input("{$field}_end") === $onFile['end'];

        return [
            "{$field}_number" => ['required', 'string', 'max:100'],
            "{$field}_start" => ['required', 'date'],
            "{$field}_end" => ['required', 'date', "after:{$field}_start", 'after_or_equal:today'],
            "{$field}_file" => $same ? ['nullable', ...array_slice(self::FILE_RULE, 1)] : self::FILE_RULE,
        ];
    }
}
