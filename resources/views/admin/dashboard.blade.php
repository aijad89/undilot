@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    @include('partials.admin-alerts')

    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>Dashboard</h2>
            <p class="mac-muted mb-0">
                Signed in as {{ $admin->name }} —
                {{ \App\Models\Admin::ROLES[$admin->role] ?? $admin->role }}@unless($global) · {{ $admin->state }}@endunless
            </p>
        </div>

        {{-- Shortcuts: each one only for the roles its own route lets through. --}}
        <div class="d-flex flex-wrap gap-2">
            @can('manage-events')
                <a href="{{ route('admin.events.create') }}" class="mac-btn-secondary"><i class="bi bi-calendar-plus me-1"></i> New event</a>
            @endcan
            <a href="{{ route('admin.companies.create') }}" class="mac-btn-secondary"><i class="bi bi-building-add me-1"></i> Add company</a>
            {{-- The public events list, as participants see it. A new tab, so the
                 admin session's page stays where it was. --}}
            <a href="{{ route('home') }}" class="mac-btn-secondary" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i> Public event page</a>
        </div>
    </div>

    {{-- ── Needs attention ─────────────────────────────────────────── --}}
    <section class="mac-card mac-card-padded mb-3" aria-labelledby="dash-attention">
        <h3 class="mac-card-title" id="dash-attention">Needs attention</h3>

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

    <div class="dash-grid">
        {{-- ── Events ─────────────────────────────────────────────── --}}
        <section class="mac-card mac-card-padded" aria-labelledby="dash-events">
            <div class="d-flex justify-content-between align-items-baseline">
                <h3 class="mac-card-title" id="dash-events">Events</h3>
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
                @endphp
                <div class="dash-event">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <a href="{{ route('admin.polling.show', $event) }}" class="dash-event-title">{{ $event->title }}</a>
                        <span class="d-flex gap-1 flex-shrink-0">
                            <span class="mac-status {{ $event->status_colour }}">{{ $event->status_label }}</span>
                            <span class="mac-status {{ \App\Models\Event::pollingStatusColour($event->polling_status) }}">
                                Draw: {{ \App\Models\Event::pollingStatusLabel($event->polling_status) }}
                            </span>
                        </span>
                    </div>

                    <div class="mac-muted mac-text-sm">
                        {{ $event->start_date?->format('j M Y') ?? 'No date' }}@if($event->location) · {{ $event->location }}@endif
                        @if($nextLabel) · <strong>{{ $nextLabel }} {{ $nextAt->diffForHumans() }}</strong>@endif
                    </div>

                    <div class="dash-figures mac-text-sm">
                        <a href="{{ route('admin.events.registrations.index', $event) }}">{{ $event->on_list }} on draw list</a>
                        <span>{{ $event->coded }} with a code</span>
                        <span>{{ $event->confirmed }} confirmed</span>
                        <span>{{ $event->plots_taken }}/{{ $event->plots_total }} plots taken</span>
                        <span @class(['dash-full' => $event->places_taken >= $event->max_participants])>{{ $event->places_taken }}/{{ $event->max_participants }} places filled</span>
                    </div>

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

                    @if($event->polling_status === 'polling' && $event->on_list > 0)
                        <div class="dash-bar" role="img" aria-label="{{ $percent }}% confirmed">
                            <span style="width: {{ $percent }}%"></span>
                        </div>
                        <div class="mac-muted mac-text-sm">{{ $percent }}% confirmed · {{ $event->on_list - $event->confirmed }} waiting</div>
                    @endif
                </div>
            @empty
                <p class="mac-muted mac-text-sm mb-0">No upcoming events.</p>
            @endforelse
        </section>

        {{-- ── Companies ──────────────────────────────────────────── --}}
        <section class="mac-card mac-card-padded" aria-labelledby="dash-companies">
            <div class="d-flex justify-content-between align-items-baseline">
                <h3 class="mac-card-title" id="dash-companies">Companies</h3>
                <a href="{{ route('admin.companies.index') }}" class="mac-text-sm">All companies</a>
            </div>

            <div class="dash-totals">
                <div><strong>{{ $companyStats['active'] }}</strong><span>active</span></div>
                <div><strong>{{ $companyStats['inactive'] }}</strong><span>inactive</span></div>
                <div><strong>{{ $companyStats['new'] }}</strong><span>new this month</span></div>
                <div><strong>{{ $companyStats['portal'] }}</strong><span>used the portal, 30 days</span></div>
            </div>

            @php $max = max(1, $companyStats['byCategory']->max() ?? 1); @endphp
            <h4 class="dash-sub">By craft category</h4>
            @forelse($companyStats['byCategory'] as $name => $n)
                <div class="dash-split">
                    <span>{{ $name }}</span>
                    <span class="dash-split-bar"><span style="width: {{ round($n / $max * 100) }}%"></span></span>
                    <strong>{{ $n }}</strong>
                </div>
            @empty
                <p class="mac-muted mac-text-sm mb-0">No company has a craft category yet.</p>
            @endforelse

            @if($companyStats['byBusinessType']->isNotEmpty())
                @php $maxBiz = max(1, $companyStats['byBusinessType']->max()); @endphp
                <h4 class="dash-sub">By business type</h4>
                @foreach($companyStats['byBusinessType'] as $type => $n)
                    <div class="dash-split">
                        <span>{{ $type }}</span>
                        <span class="dash-split-bar"><span style="width: {{ round($n / $maxBiz * 100) }}%"></span></span>
                        <strong>{{ $n }}</strong>
                    </div>
                @endforeach
            @endif

            @if($global && $companyStats['byZone']->isNotEmpty())
                @php $maxZone = max(1, $companyStats['byZone']->max()); @endphp
                <h4 class="dash-sub">By zone</h4>
                @foreach($companyStats['byZone'] as $zone => $n)
                    <a class="dash-split" href="{{ route('admin.companies.index', ['state' => $zone]) }}">
                        <span>{{ $zone }}</span>
                        <span class="dash-split-bar"><span style="width: {{ round($n / $maxZone * 100) }}%"></span></span>
                        <strong>{{ $n }}</strong>
                    </a>
                @endforeach
            @endif
        </section>
    </div>

    {{-- ── HQ verification time (HQ and super) ───────────────────── --}}
    @if($verifyTimes)
        <section class="mac-card mac-card-padded mt-3" aria-labelledby="dash-verify">
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

    {{-- ── Recent activity (super) ────────────────────────────────── --}}
    @if($activity?->isNotEmpty())
        <section class="mac-card mac-card-padded mt-3" aria-labelledby="dash-activity">
            <div class="d-flex justify-content-between align-items-baseline">
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

    {{-- ── Finished events ─────────────────────────────────────────── --}}
    @if($completed->isNotEmpty())
        <section class="mac-card mac-card-padded mt-3" aria-labelledby="dash-completed">
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

    {{-- ── Sales (HQ and super) ───────────────────────────────────── --}}
    @if($sales)
        <section class="mac-card mac-card-padded mt-3" aria-labelledby="dash-sales">
            <div class="d-flex justify-content-between align-items-baseline">
                <h3 class="mac-card-title" id="dash-sales">Sales</h3>
                <a href="{{ route('admin.sales.index') }}" class="mac-text-sm">All sales</a>
            </div>

            @if($sales['latest'])
                @php $money = fn ($n) => 'RM '.number_format((float) $n, 2); @endphp
                <div class="dash-totals">
                    <div>
                        <strong>{{ $money($sales['latest']->total) }}</strong>
                        <span>latest: <a href="{{ route('admin.sales.show', $sales['latest']->id) }}">{{ $sales['latest']->title }}</a></span>
                    </div>
                    <div><strong>{{ $money($sales['byType']->sum()) }}</strong><span>all events</span></div>
                    <div><strong>{{ $sales['events'] }}</strong><span>{{ str('event')->plural($sales['events']) }} with sales</span></div>
                </div>

                <div class="dash-grid">
                    <div>
                        <h4 class="dash-sub">Top events</h4>
                        @php $maxSale = max(1, (float) $sales['top']->max('total')); @endphp
                        @foreach($sales['top'] as $row)
                            <a class="dash-split" href="{{ route('admin.sales.show', $row->id) }}">
                                <span>{{ $row->title }}</span>
                                <span class="dash-split-bar"><span style="width: {{ round($row->total / $maxSale * 100) }}%"></span></span>
                                <strong>{{ $money($row->total) }}</strong>
                            </a>
                        @endforeach
                    </div>
                    <div>
                        <h4 class="dash-sub">By payment type</h4>
                        @php $maxType = max(1, (float) $sales['byType']->max()); @endphp
                        @foreach(\App\Support\SalesBook::PAYMENT_TYPES as $type)
                            <div class="dash-split">
                                <span>{{ $type }}</span>
                                <span class="dash-split-bar"><span style="width: {{ round(($sales['byType'][$type] ?? 0) / $maxType * 100) }}%"></span></span>
                                <strong>{{ $money($sales['byType'][$type] ?? 0) }}</strong>
                            </div>
                        @endforeach
                    </div>
                    <div>
                        <h4 class="dash-sub">Top exhibitors</h4>
                        @php $maxEx = max(1, (float) $sales['exhibitors']->max('total')); @endphp
                        @foreach($sales['exhibitors'] as $ex)
                            <{{ $ex->company_id ? 'a' : 'div' }} class="dash-split" @if($ex->company_id) href="{{ route('admin.companies.edit', $ex->company_id) }}" @endif>
                                <span title="{{ $ex->events }} {{ str('event')->plural($ex->events) }}">{{ $ex->name }}</span>
                                <span class="dash-split-bar"><span style="width: {{ round($ex->total / $maxEx * 100) }}%"></span></span>
                                <strong>{{ $money($ex->total) }}</strong>
                            </{{ $ex->company_id ? 'a' : 'div' }}>
                        @endforeach
                    </div>
                    @foreach(['By category' => $sales['byCategory'], 'By state' => $sales['byState']] as $heading => $split)
                        <div>
                            <h4 class="dash-sub">{{ $heading }}</h4>
                            @php $maxSplit = max(1, (float) $split->max()); @endphp
                            @foreach($split as $name => $total)
                                <div class="dash-split">
                                    <span>{{ $name }}</span>
                                    <span class="dash-split-bar"><span style="width: {{ round($total / $maxSplit * 100) }}%"></span></span>
                                    <strong>{{ $money($total) }}</strong>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mac-muted mac-text-sm mb-0">No sales book uploaded yet.</p>
            @endif
        </section>
    @endif
</div>

<style>
    .dash-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; margin-top: 6px;
        border-radius: var(--ui-r); font-size: 0.875rem; text-decoration: none; color: var(--ui-text); }
    .dash-row:hover { filter: brightness(0.97); }
    .dash-bad  { background: var(--ui-bad-tint);  } .dash-bad  > .bi:first-child { color: var(--ui-bad-text); }
    .dash-warn { background: var(--ui-warn-tint); } .dash-warn > .bi:first-child { color: var(--ui-warn-text); }
    .dash-info { background: var(--ui-blue-tint); } .dash-info > .bi:first-child { color: var(--ui-blue-text); }
    .dash-ok   { background: var(--ui-ok-tint); color: var(--ui-ok-text); }

    .dash-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; }
    @media (max-width: 860px) { .dash-grid { grid-template-columns: minmax(0, 1fr); } }

    .dash-event { padding: 12px 0; border-top: 1px solid var(--ui-sep); }
    .dash-event:first-of-type { border-top: 0; }
    .dash-event-title { font-weight: 650; color: var(--ui-text); text-decoration: none; }
    .dash-event-title:hover { text-decoration: underline; }
    .dash-figures { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 6px; color: var(--ui-text-2); }
    .dash-activity { grid-template-columns: minmax(0, 1fr) auto; }
    .dash-full { color: var(--ui-bad-text); font-weight: 650; }
    .dash-bar { height: 8px; margin-top: 8px; border-radius: 99px; background: var(--ui-neutral-tint); overflow: hidden; }
    .dash-bar > span { display: block; height: 100%; background: var(--ui-ok-text); }

    .dash-totals { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; margin: 8px 0 4px; }
    .dash-totals > div { padding: 10px; border-radius: var(--ui-r); background: var(--ui-neutral-tint); }
    .dash-totals strong { display: block; font-size: 1.5rem; line-height: 1.2; }
    .dash-totals span { font-size: 0.8125rem; color: var(--ui-muted); }

    .dash-sub { margin: 16px 0 6px; font-size: 0.8125rem; font-weight: 650; color: var(--ui-text-2); }
    .dash-split { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto; align-items: center;
        gap: 10px; padding: 4px 0; font-size: 0.8125rem; color: var(--ui-text); text-decoration: none; }
    .dash-split > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .dash-split-bar { height: 6px; border-radius: 99px; background: var(--ui-neutral-tint); overflow: hidden; }
    .dash-split-bar > span { display: block; height: 100%; background: var(--ui-blue-text); }
</style>
@endsection
