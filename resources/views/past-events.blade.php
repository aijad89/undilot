@extends('layouts.frontend')

@section('title', 'Past events')
@section('page-title', 'Past events')

@section('content')
@include('partials.apple-styles')

<div class="ap-page ap-dash">
    <div class="ap-page-header">
        <div>
            <h2 class="ap-page-title">Past events</h2>
            <p class="ap-page-subtitle">The events your company took part in that have ended, latest first, with the lot it had.</p>
        </div>
    </div>

    <section class="ap-card ap-table-card mb-4" aria-label="Past events">
        @if($entries->isEmpty())
            <div class="ap-empty">
                <i class="bi bi-clock-history ap-empty-icon" aria-hidden="true"></i>
                <div class="ap-empty-title">No past events yet</div>
                <p class="ap-empty-text mb-0">Once an event your company took part in has ended, it is listed here with the lot you had.</p>
            </div>
        @else
            {{-- Wide on a desk, so it scrolls inside its card rather than the page. --}}
            <div class="table-responsive">
            <table class="ap-table ap-table-stack ap-past-table">
                <thead>
                    <tr>
                        <th scope="col">Event</th>
                        <th scope="col">Booth</th>
                        <th scope="col">Lot</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($entries as $entry)
                        @php
                            $event = $entry->event;
                        @endphp
                        <tr>
                            <td data-label="Event">
                                {{-- Its summary, in a new tab, as the slip opens. --}}
                                <a href="{{ route('past-events.show', $event) }}" target="_blank" rel="noopener">{{ $event->title }}<span class="visually-hidden"> (summary, opens in a new tab)</span></a>
                                <span class="ap-past-meta ap-tabular">
                                    {{ $event->start_date->format('d M Y') }}@unless($event->end_date->isSameDay($event->start_date)) – {{ $event->end_date->format('d M Y') }}@endunless
                                    @if($event->location) · {{ $event->location }}@endif
                                </span>
                            </td>
                            <td data-label="Booth" class="ap-tabular">{{ $entry->plot_size }}</td>
                            <td data-label="Lot">
                                @if($entry->plot)
                                    {{-- One unit, so a phone's label-and-value line keeps them together. --}}
                                    <span><span class="ap-lot-code">{{ $entry->plot->plot_code }}</span>@if(isset($drawn[$event->id])) · <a href="{{ route('zvote.slip', $event) }}" target="_blank" rel="noopener">Slip<span class="visually-hidden"> (PDF, opens in a new tab)</span></a>@endif</span>
                                @else
                                    <span class="ap-muted">No lot</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </section>
</div>
@endsection
