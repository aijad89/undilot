<?php

use App\Models\Admin;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;

/*
 * An admin opens the portal as one of their participants (owner, 2026-11-24):
 * a state admin for their own zone, HQ and super for any. The admin stays
 * signed in; what they do is recorded under them; the participant's password
 * is not theirs to choose; "Sign out" takes them back to the admin.
 */

function zoneCompany(string $state = 'Johor', array $user = []): Company
{
    return Company::factory()->withProfile()->create([
        'state' => $state,
        'user_id' => User::factory()->create($user + ['name' => 'Siti Johor'])->id,
    ]);
}

it('opens the portal as a participant of the admin\'s own zone, the admin still signed in', function () {
    $admin = stateAdmin('Johor');
    $company = zoneCompany();

    $this->actingAs($admin, 'admin')->post(route('admin.companies.login-as', $company))
        ->assertRedirect(route('dashboard'));

    expect(auth('web')->id())->toBe($company->user_id)
        ->and(auth('admin')->id())->toBe($admin->id);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('You are viewing undilot as Siti Johor')
        ->assertSee('Return to admin');

    $row = AuditTrail::where('action', 'impersonation_started')->sole();
    expect($row->performer_type)->toBe(Admin::class)
        ->and($row->performer_id)->toBe($admin->id)
        ->and($row->auditable_id)->toBe($company->user_id);
});

it('refuses another zone to a state admin, and opens any zone for HQ', function () {
    $company = zoneCompany('Kedah');

    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.companies.login-as', $company))->assertForbidden();
    expect(auth('web')->check())->toBeFalse();

    auth()->forgetGuards();
    $this->actingAs(Admin::factory()->hq()->create(), 'admin')->post(route('admin.companies.login-as', $company))
        ->assertRedirect(route('dashboard'));
    expect(auth('web')->id())->toBe($company->user_id);
});

it('refuses an account or company that is switched off', function () {
    $company = zoneCompany('Johor', ['is_active' => false]);

    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.companies.login-as', $company))
        ->assertSessionHas('error');
    expect(auth('web')->check())->toBeFalse();
});

it('records what the admin does there under the admin', function () {
    $admin = stateAdmin('Johor');
    $company = zoneCompany();
    $this->actingAs($admin, 'admin')->post(route('admin.companies.login-as', $company));

    $this->put(route('profile.contact'), [
        'entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no',
        'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']], 'office_phone' => '07-1234 5678',
    ])->assertSessionHasNoErrors();

    $row = AuditTrail::where('auditable_type', Company::class)->where('auditable_id', $company->id)->where('action', 'updated')->latest('id')->first();
    expect($row->performer_type)->toBe(Admin::class)
        ->and($row->performer_id)->toBe($admin->id);
});

it('never lets the admin choose the participant\'s password, nor holds them on that page', function () {
    $company = zoneCompany('Johor', ['password_changed_at' => null]);
    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.companies.login-as', $company));

    // A participant who has not chosen one yet: the admin is not held there...
    $this->get(route('dashboard'))->assertOk();
    // ...and cannot reach it.
    $this->get(route('password.edit'))->assertForbidden();
    $this->put(route('password.change'), ['password' => 'admin2026', 'password_confirmation' => 'admin2026'])->assertForbidden();
    $this->get(route('profile'))->assertOk()->assertDontSee(route('password.edit'), false);

    expect($company->user->fresh()->password_changed_at)->toBeNull();
});

it('goes back to the admin on Sign out, without signing the admin out or touching the participant', function () {
    $admin = stateAdmin('Johor');
    $company = zoneCompany();
    $user = $company->user;
    $token = $user->remember_token;
    $seen = $user->last_seen_at;

    $this->actingAs($admin, 'admin')->post(route('admin.companies.login-as', $company));
    $this->get(route('dashboard'));

    $this->post(route('logout'))
        ->assertRedirect(route('admin.companies.edit', $company))
        ->assertSessionHas('success', 'Back to your admin account.');

    expect(auth('web')->check())->toBeFalse()
        ->and(auth('admin')->id())->toBe($admin->id)
        // Their "remember me" on their own devices, and their last-seen, are theirs.
        ->and($user->fresh()->remember_token)->toBe($token)
        ->and($user->fresh()->last_seen_at?->toString())->toBe($seen?->toString())
        ->and(AuditTrail::where('action', 'impersonation_ended')->where('performer_id', $admin->id)->exists())->toBeTrue();

    $this->get(route('admin.dashboard'))->assertOk();
});

it('shows no banner to a participant signed in as themselves', function () {
    $company = zoneCompany();

    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertOk()
        ->assertDontSee('You are viewing undilot as');
});

it('offers the button on the company page, and not for a switched-off account', function () {
    $company = zoneCompany();
    $this->actingAs(stateAdmin('Johor'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSee('Log in as this participant')
        ->assertSee(route('admin.companies.login-as', $company), false);

    $off = zoneCompany('Johor', ['is_active' => false]);
    $this->get(route('admin.companies.edit', $off))->assertDontSee('Log in as this participant');
});
