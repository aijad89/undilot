<?php

use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The transform rebuilds one event out of staging, and these tests pin the
 * decisions it makes — every one of them taken from a fact measured in the real
 * dump rather than chosen for convenience.
 *
 * legacy:transform-event runs once per old table pair (Legacy::EVENTS); this
 * file drives it on the live pair. The staging rows below are a miniature AACB: two halls, three booths, two of
 * them held, one craft code at two sizes. Small enough to read, shaped exactly
 * like the 175 rows it stands in for.
 */

function plantAacb(array $overrides = []): void
{
    // The coded half of kraf_bidangs — what a plot may be tagged with.
    KrafBidang::factory()->create(['name' => 'Batik', 'code' => 'TBP']);
    KrafBidang::factory()->create(['name' => 'Kayu', 'code' => 'RKP']);

    // A category row, which a plot must NEVER be tagged with. Present so the
    // test can prove the transform tells the two lists apart.
    KrafBidang::factory()->create(['name' => 'TEKSTIL', 'code' => null]);

    DB::table('legacy_usahawan')->insert([
        [
            'legacy_id' => '204', 'source_table' => 'usahawan',
            'name' => 'SITI CONTOH', 'ic' => '900101-12-0000', 'state' => 'Perlis',
            'companyname' => 'CONTOH KRAF LEGACY', 'crafttype' => 'Batik-TBP-3x3',
            'lot' => 'TBP01', 'location' => 'BASEMENT', 'votetime' => '2025-10-06 10:02:07',
            'bidang' => 'Tekstil', 'salu' => 'Puan', 'preset' => 'TBP01',
            'comid' => '99-1-02  ', 'byuser' => '24', 'imported_at' => now(),
        ],
        [
            'legacy_id' => '205', 'source_table' => 'usahawan',
            'name' => 'PESERTA CONTOH DUA', 'ic' => '660101-02-0000', 'state' => 'Kedah',
            'companyname' => 'CONTOH DUA KRAF', 'crafttype' => 'Kayu-RKP-3x6',
            'lot' => 'RKP01', 'location' => 'KHEMAH A', 'votetime' => '2025-10-06 13:54:09',
            'bidang' => 'Hasil Rimba', 'salu' => 'Puan', 'preset' => 'RKP01',
            'comid' => '05-2-66', 'byuser' => '4', 'imported_at' => now(),
        ],
    ]);

    DB::table('legacy_upengawai')->insert([
        'legacy_id' => '24', 'source_table' => 'upengawai', 'name' => 'PEGAWAI CONTOH SATU',
        'userright' => 'Pengawai', 'loginname' => 'pegsatu', 'state' => 'Perlis',
        'imported_at' => now(),
    ]);

    DB::table('legacy_ukhemah')->insert($overrides['booths'] ?? [
        [
            'legacy_id' => '1', 'source_table' => 'ukhemah', 'khemah' => 'BASEMENT',
            'usahawanid' => '204', 'boothid' => 'TBP01', 'bidang' => 'Tekstil',
            'crafttype' => 'Batik-TBP-3x3', 'prio' => '1', 'event' => 'AACB',
            'imported_at' => now(),
        ],
        [
            // The trailing space the dump really carries on 'TBA06 '.
            'legacy_id' => '2', 'source_table' => 'ukhemah', 'khemah' => 'KHEMAH A',
            'usahawanid' => '205', 'boothid' => 'RKP01 ', 'bidang' => 'Hasil Rimba',
            'crafttype' => 'Kayu-RKP-3x6', 'prio' => '1', 'event' => 'AACB',
            'imported_at' => now(),
        ],
        [
            // A booth nobody holds. AACB has exactly one (TBP09).
            'legacy_id' => '3', 'source_table' => 'ukhemah', 'khemah' => 'BASEMENT',
            'usahawanid' => '', 'boothid' => 'TBP02', 'bidang' => 'Tekstil',
            'crafttype' => 'Batik-TBP-3x3', 'prio' => '1', 'event' => 'AACB',
            'imported_at' => now(),
        ],
    ]);

    Artisan::call('legacy:accounts', ['--users' => 'all']);
}

it('refuses to run before the dump is loaded', function () {
    expect(Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']))->toBe(1)
        ->and(Event::count())->toBe(0);
});

it('keeps a booth whose holder has no account as an unallocated plot, not a dropped one', function () {
    plantAacb();

    // Take the links away again: staging still holds the people, but no row
    // says which account each became.
    DB::table('legacy_usahawan')->update(['user_id' => null, 'company_id' => null]);

    expect(Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']))->toBe(0);

    expect(Plot::count())->toBe(3, 'Every booth is still a plot.')
        ->and(Plot::where('is_allocated', true)->count())->toBe(0)
        ->and(DB::table('event_registrations')->count())->toBe(0);
});

it('reads the draw window out of votetime instead of inventing it', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $event = Event::first();

    // The two `votetime` values planted above, which is when the draw ran.
    expect($event->polling_start_date->toDateTimeString())->toBe('2025-10-06 10:02:07')
        ->and($event->polling_deadline->toDateTimeString())->toBe('2025-10-06 13:54:09');

    // A finished event: nothing may transition it back into life.
    expect($event->status)->toBe('completed')
        ->and($event->polling_status)->toBe('completed')
        ->and($event->auto_open)->toBeFalse()
        ->and($event->auto_polling_open)->toBeFalse();
});

it('marks the event as a preset draw, because lot equals preset on every row', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    expect(Event::first()->use_preset)->toBeTrue();

    // Preset where the holder's `usahawan.preset` names the booth; the free
    // booth has no holder to have been preset for.
    expect(Plot::where('is_allocated', true)->pluck('allocation_type')->unique()->all())->toBe(['preset']);

    expect(DB::table('preset_allocations')->count())->toBe(2)
        ->and(DB::table('preset_allocations')->where('is_confirmed', false)->count())->toBe(0);
});

it('rebuilds the plan as (location, size) pairs, counted from the booths', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $sizes = DB::table('plot_sizes')->orderBy('location')->orderBy('size')->get();

    expect($sizes)->toHaveCount(2);

    expect($sizes[0]->location)->toBe('BASEMENT')
        ->and($sizes[0]->size)->toBe('3x3')
        ->and($sizes[0]->quantity)->toBe(2);

    expect($sizes[1]->location)->toBe('KHEMAH A')
        ->and($sizes[1]->size)->toBe('3x6')
        ->and($sizes[1]->quantity)->toBe(1);

    // `total_plots` is computed from the plan, never taken from a form — so it
    // has to equal the plan it was built from.
    expect(Event::first()->total_plots)->toBe(3);
});

it('keeps the office booth id as the plot code rather than generating one', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    // Plot::codeFor() would write 'BASEMENT-TBP1'. The office wrote 'TBP01',
    // and that is what is printed on the booth and matched by usahawan.lot.
    expect(Plot::pluck('plot_code')->sort()->values()->all())
        ->toBe(['RKP01', 'TBP01', 'TBP02']);

    // And the trailing space in the dump's 'RKP01 ' is gone: trimming is the
    // transform's decision, which is why staging did not do it.
    expect(Plot::where('plot_code', 'RKP01')->exists())->toBeTrue();
});

it('tags every plot with a craft CODE row, never a category row', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $tagged = Plot::with('bidang')->get();

    expect($tagged)->toHaveCount(3);

    foreach ($tagged as $plot) {
        // The single most common mistake in this area: `kraf_bidangs` holds two
        // lists, and a plot tagged with a category can be matched by no
        // registration, because a registration carries a code.
        expect($plot->bidang->code)->not->toBeNull(
            "Plot {$plot->plot_code} was tagged with a category row."
        );
    }

    expect(Plot::where('plot_code', 'TBP01')->first()->bidang->code)->toBe('TBP');
});

it('hands each participant the plot the dump says they drew', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $registration = DB::table('event_registrations')
        ->join('users', 'users.id', '=', 'event_registrations.user_id')
        ->where('users.legacy_id', 'usahawan:204')
        ->select('event_registrations.*')
        ->first();

    $plot = Plot::find($registration->plot_id);

    expect($plot->plot_code)->toBe('TBP01', 'usahawan 204 held TBP01 in the dump.')
        ->and($plot->is_allocated)->toBeTrue()
        ->and($plot->allocated_to)->toBe($registration->user_id);

    // The code on the registration and the code on the plot have to agree, or
    // the allocator could never have matched them.
    expect($registration->code)->toBe('TBP')
        ->and($registration->plot_size)->toBe('3x3');
});

it('splits the craft code from the right, where the size is', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    // 'Kayu-RKP-3x6' — and in the real dump,
    // 'Manik/Mutiara/Karangan Laut-AMH-3x3', whose name holds punctuation.
    // Reading left to right turns that into code 'Manik'.
    $registration = DB::table('event_registrations')
        ->join('users', 'users.id', '=', 'event_registrations.user_id')
        ->where('users.legacy_id', 'usahawan:205')
        ->select('event_registrations.*')
        ->first();

    expect($registration->code)->toBe('RKP')
        ->and($registration->plot_size)->toBe('3x6');
});

it('writes every registration verified, because the draw completed', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    // PollingGuard holds a draw shut while any registration is unverified, so an
    // event that reached its end had every row through both checks. Writing
    // them unverified would describe a state this event was never in.
    expect(DB::table('event_registrations')->whereNull('verified_at')->count())->toBe(0)
        ->and(DB::table('event_registrations')->whereNull('first_verified_at')->count())->toBe(0)
        ->and(DB::table('event_registrations')->where('confirmed_participation', false)->count())->toBe(0);

    // Confirmed when they actually confirmed, not when the import ran.
    $confirmed = DB::table('event_registrations')
        ->join('users', 'users.id', '=', 'event_registrations.user_id')
        ->where('users.legacy_id', 'usahawan:204')
        ->value('confirmed_at');

    expect((string) $confirmed)->toStartWith('2025-10-06 10:02:07');
});

it('leaves an unheld booth unallocated instead of inventing a holder', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $free = Plot::where('is_allocated', false)->get();

    expect($free)->toHaveCount(1)
        ->and($free->first()->plot_code)->toBe('TBP02')
        ->and($free->first()->allocated_to)->toBeNull()
        ->and($free->first()->company_id)->toBeNull();

    // Three booths, two holders.
    expect(DB::table('event_registrations')->count())->toBe(2);
});

it('keeps a booth whose code the coding sheet does not have, as a plot with no code', function () {
    // A craft code nobody seeded — ITBP was dropped from the 2025 sheet, and 89%
    // of the old rows carry no code at all. A PAST event loses nothing by it:
    // the plot is kept with no code, the registration keeps the code as text,
    // and the booth's own row stays in staging.
    plantAacb(['booths' => [[
        'legacy_id' => '1', 'source_table' => 'ukhemah', 'khemah' => 'BASEMENT',
        'usahawanid' => '204', 'boothid' => 'ZZZ01', 'bidang' => 'Tekstil',
        'crafttype' => 'Something-ZZZ-3x3', 'prio' => '1', 'event' => 'AACB',
        'imported_at' => now(),
    ]]]);

    expect(Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']))->toBe(0)
        ->and(Plot::count())->toBe(1)
        ->and(Plot::first()->bidang_id)->toBeNull()
        ->and(DB::table('event_registrations')->value('code'))->toBe('ZZZ')
        ->and(DB::table('preset_allocations')->count())->toBe(0, 'A preset row needs a coded craft.');
});

it('rebuilds cleanly on --fresh, taking the registrations out before the plots', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $firstId = Event::first()->id;

    // `event_registrations.plot_id` has no ON DELETE clause on purpose, so the
    // database refuses to drop a plot a registration still points at. A --fresh
    // that deleted the event first would hit that wall.
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah','--fresh' => true]);

    expect(Event::count())->toBe(1, 'A re-run must replace, not accumulate.')
        ->and(Event::first()->id)->not->toBe($firstId)
        ->and(Plot::count())->toBe(3)
        ->and(DB::table('event_registrations')->count())->toBe(2)
        ->and(DB::table('polls')->count())->toBe(2);
});

it('still finds its own event on --fresh after the office edits the description', function () {
    plantAacb();
    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah']);

    $first = Event::first();

    // The description is a FIELD THE OFFICE EDITS. It is the first thing they
    // will change once they know the event's real name — and if --fresh
    // identifies the imported event by reading it, the match quietly stops
    // working and a second AACB event is built beside the first, with the same
    // 175 booths under a different id.
    $first->update(['description' => 'Pesta Kraf Antarabangsa 2025']);

    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah','--fresh' => true]);

    expect(Event::count())->toBe(1, 'A second AACB event was built beside the first.')
        ->and(Plot::count())->toBe(3)
        ->and(DB::table('event_registrations')->count())->toBe(2);
});

it('writes nothing on a dry run', function () {
    plantAacb();

    expect(Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah','--dry-run' => true]))->toBe(0);

    expect(Event::count())->toBe(0)
        ->and(Plot::count())->toBe(0)
        ->and(DB::table('plot_sizes')->count())->toBe(0);
});

it('takes the title and the fair dates from the options, since the dump has none', function () {
    plantAacb();

    Artisan::call('legacy:transform-event', ['usahawan' => 'usahawan', '--booths' => 'ukhemah',
        '--title' => 'Anugerah Adiguru Craft Bazaar 2025',
        '--venue' => 'Kompleks Kraf Kuala Lumpur',
        '--starts' => '2025-11-20',
        '--ends' => '2025-11-23',
    ]);

    $event = Event::first();

    expect($event->title)->toBe('Anugerah Adiguru Craft Bazaar 2025')
        ->and($event->location)->toBe('Kompleks Kraf Kuala Lumpur')
        ->and($event->start_date->toDateString())->toBe('2025-11-20')
        ->and($event->end_date->toDateString())->toBe('2025-11-23');

    // Registration has to have closed before the draw opened.
    expect($event->registration_close_at->lt($event->polling_start_date))->toBeTrue();
});
