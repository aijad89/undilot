@extends('layouts.user-auth')

@section('title', 'Login')

@section('content')
<div style="width: 100%;">
    <div class="mb-4">
        <h2 class="fw-bold mb-1">Welcome back</h2>
        <p class="text-muted mb-0" style="font-size: 0.875rem;">Sign in to your account</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Email + password (LoginRequest). The first time, the IC is the
             password; the next page asks for a new one. --}}
        <div class="mb-3">
            <label for="email" class="form-label fw-semibold">Email</label>
            <input type="email"
                   id="email"
                   name="email"
                   class="form-control form-control-lg @error('email') is-invalid @enderror"
                   value="{{ old('email') }}"
                   autocomplete="username"
                   required autofocus>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-semibold">Password</label>
            <input type="password"
                   id="password"
                   name="password"
                   class="form-control form-control-lg @error('password') is-invalid @enderror"
                   autocomplete="current-password"
                   required>
            <div class="form-text">First time signing in? Your password is your IC number.</div>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remember_me" name="remember">
                <label class="form-check-label text-muted" for="remember_me">Remember me</label>
            </div>
            <a href="{{ route('password.request') }}" class="text-decoration-none small">Forgot password?</a>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
        </div>
    </form>

    {{-- Only where /auto is registered (local, testing, e2e) — never on a server. --}}
    @if(Route::has('dev.auto'))
        <div class="d-grid mt-2">
            <a href="{{ route('dev.auto') }}" class="btn btn-outline-secondary">Quick sign in (dev only)</a>
        </div>
    @endif
</div>
@endsection
