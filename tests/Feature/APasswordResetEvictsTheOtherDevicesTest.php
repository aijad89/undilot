<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/*
 * A password reset must throw the other devices out. That is the point of
 * resetting it: whoever else holds the account is holding a live session, and a
 * new password they do not know evicts nobody by itself.
 *
 * THIS LOGIC RAN IN NO TEST BEFORE THIS FILE. It evicts by deleting rows from
 * the `sessions` table and refuses to try on any other driver — and phpunit.xml
 * sets SESSION_DRIVER=array for the suite, so every test took the early return.
 * The whole eviction path was unreachable from the test suite by construction,
 * which is the state the controller's own comment describes: "no error and no
 * failing test".
 *
 * So these tests set the driver to `database` themselves, and plant real session
 * rows in the shape Laravel stores them.
 */

/** A session row in the shape Laravel's database driver writes. */
function plantSession(string $guardKey, int $userId, ?string $id = null): string
{
    $id = $id ?? Str::random(40);

    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Ujian',
        // Base64 of a serialized array, which is what the driver stores. The login
        // key is `login_<guard>_<sha1 of the provider class>`; only the prefix
        // separates a portal session from an admin one.
        'payload' => base64_encode(serialize([
            '_token' => Str::random(40),
            $guardKey.'_'.sha1('SomeProviderClass') => $userId,
        ])),
        'last_activity' => now()->timestamp,
    ]);

    return $id;
}

/** Walks the real reset flow, token and all. */
function resetPasswordFor(User $user, $test): void
{
    Notification::fake();

    $test->post(route('password.email'), ['email' => $user->email]);

    $token = null;

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    $test->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'kraf12345',
        'password_confirmation' => 'kraf12345',
    ]);
}

beforeEach(function () {
    // The suite runs on the `array` driver, where the eviction refuses to act.
    config(['session.driver' => 'database']);
});

it('deletes the other portal sessions when the password is reset', function () {
    $user = User::factory()->create(['email' => 'peserta@contoh.test']);

    $a = plantSession('login_web', $user->id);
    $b = plantSession('login_web', $user->id);

    expect(DB::table('sessions')->count())->toBe(2);

    resetPasswordFor($user, $this);

    expect(Password::getRepository()->exists($user, 'x'))->toBeFalse();

    expect(DB::table('sessions')->whereIn('id', [$a, $b])->count())
        ->toBe(0, 'The other devices are still signed in after a password reset.');
});

it('leaves another participant alone', function () {
    $user = User::factory()->create(['email' => 'peserta@contoh.test']);
    $other = User::factory()->create(['email' => 'lain@contoh.test']);

    $mine = plantSession('login_web', $user->id);
    $theirs = plantSession('login_web', $other->id);

    resetPasswordFor($user, $this);

    expect(DB::table('sessions')->where('id', $mine)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $theirs)->exists())
        ->toBeTrue('Another participant was signed out by somebody else resetting their password.');
});

it('leaves an admin session alone even when the ids collide', function () {
    $user = User::factory()->create(['email' => 'peserta@contoh.test']);

    // `sessions.user_id` carries the id with NO guard beside it, so an admin
    // whose id happens to equal a participant's is indistinguishable by that
    // column alone. The payload prefix — login_admin_ against login_web_ — is
    // what separates them, which is why the eviction reads it.
    Admin::factory()->create(['role' => 'admin', 'state' => 'Johor']);

    $portal = plantSession('login_web', $user->id);
    $adminSession = plantSession('login_admin', $user->id);

    resetPasswordFor($user, $this);

    expect(DB::table('sessions')->where('id', $portal)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', $adminSession)->exists())
        ->toBeTrue('An admin session was deleted by a participant password reset.');
});

it('does not delete a row whose payload it cannot read', function () {
    $user = User::factory()->create(['email' => 'peserta@contoh.test']);

    DB::table('sessions')->insert([
        'id' => 'rosak',
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => 'bukan base64 bukan serialized',
        'last_activity' => now()->timestamp,
    ]);

    resetPasswordFor($user, $this);

    // A payload this cannot parse is a row it must not delete — guessing would
    // sign somebody out on the strength of an unreadable blob.
    expect(DB::table('sessions')->where('id', 'rosak')->exists())->toBeTrue();
});

it('ships a template that starts a new install on the driver this needs', function () {
    // The eviction above only works on the `database` driver, and a new install
    // begins by copying .env.example. Laravel's own stock template says
    // SESSION_DRIVER=database too, so this is not guarding against the
    // framework — it guards against somebody trimming this line while tidying,
    // which would cost the next install its password-reset eviction and say
    // nothing about it.
    $template = file_get_contents(base_path('.env.example'));

    expect($template)->toContain('SESSION_DRIVER=database');
});

it('says so in the log rather than silently doing nothing on another driver', function () {
    config(['session.driver' => 'array']);

    $user = User::factory()->create(['email' => 'peserta@contoh.test']);
    $kept = plantSession('login_web', $user->id);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'did not evict other devices'));

    resetPasswordFor($user, $this);

    // The row survives, which is the whole hazard: the reset reports success
    // while the other device stays signed in. The warning is the only signal
    // anybody gets, so it has to be emitted.
    expect(DB::table('sessions')->where('id', $kept)->exists())->toBeTrue();
});
