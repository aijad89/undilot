<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\User;

/*
 * Withdrawing a craft code is how a code is retired — the toggle on the coding
 * sheet, chosen over delete because a delete cascades into plots and preset
 * allocations. The toggle makes a promise in its own flash message: "Rows
 * already carrying it keep it." So two things must hold together:
 *
 *   - nobody is NEWLY given a withdrawn code: it leaves every dropdown that
 *     assigns one, and the server refuses it;
 *   - whoever already carries it keeps it, through every save that was not
 *     about the code. A dropdown that no longer lists the code is exactly how a
 *     save blanks it: the browser submits "None" and nobody chose that.
 *
 * Saves in these tests post what the browser would post. In particular a
 * `<option disabled>` is NOT submitted by a browser, even when selected — the
 * field is simply absent — so a test posting the code anyway would be testing a
 * form nobody can send.
 */

function withdrawn(string $code = 'TBPS'): KrafBidang
{
    $row = KrafBidang::factory()->code($code)->create();
    $row->is_active = false;   // not fillable, on purpose: retiring is a decision
    $row->save();

    return $row;
}

function draftEvent(): Event
{
    return Event::factory()->create(['status' => 'open', 'polling_status' => 'draft', 'state' => null]);
}

function rowCarrying(Event $event, Company $company, ?string $code): EventRegistration
{
    return EventRegistration::create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $company->user_id,
        'code' => $code,
        'code_updated_at' => $code ? now() : null,
        'plot_size' => '3x3',
        'plot_id' => null,
        'confirmed_participation' => false,
        'confirmed_at' => null,
        'verified_by' => null,
        'verified_at' => now(),
        'first_verified_at' => now(),
        'hq_note' => null,
        'payment_note' => null,
    ]);
}

/**
 * What a browser posts for the first single <select> matching $selectPattern:
 *
 *   - the option marked `selected`, if it is enabled;
 *   - nothing (null: the field is left out) if the selected option is disabled;
 *   - with nothing marked, the FIRST enabled option — here "— None —", value
 *     "". That is how a dropped `selected` blanks a code on save.
 *
 * Fails the test if the select is not on the page, so a markup change cannot
 * pass as "the field was absent".
 */
function browserPosts(string $html, string $selectPattern): ?string
{
    preg_match('/'.$selectPattern.'.*?<\/select>/s', $html, $select);

    expect($select)->not->toBeEmpty();

    preg_match_all('/<option value="([^"]*)"([^>]*)>/', $select[0], $options, PREG_SET_ORDER);

    foreach ($options as [, $value, $attrs]) {
        if (preg_match('/\bselected\b/', $attrs)) {
            return preg_match('/\bdisabled\b/', $attrs) ? null : $value;
        }
    }

    foreach ($options as [, $value, $attrs]) {
        if (! preg_match('/\bdisabled\b/', $attrs)) {
            return $value;
        }
    }

    return null;
}

function companyCarrying(?string $code): Company
{
    $user = User::factory()->create();

    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $company->kraf_code = $code;   // admin-only, not fillable
    $company->plot_size = '3x3';
    $company->save();

    return $company;
}

it('withdraws a code and offers it again, from the coding sheet', function () {
    $code = KrafBidang::factory()->code('TBPS')->create();

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.settings.kraf-codes.toggle', $code))
        ->assertSessionHas('success', 'Code TBPS withdrawn. Rows already carrying it keep it.');

    expect($code->fresh()->is_active)->toBeFalse();

    $this->post(route('admin.settings.kraf-codes.toggle', $code))
        ->assertSessionHas('success', 'Code TBPS is offered again.');

    expect($code->fresh()->is_active)->toBeTrue();
});

it('leaves the coding sheet to the roles that manage settings', function () {
    $code = KrafBidang::factory()->code('TBPS')->create();

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.settings.kraf-codes.toggle', $code))
        ->assertForbidden();

    expect($code->fresh()->is_active)->toBeTrue();
});

it('will not toggle a craft category, which is the other list in the same table', function () {
    // code NULL is a category (TEKSTIL…), managed in the hierarchy and fixed.
    $category = KrafBidang::factory()->create(['code' => null]);

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.settings.kraf-codes.toggle', $category))
        ->assertNotFound();
});

it('stops offering a withdrawn code on the draw list, and the server refuses it for a company', function () {
    withdrawn('TBPS');
    KrafBidang::factory()->code('RKP')->create();
    $event = draftEvent();
    $company = companyCarrying(null);
    rowCarrying($event, $company, null);

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertOk()
        ->assertSee('value="RKP"', false)
        ->assertDontSee('value="TBPS"', false);

    $this->put(route('admin.companies.kraf-code.update', $company), ['kraf_code' => 'TBPS', 'plot_size' => '3x3'])
        ->assertSessionHasErrors('kraf_code');

    expect($company->fresh()->kraf_code)->toBeNull();
});

it('keeps a withdrawn code on a draw-list row through a save that changes only its booth', function () {
    withdrawn('TBPS');
    $event = draftEvent();
    $company = companyCarrying(null);
    $row = rowCarrying($event, $company, 'TBPS');

    // The row's own select still lists the code, marked as retired.
    $page = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('TBPS (retired)')
        ->getContent();

    // The row's select, not the bulk ones that share name="code".
    $code = browserPosts($page, '<select name="code" class="mac-select mac-action-btn-field"');

    $this->put(route('admin.events.registrations.update', [$event, $row]),
        array_filter(['code' => $code, 'plot_size' => '3x6'], fn ($v) => $v !== null));

    expect($row->fresh())
        ->code->toBe('TBPS')
        ->plot_size->toBe('3x6');
});

it('keeps a withdrawn code on a company through a save that changes only its booth', function () {
    withdrawn('TBPS');
    $company = companyCarrying('TBPS');

    $page = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.companies.edit', $company))
        ->assertOk()
        ->getContent();

    $code = browserPosts($page, '<select id="kraf_code" name="kraf_code"');

    $this->put(route('admin.companies.kraf-code.update', $company),
        array_filter(['kraf_code' => $code, 'plot_size' => '3x6'], fn ($v) => $v !== null))
        ->assertSessionHasNoErrors();

    expect($company->fresh())
        ->kraf_code->toBe('TBPS')
        ->plot_size->toBe('3x6');
});
