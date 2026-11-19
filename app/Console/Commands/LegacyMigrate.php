<?php

namespace App\Console\Commands;

use App\Support\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The whole move from the old system, in order, on one dump:
 *
 *   1. import:legacy        every row of every Undilot table into staging, whole
 *   2. legacy:accounts      admins and participants, every staging row linked
 *   3. legacy:transform-event, once per pair in Legacy::EVENTS
 *   4. the reconciliation: for every old table, how many rows became something
 *      and how many did not — the proof that nothing was lost on the way.
 *
 * A row that became nothing is not deleted: it stays in staging with its whole
 * source row in `raw`. The report says which tables those are and why.
 *
 * Safe to re-run on the same dump: staging is reloaded, accounts are found
 * again by who they are, and each event replaces the one it built last time.
 */
class LegacyMigrate extends Command
{
    protected $signature = 'legacy:migrate
        {path : Path to the .sql dump of the old database}
        {--report-only : Skip the import and transforms; print the reconciliation of what is staged}';

    protected $description = 'Move the old system into this one: staging, accounts, past events, and a reconciliation';

    public function handle(): int
    {
        if (! $this->option('report-only')) {
            if ($this->call('import:legacy', ['path' => $this->argument('path')]) !== self::SUCCESS) {
                $this->error('import:legacy failed; nothing further was done.');

                return self::FAILURE;
            }

            $this->call('legacy:accounts');

            foreach (Legacy::EVENTS as [$booths, $people, $code]) {
                if (DB::table('legacy_usahawan')->where('source_table', $people)->doesntExist()) {
                    $this->warn("{$people}: not in this dump, skipped.");

                    continue;
                }

                $this->newLine();
                $this->call('legacy:transform-event', array_filter([
                    'usahawan' => $people,
                    '--booths' => $booths,
                    '--code' => $code,
                    '--fresh' => true,
                ]));
            }
        }

        $this->reconcile();

        return self::SUCCESS;
    }

    /**
     * Per old table: rows staged, rows that became an account / plot, rows
     * that did not. Counts only — no names, no ICs — so the output can be
     * pasted anywhere.
     */
    private function reconcile(): void
    {
        $events = array_filter(array_column(Legacy::EVENTS, 0));
        $people = Legacy::participantSources();

        $rows = [];
        $unused = 0;

        foreach ([
            ['legacy_upengawai', 'admin_id', fn ($t) => in_array($t, ['upengawai', 'fileuser'], true) ? 'staff' : 'archive'],
            ['legacy_usahawan', 'user_id', fn ($t) => in_array($t, $people, true) ? 'people' : 'archive'],
            ['legacy_ukhemah', 'plot_id', fn ($t) => in_array($t, $events, true) ? 'event' : 'archive'],
        ] as [$table, $link, $role]) {
            $counts = DB::table($table)
                ->select('source_table')
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when {$link} is not null then 1 else 0 end) as linked")
                ->groupBy('source_table')
                ->orderBy('source_table')
                ->get();

            foreach ($counts as $c) {
                $kind = $role($c->source_table);
                $left = $c->total - (int) $c->linked;
                $unused += $kind === 'archive' ? 0 : $left;

                $rows[] = [$table, $c->source_table, $kind, $c->total, (int) $c->linked, $left];
            }
        }

        $this->newLine();
        $this->line('RECONCILIATION — every old row is in staging (`raw`); this is what each became.');
        $this->table(['staging', 'old table', 'used as', 'rows', 'linked', 'not linked'], $rows);
        $this->line('"archive": kept in staging only, by decision (hourly backups, old copies).');

        if ($unused > 0) {
            $this->warn("{$unused} row(s) in migrated tables are linked to nothing — see the notes above for why"
                .' (no zone, no IC/name, holder gone). They remain in staging.');
        } else {
            $this->info('Every row of every migrated table is linked.');
        }
    }
}
