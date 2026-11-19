@extends('layouts.frontend')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@include('partials.apple-styles')

<div class="ap-page ap-dash">
    <div class="ap-page-header">
        <div>
            <h2 class="ap-page-title">Welcome, {{ $user->name }}</h2>
            <p class="ap-page-subtitle">
                @if($company)
                    {{ $company->name }}@if($company->state) · {{ $company->state }}@endif
                @else
                    Your account has no company yet. Contact the Kraftangan office to have it added.
                @endif
            </p>
        </div>
    </div>

    {{-- What is waiting on them, most pressing first, each with its one next step. --}}
    <section class="ap-card ap-card-padded ap-todo" aria-labelledby="dash-todo">
        <h2 class="ap-section-title" id="dash-todo">What to do now</h2>
        @foreach($todo as [$tone, $text, $link, $go])
            @if($link)
                <a href="{{ $link }}" class="ap-todo-row ap-todo-{{ $tone }}">
                    <i class="bi {{ $tone === 'bad' ? 'bi-exclamation-octagon' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>
                    <span class="ap-todo-text">{{ $text }}</span>
                    <span class="ap-todo-go">{{ $go }} <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
                </a>
            @else
                <p class="ap-todo-row ap-todo-{{ $tone }}">
                    <i class="bi {{ $tone === 'bad' ? 'bi-exclamation-octagon' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>
                    <span class="ap-todo-text">{{ $text }}</span>
                </p>
            @endif
        @endforeach
        @if($unreadNotificationCount > 0)
            <a href="{{ route('notifications.index') }}" class="ap-todo-row ap-todo-info">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="ap-todo-text">You have {{ $unreadNotificationCount }} unread {{ \Illuminate\Support\Str::plural('notification', $unreadNotificationCount) }} from the office.</span>
                <span class="ap-todo-go">Read <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
            </a>
        @endif
        @if(! $todo && ! $unreadNotificationCount)
            <p class="ap-todo-row ap-todo-ok">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
                <span class="ap-todo-text">Nothing is waiting on you right now.</span>
            </p>
        @endif
    </section>

    <section class="ap-dash-main">
        <h2 class="ap-section-title">Your lot draws</h2>
        @if($entries->isEmpty())
            <div class="ap-card ap-empty">
                <i class="bi bi-grid-3x3-gap ap-empty-icon" aria-hidden="true"></i>
                <div class="ap-empty-title">You are not in a lot draw yet</div>
                <p class="ap-empty-text mb-0">The office puts your company on an event's draw list. Once it does, the event appears here with its lot draw.</p>
            </div>
        @endif
        <div class="ap-draw-grid">
            @foreach($entries as $entry)
                @php
                    $event = $entry->event;
                    $hasDrawn = isset($drawn[$event->id]);
                    $open = $event->polling_status === 'polling' && ! $event->polling_deadline?->isPast();
                @endphp
                <article class="ap-card ap-draw mb-3">
                    <div class="ap-draw-head">
                        <div>
                            <h3 class="ap-draw-title">{{ $event->title }}</h3>
                            <div class="ap-draw-meta">
                                <span><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $event->location }}</span>
                                <span><i class="bi bi-calendar3" aria-hidden="true"></i>{{ $event->start_date->format('d M Y') }}</span>
                            </div>
                        </div>
                        @if($hasDrawn)
                            <span class="ap-badge ap-badge-green">Lot confirmed</span>
                        @endif
                    </div>

                    @include('partials.draw-stepper', ['status' => $event->polling_status, 'labels' => \App\Models\Event::PARTICIPANT_STAGE_LABELS, 'stops' => \App\Models\Event::PARTICIPANT_STAGES])

                    <div class="ap-draw-next">
                        {{-- A drawn lot is named once, on its own link. --}}
                        @unless($hasDrawn && $entry->plot)
                        <span>
                            @if($open)
                                The draw is open until {{ $event->polling_deadline->format('d M Y, h:i A') }}.
                            @elseif($event->polling_status === 'draft' && $event->polling_start_date)
                                The draw opens {{ $event->polling_start_date->format('d M Y, h:i A') }}.
                            @elseif($event->polling_status === 'draft')
                                You are in the draw. The draw date has not been set yet.
                            @elseif($entry->plot)
                                Your lot: <strong>{{ $entry->plot->plot_code }}</strong>
                            @else
                                The draw has closed. The office will assign your lot.
                            @endif
                        </span>
                        @endunless

                        @if($hasDrawn && $entry->plot)
                            {{-- Says what the button is; the lot number itself stays on the button. --}}
                            <span>Congratulations, you have your lot. Your lot number for this event is:</span>
                            <a href="{{ route('zvote.index', $event) }}" class="ap-btn ap-btn-outline ap-draw-lot-link">
                                <i class="bi bi-geo-alt-fill" aria-hidden="true"></i>Lot <strong class="ms-1">{{ $entry->plot->plot_code }}</strong>
                            </a>
                        @elseif($open)
                            <a href="{{ route('zvote.index', $event) }}" class="ap-btn ap-btn-primary ap-btn-lg">
                                <i class="bi bi-check-circle" aria-hidden="true"></i> Confirm Lot
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </section>
</div>
@endsection
