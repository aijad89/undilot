@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
@include('partials.mac-styles')

@php
    $plural = fn (int $n, string $word) => $n.' '.str($word)->plural($n);
    $next = $kpis['week']->first();
@endphp

<div class="mac-page dash">
    @include('partials.admin-alerts')

    <div class="dash-head">
        <div>
            <h2>Dashboard</h2>
            <p class="mac-muted mb-0">
                Signed in as {{ $admin->name }} —
                {{ \App\Models\Admin::ROLES[$admin->role] ?? $admin->role }}@unless($global) · {{ $admin->state }}@endunless
            </p>
        </div>

        {{-- Shortcuts: each one only for the roles its own route lets through. --}}
        <div class="d-flex flex-wrap gap-2">
            {{-- The public events list, as participants see it. A new tab, so the
                 admin session's page stays where it was. --}}
            <a href="{{ route('home') }}" class="mac-btn-secondary" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Public event page</a>
            <a href="{{ route('admin.companies.create') }}" class="mac-btn-secondary"><i class="bi bi-building-add me-1" aria-hidden="true"></i> Add company</a>
            @can('manage-events')
                <a href="{{ route('admin.events.create') }}" class="mac-btn-primary"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i> New event</a>
            @endcan
        </div>
    </div>

    {{-- ── What is waiting: four counts, each a kind of work ───────── --}}
    <div class="dash-kpis">
        @foreach([
            $global
                ? ['Waiting for HQ', 'bi-patch-check', $kpis['hq'], 'draw-list rows to verify']
                : ['Returned by HQ', 'bi-arrow-return-left', $kpis['hq'], 'draw-list rows to fix'],
            ['Join requests', 'bi-envelope-paper', $kpis['join'], 'waiting for the zone to review'],
            ['Not confirmed yet', 'bi-hourglass-split', $kpis['unconfirmed'], 'in '.$plural($kpis['openDraws'], 'open draw')],
        ] as [$label, $icon, $n, $foot])
            <a class="dash-kpi" href="#dash-attention">
                <span class="dash-kpi-label">{{ $label }} <i class="bi {{ $icon }}" aria-hidden="true"></i></span>
                <span class="dash-kpi-row">
                    <strong>{{ number_format($n) }}</strong>
                    <span class="dash-chip {{ $n > 0 ? 'dash-chip-warn' : 'dash-chip-up' }}">{{ $n > 0 ? 'To do' : 'Clear' }}</span>
                </span>
                <span class="dash-kpi-foot">{{ $foot }}</span>
            </a>
        @endforeach

        <a class="dash-kpi" href="#dash-events">
            <span class="dash-kpi-label">Next 7 days <i class="bi bi-calendar-week" aria-hidden="true"></i></span>
            <span class="dash-kpi-row">
                <strong>{{ $kpis['week']->count() }}</strong>
                <span class="dash-chip dash-chip-flat">{{ str('date')->plural($kpis['week']->count()) }}</span>
            </span>
            <span class="dash-kpi-foot">
                @if($next)
                    Next: {{ $next[0] }}, {{ $next[2]->title }}, {{ $next[1]->diffForHumans() }}
                @else
                    Nothing due this week
                @endif
            </span>
        </a>
    </div>

    <div class="dash-main">
        <div class="dash-col">
            {{-- ── Needs attention ────────────────────────────────────── --}}
            <section class="mac-card mac-card-padded" aria-labelledby="dash-attention">
                <div class="dash-card-head">
                    <h3 class="mac-card-title" id="dash-attention">Needs attention</h3>
                    @if($attention)<span class="mac-muted mac-text-sm">{{ $plural(count($attention), 'item') }}</span>@endif
                </div>

                @forelse($attention as [$tone, $text, $link])
                    {{-- A row with no link (a server problem) is fixed off-screen. --}}
                    <{{ $link ? 'a' : 'div' }} @if($link) href="{{ $link }}" @endif class="dash-row dash-{{ $tone }}">
                        <i class="bi {{ $tone === 'bad' ? 'bi-x-octagon' : ($tone === 'warn' ? 'bi-exclamation-triangle' : 'bi-info-circle') }}" aria-hidden="true"></i>
                        <span>{{ $text }}</span>
                        @if($link)<i class="bi bi-chevron-right ms-auto" aria-hidden="true"></i>@endif
                    </{{ $link ? 'a' : 'div' }}>
                @empty
                    <p class="dash-row dash-ok mb-0">
                        <i class="bi bi-check-circle" aria-hidden="true"></i>
                        <span>All clear — nothing waiting on you.</span>
                    </p>
                @endforelse
            </section>

            {{-- ── Events in progress ─────────────────────────────────── --}}
            <section class="mac-card mac-card-padded" aria-labelledby="dash-events">
                <div class="dash-card-head">
                    <h3 class="mac-card-title" id="dash-events">Events in progress</h3>
                    <a href="{{ route('admin.events.index') }}" class="mac-text-sm">All events</a>
                </div>

                @forelse($events as $event)
                    @php
                        // The next date that matters for this event.
                        [$nextLabel, $nextAt] = match (true) {
                            // Registration comes before the draw: the list is filled while it is open.
                            (bool) $event->registration_open_at?->isFuture() => ['Registration opens', $event->registration_open_at],
                            (bool) $event->registration_close_at?->isFuture() => ['Registration closes', $event->registration_close_at],
                            $event->polling_status === 'draft' && $event->polling_start_date?->isFuture() => ['Draw opens', $event->polling_start_date],
                            $event->polling_status === 'polling' && $event->polling_deadline?->isFuture() => ['Draw closes', $event->polling_deadline],
                            (bool) $event->start_date?->isFuture() => ['Starts', $event->start_date],
                            default => [null, null],
                        };
                        $percent = $event->on_list > 0 ? (int) round($event->confirmed / $event->on_list * 100) : 0;
                        $fill = $event->max_participants > 0 ? min(100, (int) round($event->places_taken / $event->max_participants * 100)) : 0;
                    @endphp
                    <article class="dash-event">
                        <div class="dash-event-top">
                            <div class="min-w-0">
                                <a href="{{ route('admin.polling.show', $event) }}" class="dash-event-title">{{ $event->title }}</a>
                                <div class="mac-muted mac-text-sm">
                                    {{ $event->start_date?->format('j M Y') ?? 'No date' }}@if($event->location) · {{ $event->location }}@endif
                                </div>
                            </div>
                            <span class="mac-status {{ $event->status_colour }}">{{ $event->status_label }}</span>
                        </div>

                        <div class="dash-event-stage">
                            @include('partials.draw-stepper', ['status' => $event->polling_status, 'compact' => true])
                            @if($nextLabel)<span class="dash-next"><i class="bi bi-clock" aria-hidden="true"></i> {{ $nextLabel }} {{ $nextAt->diffForHumans() }}</span>@endif
                        </div>

                        <div class="dash-figures mac-text-sm">
                            <a href="{{ route('admin.events.registrations.index', $event) }}">{{ $event->on_list }} on draw list</a>
                            <span>{{ $event->coded }} with a code</span>
                            <span>{{ $event->confirmed }} confirmed</span>
                            <span>{{ $event->plots_taken }}/{{ $event->plots_total }} plots taken</span>
                        </div>

                        <div class="dash-meter">
                            <span class="dash-meter-label mac-text-sm">Places</span>
                            <span class="dash-bar"><span @class(['dash-bar-full' => $event->places_taken >= $event->max_participants]) style="width: {{ $fill }}%"></span></span>
                            <span @class(['mac-text-sm', 'ui-num', 'dash-full' => $event->places_taken >= $event->max_participants])>{{ $event->places_taken }}/{{ $event->max_participants }} places filled</span>
                        </div>
                        @if($event->polling_status === 'polling' && $event->on_list > 0)
                            <div class="dash-meter">
                                <span class="dash-meter-label mac-text-sm">Confirmed</span>
                                <span class="dash-bar" role="img" aria-label="{{ $percent }}% confirmed"><span class="dash-bar-ok" style="width: {{ $percent }}%"></span></span>
                                <span class="mac-text-sm ui-num">{{ $percent }}% confirmed · {{ $event->on_list - $event->confirmed }} waiting</span>
                            </div>
                        @endif

                        @if($event->join_pending + $event->join_approved + $event->join_rejected > 0)
                            <div class="dash-figures mac-text-sm">
                                <a href="{{ route('admin.events.registrations.index', $event) }}#join-requests">Join requests:</a>
                                <span>{{ $event->join_pending }} to review</span>
                                <span>{{ $event->join_approved }} approved</span>
                                <span>{{ $event->join_rejected }} rejected</span>
                                @if($event->join_waiver > 0)<span>{{ $event->join_waiver }} asking for a fee waiver</span>@endif
                            </div>
                        @endif

                        @if(isset($lots[$event->id]))
                            <div class="dash-figures mac-text-sm">
                                @foreach($lots[$event->id] as $lot)
                                    <span>{{ $lot->location ?: 'No location' }} {{ $lot->size }}: <strong>{{ $lot->taken }}/{{ $lot->total }}</strong></span>
                                @endforeach
                            </div>
                        @endif
                    </article>
                @empty
                    <p class="mac-muted mac-text-sm mb-0">No upcoming events.</p>
                @endforelse
            </section>
        </div>

        <div class="dash-col">
            {{-- ── Company records ────────────────────────────────────── --}}
            <section class="mac-card mac-card-padded" aria-labelledby="dash-health">
                <div class="dash-card-head">
                    <h3 class="mac-card-title" id="dash-health">Company records</h3>
                    <a href="{{ route('admin.companies.index') }}" class="mac-text-sm">All companies</a>
                </div>

                <div class="dash-totals">
                    <div><strong>{{ number_format($health['active']) }}</strong><span>active · {{ $health['new'] }} new this month</span></div>
                    <div><strong>{{ number_format($health['portal']) }}</strong><span>used the portal, 30 days</span></div>
                </div>

                @forelse($health['gaps'] as [$n, $what, $filter, $tone])
                    <a class="dash-gap" href="{{ route('admin.companies.index', ['missing' => $filter]) }}">
                        <span class="dash-gap-n dash-gap-{{ $tone }}">{{ number_format($n) }}</span>
                        <span>{{ $plural($n, 'company') }} {{ $what }}</span>
                        <i class="bi bi-chevron-right ms-auto" aria-hidden="true"></i>
                    </a>
                @empty
                    <p class="dash-row dash-ok mb-0"><i class="bi bi-check-circle" aria-hidden="true"></i><span>Every active company's record is complete.</span></p>
                @endforelse
                @if($health['inactive'] > 0)
                    <p class="mac-muted mac-text-sm mt-2 mb-0">{{ $plural($health['inactive'], 'company') }} inactive, not counted.</p>
                @endif
            </section>

            {{-- ── Latest sales (HQ and super) ─────────────────────────── --}}
            @can('manage-sales-book')
                <section class="mac-card mac-card-padded" aria-labelledby="dash-sales">
                    <div class="dash-card-head">
                        <h3 class="mac-card-title" id="dash-sales">Latest sales</h3>
                        <a href="{{ route('admin.sales.index') }}" class="mac-text-sm">All sales</a>
                    </div>
                    @if($latestSales)
                        <a class="dash-sale" href="{{ route('admin.sales.show', $latestSales->id) }}">
                            <strong class="ui-num">RM {{ number_format((float) $latestSales->total, 2) }}</strong>
                            <span>{{ $latestSales->title }}</span>
                            <span class="mac-muted mac-text-sm">{{ \Illuminate\Support\Carbon::parse($latestSales->start_date)->format('j M') }} – {{ \Illuminate\Support\Carbon::parse($latestSales->end_date)->format('j M Y') }} · see the tables <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                        </a>
                    @else
                        <p class="mac-muted mac-text-sm mb-0">No sales book uploaded yet.</p>
                    @endif
                </section>
            @endcan

            {{-- ── Finished events ─────────────────────────────────────── --}}
            @if($completed->isNotEmpty())
                <section class="mac-card mac-card-padded" aria-labelledby="dash-completed">
                    <h3 class="mac-card-title" id="dash-completed">Recently finished</h3>
                    @foreach($completed as $event)
                        <div class="dash-event">
                            <a href="{{ route('admin.polling.show', $event) }}" class="dash-event-title">{{ $event->title }}</a>
                            <div class="mac-muted mac-text-sm">{{ $event->start_date->format('j M') }} – {{ $event->end_date->format('j M Y') }}@if($event->location) · {{ $event->location }}@endif</div>
                            <div class="dash-figures mac-text-sm">
                                <span>{{ $event->on_list }} on draw list</span>
                                <span>{{ $event->plots_taken }} lots held</span>
                                @isset($event->sales_total)
                                    <a href="{{ route('admin.sales.show', $event) }}">RM {{ number_format((float) $event->sales_total, 2) }} sales</a>
                                @endisset
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- ── HQ verification time (HQ and super) ─────────────────── --}}
            @if($verifyTimes)
                <section class="mac-card mac-card-padded" aria-labelledby="dash-verify">
                    <h3 class="mac-card-title" id="dash-verify">HQ verification time</h3>
                    <p class="mac-muted mac-text-sm mb-0">
                        From a zone placing a company on a draw list to HQ verifying it, last 90 days:
                        <strong>{{ $verifyTimes['days'] }} {{ str('day')->plural($verifyTimes['days'] == 1 ? 1 : 2) }}</strong> on average, over {{ $verifyTimes['rows'] }} {{ str('row')->plural($verifyTimes['rows']) }}.
                    </p>
                    @php $maxDays = max(0.1, $verifyTimes['byZone']->max()); @endphp
                    <h4 class="dash-sub">By zone</h4>
                    @foreach($verifyTimes['byZone'] as $zone => $d)
                        <div class="dash-split">
                            <span>{{ $zone }}</span>
                            <span class="dash-split-bar"><span style="width: {{ round($d / $maxDays * 100) }}%"></span></span>
                            <strong>{{ $d }} {{ str('day')->plural($d == 1 ? 1 : 2) }}</strong>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- ── Recent activity (super) ─────────────────────────────── --}}
            @if($activity?->isNotEmpty())
                <section class="mac-card mac-card-padded" aria-labelledby="dash-activity">
                    <div class="dash-card-head">
                        <h3 class="mac-card-title" id="dash-activity">Recent activity</h3>
                        <a href="{{ route('admin.audit-trails.index') }}" class="mac-text-sm">Audit trail</a>
                    </div>
                    @foreach($activity as $audit)
                        <a class="dash-split dash-activity" href="{{ route('admin.audit-trails.show', $audit) }}">
                            <span>{{ $audit->performer?->name ?? 'System' }} · {{ $audit->action_badge[1] }} {{ $audit->model_label }} #{{ $audit->auditable_id ?? '—' }}</span>
                            <span class="mac-muted">{{ $audit->created_at->diffForHumans() }}</span>
                        </a>
                    @endforeach
                </section>
            @endif
        </div>
    </div>
</div>

<style>
    .dash-head { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
    .dash-head h2 { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.015em; margin: 0 0 2px; }
    .dash-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 12px; }
    .dash-card-head .mac-card-title { margin: 0; }

    /* What is waiting */
    .dash-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 16px; }
    .dash-kpi { display: flex; flex-direction: column; gap: 6px; padding: 18px 20px; background: var(--ui-surface);
        border: 1px solid var(--ui-border); border-radius: var(--ui-r-lg); box-shadow: var(--ui-shadow-1);
        color: var(--ui-text); text-decoration: none; transition: border-color var(--ui-fast) var(--ui-ease), box-shadow var(--ui-fast) var(--ui-ease); }
    .dash-kpi:hover { border-color: var(--ui-border-hover); box-shadow: var(--ui-shadow-2); color: var(--ui-text); }
    .dash-kpi-label { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-weight: 650; font-size: 0.9375rem; }
    .dash-kpi-label .bi { color: var(--ui-blue); font-size: 1.05rem; }
    .dash-kpi-row { display: flex; align-items: center; gap: 10px; }
    .dash-kpi-row strong { font-size: 1.75rem; font-weight: 700; line-height: 1.1; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
    .dash-kpi-foot { font-size: 0.8125rem; color: var(--ui-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dash-chip { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 0.75rem; font-weight: 650; }
    .dash-chip-up   { background: var(--ui-ok-tint);      color: var(--ui-ok-text); }
    .dash-chip-warn { background: var(--ui-warn-tint);    color: var(--ui-warn-text); }
    .dash-chip-flat { background: var(--ui-neutral-tint); color: var(--ui-neutral-text); }

    /* Two columns, 3:2 */
    .dash-main { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; align-items: start; }
    .dash-col { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
    .dash-col > .mac-card { margin: 0; }

    /* Needs attention */
    .dash-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; margin-top: 6px;
        border-radius: var(--ui-r); font-size: 0.875rem; text-decoration: none; color: var(--ui-text); }
    .dash-row:hover { filter: brightness(0.97); }
    .dash-bad  { background: var(--ui-bad-tint);  } .dash-bad  > .bi:first-child { color: var(--ui-bad-text); }
    .dash-warn { background: var(--ui-warn-tint); } .dash-warn > .bi:first-child { color: var(--ui-warn-text); }
    .dash-info { background: var(--ui-blue-tint); } .dash-info > .bi:first-child { color: var(--ui-blue-text); }
    .dash-ok   { background: var(--ui-ok-tint); color: var(--ui-ok-text); }

    /* Events in progress */
    .dash-event { padding: 14px 0; border-top: 1px solid var(--ui-sep); }
    .dash-event:first-of-type { border-top: 0; padding-top: 4px; }
    .dash-event-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
    .dash-event-title { font-weight: 650; color: var(--ui-text); text-decoration: none; }
    .dash-event-title:hover { text-decoration: underline; }
    .dash-event-stage { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 14px; margin-top: 8px; }
    .dash-next { font-size: 0.8125rem; font-weight: 600; color: var(--ui-text-2); }
    .dash-next .bi { color: var(--ui-muted); }
    .dash-figures { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 8px; color: var(--ui-text-2); }
    .dash-meter { display: grid; grid-template-columns: 72px minmax(0, 1fr) auto; align-items: center; gap: 10px; margin-top: 8px; }
    .dash-meter-label { color: var(--ui-muted); }
    .dash-bar { height: 8px; border-radius: 99px; background: var(--ui-neutral-tint); overflow: hidden; }
    .dash-bar > span { display: block; height: 100%; background: var(--ui-blue); border-radius: 99px; }
    .dash-bar > .dash-bar-ok { background: var(--ui-ok); }
    .dash-bar > .dash-bar-full { background: var(--ui-bad); }
    .dash-full { color: var(--ui-bad-text); font-weight: 650; }

    /* Company records */
    .dash-totals { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 10px; }
    .dash-totals > div { padding: 10px 12px; border-radius: var(--ui-r); background: var(--ui-surface-2); border: 1px solid var(--ui-sep); }
    .dash-totals strong { display: block; font-size: 1.375rem; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .dash-totals span { font-size: 0.8125rem; color: var(--ui-muted); }
    .dash-gap { display: flex; align-items: center; gap: 10px; padding: 9px 2px; border-top: 1px solid var(--ui-sep);
        font-size: 0.875rem; color: var(--ui-text); text-decoration: none; }
    .dash-gap:hover { background: var(--ui-row-hover); }
    .dash-gap > .bi { color: var(--ui-muted); }
    .dash-gap-n { min-width: 40px; padding: 2px 8px; border-radius: 999px; text-align: center; font-weight: 700; font-size: 0.8125rem; font-variant-numeric: tabular-nums; }
    .dash-gap-warn { background: var(--ui-warn-tint); color: var(--ui-warn-text); }
    .dash-gap-info { background: var(--ui-blue-tint); color: var(--ui-blue-text); }

    /* Latest sales */
    .dash-sale { display: flex; flex-direction: column; gap: 2px; color: var(--ui-text); text-decoration: none; }
    .dash-sale strong { font-size: 1.5rem; font-weight: 700; letter-spacing: -0.01em; }
    .dash-sale:hover span:last-child { color: var(--ui-blue-text); }

    /* Lists */
    .dash-sub { margin: 16px 0 6px; font-size: 0.8125rem; font-weight: 650; color: var(--ui-text-2); }
    .dash-split { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto; align-items: center;
        gap: 10px; padding: 4px 0; font-size: 0.8125rem; color: var(--ui-text); text-decoration: none; }
    .dash-split > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dash-split > strong { font-variant-numeric: tabular-nums; }
    .dash-split-bar { height: 6px; border-radius: 99px; background: var(--ui-neutral-tint); overflow: hidden; }
    .dash-split-bar > span { display: block; height: 100%; background: var(--ui-blue-text); }
    .dash-activity { grid-template-columns: minmax(0, 1fr) auto; }
    .min-w-0 { min-width: 0; }

    @media (max-width: 1100px) { .dash-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 860px) { .dash-main { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 520px) {
        .dash-kpis { grid-template-columns: minmax(0, 1fr); }
        .dash-meter { grid-template-columns: minmax(0, 1fr); gap: 4px; }
        .dash-meter-label { display: none; }
    }
</style>
@endsection
