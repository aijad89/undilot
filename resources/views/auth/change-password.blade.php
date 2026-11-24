{{-- Inside the portal, not the sign-in layout: the participant is already
     signed in, and a page that looks like the login form reads as "it did
     not work, sign in again". On the first sign-in the bar has no Dashboard
     or Profile (portal-bar) — EnsurePasswordChanged would only send them back. --}}
@extends('layouts.frontend')

@section('title', $first ? 'Choose your password' : 'Change password')

@section('content')
@include('partials.apple-styles')
<style>
    .cp-wrap { max-width: 560px; }
    .cp-who { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; padding: 12px 14px; background: var(--ui-surface-2); border-radius: var(--ui-r); }
    .cp-who i { font-size: 1.5rem; color: var(--ui-blue); }
    .cp-who strong { display: block; }
    .cp-steps { margin: 0 0 18px; padding-left: 1.2rem; color: var(--ui-text-2); }
    .cp-steps li + li { margin-top: 4px; }
    .cp-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
</style>

<div class="ap-page cp-wrap">
    <div class="ap-page-header">
        <div>
            <h2 class="ap-page-title">{{ $first ? 'Choose your password' : 'Change password' }}</h2>
            <p class="ap-page-subtitle">
                @if($first)
                    You are signed in. One more step before your dashboard.
                @else
                    Your account
                @endif
            </p>
        </div>
    </div>

    @if($first)
        <div class="ap-alert ap-alert-success" role="status">
            <i class="bi bi-check-circle ap-alert-icon" aria-hidden="true"></i>
            <div>Signed in with your IC number. For your safety, choose your own password now — the IC number will not work again.</div>
        </div>
    @endif

    <section class="ap-card ap-card-padded">
        <div class="cp-who">
            <i class="bi bi-person-circle" aria-hidden="true"></i>
            <div>
                <strong>{{ auth('web')->user()->name }}</strong>
                <span class="ap-muted">{{ auth('web')->user()->email }}</span>
            </div>
        </div>

        @if($first)
            <ol class="cp-steps">
                <li>Type a new password: at least 8 characters, with letters and numbers.</li>
                <li>Type it again to confirm.</li>
                <li>Next time, sign in with your email and this password.</li>
            </ol>
        @endif

        <form method="POST" action="{{ route('password.change') }}">
            @csrf
            @method('PUT')

            @unless($first)
                <div class="ap-field">
                    <label class="ap-label" for="current_password">Current password</label>
                    <input class="ap-input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    @error('current_password')<p class="ap-field-error">{{ $message }}</p>@enderror
                </div>
            @endunless

            <div class="ap-field">
                <label class="ap-label" for="password">New password</label>
                <input class="ap-input" type="password" id="password" name="password" autocomplete="new-password" required>
                @unless($first)<p class="ap-field-hint">At least 8 characters, with letters and numbers.</p>@endunless
                @error('password')<p class="ap-field-error">{{ $message }}</p>@enderror
            </div>

            <div class="ap-field">
                <label class="ap-label" for="password_confirmation">Confirm new password</label>
                <input class="ap-input" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required>
            </div>

            <div class="cp-actions">
                <button type="submit" class="ap-btn ap-btn-primary">Save password</button>
                @unless($first)
                    <a href="{{ route('profile') }}" class="ap-btn ap-btn-secondary">Cancel</a>
                @endunless
            </div>
        </form>
    </section>
</div>
@endsection
