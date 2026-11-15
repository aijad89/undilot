<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\User;

/*
 * The draw list's craft-code FILTER offers only the codes some row on the
 * list carries — a code nobody holds can only filter to an empty table. A
 * state admin sees the codes of their own zone's rows, as they see only those
 * rows. The row's own Edit select and the bulk action still offer every active
 * code: there the admin is choosing a code, not looking for one.
 */

/** The values offered by the filter's select, and nothing else on the page. */
function filterCodes(string $html): array
{
    preg_match('/<select name="code" class="mac-select" aria-label="Filter by craft code">(.*?)<\/select>/s', $html, $select);

    expect($select)->not->toBeEmpty();

    preg_match_all('/<option value="([^"]+)"/', $select[1], $values);

    return $values[1];
}

function rowWithCode(Event $event, string $zone, ?string $code): void
{
    EventRegistration::factory()->create([
        'event_id' => $event->id,
        'company_id' => Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => $zone])->id,
        'code' => $code,
    ]);
}

function codedSheet(): void
{
    foreach (['LTS', 'RKP', 'TBA', 'TBPS'] as $code) {
        KrafBidang::factory()->code($code)->create();
    }
}

it('offers only the codes rows on this list carry, in order, once each', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');
    rowWithCode($event, 'Johor', 'TBA');
    rowWithCode($event, 'Kedah', 'TBA');
    rowWithCode($event, 'Kedah', null);

    $page = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->getContent();

    expect(filterCodes($page))->toBe(['TBA', 'TBPS']);
});

it('offers a state admin only the codes of their own zone\'s rows', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');
    rowWithCode($event, 'Kedah', 'RKP');

    $page = $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->getContent();

    expect(filterCodes($page))->toBe(['TBPS']);
});

it('keeps the code being filtered on in the dropdown, even with no row left carrying it', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');

    $page = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', [$event, 'code' => 'LTS']))
        ->getContent();

    expect(filterCodes($page))->toBe(['LTS', 'TBPS'])
        ->and($page)->toContain('<option value="LTS" selected>');

    // Filtering on a code the list does carry lists it once, not twice.
    $page = $this->get(route('admin.events.registrations.index', [$event, 'code' => 'TBPS']))->getContent();

    expect(filterCodes($page))->toBe(['TBPS']);
});

/** The "12 / 200" badge's text, or null when it is not shown. */
function filterCount(string $html): ?string
{
    return preg_match('/<span[^>]*id="filter-count"[^>]*>\s*(.*?)\s*<\/span>/s', $html, $m) ? $m[1] : null;
}

it('shows how many rows match a filter, of the rows on the list', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');
    rowWithCode($event, 'Johor', 'TBA');
    rowWithCode($event, 'Kedah', 'TBA');

    $this->actingAs(superAdmin(), 'admin');

    expect(filterCount($this->get(route('admin.events.registrations.index', [$event, 'code' => 'TBA']))->getContent()))
        ->toBe('2 / 3');
});

it('counts a state admin\'s own zone on both sides of the slash', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');
    rowWithCode($event, 'Johor', 'TBA');
    rowWithCode($event, 'Kedah', 'TBA');

    $page = $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', [$event, 'code' => 'TBA']))
        ->getContent();

    expect(filterCount($page))->toBe('1 / 2');
});

it('counts the whole match, not the page of 25 on screen', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    foreach (range(1, 30) as $_) {
        rowWithCode($event, 'Johor', 'TBA');
    }
    rowWithCode($event, 'Johor', 'TBPS');

    $page = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', [$event, 'code' => 'TBA']))
        ->getContent();

    expect(filterCount($page))->toBe('30 / 31');
});

it('shows no count without a filter, blank fields from the form included', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBA');

    $this->actingAs(superAdmin(), 'admin');

    expect(filterCount($this->get(route('admin.events.registrations.index', $event))->getContent()))->toBeNull()
        ->and(filterCount($this->get(route('admin.events.registrations.index',
            [$event, 'search' => '', 'code' => '', 'status' => '']))->getContent()))->toBeNull();
});

it('still offers every active code where a row\'s code is chosen', function () {
    codedSheet();
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowWithCode($event, 'Johor', 'TBPS');

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('<option value="LTS">LTS — ', false);   // the bulk action's select
});
