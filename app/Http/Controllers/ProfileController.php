<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Support\CompanyCredentials;
use App\Support\KrafPicker;
use App\Support\KrafSelections;
use Illuminate\Http\Request;

/**
 * The participant's own profile.
 *
 * The account (name, community ID, IC, phone, address) is the office's and is
 * read-only here: the office creates it, and two of its fields are how the
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
            'standard' => $company?->qualityStandard(),
            'missing' => $company ? CompanyCredentials::missing($company) : [],
            'picker' => $company ? KrafPicker::data($company) : null,
        ]);
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
            $contact + CompanyCredentials::staffRules(),
            CompanyCredentials::MESSAGES,
            CompanyCredentials::ATTRIBUTES,
        );

        $company->update(array_intersect_key($data, Company::CONTACT_FIELDS) + ['credentials' => [
            'staff' => array_map(fn ($s) => ['name' => $s['name'], 'phone' => $s['phone']], $data['staff']),
        ] + ($company->credentials ?? [])]);

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

    /** The signed-in participant's own company; only an active one is theirs to change. */
    private function company(Request $request): Company
    {
        $company = $request->user()->company;

        abort_unless($company && $company->is_active, 403, 'Your account has no active company.');

        return $company;
    }
}
