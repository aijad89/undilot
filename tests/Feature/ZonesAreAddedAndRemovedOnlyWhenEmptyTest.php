<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Support\AdminZones;

/*
 * Adding and removing a zone on the Dropdown Lists screen. Renaming has its own
 * test (ZonesCarryTheirRowsWhenRenamedTest).
 *
 * REMOVING A ZONE STILL IN USE MUST BE REFUSED, and it is not tidiness. Every
 * zone dropdown is built from this list, so a company whose zone left the list
 * opens its edit form with no option `selected` — and the next save files it
 * under the FIRST zone on the list, with no error and no validation failure.
 * An import with untranslated zone names hits exactly that. One row in
 * any of the three columns that hold a zone is enough to refuse.
 *
 * Zones decide who sees what, so only a super admin changes them — not HQ,
 * though HQ can open the screen.
 */

function zonesAre(array $zones): void
{
    AdminZones::set($zones);
}

it('adds a zone to the list', function () {
    zonesAre(['Johor', 'Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.settings.zones.store'), ['zone' => 'Langkawi'])
        ->assertSessionHas('success', 'Zone added.');

    expect(AdminZones::all())->toBe(['Johor', 'Perlis', 'Langkawi']);
});

it('refuses a zone already on the list, in any case', function () {
    // MySQL compares zones case-insensitively, so "JOHOR" and "Johor" would be
    // two entries naming the same rows.
    zonesAre(['Johor', 'Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.settings.zones.store'), ['zone' => 'JOHOR'])
        ->assertSessionHas('error');

    expect(AdminZones::all())->toBe(['Johor', 'Perlis']);
});

it('removes a zone nothing is filed under', function () {
    zonesAre(['Johor', 'Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.zones.destroy'), ['zone' => 'Perlis'])
        ->assertSessionHas('success', 'Zone removed.');

    expect(AdminZones::all())->toBe(['Johor']);
});

it('refuses to remove a zone that still has rows filed under it', function (string $holder) {
    zonesAre(['Johor', 'Perlis']);

    match ($holder) {
        'an admin' => Admin::factory()->create(['role' => 'admin', 'state' => 'Perlis']),
        'a company' => Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Perlis']),
        'an event' => Event::factory()->create(['state' => 'Perlis']),
    };

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.zones.destroy'), ['zone' => 'Perlis'])
        ->assertSessionHas('error', 'That zone is still in use. Rename it or move its rows first.');

    expect(AdminZones::all())->toBe(['Johor', 'Perlis']);
})->with(['an admin', 'a company', 'an event']);

it('does not report the last zone removed when it was kept', function () {
    // An empty list is refused by ManagedList::set() — every zone dropdown
    // would be empty. The screen must say so, not "Zone removed."
    zonesAre(['Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.zones.destroy'), ['zone' => 'Perlis'])
        ->assertSessionMissing('success')
        ->assertSessionHas('error');

    expect(AdminZones::all())->toBe(['Perlis']);
});

it('refuses to remove a zone that is not on the list', function () {
    zonesAre(['Johor', 'Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.zones.destroy'), ['zone' => 'Atlantis'])
        ->assertSessionHasErrors('zone');

    expect(AdminZones::all())->toBe(['Johor', 'Perlis']);
});

it('leaves zones to a super admin, not HQ or a state admin', function (string $role) {
    zonesAre(['Johor', 'Perlis']);

    $admin = $role === 'hq'
        ? Admin::factory()->create(['role' => 'hq', 'state' => null])
        : stateAdmin('Johor');

    $this->actingAs($admin, 'admin');

    $this->post(route('admin.settings.zones.store'), ['zone' => 'Langkawi'])->assertForbidden();
    $this->delete(route('admin.settings.zones.destroy'), ['zone' => 'Perlis'])->assertForbidden();

    expect(AdminZones::all())->toBe(['Johor', 'Perlis']);
})->with(['hq', 'state admin']);
