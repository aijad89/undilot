<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\Setting;
use App\Support\Legacy;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns one old event — a `usahawan*` table and its `ukhemah*` booth plan — into
 * a real, completed event with its plan, its plots and its draw results.
 *
 * The old system had no events table: an event was the live pair of tables,
 * copied by hand to `usahawan_fkj2025` / `ukhemah_fkj2025` when it ended. So an
 * event here is named by its tables, and legacy:migrate runs this once per pair
 * in `Legacy::EVENTS`.
 *
 * WHAT THE DUMP SAYS, and this command takes: the booths and their halls, who
 * held which booth, the craft code and size where the row carries one, whether
 * the office preset the booth (`usahawan.preset` = the booth), payment
 * (`bayarstatus`, receipt in `payment`), and the draw window (`votetime`).
 *
 * WHAT IT DOES NOT SAY, and this command asks for (`--title`, `--venue`,
 * `--starts`, `--ends`): the event's name, its building and the fair's dates.
 * The defaults are placeholders and the report says so.
 *
 * NOTHING IS DROPPED. A booth whose holder is gone becomes an unallocated plot;
 * a booth with no craft code becomes a plot with no code (the old 89%); a
 * company holding two booths gets one registration and both plots. Every booth
 * row is linked to its plot (`legacy_ukhemah.plot_id`), and legacy:migrate
 * reports any that are not.
 */
class LegacyTransformEvent extends Command
{
    protected $signature = 'legacy:transform-event
        {usahawan : The participant table, e.g. usahawan_fkj2025}
        {--booths= : The booth table, e.g. ukhemah_fkj2025. Omit to rebuild booths from usahawan.lot}
        {--code= : The event code, when the booths do not carry one}
        {--title= : The event title. The dump has only the code.}
        {--venue= : The venue. The dump names halls, never the building.}
        {--starts= : Fair start date (Y-m-d). Not in the dump.}
        {--ends= : Fair end date (Y-m-d). Not in the dump.}
        {--dry-run : Report what would be written, write nothing}
        {--fresh : Delete this table pair\'s previously built event first}';

    protected $description = 'Build one past event, its lot plan, plots and draw results from staging';

    /** The plot size of a booth whose `crafttype` names none — the older rows. */
    public const UNKNOWN_SIZE = 'unknown';

    private array $notes = [];

    public function handle(): int
    {
        $source = $this->argument('usahawan');
        $people = DB::table('legacy_usahawan')->where('source_table', $source)->get()
            ->keyBy(fn ($p) => trim((string) $p->legacy_id));
        $booths = $this->booths($people);

        if ($booths->isEmpty()) {
            $this->error("No booths for {$source} in staging. Run import:legacy first.");

            return self::FAILURE;
        }

        $codes = KrafBidang::codes()->get()->keyBy(fn ($row) => strtoupper($row->code));
        $window = $this->drawWindow($people, $source);
        $code = $this->eventCode($booths);

        if ($this->option('dry-run')) {
            $this->line('DRY RUN — nothing was written.');
            $this->line("{$source}: {$booths->count()} booths, {$people->count()} participant rows, code {$code}, draw "
                .$window[0]->toDateString());

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->forget($source);
        }

        DB::transaction(function () use ($source, $people, $booths, $codes, $window, $code) {
            $event = $this->event($source, $code, $booths, $window);
            $sizeIds = $this->plan($event, $booths, $codes);
            $plots = $this->plots($event, $booths, $sizeIds, $codes);
            $this->draw($event, $booths, $people, $plots, $sizeIds, $codes);

            Setting::set($this->key($source), (string) $event->id);

            $this->info("Event #{$event->id} — {$event->title}");
        });

        foreach ($this->notes as $note) {
            $this->line('  '.$note);
        }

        return self::SUCCESS;
    }

    /**
     * The booth rows. From the `ukhemah*` table when there is one; otherwise
     * rebuilt from the participants' own `lot` and `location` (FKJ 2022 kept no
     * booth plan), in the same shape, with no staging booth row to link.
     */
    private function booths(Collection $people): Collection
    {
        if ($table = $this->option('booths')) {
            return DB::table('legacy_ukhemah')->where('source_table', $table)
                ->orderByRaw('cast(legacy_id as unsigned)')->get();
        }

        $this->notes[] = 'No booth table: booths rebuilt from usahawan.lot / location.';

        return $people
            ->filter(fn ($p) => trim((string) $p->lot) !== '')
            ->map(fn ($p) => (object) [
                'id' => null,
                'khemah' => $p->location,
                'usahawanid' => $p->legacy_id,
                'boothid' => $p->lot,
                'crafttype' => $p->crafttype,
                'prio' => null,
                'event' => null,
            ])
            ->values();
    }

    /** The draw window from `votetime`; else the year in the table's name, reported as a guess. */
    private function drawWindow(Collection $people, string $source): array
    {
        $times = $people->pluck('votetime')
            ->filter(fn ($t) => $t && ! str_starts_with((string) $t, '0000'))
            ->map(function ($t) {
                try {
                    return Carbon::parse($t);
                } catch (Throwable) {
                    return null;
                }
            })
            ->filter()
            ->sort()
            ->values();

        if ($times->isNotEmpty()) {
            return [$times->first(), $times->last()];
        }

        $year = preg_match('/(20\d\d)/', $source, $m) ? (int) $m[1] : now()->year;
        $this->notes[] = "No usable votetime: draw dated 1 Jan {$year}, a GUESS from the table name.";

        return [Carbon::create($year)->startOfDay(), Carbon::create($year)->startOfDay()->addHours(4)];
    }

    /** `--code`, else the code most booths carry in `ukhemah.event`. */
    private function eventCode(Collection $booths): string
    {
        if ($this->option('code')) {
            return strtoupper($this->option('code'));
        }

        return (string) ($booths->map(fn ($b) => strtoupper(trim((string) $b->event)))
            ->filter()->countBy()->sortDesc()->keys()->first() ?? 'LEGACY');
    }

    private function event(string $source, string $code, Collection $booths, array $window): Event
    {
        [$openedAt, $closedAt] = $window;

        $title = $this->option('title') ?: $code.' '.$openedAt->year;
        $starts = $this->option('starts')
            ? Carbon::parse($this->option('starts'))->startOfDay()
            : $openedAt->copy()->addWeek()->startOfDay();
        $ends = $this->option('ends')
            ? Carbon::parse($this->option('ends'))->endOfDay()
            : $starts->copy()->addDays(3)->endOfDay();

        if (! $this->option('title') || ! $this->option('venue') || ! $this->option('starts')) {
            $this->notes[] = "Title, venue or fair dates are placeholders (\"{$title}\", "
                .$starts->toDateString().'). Confirm with the office, then edit the event.';
        }

        return Event::create([
            'title' => $title,
            'description' => "Imported from the previous system (table {$source}, event code {$code}). "
                .'The lot draw ran on '.$openedAt->toDateString().'.',
            'location' => $this->option('venue') ?: 'To be confirmed',
            'start_date' => $starts,
            'end_date' => $ends,
            'registration_open_at' => $openedAt->copy()->subMonth(),
            'registration_close_at' => $openedAt->copy()->subDay(),
            'status' => 'completed',
            'polling_status' => 'completed',
            'polling_start_date' => $openedAt,
            'polling_deadline' => $closedAt,
            'max_participants' => $booths->count(),
            'use_preset' => true,
            // Nothing may auto-transition a finished event back into life.
            'auto_open' => false,
            'auto_close' => false,
            'auto_polling_open' => false,
            'auto_polling_close' => false,
        ]);
    }

    /**
     * `plot_sizes` — a (location, size) pair — and the craft allocations under
     * them. Only codes on the coding sheet get an allocation row: an allocation
     * is a foreign key to `kraf_bidangs`.
     *
     * @return array<string, int>  "LOCATION|SIZE" => plot_sizes.id
     */
    private function plan(Event $event, Collection $booths, $codes): array
    {
        $pairs = [];

        foreach ($booths as $booth) {
            [$code, $size] = Legacy::codeAndSize($booth->crafttype);
            $key = Legacy::location($booth->khemah).'|'.($size ?? self::UNKNOWN_SIZE);
            $pairs[$key]['quantity'] = ($pairs[$key]['quantity'] ?? 0) + 1;

            if ($code !== null && $codes->has($code)) {
                $pairs[$key]['codes'][$code] = ($pairs[$key]['codes'][$code] ?? 0) + 1;
            }
        }

        ksort($pairs);
        $ids = [];

        foreach ($pairs as $key => $pair) {
            [$location, $size] = explode('|', $key);
            $row = $event->plotSizes()->create(['size' => $size, 'quantity' => $pair['quantity'], 'location' => $location]);
            $ids[$key] = $row->id;

            foreach ($pair['codes'] ?? [] as $code => $count) {
                $event->bidangAllocations()->create([
                    'bidang_id' => $codes[$code]->id,
                    'sub_bidang_id' => null,
                    'plot_size_id' => $row->id,
                    'plots_count' => $count,
                ]);
            }
        }

        // Not fillable: computed from the plan, as savePlan() does.
        $event->total_plots = $booths->count();
        $event->save();

        return $ids;
    }

    /**
     * One plot per booth ROW, keeping the office's booth id as the plot code —
     * it is the number printed on the booth and the slip. Inserted one at a
     * time so a booth id the office used twice still becomes two plots, each
     * linked back to its own staging row.
     *
     * @return array<int, int>  booth index => plots.id
     */
    private function plots(Event $event, Collection $booths, array $sizeIds, $codes): array
    {
        $now = now();
        $ids = [];
        $uncoded = 0;

        foreach ($booths as $i => $booth) {
            [$code, $size] = Legacy::codeAndSize($booth->crafttype);
            $bidang = $code !== null ? ($codes[$code] ?? null) : null;
            $uncoded += $bidang === null ? 1 : 0;

            $ids[$i] = DB::table('plots')->insertGetId([
                'event_id' => $event->id,
                'plot_size_id' => $sizeIds[Legacy::location($booth->khemah).'|'.($size ?? self::UNKNOWN_SIZE)],
                'company_id' => null,
                'allocated_to' => null,
                'bidang_id' => $bidang?->id,
                'sub_bidang_id' => null,
                'plot_code' => trim((string) $booth->boothid) ?: 'BOOTH'.($i + 1),
                'is_allocated' => false,
                'allocation_type' => 'algorithm',
                'is_locked' => false,
                'allocated_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($booth->id !== null) {
                DB::table('legacy_ukhemah')->where('id', $booth->id)->update(['plot_id' => $ids[$i]]);
            }
        }

        if ($uncoded > 0) {
            $this->notes[] = "{$uncoded} booth(s) carry no craft code on the coding sheet: plots without a code. The text is in staging.";
        }

        return $ids;
    }

    /**
     * Who held what. EVERY REGISTRATION IS WRITTEN VERIFIED: a draw that
     * completed had every row through both checks.
     */
    private function draw(Event $event, Collection $booths, Collection $people, array $plots, array $sizeIds, $codes): void
    {
        $now = now();
        $registered = [];   // company_id => true
        $polled = [];       // user_id => true
        $orphans = 0;
        $extra = 0;

        foreach ($booths as $i => $booth) {
            $holder = trim((string) $booth->usahawanid);

            if ($holder === '' || $holder === '0') {
                continue;
            }

            $person = $people[$holder] ?? null;

            if ($person === null || $person->company_id === null) {
                $orphans++;

                continue;
            }

            $raw = Legacy::raw($person);
            $boothId = trim((string) $booth->boothid);
            [$code, $size] = Legacy::codeAndSize($booth->crafttype);
            $preset = $boothId !== '' && strcasecmp(trim((string) $person->preset), $boothId) === 0;
            $at = $this->time($person->votetime) ?? $event->polling_deadline;

            DB::table('plots')->where('id', $plots[$i])->update([
                'company_id' => $person->company_id,
                'allocated_to' => $person->user_id,
                'is_allocated' => true,
                'allocation_type' => $preset ? 'preset' : 'algorithm',
                'allocated_at' => $at,
            ]);

            // One registration per company: a company that held two booths
            // ("MOHON 2 LOT") has both plots, and its registration names the first.
            if (isset($registered[$person->company_id])) {
                $extra++;

                continue;
            }
            $registered[$person->company_id] = true;

            $paid = Legacy::field($raw, 'bayarstatus');

            DB::table('event_registrations')->insert([
                'event_id' => $event->id,
                'company_id' => $person->company_id,
                'user_id' => $person->user_id,
                'code' => $code,
                'code_updated_at' => $at,
                'plot_size' => $size,
                'plot_id' => $plots[$i],
                'confirmed_participation' => true,
                'confirmed_at' => $at,
                'reminded_at' => null,
                'verified_by' => null,
                'verified_at' => $at,
                'first_verified_at' => $at,
                'hq_note' => null,
                'payment_status' => match (strtolower((string) $paid)) {
                    'ya', 'yes' => 'paid',
                    'tidak', 'no' => 'unpaid',
                    default => null,
                },
                'payment_note' => Legacy::field($raw, 'payment') ?? 'Imported from the previous system.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (! isset($polled[$person->user_id])) {
                $polled[$person->user_id] = true;
                DB::table('polls')->insert([
                    'user_id' => $person->user_id, 'event_id' => $event->id, 'code' => $code,
                    'created_at' => $at, 'updated_at' => $at,
                ]);
            }

            // A preset_allocations row needs a coded craft (a foreign key).
            if ($preset && $code !== null && $codes->has($code)) {
                DB::table('preset_allocations')->insert([
                    'event_id' => $event->id,
                    'user_id' => $person->user_id,
                    'bidang_id' => $codes[$code]->id,
                    'sub_bidang_id' => null,
                    'plot_size_id' => $sizeIds[Legacy::location($booth->khemah).'|'.$size],
                    'plot_number' => $boothId,
                    'plot_size' => $size,
                    'plot_code' => $boothId,
                    'code' => $code,
                    'priority_rank' => (int) ($booth->prio ?: 1),
                    'is_confirmed' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->notes[] = count($registered).' registrations, '.count($polled).' polls.';
        if ($orphans > 0) {
            $this->notes[] = "{$orphans} booth(s) point at a participant no longer in {$this->argument('usahawan')}: plots left unallocated.";
        }
        if ($extra > 0) {
            $this->notes[] = "{$extra} extra booth(s) held by a company that already had one: allocated, no second registration.";
        }
    }

    private function time(?string $value): ?Carbon
    {
        if (! $value || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Which event this table pair built last time, KEPT IN `settings` and not
     * read back off the event: the description is the first thing the office
     * edits, and a `--fresh` that matched on it would build the draw twice.
     */
    private function key(string $source): string
    {
        return 'legacy_event:'.$source;
    }

    private function forget(string $source): void
    {
        $id = Setting::get($this->key($source));

        if (! $id || ! Event::whereKey($id)->exists()) {
            return;
        }

        // `event_registrations.plot_id` has no ON DELETE clause: registrations
        // first, and everything else follows the event's cascades.
        $plotIds = DB::table('plots')->where('event_id', $id)->pluck('id');
        DB::table('legacy_ukhemah')->whereIn('plot_id', $plotIds)->update(['plot_id' => null]);
        DB::table('event_registrations')->where('event_id', $id)->delete();
        Event::whereKey($id)->delete();

        $this->line("Removed the event previously built from {$source}.");
    }
}
