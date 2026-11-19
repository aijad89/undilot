<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\User;
use App\Support\Legacy;
use Illuminate\Support\Facades\DB;

/*
 * legacy:migrate moves the old system in one go, and its promise is that NOTHING
 * IS LOST: every old row is in staging whole (`raw`), and every row of a
 * migrated table is linked to what it became. These tests hold that promise on
 * tests/Fixtures/legacy-migrate-sample.sql — fictional data carrying every
 * awkward shape the real dump has (see the fixture's header).
 */

function migrateSample(): string
{
    // The coded half of kraf_bidangs: TBP and RKP are on the sheet, the bare
    // 'Songket' of the older rows is not.
    KrafBidang::factory()->create(['name' => 'Batik', 'code' => 'TBP']);
    KrafBidang::factory()->create(['name' => 'Kayu', 'code' => 'RKP']);

    Artisan::call('legacy:migrate', ['path' => base_path('tests/Fixtures/legacy-migrate-sample.sql')]);

    return Artisan::output();
}

function registrationsOf(User $user): Illuminate\Support\Collection
{
    return DB::table('event_registrations')->where('user_id', $user->id)->get();
}

it('keeps every column of every old row in raw, including the ones with no column of their own', function () {
    migrateSample();

    $row = DB::table('legacy_usahawan')->where('source_table', 'usahawan')->where('legacy_id', '101')->first();
    $raw = json_decode($row->raw, true);

    // Exactly the dump's own columns, by the dump's own names.
    expect(array_keys($raw))->toBe([
        'id', 'name', 'ic', 'address', 'postcode', 'region', 'register', 'salu', 'state', 'companyname',
        'crafttype', 'lot', 'location', 'votetime', 'bidang', 'preset', 'comid', 'bayarstatus', 'payment',
        'protokol', 'sstatus',
    ]);

    // The fields the old import DROPPED at load are here now.
    expect($raw['protokol'])->toBe('')
        ->and($raw['bayarstatus'])->toBe('Ya')
        ->and($raw['payment'])->toBe('R-1001')
        ->and($raw['register'])->toBe('SA0001-A');

    // And the fileuser dates, which have no staging column at all.
    $admin = DB::table('legacy_upengawai')->where('source_table', 'fileuser')->where('legacy_id', '2')->first();
    expect(json_decode($admin->raw, true)['ExpireDate'])->toBe('2020-01-01');
});

it('reads a latin1 byte as the letter it is, and leaves double-encoded text whole in raw', function () {
    migrateSample();

    // 0xE9 in a latin1 dump is 'é'. Stored raw it is invalid UTF-8, which MySQL
    // in strict mode refuses — the row would be lost, not kept.
    $andre = DB::table('legacy_usahawan')->where('legacy_id', '103')->value('name');
    expect($andre)->toBe('ANDRÉ TIGA');

    // 'RENÃ©E' is UTF-8 that went through latin1 once too often. Staging keeps
    // it as the dump has it; the account carries the repaired name.
    expect(DB::table('legacy_usahawan')->where('legacy_id', '102')->value('name'))->toBe('RENÃ©E DUA')
        ->and(User::where('legacy_id', 'usahawan:102')->value('name'))->toBe('RENéE DUA');
});

it('maps old staff to roles from the old data, and skips what is not an Undilot account', function () {
    migrateSample();

    $super = Admin::where('legacy_id', 'fileuser:1')->first();
    $hq = Admin::where('legacy_id', 'fileuser:2')->first();

    expect($super->role)->toBe(Admin::SUPER_ROLE)->and($super->is_active)->toBeTrue()
        ->and($hq->role)->toBe('hq')
        ->and($hq->is_active)->toBeFalse('Past its ExpireDate, as the old login treated it.');

    // A document-system user is not staged at all.
    expect(DB::table('legacy_upengawai')->where('source_table', 'fileuser')->count())->toBe(2);

    // Two officers on one login: two accounts, each in their own zone.
    expect(Admin::where('username', 'pegperlis')->value('state'))->toBe('Perlis')
        ->and(Admin::where('username', 'pegperlis.6')->value('state'))->toBe('Selangor, Kuala Lumpur dan Putrajaya');

    // An officer on a zone nobody set up: no account, still in staging.
    expect(Admin::where('legacy_id', 'upengawai:7')->exists())->toBeFalse()
        ->and(DB::table('legacy_upengawai')->where('legacy_id', '7')->value('raw'))->not->toBeNull();
});

it('makes one person of every old row with the same IC, however it was written', function () {
    migrateSample();

    // '900101-01-0001' in the live table, '900101010001' in FKJ 2025.
    $satu = User::where('name', 'PESERTA SATU')->get();
    expect($satu)->toHaveCount(1);

    $events = registrationsOf($satu->first())->pluck('event_id')->unique();
    expect($events)->toHaveCount(2, 'One account, a registration in each event.');

    // Every old row naming them points at that one account.
    expect(DB::table('legacy_usahawan')->where('user_id', $satu->first()->id)->pluck('source_table')->sort()->values()->all())
        ->toBe(['usahawan', 'usahawan_fkj2025']);
});

it('carries the old profile fields the office still uses', function () {
    migrateSample();

    $user = User::where('legacy_id', 'usahawan:101')->first();
    $company = Company::where('user_id', $user->id)->first();

    expect($user->salutation)->toBe('Encik')
        ->and($user->address)->toBe('Lot 1, Jalan Satu')
        ->and($user->postcode)->toBe('01000')
        ->and($user->district)->toBe('Kangar')
        ->and($user->community_id)->toBeNull("'TIADA' is the old system's word for none.")
        ->and($company->registration_no)->toBe('SA0001-A');
});

it('builds each past event in the list, the live one and the copies', function () {
    migrateSample();

    expect(Event::count())->toBe(3, 'Live MICF, FKJ 2025, and FKJ 2022.');

    $micf = Event::where('title', 'MICF 2026')->first();
    expect($micf)->not->toBeNull('The live event takes its code from ukhemah.event.')
        ->and($micf->status)->toBe('completed');
});

it('gives a company that held two booths one registration and both plots', function () {
    migrateSample();

    $user = User::where('legacy_id', 'usahawan:101')->first();
    $micf = Event::where('title', 'MICF 2026')->first();

    expect(DB::table('event_registrations')->where('event_id', $micf->id)->where('user_id', $user->id)->count())->toBe(1)
        ->and(Plot::where('event_id', $micf->id)->where('allocated_to', $user->id)->pluck('plot_code')->sort()->values()->all())
        ->toBe(['TBP01', 'TBP03']);
});

it('keeps a booth whose holder is gone, and one with no craft code, as plots', function () {
    migrateSample();

    $fkj = Event::where('description', 'like', '%usahawan_fkj2025%')->first();
    $plots = Plot::where('event_id', $fkj->id)->get()->keyBy('plot_code');

    expect($plots)->toHaveCount(3);
    expect($plots['TBP06']->is_allocated)->toBeFalse('Holder 99 is in no table: unallocated, not dropped.')
        ->and($plots['ST01']->bidang_id)->toBeNull('Songket carries no code.')
        ->and($plots['ST01']->is_allocated)->toBeTrue();
});

it('rebuilds the booths of an event whose booth table was not kept', function () {
    migrateSample();

    $fkj2022 = Event::where('description', 'like', '%usahawan_fkj2022%')->first();

    expect(Plot::where('event_id', $fkj2022->id)->pluck('plot_code')->all())->toBe(['KH09'])
        ->and($fkj2022->polling_start_date->year)->toBe(2022, 'No votetime: dated from the table name.');
});

it('carries payment and the receipt onto the registration', function () {
    migrateSample();

    $micf = Event::where('title', 'MICF 2026')->first();
    $byUser = DB::table('event_registrations')->where('event_id', $micf->id)->get()->keyBy('user_id');

    $satu = User::where('legacy_id', 'usahawan:101')->value('id');
    $dua = User::where('legacy_id', 'usahawan:102')->value('id');

    expect($byUser[$satu]->payment_status)->toBe('paid')
        ->and($byUser[$satu]->payment_note)->toBe('R-1001')
        ->and($byUser[$dua]->payment_status)->toBe('unpaid');
});

it('links every row of every migrated table, and says which rows stay archive only', function () {
    $output = migrateSample();

    $people = Legacy::participantSources();
    $events = array_filter(array_column(Legacy::EVENTS, 0));

    expect(DB::table('legacy_usahawan')->whereIn('source_table', $people)->whereNull('user_id')->count())->toBe(0)
        ->and(DB::table('legacy_ukhemah')->whereIn('source_table', $events)->whereNull('plot_id')->count())->toBe(0);

    // The hourly backup is archive only — staged, whole, linked to nothing.
    expect(DB::table('legacy_usahawan')->where('source_table', 'usahawan_day1_2pm')->whereNotNull('raw')->count())->toBe(1)
        ->and(DB::table('legacy_usahawan')->where('source_table', 'usahawan_day1_2pm')->whereNotNull('user_id')->count())->toBe(0);

    expect($output)->toContain('RECONCILIATION')
        ->and($output)->toContain('1 row(s) in migrated tables are linked to nothing');
});

it('can run twice on the same dump without building anything twice', function () {
    migrateSample();
    $counts = [User::count(), Admin::count(), Event::count(), Plot::count(), DB::table('event_registrations')->count()];

    Artisan::call('legacy:migrate', ['path' => base_path('tests/Fixtures/legacy-migrate-sample.sql')]);

    expect([User::count(), Admin::count(), Event::count(), Plot::count(), DB::table('event_registrations')->count()])
        ->toBe($counts);
});
