<?php

namespace App\Support;

use Generator;
use RuntimeException;

/**
 * Reads INSERT rows out of the old system's mysqldump.
 *
 * WHY A HAND-WRITTEN READER rather than feeding the file to MySQL. The dump is
 * MySQL 4-era and cannot be replayed on this server: it declares `datetime NOT
 * NULL` columns that hold `0000-00-00 00:00:00`, which `NO_ZERO_DATE` rejects,
 * and `int NOT NULL` columns that hold `''`, which `STRICT_TRANS_TABLES`
 * rejects. Importing it means reading the values out as text and deciding where
 * they go, which is what the `legacy_*` staging tables are for.
 *
 * It also means the file is never trusted to be well-formed: it carries table
 * names with spaces and brackets (`ukhemah_18 feb 11`,
 * `ukhemah_hkk2012(guna_ini_12032012)`), and `CREATE TABLE` statements that the
 * export truncated mid-definition.
 *
 * ONE CONVERSION, AND IT LOSES NOTHING: text that is not valid UTF-8 is read as
 * Windows-1252 — MySQL's `latin1`, which is every table in the old database —
 * and converted to UTF-8. A latin1 dump writes 'é' as the single byte 0xE9, which
 * a utf8mb4 column refuses outright in strict mode, so without this the row is
 * lost rather than kept. The conversion maps back byte for byte. Text that is
 * ALREADY valid UTF-8 is left alone, including double-encoded 'Ã©': repairing
 * that is a judgement, and judgements are the transform's.
 *
 * WHAT IT DOES NOT DO: clean anything. A trailing space on `boothid` ('TBA06 ')
 * and a `comid` of '14-1-14   ' arrive exactly as the dump holds them. Staging
 * is a landing strip; trimming and parsing are the transform's job, where a
 * decision can be tested and re-run.
 */
class LegacyDump
{
    /**
     * MySQL's string escapes. Anything else after a backslash is the literal
     * character, which is what MySQL itself does.
     */
    private const ESCAPES = [
        '0' => "\0",
        "'" => "'",
        '"' => '"',
        'b' => "\x08",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'Z' => "\x1a",
        '\\' => '\\',
        '%' => '%',
        '_' => '_',
    ];

    public function __construct(private readonly string $path)
    {
        if (! is_file($this->path)) {
            throw new RuntimeException("Dump not found: {$this->path}");
        }
    }

    /**
     * One yield per row, not per statement, because a dump may write several
     * rows into one INSERT and the caller should not have to care.
     *
     * @return Generator<array{table: string, row: array<string, string|null>}>
     */
    public function rows(): Generator
    {
        foreach ($this->statements() as $statement) {
            if (! preg_match(
                '/^\s*insert\s+(?:ignore\s+)?into\s+`([^`]+)`\s*\(([^)]*)\)\s*values\s*(.+)$/is',
                $statement,
                $m
            )) {
                continue;
            }

            $table = $m[1];
            $columns = array_map(
                fn ($c) => trim($c, "` \t\r\n"),
                explode(',', $m[2])
            );

            foreach ($this->valueGroups($m[3]) as $values) {
                if (count($values) !== count($columns)) {
                    // A row whose arity does not match its own column list is
                    // not something to guess at. Reported, not silently dropped.
                    yield ['table' => $table, 'row' => null];

                    continue;
                }

                yield ['table' => $table, 'row' => array_combine($columns, $values)];
            }
        }
    }

    /**
     * Hunts INSERT statements rather than splitting the file on `;`.
     *
     * SPLITTING ON `;` LOOKS RIGHT AND IS NOT, which cost a full test run to
     * learn. This export truncates its `CREATE TABLE` statements mid-definition
     * — they end at `PRIMARY KEY (`id`)` with no closing bracket and NO
     * SEMICOLON — so a `;`-delimited reader glues each broken CREATE onto the
     * INSERT that follows it, and that row is then silently skipped because the
     * statement no longer begins with INSERT. Comments between statements do the
     * same thing.
     *
     * So: skip comments, find each `insert into `…``, and read it to the first
     * `;` that is not inside a quoted string. Nothing else in the file matters,
     * and a value holding a newline (the old `address` column does) is safe
     * because the scan never treats a line as a boundary.
     *
     * @return Generator<string>
     */
    private function statements(): Generator
    {
        $sql = file_get_contents($this->path);
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            // Comments, which are only comments out here — never inside a value,
            // because a statement is read in one piece below.
            if (substr($sql, $i, 2) === '--' || $sql[$i] === '#') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;

                continue;
            }

            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if (! preg_match('/\Ginsert\s+(?:ignore\s+)?into\s+`/i', $sql, $m, 0, $i)) {
                $i++;

                continue;
            }

            $j = $i;
            $inQuote = false;

            for (; $j < $length; $j++) {
                $ch = $sql[$j];

                if ($inQuote) {
                    if ($ch === '\\') {
                        $j++;

                        continue;
                    }
                    if ($ch === "'") {
                        $inQuote = false;
                    }

                    continue;
                }

                if ($ch === "'") {
                    $inQuote = true;

                    continue;
                }

                if ($ch === ';') {
                    break;
                }
            }

            yield substr($sql, $i, $j - $i);
            $i = $j + 1;
        }
    }

    /**
     * Split `(a,b),(c,d)` into groups of unescaped values.
     *
     * Quote-aware on purpose: the dump's addresses hold commas and brackets
     * ('No. 8, Pangsapuri Teratai, Jalan Metafasa, U 16/3A'), so splitting on
     * punctuation alone tears a row in half and the arity check above would be
     * the only thing to notice.
     *
     * @return array<int, array<int, string|null>>
     */
    private function valueGroups(string $s): array
    {
        $groups = [];
        $current = null;
        $value = '';
        $quoted = false;
        $inQuote = false;
        $length = strlen($s);

        for ($i = 0; $i < $length; $i++) {
            $ch = $s[$i];

            if ($inQuote) {
                if ($ch === '\\' && $i + 1 < $length) {
                    $next = $s[++$i];
                    $value .= self::ESCAPES[$next] ?? $next;

                    continue;
                }

                if ($ch === "'") {
                    $inQuote = false;

                    continue;
                }

                $value .= $ch;

                continue;
            }

            if ($ch === "'") {
                $inQuote = true;
                $quoted = true;

                continue;
            }

            if ($ch === '(' && $current === null) {
                $current = [];
                $value = '';
                $quoted = false;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($ch === ',' || $ch === ')') {
                $current[] = $this->finish($value, $quoted);
                $value = '';
                $quoted = false;

                if ($ch === ')') {
                    $groups[] = $current;
                    $current = null;
                }

                continue;
            }

            $value .= $ch;
        }

        return $groups;
    }

    /**
     * An unquoted `NULL` is a null; an unquoted number is its own text. Values
     * keep their type as STRING because every staging column is a string — see
     * the staging note in the schema migration for why that is not optional.
     */
    private function finish(string $value, bool $quoted): ?string
    {
        if ($quoted) {
            return self::utf8($value);
        }

        $bare = trim($value);

        return strcasecmp($bare, 'null') === 0 ? null : self::utf8($bare);
    }

    /** Valid UTF-8 as it is; anything else read as Windows-1252 (MySQL latin1). */
    public static function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
