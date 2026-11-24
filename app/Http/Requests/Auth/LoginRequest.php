<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The participant sign-in: EMAIL + PASSWORD (owner, 2026-11-24 — replacing
 * community ID + IC, whose values were both printed on the lot slip).
 *
 * The email is the username. It is set by the office only, and the slip does
 * not carry it. Until the participant has chosen a password
 * (`password_changed_at` NULL — a new account, or one the office reset) the IC
 * is the password, compared as digits; EnsurePasswordChanged then holds them
 * on the change-password page until they choose one. After that only the
 * chosen password works, and a forgotten one is reset by an emailed link.
 *
 * Admins (officers) sign in at /admin/login with username + password.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = $this->matchingUser();

        if ($user === null) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'That email and password do not match an account.',
            ]);
        }

        // Checked AFTER the match, as the admin login does: only someone who
        // already holds the password learns the account exists but is off.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Your account has been deactivated. Please contact the administrator.',
            ]);
        }

        if ($user->company && ! $user->company->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Your company has been deactivated. Please contact the administrator.',
            ]);
        }

        Auth::guard('web')->login($user, $this->boolean('remember'));

        RateLimiter::clear($this->throttleKey());
    }

    /** The account with this email whose password (or, before one is chosen, IC) matches. */
    private function matchingUser(): ?User
    {
        $email = self::email($this->input('email'));
        $password = (string) $this->input('password');
        $user = $email === '' ? null : User::where('email', $email)->first();

        if (! $user) {
            return null;
        }

        if ($user->password_changed_at === null) {
            $ic = self::digits($user->ic_number);

            return $ic !== '' && hash_equals($ic, self::digits($password)) ? $user : null;
        }

        return Hash::check($password, $user->password) ? $user : null;
    }

    /** As stored: trimmed, lower-case. */
    public static function email(mixed $value): string
    {
        return Str::lower(trim((string) $value));
    }
    private static function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    /**
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Per email and per address. The password is left out on purpose: keyed
     * on it, every wrong guess would start a fresh counter.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim((string) $this->input('email'))).'|'.$this->ip());
    }
}
