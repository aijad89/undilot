<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * THE STAGING TABLES MUST ACCEPT THE DUMP, NOT JUDGE IT.
 *
 * The office's system is MySQL 4-era. Across `upengawai`, `usahawan` and the
 * fifteen `ukhemah*` tables there is not one nullable column, so "no value" was
 * written as the empty string; and `lastlogin`, `votetime` and `votetimepay` are
 * `datetime NOT NULL` with no default, so "never" was written as
 * `0000-00-00 00:00:00`.
 *
 * This server runs sql_mode STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE.
 * A date column REFUSES that value. So the tests below pin the column TYPE, not
 * only the stored value: on SQLite a `datetime` column would swallow
 * `0000-00-00 00:00:00` happily and the suite would stay green while a MySQL
 * import died on row one. That is the same driver drift the enum rule exists to
 * prevent, arriving through a different door.
 */

it('declares every staging data column as a string, never a date or an integer', function () {
    // NOTE ON THE ASSERTION USED HERE. `toContain` is variadic, so
    // `->not->toContain('date', 'some message')` reads the message as a SECOND
    // NEEDLE and passes whenever either needle is absent — which a message never
    // is present. That silently disarms the assertion. `toBeTrue` does take a
    // message, so the check is written as an explicit type comparison.
    $stringTypes = ['varchar', 'char', 'text', 'string'];

    $mustBeString = [
        // datetime NOT NULL in the dump, holding '0000-00-00 00:00:00' for "never".
        ['legacy_usahawan', 'votetime'],
        // int in the dump, and an int column refuses '' under STRICT_TRANS_TABLES.
        ['legacy_ukhemah', 'prio'],
        // The original id, kept as text for the same reason.
        ['legacy_ukhemah', 'legacy_id'],
        ['legacy_usahawan', 'legacy_id'],
        ['legacy_upengawai', 'legacy_id'],
    ];

    foreach ($mustBeString as [$table, $column]) {
        $type = Schema::getColumnType($table, $column);

        expect(in_array($type, $stringTypes, true))->toBeTrue(
            "{$table}.{$column} is typed '{$type}' on ".DB::getDriverName()
            .". The dump holds '0000-00-00 00:00:00' or '' there, and MySQL's "
            .'NO_ZERO_DATE / STRICT_TRANS_TABLES refuses both for a typed column, '
            .'so the import would die on its first row.'
        );
    }
});

it('mirrors each legacy table column for column, lowercased, plus its provenance', function () {
    // Written out in full on purpose. The staging shape is a promise to the
    // office's export, so adding, renaming or dropping a column has to be a
    // deliberate edit here, not a silent drift away from the dump.
    $expected = [
        'legacy_upengawai' => [
            'id', 'legacy_id', 'source_table',
            'name', 'password', 'userright', 'loginname', 'state', 'ic', 'jawatan',
            'raw', 'admin_id', 'imported_at',
        ],
        'legacy_usahawan' => [
            'id', 'legacy_id', 'source_table',
            'name', 'ic', 'state', 'companyname', 'crafttype', 'lot', 'location',
            'votetime', 'bidang', 'salu', 'preset', 'comid', 'byuser',
            'raw', 'user_id', 'company_id', 'imported_at',
        ],
        'legacy_ukhemah' => [
            'id', 'legacy_id', 'source_table',
            'khemah', 'usahawanid', 'boothid', 'bidang', 'crafttype', 'prio', 'event',
            'raw', 'plot_id', 'imported_at',
        ],
    ];

    foreach ($expected as $table => $columns) {
        expect(Schema::hasTable($table))->toBeTrue("Staging table {$table} is missing.");

        $actual = Schema::getColumnListing($table);

        sort($columns);
        sort($actual);

        expect($actual)->toBe($columns, "The columns of {$table} no longer match the dump.");
    }
});

it('stores a zero date and an empty string in every column, because that is what the dump holds', function () {
    // Not one column of the source was nullable, so every absent value in the
    // dump is '' — and `votetime` is the column that proves the typing decision:
    // 45% of the snapshot rows hold '0000-00-00 00:00:00' there.
    DB::table('legacy_usahawan')->insert([
        'legacy_id' => '1',
        'source_table' => 'usahawan',
        'name' => '',
        'ic' => '',
        'state' => '',
        'companyname' => '',
        'crafttype' => '',
        'lot' => '',
        'location' => '',
        'votetime' => '0000-00-00 00:00:00',
        'bidang' => '',
        'salu' => '',
        'preset' => '',
        'comid' => '',
        'byuser' => '',
        'imported_at' => now(),
    ]);

    $row = DB::table('legacy_usahawan')->where('legacy_id', '1')->first();

    expect($row->votetime)->toBe('0000-00-00 00:00:00');
    expect($row->name)->toBe('');
});

it('takes a half-filled row, because ukhemah10 is a narrower table than the rest', function () {
    // Every data column nullable is what lets one staging table hold all the
    // `ukhemah*` shapes at once. `ukhemah10` stops after `subtypeitem`, so the
    // columns this import keeps from beyond that point arrive empty.
    DB::table('legacy_ukhemah')->insert([
        'legacy_id' => '7',
        'source_table' => 'ukhemah10',
        'khemah' => 'A',
        'usahawanid' => '1234',
        'boothid' => 'A-01',
        'imported_at' => now(),
    ]);

    $row = DB::table('legacy_ukhemah')->where('source_table', 'ukhemah10')->first();

    expect($row->boothid)->toBe('A-01');
    expect($row->bidang)->toBeNull();
    expect($row->prio)->toBeNull();

    // Only three of the dump's `ukhemah*` tables carry `event` at all: the live
    // one (AACB), `ukhemah_fkj2024` (FKJ) and `ukhemah_masterdata` (HKK).
    expect($row->event)->toBeNull();
});

it('keeps two rows that share a legacy id, because the snapshots repeat every row', function () {
    // The fourteen snapshots are copies of the live table taken hour by hour, so
    // `legacy_id` 1 exists fifteen times over. A unique index anywhere here would
    // reject the dump; only (source_table, legacy_id) together identify a row,
    // and even that is not promised until the office's data says so.
    foreach (['ukhemah', 'ukhemah_day1_1pm', 'ukhemah_day1_2pm'] as $source) {
        DB::table('legacy_ukhemah')->insert([
            'legacy_id' => '1',
            'source_table' => $source,
            'boothid' => 'A-01',
            'imported_at' => now(),
        ]);
    }

    expect(DB::table('legacy_ukhemah')->where('legacy_id', '1')->count())->toBe(3);
});

it('points no foreign key out of staging, so an orphan row still lands', function () {
    // `usahawan.byuser` names an `upengawai.id` and `ukhemah.usahawanid` names a
    // `usahawan.id`, but neither is enforced here. A dump with a broken link has
    // to load so the transform can report it; a constraint would hide the row
    // instead of naming the problem.
    DB::table('legacy_usahawan')->insert([
        'legacy_id' => '99',
        'source_table' => 'usahawan',
        'byuser' => '4242',
        'imported_at' => now(),
    ]);

    DB::table('legacy_ukhemah')->insert([
        'legacy_id' => '99',
        'source_table' => 'ukhemah',
        'usahawanid' => '4242',
        'imported_at' => now(),
    ]);

    expect(DB::table('legacy_usahawan')->where('byuser', '4242')->exists())->toBeTrue();
    expect(DB::table('legacy_ukhemah')->where('usahawanid', '4242')->exists())->toBeTrue();
});
