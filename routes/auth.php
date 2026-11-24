<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use Illuminate\Support\Facades\Route;

/*
 * NO REGISTRATION ROUTE AND NO EMAIL VERIFICATION.
 *
 * Accounts are created by an admin from the Company screen — one company, one
 * account — and the office types the email, so there is no self-service
 * sign-up to verify an address for.
 *
 * Participants sign in with email + password (owner, 2026-11-24); the first
 * time, the IC is the password and they must choose one (PasswordController).
 * A forgotten password is reset by an emailed link — the ONLY email the app
 * sends. Admins reset each other's passwords from the admin screens.
 *
 * That makes `is_active` the switch that matters rather than `email_verified_at`,
 * and EnforceUserIsActive turns switching it off into an immediate logout rather
 * than a session that quietly keeps working.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    // Limited like the other two ends (LoginRequest, the broker's own throttle);
    // 10 not 6 because the office shares one address.
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('password.store');
});

Route::middleware(['auth', 'user.active'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

// Outside `user.password-set`, which sends everyone here until a password is
// chosen. Not named `password.update` — see below.
Route::middleware(['auth:web', 'user.active'])->group(function () {
    Route::get('password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('password', [PasswordController::class, 'update'])->name('password.change');
});

/*
 * ONE WARNING FOR WHOEVER ADDS A CHANGE-PASSWORD ROUTE HERE LATER, because the
 * mistake it describes shipped once and took six months to surface.
 *
 * A ROUTE NAME THAT RESOLVES BUT DOES NOT WORK IS WORSE THAN NO NAME AT ALL.
 *
 * In the system this repo came from, `auth.php` declared
 *
 *     Route::put('password', [PasswordController::class, 'update'])->name('password.update');
 *
 * behind `auth`, and the GUEST reset-password form pointed at
 * `route('password.update')`. The name resolved, so there was no
 * RouteNotFoundException at render, the page looked right, and it reviewed
 * clean. It failed only on submit — as a 405, in production, to somebody trying
 * to recover an account they were locked out of. A POST from a guest matches
 * nothing, Laravel's checkForAlternateVerbs() finds the PUT, and the request
 * dies as MethodNotAllowedHttpException. Nothing upstream of the submit can see
 * it coming.
 *
 * With no such name declared, the same mistake throws at render on the first
 * page load instead.
 */
