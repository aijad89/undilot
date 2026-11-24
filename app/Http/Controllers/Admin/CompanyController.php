<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\User;
use App\Support\AdminZones;
use App\Support\BusinessTypes;
use App\Support\CompanyCredentials;
use App\Support\KrafPicker;
use App\Support\KrafSelections;
use App\Support\PlotSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Companies, each with the one account that signs in for it.
 *
 * ONE COMPANY, ONE USER, AND THE FORM MAKES BOTH. `companies.user_id` is NOT
 * NULL and carries a unique index, so there is no company without an account and
 * no account with two companies. The admin fills one form and two rows are
 * written inside one transaction — the owner's name, phone, IC and community ID are
 * columns on the USER, not on the company.
 *
 * NOTHING POSTS `user_id`, SO NOTHING VALIDATES IT. The account is always created
 * here rather than picked, which is what makes the unique index unreachable from
 * this screen; a `Rule::unique('companies', 'user_id')` would be a rule that can
 * never fire. What the admin can collide with is an IC that already has an
 * account, and that is validated against `users` with a message they can read.
 * If a "link an existing account" path is ever added, the rule comes back with it.
 *
 * THE SCOPE IS THE ZONE. A state admin sees and edits companies whose
 * `companies.state` equals their own `admins.state`, and reaches no other by
 * typing a URL. There is no middleware that looks like this guard, deliberately:
 * one would read as enforcement on every route and enforce nothing.
 */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user('admin');

        $companies = Company::query()
            ->with(['user', 'krafSelections.bidang', 'krafSelections.subBidang'])
            ->listFilters($request)
            ->unless($admin->hasGlobalAccess(), fn ($q) => $q->where('state', $admin->state))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('admin.companies.index', [
            'companies' => $companies,
            'canFilterZone' => $admin->hasGlobalAccess(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.companies.create', [
            'company' => new Company,
            'zones' => $this->zonesFor($request->user('admin')),
        ]);
    }

    private const IC_MESSAGES = [
        'ic_number.regex' => 'An IC number has twelve digits, written 990101-01-1234.',
        'ic_number.unique' => 'Another account already holds that IC number.',
    ];

    /**
     * Twelve bare digits are the commonest way an IC is typed, and the stored
     * form is dashed — icHash() and the unique index both compare the string,
     * so one IC in two spellings would be two people. Dashes go in here; anything
     * that is not twelve digits is left for the rule to refuse.
     */
    private function normaliseIc(Request $request): void
    {
        $digits = preg_replace('/\D/', '', (string) $request->input('ic_number'));

        if (strlen($digits) === 12) {
            $request->merge(['ic_number' => substr($digits, 0, 6).'-'.substr($digits, 6, 2).'-'.substr($digits, 8)]);
        }
    }

    public function store(Request $request)
    {
        $admin = $request->user('admin');

        $this->normaliseIc($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::in($this->zonesFor($admin))],
            'business_type' => ['nullable', BusinessTypes::rule()],
            'entrepreneur_category' => ['nullable', Rule::in(CompanyCredentials::ENTREPRENEUR_CATEGORIES)],
            'registration_no' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],

            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'ic_number' => ['required', 'string', 'regex:/^\d{6}-\d{2}-\d{4}$/', 'unique:users,ic_number'],
            'community_id' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'salutation' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            // The participant's username; the reset link goes to it. The
            // office's to set, never the participant's (owner, 2026-11-24).
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'account_active' => ['nullable', 'boolean'],
        ], self::IC_MESSAGES);

        // One act, or neither. Without the transaction a failing company insert
        // leaves a user behind with no company and no screen that lists them.
        $company = DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => $data['owner_name'],
                'phone' => $data['phone'] ?? null,
                'ic_number' => $data['ic_number'] ?? null,
                'community_id' => $data['community_id'] ?? null,
                'address' => $data['address'] ?? null,
                'salutation' => $data['salutation'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'district' => $data['district'] ?? null,
                'email' => $data['email'],
                'is_active' => $request->boolean('account_active', true),
                // Never used: with `password_changed_at` NULL the IC signs in the
                // first time, and the participant then chooses a password.
                'password' => bcrypt(Str::random(32)),
                'password_changed_at' => null,
            ]);

            return $user->company()->create([
                'name' => $data['name'],
                'state' => $data['state'],
                'business_type' => $data['business_type'] ?? null,
                'registration_no' => $data['registration_no'] ?? null,
                'is_active' => $request->boolean('is_active', true),
                // The participant picks it on the profile; the office may set it first.
                'credentials' => ['entrepreneur_category' => $data['entrepreneur_category'] ?? null],
            ]);
        });

        return redirect()->route('admin.companies.edit', $company)
            ->with('success', "{$company->name} created. The participant signs in with {$company->user->email} and their IC number, then chooses a password.");
    }

    public function edit(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $company->load('user');

        return view('admin.companies.edit', [
            'company' => $company,
            'zones' => $this->zonesFor($request->user('admin')),
            'picker' => KrafPicker::data($company),
            'codes' => KrafPicker::codesFor($company),
            'plotSizes' => PlotSizes::all(),
        ]);
    }

    public function update(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $user = $company->user;

        $this->normaliseIc($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'state' => ['required', Rule::in($this->zonesFor($request->user('admin')))],
            'business_type' => ['nullable', BusinessTypes::rule($company->business_type)],
            'entrepreneur_category' => ['nullable', Rule::in(CompanyCredentials::ENTREPRENEUR_CATEGORIES)],
            ...CompanyCredentials::okuRules($request, $company, required: false),
            'registration_no' => ['nullable', 'string', 'max:100'],

            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'ic_number' => [
                'required', 'string', 'regex:/^\d{6}-\d{2}-\d{4}$/',
                Rule::unique('users', 'ic_number')->ignore($user->id),
            ],
            'community_id' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'salutation' => ['nullable', 'string', 'max:50'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'district' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], self::IC_MESSAGES + CompanyCredentials::MESSAGES, CompanyCredentials::ATTRIBUTES);

        $before = CompanyCredentials::filePaths($company->profile());

        DB::transaction(function () use ($request, $data, $company, $user) {
            $user->update([
                'name' => $data['owner_name'],
                'phone' => $data['phone'] ?? null,
                'ic_number' => $data['ic_number'] ?? null,
                'community_id' => $data['community_id'] ?? null,
                'address' => $data['address'] ?? null,
                'salutation' => $data['salutation'] ?? null,
                'postcode' => $data['postcode'] ?? null,
                'district' => $data['district'] ?? null,
                'email' => $data['email'],
                // Two switches, two facts. A company switched off is off the
                // books; an account switched off cannot sign in, and
                // EnforceUserIsActive ends the session of anyone already in.
                'is_active' => $request->boolean('account_active'),
            ]);


            $company->update([
                'name' => $data['name'],
                'state' => $data['state'],
                'business_type' => $data['business_type'] ?? null,
                'registration_no' => $data['registration_no'] ?? null,
                'is_active' => $request->boolean('is_active'),
                // The participant's own field; the office may correct it.
                'credentials' => [
                    'entrepreneur_category' => $data['entrepreneur_category'] ?? null,
                    'oku' => $data['oku'] ?? null,
                    'oku_cert' => CompanyCredentials::okuCert($request, $data, $company),
                ] + ($company->credentials ?? []),
            ]);
        });

        CompanyCredentials::deleteUnused($company, $before);

        return redirect()->route('admin.companies.edit', $company)
            ->with('success', 'Saved.');
    }

    /**
     * The company goes, and its account with it.
     *
     * `companies.user_id` is RESTRICT, so the order is forced: the company first,
     * then the user that now owns nothing. The model refuses outright if this
     * company holds a lot in a draw that has already run — that refusal is a
     * ValidationException, and it is caught here so the admin reads a sentence
     * rather than a stack trace.
     */
    /**
     * Back to the IC: the participant's next sign-in is with their IC, and they
     * must choose a new password (owner, 2026-11-24). For a participant who has
     * lost both the password and the email inbox.
     */
    public function resetPassword(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $company->user->forceFill([
            'password' => bcrypt(Str::random(32)),
            'password_changed_at' => null,
        ])->save();

        return redirect()->route('admin.companies.edit', $company)
            ->with('success', 'Password reset. The participant signs in next with their IC number and chooses a new password.');
    }

    /**
     * Opens the portal as this company's participant, the admin still signed
     * in (App\Support\Impersonation). Their zone only for a state admin.
     */
    public function loginAs(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $user = $company->user;
        if (! $user || ! $user->is_active || ! $company->is_active) {
            return back()->with('error', 'This account or company is switched off, so it cannot be opened.');
        }

        \App\Support\Impersonation::start($request, $request->user('admin'), $user);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $name = $company->name;

        try {
            DB::transaction(function () use ($company) {
                $user = $company->user;

                $company->delete();
                $user?->delete();
            });
        } catch (ValidationException $e) {
            return back()->with('error', $e->validator->errors()->first());
        }

        return redirect()->route('admin.companies.index')
            ->with('success', "{$name} deleted, with its account.");
    }

    /**
     * Add a craft selection.
     *
     * HASIL LOGAM is picked several sub-categories at a time and stored as one
     * row each, which is why the input is always an array. Every sub-category is
     * checked to belong to the category named beside it: the two columns describe
     * one path down the hierarchy, and a pair that does not is a row no screen
     * can draw.
     */
    public function storeKraf(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $error = KrafSelections::add($company, $request->validate(KrafSelections::rules()));

        return $error
            ? $this->backTo('craft-categories')->with('error', $error)
            : $this->backTo('craft-categories')->with('success', 'Craft selection added.');
    }

    public function makeKrafPrimary(Request $request, Company $company, CompanyKraf $selection)
    {
        $this->authorizeScope($request, $company);

        abort_unless($selection->company_id === $company->id, 404);

        KrafSelections::makePrimary($company, $selection);

        return $this->backTo('craft-categories')->with('success', 'Primary category changed.');
    }

    public function destroyKraf(Request $request, Company $company, CompanyKraf $selection)
    {
        $this->authorizeScope($request, $company);

        abort_unless($selection->company_id === $company->id, 404);

        // Its ticked classifications and products go with it, by cascade.
        $selection->delete();

        return $this->backTo('craft-categories')->with('success', 'Craft selection removed.');
    }

    /**
     * The company's usual craft code — admin only, never shown to the participant.
     * Not in Company::$fillable, so nothing the participant posts can reach it.
     * Only an active code on the coding sheet, or blank to clear — or the code
     * the company ALREADY carries, even if withdrawn since. Withdrawing promises
     * that rows carrying a code keep it; refusing it here would turn every save
     * of this card (a booth-size change, say) into a forced re-coding.
     */
    public function updateKrafCode(Request $request, Company $company)
    {
        $this->authorizeScope($request, $company);

        $allowed = KrafPicker::codesFor($company)->pluck('code')->push($company->kraf_code)->filter()->all();
        $sizes = array_filter([...PlotSizes::all(), $company->plot_size]);

        $data = $request->validate([
            'kraf_code' => ['nullable', Rule::in($allowed)],
            'plot_size' => ['nullable', Rule::in($sizes)],
        ]);

        // Neither is fillable: both are admin-only. The booth size also moves the
        // company's draw-list rows in events whose draw has not started.
        $company->kraf_code = $data['kraf_code'] ?? null;
        $moved = $company->syncBoothSize($data['plot_size'] ?? null);

        return $this->backTo('craft-code')->with('success', 'Craft code and booth size saved.'
            .Company::boothMovedNote($moved, $request->user('admin')->can('verify-draw-list')));
    }

    /**
     * A state admin reaches their own zone and nothing else, whatever URL they
     * type. Super and HQ reach everything.
     */
    private function authorizeScope(Request $request, Company $company): void
    {
        $admin = $request->user('admin');

        abort_if(
            ! $admin->hasGlobalAccess() && $company->state !== $admin->state,
            403,
            'That company is administered by another zone.'
        );
    }

    /**
     * The zones this admin may file a company under.
     *
     * A state admin gets exactly one — their own — so the form offers no way to
     * post a company into a zone they cannot then see, and the validation rule is
     * built from the same list the form renders.
     *
     * @return array<int, string>
     */
    private function zonesFor($admin): array
    {
        return $admin->hasGlobalAccess()
            ? AdminZones::all()
            : array_values(array_filter([$admin->state]));
    }
}
