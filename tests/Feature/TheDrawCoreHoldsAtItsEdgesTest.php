<?php

use App\Exceptions\LotUnavailable;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Services\LotAllocator;
use App\Support\PollingGuard;

/*
 * Four rules in the draw's core that a systematic mutation run (2026-11-15)
 * found untested: each was removed in turn and the whole suite still passed.
 * The other survivors of that run were unreachable or equivalent — the reasons
 * are in the commit that added this file.
 */

function coreEntry(Event $event, string $code = 'RKP', string $size = '3x3', array $extra = []): EventRegistration
{
    return EventRegistration::factory()->create($extra + [
        'event_id' => $event->id,
        'company_id' => Company::factory(),
        'code' => $code,
        'plot_size' => $size,
    ]);
}

it('never draws a lot from another event, even one with the right code and size free', function () {
    $rkp = KrafBidang::factory()->code('RKP')->create();

    $here = Event::factory()->create();
    PlotSize::factory()->create(['event_id' => $here->id, 'size' => '3x3']);   // a plan, no plots

    $elsewhere = Event::factory()->create();
    $theirs = Plot::factory()->on(PlotSize::factory()->create(['event_id' => $elsewhere->id, 'size' => '3x3']))
        ->coded($rkp)->create();

    $entry = coreEntry($here);

    expect(fn () => app(LotAllocator::class)->assign($entry))->toThrow(LotUnavailable::class);

    expect($theirs->fresh()->is_allocated)->toBeFalse();
});

it('ties the lot it hands out to the company, which the admin screens show', function () {
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $event = Event::factory()->create();
    $plot = Plot::factory()->on(PlotSize::factory()->create(['event_id' => $event->id, 'size' => '3x3']))
        ->coded($rkp)->create();

    $entry = coreEntry($event);

    app(LotAllocator::class)->assign($entry);

    expect($plot->fresh())
        ->company_id->toBe($entry->company_id)
        ->allocated_to->toBe($entry->user_id);
});

it('does not count a lot already set aside as free when asking if a draw can finish', function () {
    // One RKP 3x3 lot, preset to one company. A second RKP 3x3 company is
    // waiting, with no open pool to fall back on. The draw cannot seat it.
    $event = Event::factory()->create(['polling_start_date' => now()->addDay(), 'polling_deadline' => now()->addDays(2)]);
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $size = PlotSize::factory()->create(['event_id' => $event->id, 'size' => '3x3']);

    $holder = coreEntry($event);
    $preset = Plot::factory()->on($size)->coded($rkp)->create([
        'is_allocated' => true,
        'is_locked' => true,
        'allocated_to' => $holder->user_id,
        'allocation_type' => 'preset',
    ]);
    $holder->update(['plot_id' => $preset->id]);

    coreEntry($event);   // waiting for a lot that is not there

    expect(PollingGuard::blocker($event))->toContain('not enough lots for the booth sizes');
});

it('keeps HQ\'s verification when HQ changes a company\'s booth size, unlike a zone\'s change', function () {
    // EventRegistration::booted() unverifies a change by a STATE admin. HQ is
    // the verifier: its own change needs no second look.
    $event = Event::factory()->create(['polling_status' => 'draft']);
    $entry = coreEntry($event, 'RKP', '3x3', ['verified_at' => now(), 'first_verified_at' => now()]);
    $company = $entry->company;
    $company->update(['state' => 'Johor']);

    $this->actingAs(App\Models\Admin::factory()->create(['role' => 'hq', 'state' => null]), 'admin')
        ->put(route('admin.companies.kraf-code.update', $company), ['kraf_code' => null, 'plot_size' => '3x6'])
        ->assertSessionHasNoErrors();

    expect($entry->fresh())
        ->plot_size->toBe('3x6')
        ->verified_at->not->toBeNull();
});
