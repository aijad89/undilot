@extends('layouts.admin')

@section('title', 'Sales — '.$event->title)

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    <div class="mac-breadcrumb mb-2">
        <a href="{{ route('admin.sales.index') }}">Sales</a> <span class="sep" aria-hidden="true">›</span> {{ $event->title }}
    </div>

    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>{{ $event->title }}</h2>
            <p class="mac-muted mb-0">
                {{ $event->start_date->format('d M') }} – {{ $event->end_date->format('d M Y') }}, {{ $event->location }}.
                @if($upload)
                    From <strong>{{ $upload->filename }}</strong>, uploaded by {{ $upload->admin?->name ?? 'an admin since removed' }} on {{ $upload->created_at->format('d M Y, H:i') }}.
                @endif
            </p>
        </div>
        <a href="{{ route('admin.polling.sales-book.show', $event) }}" class="mac-btn-secondary">
            <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Sales book
        </a>
    </div>

    @php($types = \App\Support\SalesBook::PAYMENT_TYPES)
    @php($money = fn ($n) => number_format((float) $n, 2))

    @if($bySheet->isEmpty())
        <div class="mac-card"><div class="mac-empty">No sales uploaded for this event yet. Upload the filled workbook on the <a href="{{ route('admin.polling.sales-book.show', $event) }}">sales book</a> page.</div></div>
    @else
        <div class="mac-card mb-3">
            <div class="mac-card-padded pb-0"><h3 class="mac-card-title">Sales by sheet</h3></div>
            <div class="table-responsive">
                <table class="mac-table">
                    <thead>
                        <tr>
                            <th>Sheet</th>
                            <th class="text-end">Exhibitors</th>
                            @foreach($types as $type)<th class="text-end">{{ $type }}</th>@endforeach
                            <th class="text-end">Total (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($bySheet as $name => $rows)
                            <tr @class(['table-active' => $sheet === $name])>
                                <td class="fw-semibold"><a href="{{ route('admin.sales.show', [$event, 'sheet' => $name]) }}#exhibitors">{{ $name }}</a></td>
                                <td class="text-end ui-num">{{ $rows->max('exhibitors') }}</td>
                                @foreach($types as $type)<td class="text-end ui-num">{{ $money($rows->firstWhere('payment_type', $type)?->total) }}</td>@endforeach
                                <td class="text-end ui-num fw-semibold">{{ $money($rows->sum('total')) }}</td>
                            </tr>
                        @endforeach
                        <tr class="fw-bold">
                            <td>Total</td>
                            <td></td>
                            @foreach($types as $type)<td class="text-end ui-num">{{ $money($bySheet->collapse()->where('payment_type', $type)->sum('total')) }}</td>@endforeach
                            <td class="text-end ui-num">{{ $money($bySheet->collapse()->sum('total')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mac-card mb-3">
            <div class="mac-card-padded pb-0"><h3 class="mac-card-title">Sales by day</h3></div>
            <div class="table-responsive">
                <table class="mac-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            @foreach($types as $type)<th class="text-end">{{ $type }}</th>@endforeach
                            <th class="text-end">Total (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byDay as $date => $rows)
                            <tr>
                                <td>{{ \Illuminate\Support\Carbon::parse($date)->locale('ms')->isoFormat('dddd, D/M/YYYY') }}</td>
                                @foreach($types as $type)<td class="text-end ui-num">{{ $money($rows->firstWhere('payment_type', $type)?->total) }}</td>@endforeach
                                <td class="text-end ui-num fw-semibold">{{ $money($rows->sum('total')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if($exhibitors !== null)
            <div class="mac-card mb-3" id="exhibitors">
                <div class="mac-card-padded pb-0"><h3 class="mac-card-title">{{ $sheet }}: sales by exhibitor</h3></div>
                <div class="table-responsive">
                    <table class="mac-table">
                        <thead>
                            <tr>
                                <th>Lot</th>
                                <th>Company</th>
                                <th>Entrepreneur</th>
                                <th>State</th>
                                @foreach($types as $type)<th class="text-end">{{ $type }}</th>@endforeach
                                <th class="text-end">Total (RM)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($exhibitors as $rows)
                                @php($who = $rows->first())
                                <tr>
                                    <td class="fw-semibold">{{ $who->lot }}</td>
                                    <td>{{ $who->company_name }}</td>
                                    <td>{{ $who->entrepreneur }}</td>
                                    <td>{{ $who->state }}</td>
                                    @foreach($types as $type)<td class="text-end ui-num">{{ $money($rows->where('payment_type', $type)->sum('amount')) }}</td>@endforeach
                                    <td class="text-end ui-num fw-semibold">{{ $money($rows->sum('amount')) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ 5 + count($types) }}"><div class="mac-empty">No sales on this sheet.</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

</div>
@endsection
