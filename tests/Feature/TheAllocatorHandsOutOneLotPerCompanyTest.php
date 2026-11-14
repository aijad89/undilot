<?php

use App\Exceptions\LotUnavailable;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\Poll;
use App\Models\User;
use App\Services\LotAllocator;
use Illuminate\Support\Facades\DB;

/*
 * ONE LOT PER COMPANY PER EVENT — the invariant the whole product rests on, and
 * the one a participant would notice broken before anybody else.
 *
 * Asked of LotAllocator directly rather than through a screen, because that is
 * where the rule is supposed to live. Four separate guards in three other files
 * keep callers from reaching the state that breaks it — a removal that holds a
 * lot is refused, unassignPlot deletes the Poll in the same transaction, the plan
 * freezes once a lot is held, and `plot_id` carries no ON DELETE so the database
 * restricts. The allocator must not need any of them.
 *
 * `lockForUpdate()` is a no-op on SQLite, so nothing here proves the locking. Two
 * overlapping transactions are a MySQL question and live in the browser suite
 * (tests/e2e/03-concurrent-confirm).
 */

function lotWorld(int $plots = 4): array
{
    $code = KrafBidang::factory()->code('TBPS')->create();

    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'polling',
        'polling_deadline' => now()->addDay(),
        'state' => null,
    ]);

    $size = PlotSize::create([
        'event_id' => $event->id,
        'size' => '3x3',
        'quantity' => $plots,
        'location' => 'Dewan',
    ]);

    for ($i = 0; $i < $plots; $i++) {
        Plot::create([
            'event_id' => $event->id,
            'plot_size_id' => $size->id,
            'company_id' => null,
            'allocated_to' => null,
            'bidang_id' => $code->id,
            'sub_bidang_id' => null,
            'plot_code' => 'D-TBPS'.$event->id.'-'.$i,
            'is_allocated' => false,
            'allocation_type' => 'algorithm',
            'is_locked' => false,
            'allocated_at' => null,
        ]);
    }

    return [$event, $code, $size];
}

function lotEntry(Event $event, ?string $code = 'TBPS', ?int $plotId = null): EventRegistration
{
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

    return EventRegistration::create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'code' => $code,
        'code_updated_at' => now(),
        'plot_size' => '3x3',
        'plot_id' => $plotId,
        'confirmed_participation' => false,
        'confirmed_at' => null,
        'verified_by' => null,
        'verified_at' => now(),
        'first_verified_at' => now(),
        'hq_note' => null,
        'payment_note' => null,
    ]);
}

/** Plots standing in this company's name, which is the number that must stay 1. */
function lotsHeldBy(EventRegistration $registration): int
{
    return Plot::where('event_id', $registration->event_id)
        ->where('allocated_to', $registration->user_id)
        ->count();
}

function assignLot(EventRegistration $registration): ?string
{
    try {
        DB::transaction(fn () => (new LotAllocator)->assign($registration));

        return null;
    } catch (LotUnavailable $e) {
        return $e->getMessage();
    }
}

it('refuses a second lot when the link was cleared but the Poll survived', function () {
    /*
     * THE REGRESSION. This state used to hand out a second lot in silence: the
     * drawn path asked nothing, and Poll::firstOrCreate finds the existing row
     * and writes nothing — so the company ended holding two plots against one
     * Poll, with no error anywhere and no way back without editing the database.
     *
     * Measured before the fix: 2 plots, 1 poll.
     */
    [$event] = lotWorld();
    $registration = lotEntry($event);

    expect(assignLot($registration))->toBeNull();

    $registration->fresh()->update(['plot_id' => null]);

    expect(assignLot($registration->fresh()))->not->toBeNull('A second lot was handed out.');

    expect(lotsHeldBy($registration))->toBe(1)
        ->and(Poll::where('event_id', $event->id)->count())->toBe(1);
});

it('refuses a second lot on a plain repeat, which is what a double-click is', function () {
    [$event] = lotWorld();
    $registration = lotEntry($event);

    expect(assignLot($registration))->toBeNull()
        ->and(assignLot($registration->fresh()))->not->toBeNull();

    expect(lotsHeldBy($registration))->toBe(1);
});

it('refuses a second lot to a preset holder who already drew', function () {
    [$event] = lotWorld();

    $plot = Plot::where('event_id', $event->id)->first();
    $registration = lotEntry($event, 'TBPS', $plot->id);

    $plot->update([
        'is_allocated' => true,
        'allocated_to' => $registration->user_id,
        'is_locked' => true,
        'allocation_type' => 'preset',
    ]);

    expect(assignLot($registration))->toBeNull()
        ->and(assignLot($registration->fresh()))->not->toBeNull();

    expect(lotsHeldBy($registration))->toBe(1);
});

it('gives a reservation somebody else now holds to nobody', function () {
    [$event] = lotWorld();

    $plot = Plot::where('event_id', $event->id)->first();
    $first = lotEntry($event, 'TBPS', $plot->id);
    $second = lotEntry($event, 'TBPS', $plot->id);

    $plot->update([
        'is_allocated' => true,
        'allocated_to' => $first->user_id,
        'is_locked' => true,
        'allocation_type' => 'preset',
    ]);

    expect(assignLot($first))->toBeNull()
        ->and(assignLot($second->fresh()))->not->toBeNull();

    // The second company does not fall back to the open pool either: it asked for
    // a specific lot and that lot is gone, which is a fact for an admin to fix
    // rather than something to paper over with a different plot.
    expect(lotsHeldBy($second))->toBe(0)
        ->and(lotsHeldBy($first))->toBe(1);
});

it('refuses a company with no participant account before touching any plot', function () {
    [$event] = lotWorld();

    $registration = lotEntry($event);
    $registration->forceFill(['user_id' => null])->save();

    expect(assignLot($registration->fresh()))->not->toBeNull();

    // Checked first on purpose: polls.user_id is NOT NULL, so without this the
    // insert fails as a database error rather than a LotUnavailable, and the bulk
    // run rolls back every lot it had already handed out.
    expect(Plot::where('is_allocated', true)->count())->toBe(0)
        ->and(Poll::count())->toBe(0);
});

it('will not hand over a reservation that belongs to another event', function () {
    [$mine] = lotWorld();
    [$theirs] = lotWorld();

    $foreign = Plot::where('event_id', $theirs->id)->first();
    $registration = lotEntry($mine, 'TBPS', $foreign->id);

    expect(assignLot($registration))->not->toBeNull();

    expect(Plot::where('event_id', $theirs->id)->where('is_allocated', true)->count())
        ->toBe(0, 'A plot from another event was handed out.');
});

it('seats exactly as many companies as there are lots, and names the rest', function () {
    [$event] = lotWorld(2);

    $registrations = collect(range(1, 4))->map(fn () => lotEntry($event));
    $refused = $registrations->map(fn ($r) => assignLot($r->fresh()))->filter()->count();

    expect($refused)->toBe(2)
        ->and(Plot::where('is_allocated', true)->count())->toBe(2)
        ->and(Poll::count())->toBe(2);
});

it('keeps the database unique key and the allocator saying the same thing', function () {
    // `polls` carries a UNIQUE on (user_id, event_id). The allocator's refusal
    // above is that constraint stated where it can be acted on — as a message to
    // a participant rather than as a 500 from an insert.
    $unique = collect(Schema::getIndexes('polls'))
        ->filter(fn ($i) => $i['unique'] && $i['columns'] === ['user_id', 'event_id']);

    expect($unique)->not->toBeEmpty('polls lost the unique key that means "drawn once".');
});
