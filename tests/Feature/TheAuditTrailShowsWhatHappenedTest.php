<?php

use App\Models\Admin;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;

/**
 * The audit trail screen, restored 2026-11-24 for SUPER ONLY: every row,
 * including the cascade summaries and what an admin did while signed in as a
 * participant. HQ and state admins read none of it.
 */
it('lists what happened and opens a cascade summary in readable form', function () {
    $event = Event::factory()->create(['title' => 'Deleted Fair']);
    EventRegistration::factory()->count(2)->create(['event_id' => $event->id]);
    $event->delete();

    $row = AuditTrail::where('action', 'deleted')->where('auditable_type', Event::class)->sole();
    $super = superAdmin();

    $this->actingAs($super, 'admin')->get(route('admin.audit-trails.index', ['model' => 'Event']))
        ->assertOk()
        ->assertSee(route('admin.audit-trails.show', $row));

    $this->actingAs($super, 'admin')->get(route('admin.audit-trails.show', $row))
        ->assertOk()
        ->assertSee('event_registrations');
});

it('keeps the audit trail away from HQ and a state admin, menu and all', function (Closure $who) {
    $row = AuditTrail::record('created', Company::factory()->create());
    $admin = $who();

    $this->actingAs($admin, 'admin')->get(route('admin.audit-trails.index'))->assertForbidden();
    $this->actingAs($admin, 'admin')->get(route('admin.audit-trails.show', $row))->assertForbidden();
    $this->get(route('admin.dashboard'))->assertDontSee(route('admin.audit-trails.index'), false);
})->with([
    'hq' => [fn () => Admin::factory()->hq()->create()],
    'state admin' => [fn () => stateAdmin('Johor')],
]);

it('shows who opened a participant\'s account, and what they did there', function () {
    $admin = stateAdmin('Johor');
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);

    $this->actingAs($admin, 'admin')->post(route('admin.companies.login-as', $company));
    $this->put(route('profile.contact'), [
        'entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no',
        'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']], 'office_phone' => '07-1234 5678',
    ]);
    $this->post(route('logout'));
    auth()->forgetGuards();

    $super = superAdmin();
    $this->actingAs($super, 'admin')->get(route('admin.dashboard'))->assertSee(route('admin.audit-trails.index'), false);
    $this->get(route('admin.companies.edit', $company))
        ->assertSee(route('admin.audit-trails.index', ['user' => $company->user_id]), false);

    // The account's own rows: opened, and returned.
    $this->get(route('admin.audit-trails.index', ['user' => $company->user_id]))->assertOk()
        ->assertSeeInOrder(['Returned to admin', 'Logged in as participant'])
        ->assertSee($admin->name);

    // The admin's rows include the change made as the participant.
    $this->get(route('admin.audit-trails.index', ['admin' => $admin->id, 'model' => 'Company']))->assertOk()
        ->assertSee('Updated');
});