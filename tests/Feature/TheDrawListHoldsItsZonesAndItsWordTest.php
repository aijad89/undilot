<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Rules on the draw list and on admin accounts that a systematic mutation run
 * (2026-11-15) found untested: each was removed in turn and the whole suite
 * still passed. Grouped by what breaks without them.
 */

function hqAdmin(): Admin
{
    return Admin::factory()->create(['role' => 'hq', 'state' => null]);
}

function listRow(Event $event, string $zone = 'Johor', array $extra = []): EventRegistration
{
    return EventRegistration::factory()->create($extra + [
        'event_id' => $event->id,
        'company_id' => Company::factory()->create([
            'user_id' => User::factory()->create()->id,
            'state' => $zone,
        ])->id,
    ]);
}

function draftDraw(): Event
{
    return Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
}

/* ── A zone stays inside its zone ─────────────────────────────────────── */

it('refuses a zone saving another zone\'s row', function () {
    $event = draftDraw();
    $theirs = listRow($event, 'Kedah', ['code' => 'RKP']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(route('admin.events.registrations.update', [$event, $theirs]), ['code' => 'TBPS', 'plot_size' => '3x6'])
        ->assertForbidden();

    expect($theirs->fresh())->code->toBe('RKP')->plot_size->toBe('3x3');
});

it('reaches only the zone\'s own rows through a bulk action, whatever ids are posted', function () {
    $event = draftDraw();
    $theirs = listRow($event, 'Kedah', ['code' => 'RKP']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.events.registrations.bulk', $event), [
            'action' => 'set_code', 'registration_id' => [$theirs->id], 'code' => 'TBPS',
        ])
        ->assertSessionHas('error', 'Nothing matched.');

    expect($theirs->fresh()->code)->toBe('RKP');
});

it('offers a zone only its own companies to add', function () {
    $event = draftDraw();
    Company::factory()->create(['name' => 'Kraf Johor Sdn Bhd', 'state' => 'Johor']);
    Company::factory()->create(['name' => 'Kraf Kedah Sdn Bhd', 'state' => 'Kedah']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.companies', $event))
        ->assertSee('Kraf Johor Sdn Bhd')
        ->assertDontSee('Kraf Kedah Sdn Bhd');
});

/* ── A drawn lot does not vanish with a list edit ─────────────────────── */

it('refuses even HQ removing a company that holds a lot', function () {
    $event = draftDraw();
    $plot = Plot::factory()->on(PlotSize::factory()->create(['event_id' => $event->id]))->create(['is_allocated' => true]);
    $holder = listRow($event, 'Johor', ['plot_id' => $plot->id]);

    $this->actingAs(hqAdmin(), 'admin')
        ->delete(route('admin.events.registrations.destroy', [$event, $holder]), ['removal_reason' => 'Tarik diri.'])
        ->assertSessionHas('error');

    expect(EventRegistration::find($holder->id))->not->toBeNull();
});

/* ── A company told it is in hears why it is out ──────────────────────── */

it('refuses removing a company already told it is in, without a reason', function () {
    Notification::fake();
    $event = draftDraw();
    $told = listRow($event);   // factory: verified and announced

    $this->actingAs(hqAdmin(), 'admin');

    $this->delete(route('admin.events.registrations.destroy', [$event, $told]))
        ->assertSessionHas('error');
    expect(EventRegistration::find($told->id))->not->toBeNull();

    $this->delete(route('admin.events.registrations.destroy', [$event, $told]), ['removal_reason' => 'Bayaran tidak diterima.'])
        ->assertSessionHas('success');
    expect(EventRegistration::find($told->id))->toBeNull();
});

it('refuses removing told companies in bulk without a reason', function () {
    Notification::fake();
    $event = draftDraw();
    $rows = [listRow($event), listRow($event)];

    $this->actingAs(hqAdmin(), 'admin')
        ->post(route('admin.events.registrations.bulk', $event), [
            'action' => 'remove', 'registration_id' => array_map(fn ($r) => $r->id, $rows),
        ])
        ->assertSessionHas('error');

    expect(EventRegistration::whereIn('id', array_map(fn ($r) => $r->id, $rows))->count())->toBe(2);
});

/* ── The two checks keep their state straight ─────────────────────────── */

it('unverifies a row HQ returns to its zone, so the draw cannot open on it', function () {
    $event = draftDraw();
    $row = listRow($event);

    $this->actingAs(hqAdmin(), 'admin')
        ->post(route('admin.events.registrations.return', [$event, $row]), ['hq_note' => 'Semak resit.']);

    expect($row->fresh())
        ->verified_at->toBeNull()
        ->hq_note->toBe('Semak resit.');
});

it('returns ticked rows to their zones in one step, each unverified with HQ\'s note', function () {
    $event = draftDraw();
    $rows = [listRow($event, 'Johor'), listRow($event, 'Kedah')];
    $kept = listRow($event, 'Johor');

    $this->actingAs(hqAdmin(), 'admin')
        ->post(route('admin.events.registrations.bulk', $event), [
            'action' => 'return', 'registration_id' => array_map(fn ($r) => $r->id, $rows), 'hq_note' => 'Semak resit.',
        ])
        ->assertSessionHas('success', '2 returned to their zones.');

    foreach ($rows as $row) {
        expect($row->fresh())->verified_at->toBeNull()->hq_note->toBe('Semak resit.');
    }

    expect($kept->fresh())->verified_at->not->toBeNull()->hq_note->toBeNull();
});

it('refuses a bulk return without a note, from a zone, or once the draw has started', function () {
    $event = draftDraw();
    $row = listRow($event);
    $return = fn (array $extra = []) => $this->post(route('admin.events.registrations.bulk', $event),
        $extra + ['action' => 'return', 'registration_id' => [$row->id], 'hq_note' => 'Semak resit.']);

    $this->actingAs(hqAdmin(), 'admin');
    $return(['hq_note' => ''])->assertSessionHasErrors('hq_note');

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin');
    $return()->assertForbidden();

    auth()->forgetGuards();
    $event->update(['polling_status' => 'polling']);
    $this->actingAs(hqAdmin(), 'admin');
    $return()->assertSessionHas('error');

    expect($row->fresh())->verified_at->not->toBeNull()->hq_note->toBeNull();
});

it('offers the bulk Return to HQ alone, and only while the draw is a draft', function () {
    $draft = draftDraw();
    listRow($draft);
    $running = Event::factory()->create(['status' => 'open', 'polling_status' => 'polling']);
    listRow($running);
    $option = '<option value="return">Return to the zone</option>';

    $this->actingAs(hqAdmin(), 'admin');
    $this->get(route('admin.events.registrations.index', $draft))
        ->assertSee($option, false)->assertSee('name="hq_note"', false);
    $this->get(route('admin.events.registrations.index', $running))->assertDontSee($option, false);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $draft))
        ->assertDontSee($option, false);
});

it('clears HQ\'s note when HQ verifies the row', function () {
    $event = draftDraw();
    $row = listRow($event, 'Johor', ['verified_at' => null, 'hq_note' => 'Semak resit.']);

    $this->actingAs(hqAdmin(), 'admin')
        ->post(route('admin.events.registrations.bulk', $event), ['action' => 'verify', 'registration_id' => [$row->id]]);

    expect($row->fresh())
        ->verified_at->not->toBeNull()
        ->hq_note->toBeNull();
});

it('clears HQ\'s note when the zone answers it with a payment note', function () {
    $event = draftDraw();
    $row = listRow($event, 'Johor', ['verified_at' => null, 'hq_note' => 'Mana resit?']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(route('admin.events.registrations.payment-note', [$event, $row]), ['payment_note' => 'Resit 0042, 3 Nov, tunai']);

    expect($row->fresh())
        ->payment_note->toBe('Resit 0042, 3 Nov, tunai')
        ->hq_note->toBeNull();
});

it('keeps the date HQ first verified a row through a re-verification', function () {
    // first_verified_at says when the company was told. A later re-check by
    // HQ, after a zone edit, must not move it.
    $event = draftDraw();
    $first = now()->subDays(3)->startOfSecond();
    $row = listRow($event, 'Johor', ['verified_at' => null, 'first_verified_at' => $first]);

    $this->actingAs(hqAdmin(), 'admin')
        ->post(route('admin.events.registrations.bulk', $event), ['action' => 'verify', 'registration_id' => [$row->id]]);

    expect($row->fresh()->first_verified_at->toDateTimeString())->toBe($first->toDateTimeString());
});

it('puts back nothing once the draw has started', function () {
    // A zone's row waits for HQ, and no waiting row may exist while a draw
    // runs (zoneLocked). Undo is a way of adding rows, so the same holds.
    // Found by test, 2026-11-15: undo had no such check, and this restored a
    // waiting row into a running draw.
    $event = draftDraw();
    $row = listRow($event, 'Johor', ['verified_at' => null, 'first_verified_at' => null]);
    $zone = stateAdmin('Johor');

    $this->actingAs($zone, 'admin')
        ->delete(route('admin.events.registrations.destroy', [$event, $row]))
        ->assertSessionHas('success');

    $event->update(['polling_status' => 'polling']);

    $this->post(route('admin.events.registrations.undo', $event))
        ->assertSessionHas('error');

    expect(EventRegistration::where('event_id', $event->id)->count())->toBe(0);
});

/* ── The last super stays ─────────────────────────────────────────────── */

it('refuses deleting the last super admin, on the model itself', function () {
    // No screen reaches this today — a super cannot delete itself, and hq
    // cannot touch a super — so it is held where it lives: Admin::booted().
    $only = superAdmin();

    expect(fn () => $only->delete())->toThrow(ValidationException::class);
    expect(Admin::find($only->id))->not->toBeNull();

    superAdmin();
    $only->delete();
    expect(Admin::find($only->id))->toBeNull();
});
