<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event as Events;

/*
 * The low-priority bugs from the test manual's Known Issues sheet, K12-K29,
 * one test each, fixed 2026-11-24.
 */

it('K12: does not set aside a plot taken between the check and the save', function () {
    $event = Event::factory()->create([
        'status' => 'open', 'polling_status' => 'polling',
        'polling_start_date' => now()->subDay(), 'polling_deadline' => now()->addDay(),
    ]);
    $plot = Plot::factory()->create(['event_id' => $event->id]);
    $entry = EventRegistration::factory()->create(['event_id' => $event->id]);
    $other = EventRegistration::factory()->create(['event_id' => $event->id]);

    // The race, made to happen: as the save opens its transaction, a
    // participant's Confirm takes the plot.
    $raced = false;
    Events::listen(TransactionBeginning::class, function () use (&$raced, $plot, $other) {
        if ($raced) {
            return;
        }
        $raced = true;
        Plot::whereKey($plot->id)->update(['is_allocated' => true, 'company_id' => $other->company_id, 'allocation_type' => 'polling']);
        $other->update(['plot_id' => $plot->id]);
    });

    $this->actingAs(superAdmin(), 'admin')->post(route('admin.polling.preset-assign', $event), [
        'plot_id' => $plot->id, 'registration_id' => $entry->id,
    ])->assertSessionHasErrors(['error' => "Plot {$plot->plot_code} was taken a moment ago. Choose another."]);

    expect($plot->fresh()->company_id)->toBe($other->company_id)
        ->and($entry->fresh()->plot_id)->toBeNull();
});

it('K13: the scheduler does not open a draw whose deadline has passed', function () {
    $ready = fn (array $at) => tap(Event::factory()->create($at + [
        'polling_status' => 'draft', 'auto_polling_open' => true, 'auto_polling_close' => true,
        'polling_start_date' => now()->subDays(2),
    ]), function (Event $e) {
        Plot::factory()->create(['event_id' => $e->id]);
        EventRegistration::factory()->coded('RKP')->create(['event_id' => $e->id]);
    });

    $past = $ready(['polling_deadline' => now()->subHour()]);
    $open = $ready(['polling_deadline' => now()->addDay()]);

    $this->artisan('events:update-statuses')->assertSuccessful();

    // Before: opened and closed in the same tick, nobody drew.
    expect($past->fresh()->polling_status)->toBe('draft')
        ->and($open->fresh()->polling_status)->toBe('polling');
});

it('K15: reminds again for a new deadline, but once per window', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $event = Event::factory()->create(['polling_status' => 'polling', 'polling_deadline' => now()->addMinutes(30)]);
    $entry = EventRegistration::factory()->create(['event_id' => $event->id]);
    // Reminded two days ago, for a deadline that was then moved.
    $entry->forceFill(['reminded_at' => now()->subDays(2)])->saveQuietly();

    $this->artisan('voting:send-reminder');
    $this->artisan('voting:send-reminder');

    \Illuminate\Support\Facades\Notification::assertSentToTimes($entry->user, \App\Notifications\VotingCloseReminder::class, 1);
});

it('K24: the calendar carries every filter to its feed, not just the first', function () {
    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.calendar', ['search' => 'Batik', 'status' => 'open', 'state' => 'Johor']))
        ->assertOk()->getContent();

    preg_match('/events:\s*"([^"]+)"/', $html, $m);
    $url = json_decode('"'.$m[1].'"');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toEqual(['search' => 'Batik', 'status' => 'open', 'state' => 'Johor']);
});

it('K27: the dashboard agrees with the event page for a company being added back', function () {
    $event = Event::factory()->create(['title' => 'Pesta Kraf Utara']);
    $company = \App\Models\Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = \App\Models\EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    // Approved by the zone, verified by HQ, then taken off by HQ.
    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3']);
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->put(route('admin.events.registrations.update', [$event, EventRegistration::sole()]), ['plot_size' => '3x3']);
    $this->delete(route('admin.events.registrations.destroy', [$event, EventRegistration::sole()]), ['removal_reason' => 'Payment refunded']);

    // The zone adds it back; it waits for HQ.
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]]);
    expect(EventRegistration::sole()->first_verified_at)->toBeNull();

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('events.show', $event))
        ->assertSee('The office is adding your company to this draw.');
    $this->get(route('dashboard'))
        ->assertDontSee('Ask to join again')
        ->assertDontSee('Taken off the draw list');
});

it('K29: the public list and event page show the location, not the admin zone', function () {
    $event = Event::factory()->create(['status' => 'open', 'location' => 'Dewan Kraf Kuantan', 'state' => 'Zon Timur Pejabat']);

    foreach ([route('home'), route('events.show', $event)] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('Dewan Kraf Kuantan')
            ->assertDontSee('Zon Timur Pejabat');
    }
});

it('K26: the draw list shows the count against Maximum participants, as a guide only', function () {
    $event = Event::factory()->create(['max_participants' => 2]);
    EventRegistration::factory()->count(2)->create(['event_id' => $event->id]);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.events.registrations.index', $event))
        ->assertSee('2 / 2 participants')
        ->assertDontSee('over the maximum');

    // A third is still taken: nothing is refused.
    $company = \App\Models\Company::factory()->create(['state' => 'Johor']);
    $this->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]]);

    $this->get(route('admin.events.registrations.index', $event))
        ->assertSeeInOrder(['3 / 2 participants', 'over the maximum']);
});
