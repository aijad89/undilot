<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * An admin running the portal as one of their participants (owner,
 * 2026-11-24). The two guards share one session, so the admin stays signed
 * in on `admin` while `web` holds the participant: every write made meanwhile
 * is recorded under the ADMIN (AuditTrail::record() asks the admin guard
 * first), and the start and end are recorded too.
 *
 * Everything the participant can do, the admin can — except choose the
 * participant's password (PasswordController, EnsurePasswordChanged).
 */
final class Impersonation
{
    private const KEY = 'impersonated_by';

    public static function start(Request $request, Admin $admin, User $user): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(self::KEY, $admin->id);

        AuditTrail::record('impersonation_started', $user, null, ['admin_id' => $admin->id]);
    }

    /** True only while the admin who started it is still the admin signed in. */
    public static function active(): bool
    {
        $by = session(self::KEY);

        return $by !== null && Auth::guard('admin')->id() === $by && Auth::guard('web')->check();
    }

    /**
     * Back to the admin alone. logoutCurrentDevice(), not logout(): logout()
     * rotates the participant's remember token, which would sign them out of
     * their own devices because an admin looked.
     */
    public static function end(Request $request): ?User
    {
        $user = Auth::guard('web')->user();
        if ($user) {
            AuditTrail::record('impersonation_ended', $user);
        }

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->forget(self::KEY);
        $request->session()->regenerateToken();

        return $user;
    }
}
