@extends('layouts.admin')

@section('title', 'Sales')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    <div class="mac-header mb-4">
        <h2>Sales</h2>
        <p class="mac-muted mb-0">The sales kept from each event's uploaded sales book. Open an event to see its tables.</p>
    </div>

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
@endsection
