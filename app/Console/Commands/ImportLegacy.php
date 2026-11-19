<?php

namespace App\Console\Commands;

use App\Support\LegacyDump;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Loads the old system's dump into the `legacy_*` staging tables.
 *
 * THIS COMMAND DECIDES NOTHING. It copies text from a mysqldump into three
 * tables and reports what it saw. Every judgement — which event a row belongs
 * to, what a craft code means, whether two rows are the same usahawan — is the
 * transform's, so that a wrong judgement costs a re-transform and never a
 * re-import.
 *
 * Three families, routed by table-name prefix, because the dump holds 64 tables
 * that are really three shapes repeated: `upengawai*` (37 officers plus three
 * older copies), `usahawan*` (the participant rows plus twenty-odd snapshots)
 * and `ukhemah*` (the booth plans plus their hourly snapshots). Anything else —
 * `syatemconfiguration`, `uploadfile` — is counted as skipped and reported, not
 * silently ignored.
 *
 * COLUMNS NOT ON THE STAGING TABLE ARE DROPPED ON PURPOSE and counted so the
 * report says so. 21 of the dump's columns are left out: measured at 0% filled,
 * or filled with one repeated value, or abandoned by the office a decade ago.
 * The schema migration names each one and why.
 */
class ImportLegacy extends Command
{
    protected $signature = 'import:legacy
        {path : Path to the .sql dump}
        {--append : Add to the staging tables instead of emptying them first}
        {--dry-run : Parse and report, write nothing}
        {--only= : Only load source tables whose name starts with this}';

    protected $description = 'Load the old system dump into the legacy_* staging tables';

    /** Table-name prefix in the dump => staging table. */
    private const FAMILIES = [
        'upengawai' => 'legacy_upengawai',
        'usahawan' => 'legacy_usahawan',
        'ukhemah' => 'legacy_ukhemah',
    ];

    private const CHUNK = 500;

    /** Staging table => the data columns it will accept. */
    private array $accepts = [];

    /** @var array<string, array<int, array<string, mixed>>> staging table => buffered rows */
    private array $buffer = [];

    private array $loaded = [];      // source table => rows written
    private array $skipped = [];     // source table => rows seen but not routed
    private array $droppedCols = []; // column name => times dropped
    private array $failures = [];    // human-readable, capped
    private int $malformed = 0;

    public function handle(): int
    {
        $path = $this->argument('path');
        $only = $this->option('only');
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::FAMILIES as $staging) {
            $this->accepts[$staging] = array_values(array_diff(
                Schema::getColumnListing($staging),
                ['id', 'legacy_id', 'source_table', 'imported_at']
            ));
            $this->buffer[$staging] = [];
        }

        if (! $dryRun && ! $this->option('append')) {
            foreach (self::FAMILIES as $staging) {
                DB::table($staging)->delete();
            }
            $this->line('Emptied the three staging tables. Pass --append to add instead.');
        }

        try {
            $dump = new LegacyDump($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = now();

        foreach ($dump->rows() as $item) {
            $table = $item['table'];

            if ($only !== null && ! str_starts_with($table, $only)) {
                continue;
            }

            if ($item['row'] === null) {
                $this->malformed++;

                continue;
            }

            $staging = $this->stagingFor($table);

            if ($staging === null) {
                $this->skipped[$table] = ($this->skipped[$table] ?? 0) + 1;

                continue;
            }

            $this->buffer[$staging][] = $this->shape($item['row'], $table, $staging, $now);
            $this->loaded[$table] = ($this->loaded[$table] ?? 0) + 1;

            if (! $dryRun && count($this->buffer[$staging]) >= self::CHUNK) {
                $this->flush($staging);
            }
        }

        if (! $dryRun) {
            foreach (array_keys($this->buffer) as $staging) {
                $this->flush($staging);
            }
        }

        $this->report($dryRun);

        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }

    private function stagingFor(string $table): ?string
    {
        foreach (self::FAMILIES as $prefix => $staging) {
            if (str_starts_with($table, $prefix)) {
                return $staging;
            }
        }

        return null;
    }

    /**
     * The one mapping rule: a staging column is the legacy column lowercased.
     * `id` becomes `legacy_id`, because the staging table needs its own.
     */
    private function shape(array $row, string $table, string $staging, $now): array
    {
        $out = [
            'legacy_id' => null,
            'source_table' => $table,
            'imported_at' => $now,
        ];

        foreach ($row as $column => $value) {
            $key = strtolower(trim($column));

            if ($key === 'id') {
                $out['legacy_id'] = $value === null ? null : (string) $value;

                continue;
            }

            if (in_array($key, $this->accepts[$staging], true)) {
                $out[$key] = $value;

                continue;
            }

            $this->droppedCols[$key] = ($this->droppedCols[$key] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * A chunk that fails is retried row by row. A 21,000-row load that dies on
     * one bad value should name that value, not leave the operator to bisect a
     * dump by hand.
     */
    private function flush(string $staging): void
    {
        $rows = $this->buffer[$staging];
        $this->buffer[$staging] = [];

        if ($rows === []) {
            return;
        }

        try {
            DB::table($staging)->insert($rows);

            return;
        } catch (Throwable $e) {
            // Fall through to the row-by-row pass.
        }

        foreach ($rows as $row) {
            try {
                DB::table($staging)->insert($row);
            } catch (Throwable $e) {
                $source = $row['source_table'] ?? '?';
                $id = $row['legacy_id'] ?? '?';
                $this->loaded[$source] = max(0, ($this->loaded[$source] ?? 1) - 1);

                if (count($this->failures) < 20) {
                    $this->failures[] = "{$staging} <- {$source} id {$id}: "
                        .explode(' (', $e->getMessage())[0];
                }
            }
        }
    }

    private function report(bool $dryRun): void
    {
        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing was written.' : 'Loaded.');

        ksort($this->loaded);
        $rows = [];
        foreach ($this->loaded as $table => $n) {
            $rows[] = [$table, $this->stagingFor($table), number_format($n)];
        }

        if ($rows !== []) {
            $this->table(['source table', 'staging table', 'rows'], $rows);
        }

        $this->line('Total rows: '.number_format(array_sum($this->loaded)));

        if ($this->skipped !== []) {
            ksort($this->skipped);
            $this->newLine();
            $this->line('Skipped — no staging table for these:');
            foreach ($this->skipped as $table => $n) {
                $this->line("  {$table} ({$n})");
            }
        }

        if ($this->droppedCols !== []) {
            arsort($this->droppedCols);
            $this->newLine();
            $this->line('Columns dropped (not on the staging tables, by design):');
            foreach ($this->droppedCols as $col => $n) {
                $this->line('  '.str_pad($col, 16).number_format($n));
            }
        }

        if ($this->malformed > 0) {
            $this->newLine();
            $this->warn("{$this->malformed} row(s) had a value count that did not match their own column list and were not loaded.");
        }

        if ($this->failures !== []) {
            $this->newLine();
            $this->error(count($this->failures).' row(s) were refused by the database:');
            foreach ($this->failures as $line) {
                $this->line('  '.$line);
            }
        }
    }
}
