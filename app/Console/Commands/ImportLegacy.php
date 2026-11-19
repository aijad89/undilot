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
 * older copies), `usahawan*` (the participant rows, the master list and
 * twenty-odd snapshots) and `ukhemah*` (the booth plans plus their snapshots).
 * The admin rows of `fileuser` join `legacy_upengawai` — only UserRight
 * Administrator or DepartmentAdmin; the rest of that table is the document
 * system's users. Anything else — `syatemconfiguration`, `uploadfile` — is
 * counted as skipped and reported, not silently ignored.
 *
 * NOTHING IS DROPPED. Every source row is written whole to `raw` (JSON). A
 * column without a typed staging column of its own is only in `raw`, and the
 * report counts those, so the operator sees which fields live there.
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

    /** Columns on the staging tables that are not legacy columns. */
    private const OWN_COLUMNS = ['id', 'legacy_id', 'source_table', 'imported_at', 'raw', 'user_id', 'company_id', 'plot_id', 'admin_id'];

    /** The `fileuser` rights that are Undilot accounts; the rest are not ours. */
    private const FILEUSER_RIGHTS = ['Administrator', 'DepartmentAdmin'];

    /** Staging table => the data columns it will accept. */
    private array $accepts = [];

    /** @var array<string, array<int, array<string, mixed>>> staging table => buffered rows */
    private array $buffer = [];

    private array $loaded = [];      // source table => rows written
    private array $skipped = [];     // source table => rows seen but not routed
    private array $rawOnly = [];     // column name => rows where it is only in `raw`
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
                self::OWN_COLUMNS
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

            $staging = $this->stagingFor($table, $item['row']);

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

    private function stagingFor(string $table, ?array $row = null): ?string
    {
        if ($table === 'fileuser') {
            if ($row === null) {
                return 'legacy_upengawai';
            }

            $right = collect($row)->first(fn ($v, $k) => strtolower(trim($k)) === 'userright');

            return in_array(trim((string) $right), self::FILEUSER_RIGHTS, true) ? 'legacy_upengawai' : null;
        }

        foreach (self::FAMILIES as $prefix => $staging) {
            if (str_starts_with($table, $prefix)) {
                return $staging;
            }
        }

        return null;
    }

    /**
     * The one mapping rule: a staging column is the legacy column lowercased.
     * `id` becomes `legacy_id`, because the staging table needs its own. The
     * whole row also goes to `raw`, exactly as the dump spelled it.
     */
    private function shape(array $row, string $table, string $staging, $now): array
    {
        $out = [
            'legacy_id' => null,
            'source_table' => $table,
            'imported_at' => $now,
            'raw' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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

            $this->rawOnly[$key] = ($this->rawOnly[$key] ?? 0) + 1;
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

        if ($this->rawOnly !== []) {
            arsort($this->rawOnly);
            $this->newLine();
            $this->line('Columns kept in `raw` only (no typed staging column):');
            foreach ($this->rawOnly as $col => $n) {
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
