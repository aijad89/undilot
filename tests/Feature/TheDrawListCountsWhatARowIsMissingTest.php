<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

/*
 * The draw list counts, in each row's Actions column, how many of the three
 * details the office fills in are still empty — payment note, craft code,
 * booth size — so a zone sees which rows are not ready for HQ. "1 missing",
 * "2 missing"; nothing when the row is complete. The badge's title names them.
 */

function rowMissing(Event $event, array $details): EventRegistration
{
    return EventRegistration::factory()->create($details + [
        'event_id' => $event->id,
        'company_id' => Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor'])->id,
        'payment_note' => 'Resit 0042',
        'code' => 'TBPS',
        'plot_size' => '3x3',
    ]);
}

it('names the model\'s missing details in the order the list shows them', function () {
    $event = Event::factory()->create();

    expect(rowMissing($event, [])->missingDetails())->toBe([])
        ->and(rowMissing($event, ['payment_note' => null])->missingDetails())->toBe(['payment note'])
        ->and(rowMissing($event, ['payment_note' => '', 'code' => null])->missingDetails())->toBe(['payment note', 'craft code'])
        ->and(rowMissing($event, ['payment_note' => null, 'code' => '', 'plot_size' => null])->missingDetails())
        ->toBe(['payment note', 'craft code', 'booth size']);
});

it('shows 1 or 2 missing on a row, and nothing on a complete one', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $complete = rowMissing($event, []);
    $noCode = rowMissing($event, ['code' => null]);
    $noPaymentNoCode = rowMissing($event, ['payment_note' => null, 'code' => null]);

    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertOk()
        ->getContent();

    // Each row's badge, read from its company name to the end of its row.
    $badges = [];

    foreach ([$complete, $noCode, $noPaymentNoCode] as $r) {
        $row = substr($html, strpos($html, e($r->company->name)));
        $row = substr($row, 0, strpos($row, '</tr>'));
        $badges[$r->company->name] = preg_match('/missing-count"\s+title="([^"]*)">\s*(.*?)\s*</s', $row, $m)
            ? [$m[2], html_entity_decode($m[1])]
            : null;
    }

    expect($badges[$complete->company->name])->toBeNull()
        ->and($badges[$noCode->company->name])->toBe(['1 missing', 'Missing: craft code'])
        ->and($badges[$noPaymentNoCode->company->name])->toBe(['2 missing', 'Missing: payment note, craft code']);
});
