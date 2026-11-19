<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;
use App\Models\Poll;
use App\Models\User;

/**
 * Past events: the events a company took part in that have ended, as a table,
 * latest first, with the lot it had. They leave the dashboard, which keeps
 * what is still to come.
 */
function endedEvent(string $title, int $daysAgo, array $attributes = []): Event
{
    return Event::factory()->create([
        'title' => $title,
        'start_date' => now()->subDays($daysAgo + 2),
        'end_date' => now()->subDays($daysAgo),
    ] + $attributes);
}

it('shows a participant their draws and nothing about requesting to join', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

    $event = Event::factory()->create([
        'title' => 'Pesta Kraf Johor',
        'status' => 'open',
        'polling_status' => 'draft',
        'start_date' => now()->addMonth(),
        'end_date' => now()->addMonth()->addDay(),
    ]);

    EventRegistration::factory()->create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'verified_at' => now(),
        'first_verified_at' => now(),
    ]);

    $page = $this->actingAs($user, 'web')->get(route('dashboard'))->assertOk();
    $body = $page->getContent();

    expect(str_contains($body, 'Pesta Kraf Johor'))
        ->toBeTrue('The draw the office put them in has to be on the dashboard.');

    // The dashboard used to carry two more sections: requests the company sent,
    // and events still open to join. Neither can exist now.
    foreach (['Requests to join', 'Ask to join', 'Finish and send'] as $gone) {
        expect(str_contains($body, $gone))
            ->toBeFalse("The dashboard still shows '{$gone}'.");
    }
});

it('lists the ended events the company was in, latest first, with the lot and its slip', function () {
    $company = Company::factory()->create();
    $mine = Company::factory()->create(); // another company's past is not shown

    $older = endedEvent('Pesta Lama', 400);
    $recent = endedEvent('Karnival Semalam', 3);
    $cancelled = endedEvent('Yang Dibatalkan', 30, ['polling_status' => 'cancelled']);
    $upcoming = Event::factory()->create(['title' => 'Ekspo Akan Datang']);
    $theirs = endedEvent('Bukan Kami', 10);

    $plot = Plot::factory()->create(['plot_code' => 'B-TTK2']);
    EventRegistration::factory()->create(['event_id' => $recent->id, 'company_id' => $company->id, 'plot_id' => $plot->id, 'plot_size' => '3x6']);
    Poll::create(['user_id' => $company->user_id, 'event_id' => $recent->id, 'code' => null]);
    // Assigned by the office, not confirmed: the lot, but no slip.
    EventRegistration::factory()->create(['event_id' => $older->id, 'company_id' => $company->id, 'plot_id' => Plot::factory()->create(['plot_code' => 'A-OFIS'])->id]);
    $noLot = endedEvent('Tanpa Lot', 800);
    EventRegistration::factory()->create(['event_id' => $noLot->id, 'company_id' => $company->id]);
    EventRegistration::factory()->create(['event_id' => $cancelled->id, 'company_id' => $company->id]);
    EventRegistration::factory()->create(['event_id' => $upcoming->id, 'company_id' => $company->id]);
    EventRegistration::factory()->create(['event_id' => $theirs->id, 'company_id' => $mine->id]);

    $this->actingAs($company->user, 'web')->get(route('past-events'))
        ->assertOk()
        ->assertSeeInOrder(['<th scope="col">Event</th>', '<th scope="col">Lot</th>'], false)
        ->assertSeeInOrder(['Karnival Semalam', '3x6', 'B-TTK2', route('zvote.slip', $recent), 'Pesta Lama', 'A-OFIS', 'Tanpa Lot', 'No lot'], false)
        ->assertDontSee('Yang Dibatalkan')
        ->assertDontSee('Ekspo Akan Datang')
        ->assertDontSee('Bukan Kami');

    // The slip opens in a new tab, as it does from the lot page.
    $this->get(route('past-events'))->assertSee('<a href="'.route('zvote.slip', $recent).'" target="_blank" rel="noopener">Slip', false);

    // No slip for a lot the participant did not confirm.
    expect(substr_count($this->get(route('past-events'))->getContent(), '/lot/slip'))->toBe(1);

    // The dashboard keeps what is still to come.
    $this->get(route('dashboard'))
        ->assertSee('Ekspo Akan Datang')
        ->assertDontSee('Karnival Semalam')
        ->assertDontSee('Pesta Lama');
});

it('opens the summary of a past event in a new tab, under construction for now, for a company that took part only', function () {
    $company = Company::factory()->create();
    $event = endedEvent('Karnival Ringkasan', 10);
    EventRegistration::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs($company->user, 'web')->get(route('past-events'))
        ->assertSee('<a href="'.route('past-events.show', $event).'" target="_blank" rel="noopener">Karnival Ringkasan', false);

    $summary = $this->get(route('past-events.show', $event))
        ->assertOk()
        ->assertSee('Karnival Ringkasan')
        ->assertSee('Page under construction');

    // Past events is NOT on the participant's nav — the bar carries only what
    // they have to act on — so there is no active marker to look for. The page
    // is reached from a past event's own row, and it still renders.
    expect(str_contains($summary->getContent(), 'data-icon="past-events"'))
        ->toBeFalse('Past events is off the nav; an entry here means it came back.');

    // Not one it took part in, or not over yet: no such page.
    $theirs = endedEvent('Bukan Kami', 10);
    $this->get(route('past-events.show', $theirs))->assertNotFound();
    $coming = Event::factory()->create();
    EventRegistration::factory()->create(['event_id' => $coming->id, 'company_id' => $company->id]);
    $this->get(route('past-events.show', $coming))->assertNotFound();
    // Cancelled: not on Past events, so no summary either.
    $cancelled = endedEvent('Dibatalkan', 10, ['polling_status' => 'cancelled']);
    EventRegistration::factory()->create(['event_id' => $cancelled->id, 'company_id' => $company->id]);
    $this->get(route('past-events.show', $cancelled))->assertNotFound();
});

it('says so when there is no past event yet, and is for a signed-in participant only', function () {
    $this->get(route('past-events'))->assertRedirect(route('login'));

    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web')->get(route('past-events'))
        ->assertSee('No past events yet')
        ->assertDontSee('<table', false);

    // The page is reachable by URL but is NOT offered on the nav: the portal bar
    // carries what a participant has to act on, and a finished event is not that.
    expect(str_contains($this->get(route('dashboard'))->getContent(), 'href="'.route('past-events').'"'))
        ->toBeFalse('Past events is back on the nav.');
});
