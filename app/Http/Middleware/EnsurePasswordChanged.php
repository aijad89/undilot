<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Holds a participant on the change-password page until they have chosen a
 * password. Until then their IC is the password (LoginRequest), and the IC is
 * no secret — so nothing else in the portal opens first.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next)
    {
        // An admin viewing as the participant is not held: the password is the participant's to choose.
        if ($request->user('web') && $request->user('web')->password_changed_at === null && ! \App\Support\Impersonation::active()) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
