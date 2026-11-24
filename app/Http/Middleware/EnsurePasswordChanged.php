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
        if ($request->user('web')?->password_changed_at === null && $request->user('web')) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
