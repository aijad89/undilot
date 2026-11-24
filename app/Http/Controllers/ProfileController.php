<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Support\CompanyCredentials;
use App\Support\KrafPicker;
use App\Support\KrafSelections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The participant's own profile.
 *
 * The account card follows the office's company form, and the participant
 * changes all of it except the zone, IC and community ID — the office's: the
 * zone decides who handles the company, the other two are how the
 * participant signs in. The COMPANY's contact details, recognition,
 * certificates, staff to contact and crafts are the participant's to keep — the
 * part of the MICF entry form that is the same for every event, filled in once
 * so a request to join only adds its payment and photos.
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $company = $user->company;

        return view('profile', [
            'user' => $user,
            'company' => $company,
            'held' => $company?->credentials ?? [],
            'standards' => $company?->qualityStandards() ?? [],
            'missing' => $company ? CompanyCredentials::missing($company) : [],
            'picker' => $company ? KrafPicker::data($company) : null,
        ]);
    }

    /**
     * The account card: the company's name and registration no., the owner's
     * name, salutation, phone and address. Zone, IC and Community ID are not
     * read from the request at all — the office's (owner, 2026-11-20).
     */
    public function updateAccount(Request $request)
    {
        $company = $this->company($request);
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'registration_no' => ['nullable', 'string', 'max:100'],
            // The list, or the value already on record (old data).
            'salutation' => ['nullable', \Illuminate\Validation\Rule::in(array_filter([...\App\Models\User::SALUTATIONS, $user->salutation]))],
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
        ], [], ['name' => 'company name', 'owner_name' => "owner's name"]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($company, $user, $data) {
            $company->update(['name' => $data['name'], 'registration_no' => $data['registration_no'] ?? null]);
            $user->update([
                'name' => $data['owner_name'],
                'salutation' => $data['salutation'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'district' => $data['district'] ?? null,
            ]);
        });

        return redirect()->route('profile')->with('success', 'Account saved.');
    }

    /** The contact card: the company's contact details and the staff to contact. */
    public function updateContact(Request $request)
    {
        $company = $this->company($request);

        $contact = array_fill_keys(array_keys(Company::CONTACT_FIELDS), ['nullable', 'string', 'max:255']);
        $contact['workshop_address'] = ['nullable', 'string', 'max:1000'];
        $contact['office_phone'] = $contact['fax'] = ['nullable', 'string', 'max:30'];

        // A staff row left blank is not a staff member.
        $request->merge(['staff' => array_values(array_filter(
            (array) $request->input('staff', []),
            fn ($s) => trim(($s['name'] ?? '').($s['phone'] ?? '')) !== ''
        ))]);

        $data = $request->validate(
            $contact + CompanyCredentials::staffRules($request, $company),
            CompanyCredentials::MESSAGES,
            CompanyCredentials::ATTRIBUTES,
        );

        $before = CompanyCredentials::filePaths($company->credentials ?? []);

        $company->update(array_intersect_key($data, Company::CONTACT_FIELDS) + [
            'credentials' => [
                'entrepreneur_category' => $data['entrepreneur_category'],
                'oku' => $data['oku'],
                'oku_cert' => CompanyCredentials::okuCert($request, $data, $company),
                'staff' => array_map(fn ($s) => ['name' => $s['name'], 'phone' => $s['phone']], $data['staff']),
            ] + ($company->credentials ?? []),
        ]);

        CompanyCredentials::deleteUnused($company, $before);

        return redirect()->route('profile')->with('success', 'Information saved.');
    }

    /** The recognition and certificates card. */
    public function update(Request $request)
    {
        $company = $this->company($request);

        $data = $request->validate(
            CompanyCredentials::rules($request, $company),
            CompanyCredentials::MESSAGES,
            CompanyCredentials::ATTRIBUTES,
        );

        $before = CompanyCredentials::filePaths($company->credentials ?? []);

        $company->update(['credentials' => CompanyCredentials::build($request, $data, $company)]);

        // A replaced certificate goes once no request still points at it.
        CompanyCredentials::deleteUnused($company, $before);

        return redirect()->route('profile')->with('success', 'Details saved.');
    }

    public function storeKraf(Request $request)
    {
        $company = $this->company($request);

        $error = KrafSelections::add($company, $request->validate(KrafSelections::rules()));

        return redirect()->to(route('profile').'#crafts')
            ->with($error ? 'error' : 'success', $error ?? 'Craft added.');
    }

    public function makeKrafPrimary(Request $request, CompanyKraf $selection)
    {
        $company = $this->company($request);
        abort_unless($selection->company_id === $company->id, 404);

        KrafSelections::makePrimary($company, $selection);

        return redirect()->to(route('profile').'#crafts')->with('success', 'Main craft changed.');
    }

    public function destroyKraf(Request $request, CompanyKraf $selection)
    {
        $company = $this->company($request);
        abort_unless($selection->company_id === $company->id, 404);

        // Its ticked classifications and products go with it, by cascade.
        $selection->delete();

        return redirect()->to(route('profile').'#crafts')->with('success', 'Craft removed.');
    }

    /**
     * A recognition proof or certificate file on the company's profile: for the
     * company's own account, or an admin of its zone (super and HQ: any).
     */
    public function certificate(Request $request, Company $company, string $key)
    {
        $admin = $request->user('admin');
        $user = $request->user('web');

        abort_unless(
            ($admin && ($admin->hasGlobalAccess() || $admin->state === $company->state))
                || ($user && $user->company?->id === $company->id),
            404
        );

        // ?history=N: a certificate held before (classification and quality only).
        $file = $request->has('history')
            ? ($company->credentials["{$key}_history"][(int) $request->query('history')] ?? null)
            : ($company->credentials["{$key}_cert"] ?? null);
        abort_unless(! empty($file['path']) && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->response($file['path'], $file['original'] ?? basename($file['path']));
    }

    /** The signed-in participant's own company; only an active one is theirs to change. */
    private function company(Request $request): Company
    {
        $company = $request->user()->company;

        abort_unless($company && $company->is_active, 403, 'Your account has no active company.');

        return $company;
    }
}
