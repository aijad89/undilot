<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;
use App\Support\DevEnvironment;
use App\Support\PollingGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The admin landing screen.
 *
 * NOT OPTIONAL: bootstrap/app.php's `redirectUsersTo` sends every signed-in
 * admin here, so this route existing is what keeps an already-signed-in admin
 * from being bounced to the PARTICIPANT login form when they open /admin/login.
 *
 * Work first: four counts of what is waiting, what needs fixing, the events
 * in progress. Then the company records' gaps, the latest sales (HQ and
 * super), recently finished events, HQ verification time (HQ and super) and
 * recent activity (super). Sales charts live on the Sales page.
 * Every count is scoped the way the screen it links to is scoped: a state admin
 * counts their own zone (through `companies.state`) and never sees a draft
 * event; HQ and super count everything. Counts only, aggregated in SQL.
 */
class DashboardController extends Controller
{
    /** How many events the events card lists. */
    private const EVENTS_SHOWN = 6;

    public function index(Request $request)
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $global = $admin->hasGlobalAccess();

        // A draw-list row is in this admin's zone when its company is.
        $inZone = fn ($q) => $q->unless($global, fn ($q) => $q->whereHas(
            'company', fn ($c) => $c->where('state', $admin->state)
        ));
        $companies = fn () => Company::query()->unless($global, fn ($q) => $q->where('state', $admin->state));

        $events = $this->events($global, $inZone);
        $shown = $events->take(self::EVENTS_SHOWN);

        return view('admin.dashboard', [
            'admin' => $admin,
            'global' => $global,
            'attention' => array_merge($this->attention($global, $inZone, $events), $this->idleAdmins($admin)),
            // Every current event is checked for attention; the card lists the soonest few.
            'events' => $shown,
            'lots' => $this->lotsBySize($shown->pluck('id'), $global),
            'kpis' => $this->kpis($events, $global),
            'completed' => $this->completed($admin, $global, $inZone),
            'health' => $this->health($companies),
            // One line; the charts are on the Sales page.
            'latestSales' => $admin->can('manage-sales-book') ? $this->latestSales() : null,
            'verifyTimes' => $global ? $this->verifyTimes() : null,
            // The audit trail is super's alone (read-audit-trail).
            'activity' => $admin->can('read-audit-trail')
                ? \App\Models\AuditTrail::with('performer')->latest('created_at')->latest('id')->take(10)->get()
                : null,
        ]);
    }

    /**
     * The server setup that fails without an error (docs/sedia-go-live.md):
     * no queue worker, so no notification goes out; no scheduler, so draws do
     * not open or close; no storage:link, so banners vanish. Each row has no
     * link — the fix is on the server. Never on a dev machine, which runs none
     * of the three.
     *
     * @return array<int, array{0: string, 1: string, 2: null}>
     */
    public static function serverProblems(): array
    {
        if (DevEnvironment::here()) {
            return [];
        }

        $rows = [];

        $oldest = DB::table('jobs')->min('created_at');
        if ($oldest && $oldest < now()->subMinutes(10)->timestamp) {
            $rows[] = ['bad', 'Server: '.DB::table('jobs')->count().' notifications waiting since '.Carbon::createFromTimestamp($oldest)->diffForHumans()
                .' — the queue worker is not running. Participants are not being told.', null];
        }
        if ($failed = DB::table('failed_jobs')->count()) {
            $rows[] = ['bad', "Server: {$failed} ".str('notification')->plural($failed).' could not be sent (failed_jobs).', null];
        }

        $beat = Cache::get(self::SCHEDULER_HEARTBEAT);
        if (! $beat || $beat < now()->subMinutes(5)->timestamp) {
            $rows[] = ['bad', 'Server: the scheduler has not run '.($beat ? 'since '.Carbon::createFromTimestamp($beat)->diffForHumans() : 'yet')
                .' — draws will not open or close on their own.', null];
        }

        if (! file_exists(public_path('storage'))) {
            $rows[] = ['bad', 'Server: php artisan storage:link has not been run — event banners do not show.', null];
        }

        return $rows;
    }

    /** Written every minute by the scheduler (routes/console.php). */
    public const SCHEDULER_HEARTBEAT = 'scheduler.heartbeat';

    /**
     * Upcoming and running events, soonest first, each with its draw-list counts
     * (in this admin's zone) and its plots (event-wide — plots have no zone).
     */
    private function events(bool $global, \Closure $inZone)
    {
        return Event::query()
            ->unless($global, fn ($q) => $q->where('status', '!=', 'draft'))
            ->where('status', '!=', 'completed')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay()))
            ->withCount([
                'registrations as on_list' => $inZone,
                // Capacity is event-wide, so the places taken are too.
                'registrations as places_taken',
                'registrations as coded' => fn ($q) => $inZone($q)->whereNotNull('code')->where('code', '!=', ''),
                'registrations as confirmed' => fn ($q) => $inZone($q)->where('confirmed_participation', true),
                // Reminded by the scheduler (voting:remind) and still not confirmed: a phone call is next.
                'registrations as reminded' => fn ($q) => $inZone($q)->where('confirmed_participation', false)->whereNotNull('reminded_at'),
                // Event-wide on purpose: only super and HQ read it (rule A7).
                'registrations as awaiting' => fn ($q) => $q->awaitingLot(),
                // A request the zone has approved stays pending until HQ verifies,
                // but its company is on the list and it is no longer the zone's to review.
                'joinRequests as join_pending' => fn ($q) => $inZone($q)->where('status', 'pending')
                    ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('event_registrations')
                        ->whereColumn('event_registrations.event_id', 'event_join_requests.event_id')
                        ->whereColumn('event_registrations.company_id', 'event_join_requests.company_id')),
                'registrations as hq_waiting' => fn ($q) => $inZone($q)->whereNull('verified_at')->whereNull('hq_note'),
                'registrations as hq_returned' => fn ($q) => $inZone($q)->whereNotNull('hq_note'),
                'registrations as unpaid' => fn ($q) => $inZone($q)->where('payment_status', 'unpaid'),
                'joinRequests as join_approved' => fn ($q) => $inZone($q)->where('status', 'approved'),
                'joinRequests as join_rejected' => fn ($q) => $inZone($q)->where('status', 'rejected'),
                'joinRequests as join_waiver' => fn ($q) => $inZone($q)->whereIn('status', ['pending', 'approved'])->where('payment_method', 'waiver'),
                'joinRequests as zone_rejected' => fn ($q) => $q->rejectedByZone()->where('reviewed_at', '>=', now()->subDays(7)),
                'plots as plots_total',
                // A set-aside lot is a surprise to the zone until it is confirmed.
                'plots as plots_taken' => fn ($q) => $q->where('is_allocated', true)
                    ->unless($global, fn ($q) => $q->whereNot(fn ($w) => $w->secretPresets())),
            ])
            ->orderByRaw('start_date is null, start_date')
            ->get();
    }

    /**
     * What needs fixing, as a list of [tone, text, link] rows. An empty section
     * shows a single "all clear" line instead.
     */
    private function attention(bool $global, \Closure $inZone, $events): array
    {
        // Server setup first: when it is broken, nothing below happens on time.
        $rows = $global ? self::serverProblems() : [];

        // A1 — draw-list rows with no craft code, per event.
        foreach ($events as $event) {
            $missing = $event->on_list - $event->coded;
            if ($missing > 0) {
                $rows[] = ['warn', "{$event->title}: {$missing} draw-list ".str('row')->plural($missing).' with no craft code',
                    route('admin.events.registrations.index', $event)];
            }
        }

        // A8 — companies asking to join, waiting on this admin's zone.
        foreach ($events as $event) {
            if ($event->join_pending > 0) {
                $rows[] = ['warn', "{$event->title}: {$event->join_pending} join ".str('request')->plural($event->join_pending).' to review',
                    route('admin.events.registrations.index', $event).'#join-requests'];
            }
        }

        // A9 — the two checks: rows waiting on HQ, and rows HQ sent back to the zone.
        foreach ($events as $event) {
            if ($global && $event->hq_waiting > 0) {
                $rows[] = ['warn', "{$event->title}: {$event->hq_waiting} draw-list ".str('row')->plural($event->hq_waiting).' waiting for HQ to verify',
                    route('admin.events.registrations.index', [$event, 'status' => 'waiting'])];
            }
            if ($global && $event->zone_rejected > 0) {
                $rows[] = ['warn', "{$event->title}: {$event->zone_rejected} join ".str('request')->plural($event->zone_rejected).' rejected by zones in the last 7 days',
                    route('admin.events.registrations.index', $event).'#rejected'];
            }
            if (! $global && $event->hq_returned > 0) {
                $rows[] = ['bad', "{$event->title}: {$event->hq_returned} draw-list ".str('row')->plural($event->hq_returned).' returned by HQ',
                    route('admin.events.registrations.index', [$event, 'status' => 'returned'])];
            }
        }

        // A2/A3 — a draw that has not opened yet and could not open now. Event-wide,
        // so for super and HQ only: a state admin cannot fix other zones' rows.
        if ($global) {
            foreach ($events->where('polling_status', 'draft') as $event) {
                $reason = $event->on_list > 0 ? PollingGuard::blocker($event) : null;
                if ($reason && ! ($event->hq_waiting > 0 && str_contains($reason, 'waiting for HQ'))) {
                    $when = $event->polling_start_date ? ' (draw opens '.$event->polling_start_date->diffForHumans().')' : '';
                    $rows[] = ['bad', "{$event->title}{$when}: ".str($reason)->after('Cannot start polling: '),
                        route('admin.polling.show', $event)];
                }
            }
        }

        // A4 — a draw open now, with companies that have not confirmed yet.
        foreach ($events->where('polling_status', 'polling') as $event) {
            $waiting = $event->on_list - $event->confirmed;
            if ($waiting > 0) {
                $deadline = $event->polling_deadline ? ', closes '.$event->polling_deadline->diffForHumans() : '';
                $reminded = $event->reminded > 0 ? " ({$event->reminded} already reminded)" : '';
                $rows[] = ['warn', "{$event->title}: {$waiting} ".str('company')->plural($waiting)." not confirmed yet{$reminded}{$deadline}",
                    route('admin.events.registrations.index', $event)];
            }
        }

        // A7 — voting has closed (often by the scheduler, unattended) and some
        // companies still have no lot. Closing hands out nothing; Assign remaining
        // lots does, and only super and HQ may press it.
        if ($global) {
            foreach ($events->where('polling_status', 'closed') as $event) {
                if ($event->awaiting > 0) {
                    $rows[] = ['bad', "{$event->title}: voting closed, {$event->awaiting} ".str('company')->plural($event->awaiting).' still without a lot',
                        route('admin.polling.show', $event)];
                }
            }
        }

        // A10 — draw-list rows marked unpaid. It does not hold the draw; it is a reminder.
        foreach ($events as $event) {
            if ($event->unpaid > 0) {
                $rows[] = ['info', "{$event->title}: {$event->unpaid} draw-list ".str('row')->plural($event->unpaid).' marked unpaid',
                    route('admin.events.registrations.index', [$event, 'payment' => 'unpaid'])];
            }
        }

        return $rows;
    }

    /**
     * Lots per (location, size) for the events the card lists, keyed by event
     * id, each row {location, size, taken, total}. Event-wide, as the plot
     * counts are — and, like plots_taken, a set-aside lot looks free to a zone.
     */
    private function lotsBySize($eventIds, bool $global)
    {
        $count = fn ($q) => $q->join('plot_sizes', 'plot_sizes.id', '=', 'plots.plot_size_id')
            ->whereIn('plots.event_id', $eventIds)
            ->selectRaw('plots.event_id, plot_sizes.location, plot_sizes.size, count(*) as n')
            ->groupBy('plots.event_id', 'plot_sizes.location', 'plot_sizes.size')
            ->toBase()->get();
        $key = fn ($r) => "{$r->event_id}|{$r->location}|{$r->size}";

        $taken = $count(Plot::query()->where('plots.is_allocated', true)
            ->unless($global, fn ($q) => $q->whereNot(fn ($w) => $w->secretPresets())))
            ->keyBy($key);

        return $count(Plot::query())
            ->map(fn ($r) => (object) ['event_id' => $r->event_id, 'location' => $r->location, 'size' => $r->size,
                'taken' => (int) ($taken[$key($r)]->n ?? 0), 'total' => (int) $r->n])
            ->sortBy(fn ($r) => [$r->location, $r->size])
            ->groupBy('event_id');
    }

    /**
     * The last few events that have finished, with what came of them: the
     * draw list (this admin's zone), the lots held, and — for HQ and super —
     * the sales uploaded.
     */
    private function completed(Admin $admin, bool $global, \Closure $inZone)
    {
        $sales = $admin->can('manage-sales-book');

        return Event::query()
            ->unless($global, fn ($q) => $q->where('status', '!=', 'draft'))
            ->where(fn ($q) => $q->where('status', 'completed')->orWhere('end_date', '<', now()->startOfDay()))
            ->withCount([
                'registrations as on_list' => $inZone,
                'plots as plots_taken' => fn ($q) => $q->where('is_allocated', true)
                    ->unless($global, fn ($q) => $q->whereNot(fn ($w) => $w->secretPresets())),
            ])
            ->when($sales, fn ($q) => $q->withSum('salesBookEntries as sales_total', 'amount'))
            ->orderByDesc('end_date')
            ->take(self::COMPLETED_SHOWN)
            ->get();
    }

    /** How many finished events the card lists. */
    private const COMPLETED_SHOWN = 3;

    /**
     * Officers who have not signed in for 30 days — an account nobody uses is
     * one to switch off. HQ sees state admins (the ones it manages); super
     * also sees HQ and super. The reader is never among them: TrackAdminLastSeen
     * stamps last_seen_at before this runs.
     */
    private function idleAdmins(Admin $admin): array
    {
        if (! $admin->can('manage-admins')) {
            return [];
        }

        $groups = $admin->isSuperAdmin() ? ['state' => ['admin'], 'hq' => ['super', 'hq']] : ['state' => ['admin']];
        $rows = [];
        foreach ($groups as $group => $roles) {
            $n = Admin::query()->idle()->whereIn('role', $roles)->count();
            if ($n > 0) {
                $who = $group === 'hq' ? 'HQ/super' : 'state';
                $rows[] = ['info', "{$n} {$who} ".str('officer')->plural($n).' not signed in for 30 days',
                    route("admin.admins.{$group}", ['idle' => 1])];
            }
        }

        return $rows;
    }

    /**
     * How long HQ takes to verify a row a zone placed, over the last 90 days:
     * placed (created_at) to first verified, overall and by the company's zone.
     * A row HQ placed itself is verified as it is made, so anything verified
     * within a minute is left out. Null when there is nothing to measure.
     */
    private function verifyTimes(): ?array
    {
        $rows = EventRegistration::query()
            ->join('companies', 'companies.id', '=', 'event_registrations.company_id')
            ->where('event_registrations.first_verified_at', '>=', now()->subDays(90))
            ->select('companies.state', 'event_registrations.created_at', 'event_registrations.first_verified_at')
            ->toBase()->get()
            ->map(fn ($r) => (object) ['state' => $r->state ?: 'No zone',
                'seconds' => Carbon::parse($r->created_at)->diffInSeconds(Carbon::parse($r->first_verified_at))])
            ->where('seconds', '>', 60);

        if ($rows->isEmpty()) {
            return null;
        }
        $days = fn ($rows) => round($rows->avg('seconds') / 86400, 1);

        return [
            'days' => $days($rows),
            'rows' => $rows->count(),
            'byZone' => $rows->groupBy('state')->map($days)->sortDesc(),
        ];
    }

    /**
     * The zone's company records at a glance, and what is missing from them:
     * each gap a count, and a link to the Companies list filtered to it.
     */
    private function health(\Closure $companies): array
    {
        return [
            'active' => $companies()->where('is_active', true)->count(),
            'inactive' => $companies()->where('is_active', false)->count(),
            'new' => $companies()->where('created_at', '>=', now()->startOfMonth())->count(),
            // Signed in to the portal themselves; an admin's "login as" is not stamped.
            'portal' => $companies()->active()->whereHas('user', fn ($u) => $u->where('last_seen_at', '>=', now()->subDays(30)))->count(),

            // [count, what is wrong, Companies filter, tone], in the order the office fixes them.
            'gaps' => collect([
                // Not complete: they cannot send a request to join.
                [$companies()->whereIntegerInRaw('id', \App\Support\CompanyCredentials::incompleteIds())->count(),
                    'with an incomplete profile — they cannot ask to join an event', 'profile', 'info'],
                // The office sets the email; without it there is no sign-in.
                [$companies()->active()->missingAccount('email')->count(), 'with no email — they cannot sign in to the portal', 'email', 'warn'],
                [$companies()->active()->missingAccount('login')->count(), 'with an email but never signed in', 'login', 'info'],
                // Never a block (owner 2026-11-24): a warning so the office can ask for the new one.
                [$companies()->active()->expiringCertificate()->count(), 'with a certificate expired or expiring within 30 days', 'certificate', 'warn'],
                [$companies()->active()->whereDoesntHave('krafSelections')->count(), 'with no craft category', 'category', 'warn'],
                [$companies()->active()->whereNull('kraf_code')->count(), 'with no craft code — the draw list cannot fill one in', 'code', 'info'],
            ])->filter(fn ($gap) => $gap[0] > 0)->values(),
        ];
    }

    /**
     * The four figures at the top, each a kind of work waiting: rows for HQ to
     * verify (or, for a zone, rows HQ sent back), join requests to review,
     * companies yet to confirm in an open draw, and the dates in the next 7 days.
     */
    private function kpis($events, bool $global): array
    {
        $week = now()->addDays(7);
        $open = $events->where('polling_status', 'polling');

        return [
            'hq' => $global ? $events->sum('hq_waiting') : $events->sum('hq_returned'),
            'join' => $events->sum('join_pending'),
            'unconfirmed' => $open->sum(fn ($e) => $e->on_list - $e->confirmed),
            'openDraws' => $open->count(),
            // [what, when, event], soonest first.
            'week' => $events->flatMap(fn ($e) => collect([
                ['Registration closes', $e->registration_close_at],
                ['Draw opens', $e->polling_status === 'draft' ? $e->polling_start_date : null],
                ['Draw closes', $e->polling_status === 'polling' ? $e->polling_deadline : null],
                ['Starts', $e->start_date],
            ])->filter(fn ($d) => $d[1]?->isFuture() && $d[1]->lte($week))->map(fn ($d) => [$d[0], $d[1], $e]))
                ->sortBy(fn ($d) => $d[1]->timestamp)->values(),
        ];
    }

    /** The latest event with sales uploaded, and its total. HQ and super only. */
    private function latestSales(): ?object
    {
        return DB::table('sales_book_entries')
            ->join('events', 'events.id', '=', 'sales_book_entries.event_id')
            ->selectRaw('events.id, events.title, events.start_date, events.end_date, sum(amount) as total')
            ->groupBy('events.id', 'events.title', 'events.start_date', 'events.end_date')
            ->orderByDesc('events.start_date')
            ->first();
    }
}
