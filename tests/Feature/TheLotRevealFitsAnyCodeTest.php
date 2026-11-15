<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;

/*
 * The participant's lot page, after Confirm.
 *
 * THE BOARD BREAKS BETWEEN WORDS. A generated lot code carries its location
 * ("DEWAN UTAMA-TBPS185", 19 characters), and one unbroken row of flaps that
 * long is ~740px: on a phone it stretched the card past the screen and half
 * the code was never seen. The flaps are grouped by word — split at spaces,
 * which are dropped, and after a hyphen, which ends its word — and the groups
 * wrap. Measured in a browser at 390px and 1366px; held here by its markup.
 *
 * NO CHECK-IN QR ON THIS PAGE. It is on the slip, which is what the counter
 * sees; on the page it only pushed the slip buttons down.
 */

function confirmedLot(string $plotCode): array
{
    $event = Event::factory()->create([
        'status' => 'open', 'polling_status' => 'polling',
        'polling_start_date' => now()->subDay(), 'polling_deadline' => now()->addDay(),
    ]);
    $tbps = KrafBidang::factory()->code('TBPS')->create();
    Plot::factory()->on(PlotSize::factory()->create(['event_id' => $event->id]))->coded($tbps)
        ->create(['plot_code' => $plotCode]);

    $entry = EventRegistration::factory()->create([
        'event_id' => $event->id, 'company_id' => Company::factory(), 'code' => 'TBPS',
    ]);
    $user = $entry->company->user;
    $user->forceFill(['ic_number' => '850312-01-5023'])->save();

    test()->actingAs($user)->post(route('zvote.submit', $event), ['agreement' => '1']);

    return [$event, $user];
}

/** The board's words, each as the string its flaps spell. */
function boardWords(string $html): array
{
    preg_match('/<div class="lr-board"[^>]*>(.*?)<\/div>/s', $html, $board);

    expect($board)->not->toBeEmpty();

    preg_match_all('/<span class="lr-word">(.*?)<\/span>\s*(?=<span class="lr-word">|$)/s', trim($board[1]), $words);

    return array_map(function ($word) {
        preg_match_all('/data-final="([^"]*)"/', $word, $finals);

        return implode('', $finals[1]);
    }, $words[1]);
}

it('groups the board by word: spaces dropped, a hyphen ending its word', function () {
    [$event, $user] = confirmedLot('DEWAN UTAMA-TBPS185');

    $html = $this->actingAs($user)->withSession(['just_drawn' => true])
        ->get(route('zvote.index', $event))->assertOk()->getContent();

    expect(boardWords($html))->toBe(['DEWAN', 'UTAMA-', 'TBPS185'])
        ->and($html)->not->toContain('data-final=" "');
});

it('keeps a short code as one word', function () {
    [$event, $user] = confirmedLot('TBPS01');

    $html = $this->actingAs($user)->withSession(['just_drawn' => true])
        ->get(route('zvote.index', $event))->getContent();

    expect(boardWords($html))->toBe(['TBPS01']);
});

it('shows no check-in QR on the lot page, even with an IC on file', function () {
    [$event, $user] = confirmedLot('DEWAN UTAMA-TBPS185');

    expect($user->icQrDataUri())->not->toBeNull();   // there IS a code to show

    $this->actingAs($user)->get(route('zvote.index', $event))
        ->assertOk()
        ->assertSee(route('zvote.slip', $event))     // the slip carries it
        ->assertDontSee('Your check-in code')
        ->assertDontSee('Show this at the counter')
        ->assertDontSee('data:image/png;base64', false);
});

it('puts the slip and the way back in one row below the record, with no side column', function () {
    [$event, $user] = confirmedLot('DEWAN UTAMA-TBPS185');

    $this->actingAs($user)->get(route('zvote.index', $event))
        ->assertOk()
        ->assertSeeInOrder(['Your Lot Is Confirmed!', 'Confirmed On', 'Print Slip', 'Share Slip', 'Back to Dashboard'])
        ->assertDontSee('class="lot-side"', false);
});
