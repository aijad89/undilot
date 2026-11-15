<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\PlotSize;
use App\Models\User;
use App\Support\EntrepreneurCategories;
use App\Support\PlotSizes;

/*
 * The Dropdown Lists screen edits two lists as a textarea: booth sizes and
 * entrepreneur categories. Both name values that live in database columns:
 *
 *   plot_sizes               event_registrations.plot_size, companies.plot_size,
 *                            plot_sizes.size (the plan's own rows)
 *   entrepreneur_categories  companies.entrepreneur_category
 *
 * Dropping a line from the textarea used to be accepted whatever held it. Then
 * every dropdown built from the list stopped offering a value rows still carry:
 * a company form opens with "— None —" selected and the next save blanks the
 * category; a draw-list row refuses to save its booth at all. It is the same
 * failure zones are guarded against (a zone still in use cannot be deleted),
 * arriving through the door that had no guard.
 *
 * So a save that would remove a value still in use is refused whole, naming
 * the values and their counts. Adding, reordering and removing unused values
 * go through as before.
 */

function registrationAt(string $size): EventRegistration
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft', 'state' => null]);

    return EventRegistration::create([
        'event_id' => $event->id, 'company_id' => $company->id, 'user_id' => $user->id, 'code' => null,
        'code_updated_at' => null, 'plot_size' => $size, 'plot_id' => null, 'confirmed_participation' => false,
        'confirmed_at' => null, 'verified_by' => null, 'verified_at' => null, 'first_verified_at' => null,
        'hq_note' => null, 'payment_note' => null,
    ]);
}

function saveList(string $list, array $values)
{
    return test()->put(route('admin.settings.lists.update', $list), ['values' => implode("\n", $values)]);
}

it('saves a list that adds, reorders and drops unused values', function () {
    PlotSizes::set(['3x3', '3x6', '3x9']);

    $this->actingAs(superAdmin(), 'admin');
    saveList('plot_sizes', ['3x6', '3x3', '6x6'])->assertSessionHas('success', 'List saved.');

    expect(PlotSizes::all())->toBe(['3x6', '3x3', '6x6']);
});

it('refuses to drop a booth size still in use, wherever it is held', function (string $holder) {
    PlotSizes::set(['3x3', '3x6', '3x9']);

    match ($holder) {
        'a draw-list row' => registrationAt('3x9'),
        'a company' => tap(Company::factory()->create(['user_id' => User::factory()->create()->id]), function ($c) {
            $c->plot_size = '3x9';
            $c->save();
        }),
        'a lot plan' => PlotSize::create([
            'event_id' => Event::factory()->create()->id, 'location' => 'Dewan', 'size' => '3x9', 'quantity' => 2,
        ]),
    };

    $this->actingAs(superAdmin(), 'admin');
    saveList('plot_sizes', ['3x3', '3x6'])
        ->assertSessionMissing('success')
        ->assertSessionHas('error', fn ($m) => str_contains($m, '3x9'));

    expect(PlotSizes::all())->toBe(['3x3', '3x6', '3x9']);
})->with(['a draw-list row', 'a company', 'a lot plan']);

it('refuses to drop an entrepreneur category a company still carries', function () {
    EntrepreneurCategories::set(['Micro, Small & Medium (MSM)', 'No Category']);

    Company::factory()->create([
        'user_id' => User::factory()->create()->id,
        'entrepreneur_category' => 'No Category',
    ]);

    $this->actingAs(superAdmin(), 'admin');
    saveList('entrepreneur_categories', ['Micro, Small & Medium (MSM)'])
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'No Category'));

    expect(EntrepreneurCategories::all())->toBe(['Micro, Small & Medium (MSM)', 'No Category']);
});

it('reads a change of case as the same value, not a removal', function () {
    // MySQL compares these columns case-insensitively, and ManagedList treats
    // "3X9" and "3x9" as one entry. Re-casing is not dropping.
    PlotSizes::set(['3x3', '3x9']);
    registrationAt('3x9');

    $this->actingAs(superAdmin(), 'admin');
    saveList('plot_sizes', ['3x3', '3X9'])->assertSessionHas('success');
});

it('does not report an all-blank list saved when it was kept', function () {
    // ManagedList::set() refuses to store an empty list.
    PlotSizes::set(['3x3', '3x6']);

    $this->actingAs(superAdmin(), 'admin');
    saveList('plot_sizes', ['   ', ''])->assertSessionMissing('success');

    expect(PlotSizes::all())->toBe(['3x3', '3x6']);
});

it('answers 404 for a list it does not manage', function () {
    $this->actingAs(superAdmin(), 'admin');
    saveList('zones', ['Johor'])->assertNotFound();
});

it('leaves the lists to the roles that manage settings', function () {
    PlotSizes::set(['3x3', '3x6']);

    $this->actingAs(stateAdmin('Johor'), 'admin');
    saveList('plot_sizes', ['3x3'])->assertForbidden();

    expect(PlotSizes::all())->toBe(['3x3', '3x6']);
});
