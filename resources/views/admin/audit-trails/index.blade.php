@extends('layouts.admin')

@section('title', 'Audit Trail')

@section('content')
@include('partials.mac-styles')
<div class="mac-page">
    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>Audit Trail</h2>
            <div class="mac-breadcrumb">
                <a href="{{ route('admin.dashboard') }}">Dashboard</a>
                <span class="sep" aria-hidden="true">›</span> Audit Trail
            </div>
            <p class="mac-muted mb-0">Every recorded change, who made it and when, newest first.</p>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mac-filter-bar mb-4">
        <form method="GET" action="{{ route('admin.audit-trails.index') }}" class="row g-3 align-items-end">
            {{-- Kept through Filter: one admin's rows, or one participant's account. --}}
            @foreach(['admin', 'user'] as $kept)
                @if(request($kept))<input type="hidden" name="{{ $kept }}" value="{{ request($kept) }}">@endif
            @endforeach
            <div class="col-6 col-md-4 col-xl-2">
                <label class="mac-label" for="at-action">Action</label>
                <select name="action" id="at-action" class="mac-select">
                    <option value="">All</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" {{ request('action') == $action ? 'selected' : '' }}>
                            {{ ucfirst(str_replace('_', ' ', $action)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="mac-label" for="at-model">Model</label>
                <select name="model" id="at-model" class="mac-select">
                    <option value="">All</option>
                    @foreach($models as $model)
                        <option value="{{ $model }}" {{ request('model') == $model ? 'selected' : '' }}>
                            {{ $model }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="mac-label" for="at-from">From</label>
                <input type="date" name="from" id="at-from" class="mac-input" value="{{ request('from') }}">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="mac-label" for="at-to">To</label>
                <input type="date" name="to" id="at-to" class="mac-input" value="{{ request('to') }}">
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <label class="mac-label" for="at-search">Search (IP/URL)</label>
                <input type="text" name="search" id="at-search" class="mac-input" value="{{ request('search') }}" placeholder="Search...">
            </div>
            <div class="col-12 col-md-4 col-xl-2 d-flex gap-2">
                <button type="submit" class="mac-filter-btn w-100">Filter</button>
                <a href="{{ route('admin.audit-trails.index') }}" class="mac-clear-btn">Clear</a>
            </div>
        </form>
    </div>

    <div class="mac-card">
        <div class="table-responsive">
            <table class="mac-table">
                {{-- Five columns, the short ones on one line: eight wrapping columns
                     made every row three or four lines tall. The date opens the row
                     (no separate View column); IP only on a wide screen. Under 768px
                     mac-table turns rows into cards by itself. --}}
                <thead>
                    <tr>
                        <th>When</th>
                        <th style="min-width: 9rem">Who</th>
                        <th>Action</th>
                        <th>Record</th>
                        <th class="d-none d-xl-table-cell">IP address</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($audits as $audit)
                        @php
                            [$badgeClass, $badgeLabel] = $audit->action_badge;
                            $macStatus = match(true) {
                                str_contains($badgeClass, 'success') => 'mac-status-green',
                                str_contains($badgeClass, 'info') => 'mac-status-blue',
                                str_contains($badgeClass, 'warning') => 'mac-status-orange',
                                str_contains($badgeClass, 'danger') => 'mac-status-red',
                                default => 'mac-status-gray',
                            };
                        @endphp
                        <tr>
                            <td class="text-nowrap">
                                <a href="{{ route('admin.audit-trails.show', $audit) }}" class="fw-semibold">{{ $audit->created_at->format('d M Y') }}</a>
                                <div class="mac-muted mac-text-sm">{{ $audit->created_at->format('H:i:s') }}</div>
                            </td>
                            <td>
                                @if($audit->performer)
                                    {{ $audit->performer->name ?? '—' }}
                                    <div class="mac-muted mac-text-sm">{{ class_basename($audit->performer_type) }}</div>
                                @else
                                    <span class="mac-muted">System</span>
                                @endif
                            </td>
                            <td class="text-nowrap"><span class="mac-status {{ $macStatus }}"><span class="mac-status-dot"></span>{{ $badgeLabel }}</span></td>
                            <td>
                                {{ $audit->model_label }}
                                <div class="mac-muted mac-text-sm">#{{ $audit->auditable_id ?? '—' }}</div>
                            </td>
                            <td class="mac-muted d-none d-xl-table-cell">{{ $audit->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="mac-empty">
                                    <div class="mac-empty-icon">📋</div>
                                    <div class="mac-empty-text">No audit records found.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $audits->links() }}
    </div>
</div>
@endsection
