<?php

use Illuminate\Support\Facades\DB;

/*
 * The loader's whole job is to copy text without deciding anything. These tests
 * pin the parts that a naive reader gets wrong on THIS dump, each one taken from
 * a row that exists in it:
 *
 *   - a table name with spaces and brackets  `usahawan_19 feb 6pm`
 *   - an escaped apostrophe inside a value   'SITI O\'BRIEN'
 *   - commas and brackets inside a value     'No. 8, Jalan Metafasa, U 16/3A'
 *   - several rows in one INSERT
 *   - an unquoted NULL beside quoted ''
 *   - '0000-00-00 00:00:00' as a value
 *   - a trailing space that must SURVIVE     'TBA06 '
 *   - a narrower table than the rest         `ukhemah10`
 *   - a table belonging to no family         `syatemconfiguration`
 *   - a row whose arity does not match its own column list
 */

function loadFixture(array $options = []): int
{
    return Artisan::call('import:legacy', [
        'path' => base_path('tests/Fixtures/legacy-dump-sample.sql'),
    ] + $options);
}

it('routes each table family to its own staging table and skips the rest', function () {
    loadFixture();

    expect(DB::table('legacy_usahawan')->count())->toBe(4)   // 2 live + 2 from one snapshot statement
        ->and(DB::table('legacy_ukhemah')->count())->toBe(3) // fkj2024 + ukhemah10 + ukhemah
        ->and(DB::table('legacy_upengawai')->count())->toBe(3);

    // `syatemconfiguration` belongs to no family, so it must not have landed
    // anywhere — including as a stray row in the biggest table.
    expect(DB::table('legacy_usahawan')->where('source_table', 'syatemconfiguration')->exists())
        ->toBeFalse();
});

it('keeps the original id and the table the row came from', function () {
    loadFixture();

    $row = DB::table('legacy_usahawan')->where('legacy_id', '204')->first();

    expect($row->source_table)->toBe('usahawan')
        ->and($row->companyname)->toBe('WARDAHAMIRAH LEGACY');

    // A table name with spaces is data here, so it cannot break anything.
    expect(DB::table('legacy_usahawan')->where('source_table', 'usahawan_19 feb 6pm')->count())
        ->toBe(2);
});

it('reads an escaped apostrophe and a value full of commas without tearing the row', function () {
    loadFixture();

    $row = DB::table('legacy_usahawan')->where('legacy_id', '205')->first();

    // If the apostrophe had ended the string early, every later value would be
    // off by one and this name would be truncated.
    expect($row->name)->toBe("SITI O'BRIEN BINTI ABDULLAH")
        ->and($row->state)->toBe('Kedah')
        ->and($row->companyname)->toBe('BINTARA KRAF')
        ->and($row->byuser)->toBe('4');
});

it('takes several rows out of one INSERT', function () {
    loadFixture();

    $rows = DB::table('legacy_usahawan')
        ->where('source_table', 'usahawan_19 feb 6pm')
        ->orderBy('legacy_id')
        ->pluck('name', 'legacy_id');

    expect($rows['2'])->toBe('Leena binti Hassan')
        ->and($rows['3'])->toBe('Rosmawati binti Said');
});

it('tells an unquoted NULL from a quoted empty string, and keeps a zero date', function () {
    loadFixture();

    // `event` is a literal NULL in that row; `usahawanid` is ''.
    $row = DB::table('legacy_ukhemah')->where('source_table', 'ukhemah')->first();

    expect($row->event)->toBeNull()
        ->and($row->usahawanid)->toBe('');

    // The value the whole staging design exists for.
    expect(DB::table('legacy_usahawan')->where('legacy_id', '204')->value('votetime'))
        ->toBe('2025-10-06 10:13:34');
});

it('does not clean the values, because trimming is a decision', function () {
    loadFixture();

    // 'TBA06 ' and '10-1-62  ' are real values in the dump. Staging keeps them
    // as they are so the transform can decide, and be tested deciding.
    expect(DB::table('legacy_ukhemah')->where('source_table', 'ukhemah_fkj2024')->value('boothid'))
        ->toBe('TBA06 ');

    expect(DB::table('legacy_usahawan')->where('legacy_id', '205')->value('comid'))
        ->toBe('10-1-62  ');
});

it('fills what a narrower table has and leaves the rest null', function () {
    loadFixture();

    $row = DB::table('legacy_ukhemah')->where('source_table', 'ukhemah10')->first();

    expect($row->khemah)->toBe('Khemah A')
        ->and($row->usahawanid)->toBe('1234')
        ->and($row->boothid)->toBe('KH01')
        ->and($row->bidang)->toBeNull()
        ->and($row->prio)->toBeNull()
        ->and($row->event)->toBeNull();
});

it('drops the columns the staging tables do not carry, without failing', function () {
    $exit = loadFixture();

    // The fixture's `ukhemah_fkj2024` row carries typeitem, subtypeitem,
    // category, sstatusexport, sstatus and Protokol — all six measured at 0% in
    // the live data and left off the staging table on purpose.
    expect($exit)->toBe(0);

    $row = DB::table('legacy_ukhemah')->where('source_table', 'ukhemah_fkj2024')->first();

    expect($row->crafttype)->toBe('Batik-TBA-3x3')
        ->and($row->event)->toBe('FKJ');
});

it('refuses to guess at a row whose values do not match its own columns', function () {
    loadFixture();

    // The fixture's last row names three columns and supplies two values.
    expect(DB::table('legacy_usahawan')->where('legacy_id', '999')->exists())->toBeFalse();
});

it('empties the staging tables on a second run, and keeps both on --append', function () {
    loadFixture();
    loadFixture();

    expect(DB::table('legacy_usahawan')->count())->toBe(4, 'A re-run must not double the rows.');

    loadFixture(['--append' => true]);

    expect(DB::table('legacy_usahawan')->count())->toBe(8);
});

it('writes nothing on a dry run', function () {
    loadFixture(['--dry-run' => true]);

    expect(DB::table('legacy_usahawan')->count())->toBe(0)
        ->and(DB::table('legacy_ukhemah')->count())->toBe(0)
        ->and(DB::table('legacy_upengawai')->count())->toBe(0);
});

it('loads only one family when asked', function () {
    loadFixture(['--only' => 'upengawai']);

    expect(DB::table('legacy_upengawai')->count())->toBe(3)
        ->and(DB::table('legacy_usahawan')->count())->toBe(0)
        ->and(DB::table('legacy_ukhemah')->count())->toBe(0);
});

it('carries the one UserRight value through, since that is the role answer', function () {
    loadFixture();

    $row = DB::table('legacy_upengawai')->where('legacy_id', '24')->first();

    expect($row->name)->toBe('NORAINI BINTI OSMAN')
        ->and($row->userright)->toBe('Pengawai')
        ->and($row->loginname)->toBe('noraini')
        ->and($row->jawatan)->toBe('Penolong Pegawai Kraf')
        ->and($row->state)->toBe('Perlis');
});
