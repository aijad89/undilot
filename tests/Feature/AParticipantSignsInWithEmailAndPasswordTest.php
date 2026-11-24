<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
 * Participants sign in with EMAIL + PASSWORD (owner, 2026-11-24). The email is
 * set by the office. The first time — or after the office resets them — the IC
 * is the password, and they are held on the change-password page until they
 * choose one; after that the IC no longer works.
 */

function firstTimer(array $attributes = []): User
{
    $user = User::factory()->firstSignIn()->create($attributes + ['email' => 'siti@kraf.test', 'ic_number' => '890508-12-0000']);
    Company::factory()->withProfile()->create(['user_id' => $user->id]);

    return $user;
}

function signInWith(string $email, string $password)
{
    return test()->post(route('login'), ['email' => $email, 'password' => $password]);
}

it('signs in the first time with the IC, however it is written, and holds them on the password page', function (string $email, string $ic) {
    $user = firstTimer();

    signInWith($email, $ic)->assertRedirect(route('dashboard'));
    expect(auth('web')->id())->toBe($user->id);

    // Nothing else opens until a password is chosen.
    $this->get(route('dashboard'))->assertRedirect(route('password.edit'));
    $this->get(route('profile'))->assertRedirect(route('password.edit'));
    $this->get(route('password.edit'))->assertOk()->assertSee('Choose your password')->assertDontSee('name="current_password"', false);
})->with([
    'as stored' => ['siti@kraf.test', '890508-12-0000'],
    'IC as bare digits' => ['siti@kraf.test', '890508120000'],
    'email padded and in other case' => ['  Siti@Kraf.TEST ', '890508-12-0000'],
]);

it('takes the chosen password from then on, and no longer the IC', function () {
    $user = firstTimer();
    signInWith('siti@kraf.test', '890508120000');

    // The IC itself is not a password: letters and numbers are required.
    $this->put(route('password.change'), ['password' => '890508120000', 'password_confirmation' => '890508120000'])
        ->assertSessionHasErrors('password');
    $this->put(route('password.change'), ['password' => 'batik2026', 'password_confirmation' => 'batik2026'])
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->password_changed_at)->not->toBeNull()
        ->and(Hash::check('batik2026', $user->fresh()->password))->toBeTrue();
    $this->get(route('dashboard'))->assertOk();

    auth('web')->logout();
    signInWith('siti@kraf.test', '890508-12-0000')->assertSessionHasErrors(['email' => 'That email and password do not match an account.']);
    signInWith('siti@kraf.test', 'batik2026')->assertRedirect(route('dashboard'));
});

it('asks for the current password to change it later', function () {
    $user = User::factory()->create(['password' => 'batik2026']);
    $this->actingAs($user, 'web');

    $this->get(route('password.edit'))->assertSee('name="current_password"', false);
    $this->put(route('password.change'), ['current_password' => 'wrong123', 'password' => 'songket99', 'password_confirmation' => 'songket99'])
        ->assertSessionHasErrors(['current_password' => 'That is not your current password.']);
    $this->put(route('password.change'), ['current_password' => 'batik2026', 'password' => 'songket99', 'password_confirmation' => 'songket99'])
        ->assertSessionHasNoErrors();
    expect(Hash::check('songket99', $user->fresh()->password))->toBeTrue();
});

it('refuses a wrong password, an unknown email, and an account with no IC on its first sign-in', function () {
    firstTimer();
    signInWith('siti@kraf.test', '000000-00-0000')->assertSessionHasErrors('email');
    signInWith('nobody@kraf.test', '890508-12-0000')->assertSessionHasErrors('email');

    User::factory()->firstSignIn()->withoutIc()->create(['email' => 'noic@kraf.test']);
    signInWith('noic@kraf.test', '')->assertSessionHasErrors('password');
    signInWith('noic@kraf.test', 'anything1')->assertSessionHasErrors('email');

    expect(auth('web')->check())->toBeFalse();
});

it('refuses a switched-off account, or a switched-off company, with the reason', function () {
    firstTimer(['is_active' => false]);
    signInWith('siti@kraf.test', '890508-12-0000')
        ->assertSessionHasErrors(['email' => 'Your account has been deactivated. Please contact the administrator.']);

    $other = User::factory()->create(['email' => 'ros@kraf.test', 'password' => 'tenun2026']);
    Company::factory()->create(['user_id' => $other->id, 'is_active' => false]);
    signInWith('ros@kraf.test', 'tenun2026')
        ->assertSessionHasErrors(['email' => 'Your company has been deactivated. Please contact the administrator.']);

    expect(auth('web')->check())->toBeFalse();
});

it('stops guessing after five wrong tries on one email', function () {
    firstTimer();

    foreach (range(1, 5) as $n) {
        signInWith('siti@kraf.test', "wrong{$n}");
    }

    signInWith('siti@kraf.test', '890508-12-0000')->assertSessionHasErrors('email');
    expect(auth('web')->check())->toBeFalse();
});

it('asks for email and password, and offers the reset link', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('Your password is your IC number')
        ->assertSee(route('password.request'), false)
        ->assertDontSee('name="community_id"', false);
});

it('has the office set the email, and only the office', function () {
    $this->actingAs(superAdmin(), 'admin');

    $this->post(route('admin.companies.store'), [
        'name' => 'Kraf Emel', 'state' => 'Johor', 'owner_name' => 'Aminah',
        'ic_number' => '900101-01-2222', 'community_id' => 'KOM-0003',
    ])->assertSessionHasErrors('email');

    $this->post(route('admin.companies.store'), [
        'name' => 'Kraf Emel', 'state' => 'Johor', 'owner_name' => 'Aminah',
        'ic_number' => '900101-01-2222', 'community_id' => 'KOM-0003', 'email' => ' Aminah@Kraf.TEST',
    ])->assertSessionHasNoErrors();

    $user = User::where('community_id', 'KOM-0003')->sole();
    expect($user->email)->toBe('aminah@kraf.test')
        ->and($user->password_changed_at)->toBeNull();

    // Taken by another account: refused.
    $this->post(route('admin.companies.store'), [
        'name' => 'Kraf Dua', 'state' => 'Johor', 'owner_name' => 'Ros',
        'ic_number' => '900101-01-3333', 'community_id' => 'KOM-0004', 'email' => 'aminah@kraf.test',
    ])->assertSessionHasErrors('email');

    // The participant's own account form does not take it.
    auth()->forgetGuards();
    $user->forceFill(['password_changed_at' => now()])->save();
    $this->actingAs($user->fresh(), 'web');
    $this->put(route('profile.account'), ['name' => 'Kraf Emel', 'owner_name' => 'Aminah', 'email' => 'mine@kraf.test']);
    expect($user->fresh()->email)->toBe('aminah@kraf.test');
});

it('lets the office reset a participant back to the IC', function () {
    $user = User::factory()->create(['email' => 'siti@kraf.test', 'password' => 'batik2026', 'ic_number' => '890508-12-0000']);
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Perak']);

    // Not another zone's admin.
    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.companies.reset-password', $company))->assertForbidden();
    expect($user->fresh()->password_changed_at)->not->toBeNull();

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))->assertSee('Reset password to IC');
    $this->post(route('admin.companies.reset-password', $company))->assertSessionHas('success');
    expect($user->fresh()->password_changed_at)->toBeNull();

    auth()->forgetGuards();
    signInWith('siti@kraf.test', 'batik2026')->assertSessionHasErrors('email');
    signInWith('siti@kraf.test', '890508120000')->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertRedirect(route('password.edit'));
});
