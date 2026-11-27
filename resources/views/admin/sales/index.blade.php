@extends('layouts.admin')

@section('title', 'Sales')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    <div class="mac-header mb-4">
        <h2>Sales</h2>
        <p class="mac-muted mb-0">The sales kept from each event's uploaded sales book. Open an event to see its tables.</p>
    </div>

    @php
        $o = $overview;
        $money = fn ($n) => 'RM '.number_format((float) $n, 2);
        // Axis labels and big figures: RM 12,500 reads as 12.5K, RM 38,147,511 as 38.1M.
        $short = fn ($n) => match (true) {
            $n >= 1e6 => rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.').'M',
            $n >= 1e3 => rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.').'K',
            default => (string) round($n),
        };
        $pct = fn ($part, $whole) => $whole > 0 ? (int) round($part / $whole * 100) : 0;
        $latest = $o['latest'];
    @endphp

    @if($latest)
        @php
            $days = $o['latestDaily']->values();
            $dates = $o['latestDaily']->keys();
            $n = max($days->count(), 2);
            $top = max(1, $days->max() ?? 0);
            $step = 10 ** floor(log10($top));
            $ceil = ceil($top / $step * 2) / 2 * $step;
            [$W, $H, $L, $R, $T, $B] = [560, 210, 40, 12, 14, 26];
            $x = fn ($i) => round($L + $i * ($W - $L - $R) / ($n - 1), 1);
            $y = fn ($v) => round($T + (1 - $v / $ceil) * ($H - $T - $B), 1);
            $line = $days->map(fn ($v, $i) => ($i ? 'L' : 'M').$x($i).' '.$y($v))->implode(' ');
            $peak = $days->search($days->max());
            $typeTotal = max(1, $o['latestByType']->sum());
            $exTotal = max(1, $o['exhibitors']->sum('total'));
        @endphp

        <div class="sl-grid mb-3">
            <section class="mac-card mac-card-padded" aria-labelledby="sl-latest">
                <div class="sl-head">
                    <h3 class="mac-card-title" id="sl-latest">Latest event</h3>
                    <a href="{{ route('admin.sales.show', $latest->id) }}" class="mac-text-sm">Open its tables</a>
                </div>
                <div class="sl-figure">
                    <strong>RM {{ $short($latest->total) }}</strong>
                    <span class="mac-muted mac-text-sm">{{ $money($latest->total) }} at {{ $latest->title }}</span>
                </div>

                <svg class="sl-chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="Sales per day at {{ $latest->title }}, best day {{ $money($days->max()) }}">
                    <defs>
                        <linearGradient id="sl-area" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="var(--ui-blue)" stop-opacity="0.18"/>
                            <stop offset="1" stop-color="var(--ui-blue)" stop-opacity="0"/>
                        </linearGradient>
                    </defs>
                    @foreach([0, 1 / 3, 2 / 3, 1] as $f)
                        <line x1="{{ $L }}" x2="{{ $W - $R }}" y1="{{ $y($ceil * $f) }}" y2="{{ $y($ceil * $f) }}" class="sl-grid-line"/>
                        <text x="{{ $L - 8 }}" y="{{ $y($ceil * $f) + 4 }}" text-anchor="end" class="sl-axis">{{ $short($ceil * $f) }}</text>
                    @endforeach
                    @foreach($dates as $i => $date)
                        <text x="{{ $x($i) }}" y="{{ $H - 6 }}" text-anchor="middle" class="sl-axis">{{ \Illuminate\Support\Carbon::parse($date)->format('j M') }}</text>
                    @endforeach
                    @if($days->count() > 1)
                        <path d="{{ $line }} L{{ $x($days->count() - 1) }} {{ $y(0) }} L{{ $x(0) }} {{ $y(0) }} Z" fill="url(#sl-area)"/>
                        <path d="{{ $line }}" class="sl-line"/>
                    @endif
                    @foreach($days as $i => $v)
                        <circle cx="{{ $x($i) }}" cy="{{ $y($v) }}" r="{{ $i === $peak ? 5 : 3 }}" class="sl-dot {{ $i === $peak ? 'sl-dot-peak' : '' }}">
                            <title>{{ \Illuminate\Support\Carbon::parse($dates[$i])->format('D j M') }}: {{ $money($v) }}</title>
                        </circle>
                    @endforeach
                    @if($peak !== false)
                        @php $px = min(max($x($peak), $L + 70), $W - $R - 70); $py = max($T, $y($days[$peak]) - 52); @endphp
                        <line x1="{{ $x($peak) }}" x2="{{ $x($peak) }}" y1="{{ $T }}" y2="{{ $y(0) }}" class="sl-peak-line"/>
                        <g class="sl-tip">
                            <rect x="{{ $px - 66 }}" y="{{ $py }}" width="132" height="40" rx="6"/>
                            <text x="{{ $px }}" y="{{ $py + 16 }}" text-anchor="middle" class="sl-tip-date">Best day, {{ \Illuminate\Support\Carbon::parse($dates[$peak])->format('D j M') }}</text>
                            <text x="{{ $px }}" y="{{ $py + 32 }}" text-anchor="middle" class="sl-tip-value">{{ $money($days[$peak]) }}</text>
                        </g>
                    @endif
                </svg>

                <div class="sl-segments" aria-label="Sales at {{ $latest->title }} by payment type">
                    <div class="sl-segment-cols">
                        @foreach(\App\Support\SalesBook::PAYMENT_TYPES as $i => $type)
                            @php $amount = (float) ($o['latestByType'][$type] ?? 0); @endphp
                            <div class="sl-segment sl-seg-{{ $i }}">
                                <span class="sl-segment-label"><i class="sl-key"></i>{{ $type }}</span>
                                <strong title="{{ $money($amount) }}">RM {{ $short($amount) }}</strong>
                                <span class="mac-muted mac-text-sm">{{ $pct($amount, $typeTotal) }}%</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="sl-stack" aria-hidden="true">
                        @foreach(\App\Support\SalesBook::PAYMENT_TYPES as $i => $type)
                            <span class="sl-seg-{{ $i }}" style="width: {{ round((float) ($o['latestByType'][$type] ?? 0) / $typeTotal * 100, 2) }}%"></span>
                        @endforeach
                    </div>
                </div>
            </section>

            <section class="mac-card mac-card-padded" aria-labelledby="sl-all">
                <div class="sl-head">
                    <h3 class="mac-card-title" id="sl-all">All events</h3>
                    <span class="mac-muted mac-text-sm">{{ $o['events'] }} {{ str('event')->plural($o['events']) }} with sales</span>
                </div>
                <div class="sl-figure">
                    <strong>RM {{ $short($o['byType']->sum()) }}</strong>
                    <span class="mac-muted mac-text-sm">{{ $money($o['byType']->sum()) }} in all</span>
                </div>
                @foreach(['Top events' => $o['top']->mapWithKeys(fn ($r) => [$r->title => $r->total]), 'By payment type' => $o['byType']->sortDesc(), 'By category' => $o['byCategory'], 'By state' => $o['byState']] as $heading => $split)
                    @php $maxSplit = max(1, (float) $split->max()); @endphp
                    <h4 class="sl-sub">{{ $heading }}</h4>
                    @foreach($split->take(6) as $name => $total)
                        <div class="sl-split">
                            <span>{{ $name }}</span>
                            <span class="sl-split-bar"><span style="width: {{ round($total / $maxSplit * 100) }}%"></span></span>
                            <strong>RM {{ $short($total) }}</strong>
                        </div>
                    @endforeach
                    @if($split->count() > 6)<p class="mac-muted mac-text-sm mb-0">and {{ $split->count() - 6 }} more</p>@endif
                @endforeach
            </section>
        </div>

        @if($o['exhibitors']->isNotEmpty())
            <section class="mac-card mb-3" aria-labelledby="sl-exhibitors">
                <div class="sl-head mac-card-padded pb-0">
                    <h3 class="mac-card-title" id="sl-exhibitors">Top exhibitors</h3>
                    <span class="mac-muted mac-text-sm">all events</span>
                </div>
                <div class="table-responsive">
                    <table class="mac-table sl-table">
                        <thead>
                            <tr><th>#</th><th>Exhibitor</th><th class="text-end">Events</th><th class="text-end">Sales</th><th class="text-end">Share</th></tr>
                        </thead>
                        <tbody>
                            @foreach($o['exhibitors'] as $rank => $ex)
                                <tr>
                                    <td class="mac-muted ui-num">{{ $rank + 1 }}</td>
                                    <td class="sl-table-name">
                                        @if($ex->company_id)
                                            <a href="{{ route('admin.companies.edit', $ex->company_id) }}">{{ $ex->name }}</a>
                                        @else
                                            {{ $ex->name }} <span class="mac-muted mac-text-sm">· outside the draw</span>
                                        @endif
                                    </td>
                                    <td class="text-end ui-num">{{ $ex->events }}</td>
                                    <td class="text-end ui-num fw-semibold">{{ $money($ex->total) }}</td>
                                    <td class="text-end ui-num mac-muted">{{ $pct($ex->total, $exTotal) }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <div class="mac-card">
        <div class="table-responsive">
            <table class="mac-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Location</th>
                        <th>Last upload</th>
                        <th class="text-end">Total sales (RM)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                        <tr>
                            <td class="fw-semibold"><a href="{{ route('admin.sales.show', $event) }}">{{ $event->title }}</a></td>
                            <td class="text-nowrap">{{ $event->start_date->format('d M') }} – {{ $event->end_date->format('d M Y') }}</td>
                            <td>{{ $event->location }}</td>
                            <td>
                                @if($event->salesBookUpload)
                                    {{ $event->salesBookUpload->created_at->format('d M Y, H:i') }}
                                    <span class="mac-muted">· {{ $event->salesBookUpload->admin?->name ?? 'an admin since removed' }}</span>
                                @else
                                    <span class="mac-muted">Nothing uploaded</span>
                                @endif
                            </td>
                            <td class="text-end ui-num">{{ $event->sales_total !== null ? number_format((float) $event->sales_total, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><div class="mac-empty">No events yet.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $events->links() }}</div>
</div>

<style>
    .sl-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 16px; align-items: start; }
    .sl-grid > .mac-card { margin: 0; }
    .sl-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-bottom: 10px; }
    .sl-head .mac-card-title { margin: 0; }
    .sl-figure { display: flex; flex-direction: column; gap: 2px; margin-bottom: 8px; }
    .sl-figure > strong { font-size: 1.75rem; font-weight: 700; letter-spacing: -0.02em; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .sl-chart { width: 100%; height: auto; display: block; overflow: visible; }
    .sl-grid-line { stroke: var(--ui-sep); stroke-dasharray: 4 4; }
    .sl-axis { fill: var(--ui-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
    .sl-line { fill: none; stroke: var(--ui-blue); stroke-width: 2.25; stroke-linejoin: round; stroke-linecap: round; }
    .sl-dot { fill: var(--ui-surface); stroke: var(--ui-blue); stroke-width: 2; }
    .sl-dot-peak { fill: var(--ui-blue); stroke: var(--ui-surface); }
    .sl-peak-line { stroke: var(--ui-border-strong); stroke-dasharray: 3 3; }
    .sl-tip rect { fill: var(--ui-surface); stroke: var(--ui-border); filter: drop-shadow(0 2px 4px var(--ui-shade)); }
    .sl-tip-date { fill: var(--ui-muted); font-size: 11px; }
    .sl-tip-value { fill: var(--ui-text); font-size: 12.5px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .sl-segments { margin-top: 14px; padding: 14px 16px; border: 1px solid var(--ui-border); border-radius: var(--ui-r-lg); }
    .sl-segment-cols { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .sl-segment { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .sl-segment + .sl-segment { padding-left: 12px; border-left: 1px solid var(--ui-sep); }
    .sl-segment-label { display: flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 600; color: var(--ui-muted); letter-spacing: 0.02em; white-space: nowrap; }
    .sl-segment strong { font-size: 0.9375rem; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .sl-key { width: 8px; height: 8px; border-radius: 2px; flex: none; }
    .sl-stack { display: flex; gap: 2px; height: 8px; margin-top: 12px; border-radius: 999px; overflow: hidden; background: var(--ui-neutral-tint); }
    .sl-stack > span { display: block; height: 100%; }
    .sl-seg-0 .sl-key, .sl-stack > .sl-seg-0 { background: var(--ui-blue); }
    .sl-seg-1 .sl-key, .sl-stack > .sl-seg-1 { background: var(--ui-ok); }
    .sl-seg-2 .sl-key, .sl-stack > .sl-seg-2 { background: var(--ui-warn); }
    .sl-seg-3 .sl-key, .sl-stack > .sl-seg-3 { background: var(--ui-violet-text); }
    .sl-sub { margin: 14px 0 6px; font-size: 0.8125rem; font-weight: 650; color: var(--ui-text-2); }
    .sl-split { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) auto; align-items: center; gap: 10px; padding: 3px 0; font-size: 0.8125rem; }
    .sl-split > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sl-split > strong { font-variant-numeric: tabular-nums; }
    .sl-split-bar { height: 6px; border-radius: 99px; background: var(--ui-neutral-tint); overflow: hidden; }
    .sl-split-bar > span { display: block; height: 100%; background: var(--ui-blue-text); }
    .sl-table td.ui-num { white-space: nowrap; }
    .sl-table thead th:first-child, .sl-table tbody td:first-child { width: 48px; }
    .sl-table-name { max-width: 0; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 860px) { .sl-grid { grid-template-columns: minmax(0, 1fr); } }
    @media (max-width: 520px) {
        .sl-segment-cols { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .sl-segment + .sl-segment { padding-left: 0; border-left: 0; }
    }
</style>
@endsection
