<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use App\Support\AdminZones;
use Illuminate\Support\Facades\DB;

/*
 * legacy:accounts turns the old staff and participants into accounts. Roles come
 * from the old data (owner, 2026-11-19): every `upengawai` officer becomes the
 * state admin of their own zone; `fileuser` Administrator / DepartmentAdmin
 * become super / hq (LegacyMigrateTest covers those). Usernames come from
 * `loginname`; old passwords are not carried. Every row built carries
 * `legacy_id` (`source_table:id`), because --fresh deletes by it — never what
 * the office or the demo seeders made.
 */

function loadForAccounts(): void
{
    Artisan::call('import:legacy', [
        'path' => base_path('tests/Fixtures/legacy-dump-sample.sql'),
    ]);
}

it('refuses to run before anything is loaded', function () {
    expect(Artisan::call('legacy:accounts'))->toBe(1);

    expect(Admin::whereNotNull('legacy_id')->exists())->toBeFalse();
});

it('makes an officer the state admin of their own zone, keeping the office name', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $admin = Admin::where('legacy_id', 'upengawai:24')->first();

    // An officer was a branch officer in the old system, never HQ: the role is
    // read from where they came from, not handed out by position in the table.
    expect($admin->name)->toBe('PEGAWAI CONTOH SATU')
        ->and($admin->username)->toBe('pegsatu')
        ->and($admin->role)->toBe('admin')
        ->and($admin->state)->toBe('Perlis');

    expect(DB::table('legacy_upengawai')->where('legacy_id', '24')->value('admin_id'))
        ->toBe($admin->id, 'The staging row is linked to the account it became.');
});

it('builds a participant with their company, craft code and booth size', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // 'Manik/Mutiara/Karangan Laut-AMH-3x3' — the code and size are glued to a
    // craft name that itself holds punctuation.
    $user = User::where('legacy_id', 'usahawan:204')->first();

    expect($user->name)->toBe('SITI CONTOH BINTI ABU BAKAR')
        ->and($user->ic_number)->toBe('900101-12-0000');

    $company = Company::where('user_id', $user->id)->first();

    expect($company->name)->toBe('CONTOH KRAF LEGACY')
        ->and($company->state)->toBe('Perlis')
        ->and($company->kraf_code)->toBe('AMH', 'Split from the RIGHT, or the code reads "Manik".')
        ->and($company->plot_size)->toBe('3x3');
});

it('leaves the code and size null for the older rows that carry neither', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture's snapshot rows carry a bare 'Songket' — 89% of the real dump
    // looks like this, so guessing a code here would invent data.
    $user = User::where('legacy_id', 'usahawan:205')->first();
    $company = Company::where('user_id', $user->id)->first();

    expect($company->kraf_code)->toBe('RBH')
        ->and($company->plot_size)->toBe('3x3');

    // An HOURLY BACKUP is not a source of people (Legacy::participantSources):
    // its rows stay in staging, archive only, and become no account.
    expect(User::where('name', 'Lina binti Contoh')->exists())
        ->toBeFalse('An hourly backup row became an account.');
});

it('hands out the same role to the same officer on a re-run', function () {
    loadForAccounts();

    Artisan::call('legacy:accounts');
    $first = Admin::where('username', 'pegsatu')->first();

    Artisan::call('legacy:accounts');
    $second = Admin::where('username', 'pegsatu')->first();

    expect($second->id)->toBe($first->id, 'A re-run must update, not duplicate.')
        ->and($second->role)->toBe($first->role);

    expect(Admin::where('username', 'pegsatu')->count())->toBe(1);
});

it('gives two officers who share one login two accounts, and does not let the second take the first one\'s role', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // The fixture carries ids 30 and 31, both 'PEGAWAI CONTOH DUA' with
    // loginname 'pegdua' — the same shape as ids 5 and 25 in the real dump.
    //
    // THIS IS THE BUG THIS TEST EXISTS FOR. Keyed on a colliding address,
    // updateOrCreate does not fail: it updates the FIRST officer's row, so the
    // second one takes over the role already handed out. On the real dump that
    // built 17 admins into 16 rows and left no hq admin at all, silently.
    $first = Admin::where('username', 'pegdua')->first();
    $second = Admin::where('username', 'pegdua.31')->first();

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull('The second officer was swallowed by the first.');

    // Each keeps their OWN zone: had the second updated the first one's row,
    // the Langkawi officer would now sit in Terengganu.
    expect($first->state)->toBe('Langkawi', 'The second officer took over the first officer account.')
        ->and($second->state)->toBe('Terengganu')
        ->and($first->id)->not->toBe($second->id);
});

it('translates a legacy zone the office dropdown does not have', function () {
    loadForAccounts();

    // 'P.Penang' and 'Kedah(Langkawi)' are real values in the dump and neither
    // is an undilot zone. The dump is inconsistent with itself too: it also
    // writes 'Pulau Pinang' and 'Negeri Sembilan' in other rows.
    DB::table('legacy_usahawan')->insert([
        'legacy_id' => '300', 'source_table' => 'usahawan',
        'name' => 'PEGAWAI CONTOH TIGA', 'ic' => '590101-07-0003', 'state' => 'P.Penang',
        'companyname' => 'JATI INDAH MULIA', 'crafttype' => 'Batik-TBP-3x3',
        'lot' => 'TBP07', 'location' => 'BASEMENT', 'votetime' => '2025-10-06 11:00:00',
        'bidang' => 'Tekstil', 'salu' => 'Encik', 'preset' => 'TBP07',
        'comid' => '07-3-21  ', 'byuser' => '40', 'imported_at' => now(),
    ]);

    Artisan::call('legacy:accounts', ['--users' => 'all']);

    $company = Company::whereHas('user', fn ($q) => $q->where('legacy_id', 'usahawan:300'))->first();

    // THE BUG THIS LOCKS. Stored raw, `companies.state` is 'P.Penang', which no
    // zone dropdown contains — so the edit form renders with NO option selected
    // and the next save silently moves the company to whichever zone sits first
    // in the list, taking it away from its own state admin with no error.
    expect($company->state)->toBe('Pulau Pinang');

    expect(AdminZones::has($company->state))->toBeTrue(
        "A company on a zone outside AdminZones cannot be edited without being moved."
    );
});

it('puts every imported zone inside the office vocabulary, admins included', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts', ['--users' => 'all']);

    // The fixture's officer 30 carries 'Kedah(Langkawi)'.
    foreach (Admin::whereNotNull('legacy_id')->whereNotNull('state')->get() as $admin) {
        expect(AdminZones::has($admin->state))->toBeTrue(
            "Admin {$admin->username} is on '{$admin->state}', which is not a zone."
        );
    }

    foreach (Company::whereHas('user', fn ($q) => $q->whereNotNull('legacy_id'))->get() as $company) {
        expect(AdminZones::has($company->state))->toBeTrue(
            "Company {$company->name} is on '{$company->state}', which is not a zone."
        );
    }
});

it('collapses two legacy zones that became one, keeping both officers on it', function () {
    loadForAccounts();

    // The old system ran Selangor and Kuala Lumpur as separate offices; undilot
    // has one zone covering Selangor, KL and Putrajaya.
    DB::table('legacy_upengawai')->insert([
        [
            'legacy_id' => '50', 'source_table' => 'upengawai', 'name' => 'OFFICER SELANGOR',
            'userright' => 'Pengawai', 'loginname' => 'sel', 'state' => 'Selangor',
            'imported_at' => now(),
        ],
        [
            'legacy_id' => '51', 'source_table' => 'upengawai', 'name' => 'OFFICER KL',
            'userright' => 'Pengawai', 'loginname' => 'kl', 'state' => 'Kuala Lumpur',
            'imported_at' => now(),
        ],
    ]);

    Artisan::call('legacy:accounts');

    $zone = 'Selangor, Kuala Lumpur dan Putrajaya';

    // Both old offices' officers still work, now in the one zone.
    expect(Admin::where('state', $zone)->pluck('username')->sort()->values()->all())
        ->toBe(['kl', 'sel']);
});

it('gives no account to an officer on a zone it has no mapping for, rather than planting it raw', function () {
    loadForAccounts();

    DB::table('legacy_upengawai')->insert([
        'legacy_id' => '60', 'source_table' => 'upengawai', 'name' => 'OFFICER NOWHERE',
        'userright' => 'Pengawai', 'loginname' => 'nowhere', 'state' => 'Wilayah Baru',
        'imported_at' => now(),
    ]);

    // Storing it would put an admin where no company can ever be. The officer's
    // row stays in staging, unlinked, and the report counts it.
    Artisan::call('legacy:accounts');

    expect(Admin::where('username', 'nowhere')->exists())->toBeFalse()
        ->and(DB::table('legacy_upengawai')->where('legacy_id', '60')->value('admin_id'))->toBeNull()
        ->and(Artisan::output())->toContain('1 staff row(s) got no account');
});

it('carries the community id across, trimmed', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    // `comid` is filled on 174 of the 175 live rows, and 2,182 rows across the
    // dump carry it with trailing spaces: '99-1-02  '.
    expect(User::where('legacy_id', 'usahawan:205')->value('community_id'))
        ->toBe('99-1-02');
});

it('never writes a password anybody could know', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $admin = Admin::where('username', 'pegsatu')->first();

    // Not the login name, not the office name, not a word from the dump.
    foreach (['pegsatu', 'password', 'Pengawai', 'PEGAWAI CONTOH SATU'] as $guess) {
        expect(Hash::check($guess, $admin->password))->toBeFalse("'{$guess}' opened the account.");
    }
});

it('stamps every account it builds with the dump id', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    expect(Admin::whereNull('legacy_id')->count())->toBe(0)
        ->and(User::whereNull('legacy_id')->count())->toBe(0)
        ->and(Admin::count())->toBeGreaterThan(0);
});

it('clears out what it made when asked, and leaves the demo accounts alone', function () {
    loadForAccounts();
    Artisan::call('legacy:accounts');

    $outsider = User::factory()->create(['community_id' => 'KOM-REAL']);

    // THE ONE THAT MATTERS. This command once shared a marker with DemoSeeder
    // (an email domain), so `--fresh` deleted both. A seeded demo participant
    // has to survive, or a developer loses their whole demo dataset to a flag
    // that promised to clean up after one command.
    $demoUser = User::factory()->create(['community_id' => 'KOM-0001']);
    $demoAdmin = Admin::factory()->create(['username' => 'demo.hq', 'role' => 'hq', 'state' => null]);

    Artisan::call('legacy:accounts', ['--fresh' => true]);

    expect(User::where('id', $outsider->id)->exists())->toBeTrue()
        ->and(User::where('id', $demoUser->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder participant.')
        ->and(Admin::where('id', $demoAdmin->id)->exists())
        ->toBeTrue('--fresh deleted a DemoSeeder admin.');

    // And it did do its own job: the rebuild leaves exactly one of its admins.
    expect(Admin::where('username', 'pegsatu')->count())->toBe(1);
});
