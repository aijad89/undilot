<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\AddedToDraw;
use App\Notifications\RemovedFromDraw;
use App\Notifications\VotingCloseReminder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/*
 * THE ONLY EMAIL THE APP SENDS IS THE PASSWORD-RESET LINK (owner, 2026-11-24,
 * replacing "no email at all" of 2026-11-17).
 *
 * Participants hold an email — their username, set by the office — and a
 * forgotten password is reset through it. Every notification stays in-app.
 * Officers have no email: username + password, reset by another admin.
 */

dataset('participant notifications', [
    'added to draw' => [fn (Event $e) => new AddedToDraw($e)],
    'removed from draw' => [fn (Event $e) => new RemovedFromDraw($e, 'Duplicate entry')],
    'voting close reminder' => [fn (Event $e) => new VotingCloseReminder($e)],
]);

it('keeps an email on participants only, and the reset-token table', function () {
    expect(Schema::hasColumn('users', 'email'))->toBeTrue('users.email missing')
        ->and(Schema::hasColumn('admins', 'email'))->toBeFalse('admins.email exists')
        ->and(Schema::hasColumn('admins', 'username'))->toBeTrue('admins.username missing')
        ->and(Schema::hasTable('password_reset_tokens'))->toBeTrue('password_reset_tokens missing');
});

it('sends each notification in-app only', function (Closure $make) {
    $notification = $make(Event::factory()->create());

    expect($notification->via(User::factory()->create()))->toBe(['database'])
        ->and(method_exists($notification, 'toMail'))->toBeFalse();
})->with('participant notifications');

it('has no notification class of its own that can mail, including any added later', function () {
    $classes = collect(glob(app_path('Notifications/*.php')))
        ->map(fn ($path) => 'App\\Notifications\\'.basename($path, '.php'));

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        expect(method_exists($class, 'toMail'))->toBeFalse("{$class} has toMail()");
    }
});

it('tells a company it is in the draw in-app, not by mail', function () {
    Notification::fake();
    $admin = superAdmin();
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $registration = EventRegistration::factory()->create([
        'event_id' => $event->id, 'company_id' => $company->id, 'user_id' => $user->id,
        'verified_at' => null, 'first_verified_at' => null,
    ]);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), ['code' => 'TBPS', 'plot_size' => '3x3'])
        ->assertRedirect();

    Notification::assertSentTo($user, AddedToDraw::class, fn ($n, array $channels) => $channels === ['database']);
});

it('mails the reset link to the email on record, and says the same for an unknown one', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'siti@kraf.test']);
    $said = 'If that email is on record, a link to reset your password has been sent to it.';

    $this->post(route('password.email'), ['email' => ' Siti@Kraf.test'])->assertSessionHas('status', $said);
    Notification::assertSentTo($user, ResetPassword::class, fn ($n, array $channels) => $channels === ['mail']);

    // Nobody: the same words, so the form does not say which emails hold an account.
    $this->post(route('password.email'), ['email' => 'nobody@kraf.test'])->assertSessionHas('status', $said);
    Notification::assertCount(1);
});

it('sets a reset password as chosen, so the IC no longer signs in', function () {
    Notification::fake();
    $user = User::factory()->firstSignIn()->create(['email' => 'siti@kraf.test', 'ic_number' => '890508-12-0000']);

    $this->post(route('password.email'), ['email' => 'siti@kraf.test']);
    Notification::assertSentTo($user, ResetPassword::class, function ($n) use ($user) {
        $this->post(route('password.store'), [
            'token' => $n->token, 'email' => 'siti@kraf.test',
            'password' => 'batik2026', 'password_confirmation' => 'batik2026',
        ])->assertRedirect(route('login'));

        return true;
    });

    expect($user->fresh()->password_changed_at)->not->toBeNull();
    $this->post(route('login'), ['email' => 'siti@kraf.test', 'password' => '890508120000'])->assertSessionHasErrors('email');
    $this->post(route('login'), ['email' => 'siti@kraf.test', 'password' => 'batik2026'])->assertRedirect(route('dashboard'));
});

it('asks no officer for an email', function () {
    $super = Admin::factory()->super()->create();

    $html = $this->get(route('admin.login'))->assertOk()->getContent();
    expect(preg_match('/name="email"|type="email"/', $html))->toBe(0, 'the admin login asks for an email');

    $html = $this->actingAs($super, 'admin')->get(route('admin.admins.create', ['group' => 'state']))->assertOk()->getContent();
    expect(preg_match('/name="email"|type="email"/', $html))->toBe(0, 'the admin form asks for an email');
});
