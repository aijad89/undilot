{{-- Chrome-less, like auth/login: a participant held here by
     EnsurePasswordChanged has no portal to see yet. --}}
@extends('layouts.user-auth')

@section('title', 'Choose a password')

@section('content')
<div style="width: 100%;">
    <div class="mb-4">
        <h2 class="fw-bold mb-1">{{ $first ? 'Choose your password' : 'Change password' }}</h2>
        <p class="text-muted mb-0" style="font-size: 0.875rem;">
            @if($first)
                You signed in with your IC number. Choose a password of your own to continue — the IC will not work again.
            @else
                At least 8 characters, with letters and numbers.
            @endif
        </p>
    </div>

    <form method="POST" action="{{ route('password.change') }}">
        @csrf
        @method('PUT')

        @unless($first)
            <div class="mb-3">
                <label for="current_password" class="form-label fw-semibold">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required
                       class="form-control form-control-lg @error('current_password') is-invalid @enderror">
                @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endunless

        <div class="mb-3">
            <label for="password" class="form-label fw-semibold">New password</label>
            <input type="password" id="password" name="password" autocomplete="new-password" required
                   class="form-control form-control-lg @error('password') is-invalid @enderror">
            <div class="form-text">At least 8 characters, with letters and numbers.</div>
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-semibold">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password" required
                   class="form-control form-control-lg">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">Save password</button>
        </div>
    </form>

    @unless($first)
        <p class="text-center mt-3 mb-0"><a href="{{ route('profile') }}" class="text-decoration-none small">← Back to profile</a></p>
    @endunless

    <form method="POST" action="{{ route('logout') }}" class="text-center mt-3">
        @csrf
        <button type="submit" class="btn btn-link text-decoration-none small">Sign out</button>
    </form>
</div>
@endsection
