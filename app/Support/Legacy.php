<?php

namespace App\Support;

use RuntimeException;

/**
 * The old system's data rules, in one place, for legacy:accounts,
 * legacy:transform-event and legacy:migrate.
 *
 * WHICH OLD TABLES BECOME WHAT (owner, 2026-11-19): the live pair and the final
 * copy of each past event become real events; the hourly 2011/2012 backups
 * (`ukhemah_day1_2pm`, `usahawan_18 feb 11`…) stay in staging only, whole, in
 * `raw`. Nothing is dropped either way — a table not listed here is still in
 * staging, and legacy:migrate reports every row that was not used.
 */
final class Legacy
{
    /**
     * The events to build: [ukhemah table, usahawan table, event code]. The code
     * is the old system's own (`ukhemah.event`); title, venue and fair dates are
     * not in the dump and are confirmed with the office afterwards.
     *
     * A `null` ukhemah table means the booth plan was not kept (FKJ 2022): its
     * booths are rebuilt from `usahawan.lot` and `usahawan.location`.
     *
     * HKK 2011 IS NOT HERE. Its booths survive (`ukhemah_hkk2011`) but no
     * `usahawan` copy carries the same name, and pairing it with a backup by
     * guesswork could hand one person's booth to another. Its rows stay in
     * staging.
     */
    public const EVENTS = [
        ['ukhemah', 'usahawan', null],
        ['ukhemah_asean2025', 'usahawan_asean2025', 'ASEAN'],
        ['ukhemah_fkj2025', 'usahawan_fkj2025', 'FKJ'],
        ['ukhemah_fkj2024', 'usahawan_fkj2024', 'FKJ'],
        ['ukhemah_hkk2024', 'usahawan_hkk2024', 'HKK'],
        ['ukhemah_fkj2023', 'usahawan_fkj2023', 'FKJ'],
        ['ukhemah_hkk2023', 'usahawan_hkk2023', 'HKK'],
        ['ukhemah_batik2023', 'usahawan_batik2023', 'BATIK'],
        [null, 'usahawan_fkj2022', 'FKJ'],
        ['ukhemah_hkk2022', 'usahawan_hkk2022', 'HKK'],
        ['ukhemah_wwa2021', 'usahawan_wwa2021', 'WWA'],
        ['ukhemah_hkk2012', 'usahawan_hkk2012', 'HKK'],
    ];

    /**
     * Where people come from, MOST RECENT FIRST: the first row that names a
     * person gives their profile, every later row only links to them. The two
     * master lists come after the events they were drawn from.
     */
    public static function participantSources(): array
    {
        $events = array_column(self::EVENTS, 1);

        return array_values(array_unique([
            $events[0],
            'usahawan_masterdata',
            ...array_slice($events, 1),
            'usahawan_masterlist',
        ]));
    }

    /** The staging row's whole source row, keys lower-cased and trimmed. */
    public static function raw(object $row): array
    {
        $decoded = json_decode((string) ($row->raw ?? ''), true);

        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[strtolower(trim((string) $key))] = $value;
        }

        return $out;
    }

    /** A raw field, trimmed and repaired, or null when blank. */
    public static function field(array $raw, string $key): ?string
    {
        $value = trim(self::repair((string) ($raw[$key] ?? '')));

        return $value === '' ? null : $value;
    }

    /**
     * Double-encoded UTF-8 — 'Ã©' where 'é' was meant — is what a latin1 table
     * fed UTF-8 text holds. LegacyDump keeps it as it is (staging is not
     * cleaned); it is repaired here, on the way into an application column,
     * and only when the repair is itself valid UTF-8 and shorter.
     */
    public static function repair(string $value): string
    {
        if (! preg_match('/[\xC3\xC2][\x80-\xBF]/', $value)) {
            return $value;
        }

        $once = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');

        return mb_check_encoding($once, 'UTF-8') && strlen($once) < strlen($value) ? $once : $value;
    }

    /**
     * Who a participant row is. The IC as digits when it has any — the same rule
     * import:participants uses, because the office writes `900101-12-0000` and
     * `900101120000` for one person. Without an IC, the name and company
     * together. Null when the row has neither: it cannot be told apart from
     * anyone, so it links to nobody and is reported.
     */
    public static function identity(?string $ic, ?string $name, ?string $company): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $ic);

        if ($digits !== '') {
            return 'ic:'.$digits;
        }

        $name = mb_strtolower(trim((string) $name));
        $company = mb_strtolower(trim((string) $company));

        return $name === '' && $company === '' ? null : 'nc:'.$name.'|'.$company;
    }

    /**
     * `crafttype` holds the craft name, its code and the booth size, joined by
     * hyphens: 'Manik/Mutiara/Karangan Laut-AMH-3x3'. SPLIT FROM THE RIGHT — the
     * craft name itself carries punctuation and hyphens. Older rows carry a bare
     * name ('Songket') and return nulls.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function codeAndSize(?string $crafttype): array
    {
        if (! preg_match('/-([A-Za-z]{2,6})-(\d+x\d+)\s*$/', trim((string) $crafttype), $m)) {
            return [null, null];
        }

        return [strtoupper($m[1]), strtolower($m[2])];
    }

    /**
     * Hall names upper-cased and whitespace collapsed: the 2011 rows read
     * 'KHEMAH A' in one table and 'Khemah A' in the other, and a plan keyed on
     * raw text would split one hall into two.
     */
    public static function location(?string $khemah): string
    {
        $clean = preg_replace('/\s+/', ' ', trim(self::repair((string) $khemah)));

        return mb_strtoupper($clean) ?: 'LOC';
    }

    /** LegacyZones::translate(), with an unknown zone answered as null. */
    public static function zone(?string $state): ?string
    {
        try {
            return LegacyZones::translate($state);
        } catch (RuntimeException) {
            return null;
        }
    }

    /** `comid`, trimmed, with the old system's 'TIADA' ("none") as null. */
    public static function communityId(?string $comid): ?string
    {
        $value = trim((string) $comid);

        return $value === '' || strcasecmp($value, 'TIADA') === 0 ? null : $value;
    }
}
