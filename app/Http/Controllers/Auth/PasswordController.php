<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The participant chooses a password: on their first sign-in (or after the
 * office reset them to their IC) without the current one — they have just
 * typed it — and afterwards with it. The IC cannot be the new one.
 */
class PasswordController extends Controller
{
    public function edit(Request $request)
    {
        // A rule, not an error: back inside the portal, with the reason.
        if (\App\Support\Impersonation::active()) {
            return redirect()->route('profile')->with('error', 'Only the participant can change their password.');
        }

        return view('auth.change-password', ['first' => $request->user('web')->password_changed_at === null]);
    }

    public function update(Request $request)
    {
        // A rule, not an error: back inside the portal, with the reason.
        if (\App\Support\Impersonation::active()) {
            return redirect()->route('profile')->with('error', 'Only the participant can change their password.');
        }

        $user = $request->user('web');
        $first = $user->password_changed_at === null;

        $data = $request->validate([
            'current_password' => $first ? ['exclude'] : ['required', 'current_password:web'],
            // Letters and numbers (AppServiceProvider), so the IC — digits only — never passes.
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => 'That is not your current password.',
        ]);

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'password_changed_at' => now(),
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Password saved. Sign in with it from now on.');
    }
}
