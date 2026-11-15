<?php

use App\Http\Middleware\EnforceUserIsActive;
use App\Models\Admin;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;

/*
 * EnforceUserIsActive enforces `users.is_active` — participants only. Admins
 * have their own column and their own class (EnforceAdminIsActive). So this one
 * must read the WEB guard by name, and this tests that
 * contract directly rather than through a route.
 *
 * Through a route it cannot be tested: `auth:web` on the participant group turns
 * an admin away before the middleware runs, so the two readings never differ
 * there. That makes the named guard defence in depth — and defence in depth with
 * no test is just untested code, which is what this file is for.
 *
 * What the unnamed form does, if it ever reaches an admin: outside production
 * `$admin->is_active` throws (preventAccessingMissingAttributes); in production
 * the attribute is simply absent, which is falsy, so the deactivation branch
 * fires. That branch invalidates the session — and there is only one session, so
 * it ends the ADMIN's — then redirects to the participant login telling them
 * their account has been deactivated.
 */

/**
 * Runs the middleware against a request, returning what it did.
 *
 * The user resolver has to be set by hand and must honour the guard argument.
 * A Request from Request::create() carries NO resolver — Laravel installs one
 * when it builds the real request — so `$request->user('web')` on a bare one
 * returns null whoever is signed in, and every assertion here would read
 * "passed through" without the middleware having looked at anybody. This
 * mirrors what Illuminate\Auth\AuthServiceProvider installs.
 */
function runActiveGuard(): ?Symfony\Component\HttpFoundation\Response
{
    $request = Request::create('/dashboard', 'GET');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn ($guard = null) => auth()->guard($guard)->user());

    return (new EnforceUserIsActive)->handle($request, fn () => new Illuminate\Http\Response('through'));
}

it('lets an active participant through', function () {
    $user = User::factory()->create(['is_active' => true]);
    Company::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    auth()->guard('web')->setUser($user);

    expect(runActiveGuard()->getContent())->toBe('through');
});

it('ejects a participant whose account has been switched off', function () {
    $user = User::factory()->create(['is_active' => false]);
    Company::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    auth()->guard('web')->setUser($user);

    $response = runActiveGuard();

    expect($response->getContent())->not->toBe('through')
        ->and($response->isRedirect(route('login')))->toBeTrue();
});

it('ignores an admin entirely, even a switched-off one: that is EnforceAdminIsActive\'s job', function () {
    // SWITCHED OFF on purpose. Since admins gained `is_active`, an ACTIVE admin
    // would pass through even an unnamed `$request->user()` — the read would
    // find a truthy column — and this test would stop catching that mistake.
    // A switched-off one is what the wrong read would act on: it would sign the
    // admin out and send them to the PARTICIPANT login.
    $admin = Admin::factory()->inactive()->create(['role' => 'admin', 'state' => 'Johor']);

    // The default guard switched to `admin` — which is what an unnamed
    // `$request->user()` would then resolve.
    auth()->shouldUse('admin');
    auth()->guard('admin')->setUser($admin);

    // Nobody is signed in on `web`, so the middleware has nothing to enforce and
    // must pass the request straight through.
    expect(runActiveGuard()->getContent())->toBe('through');

    // And the admin is untouched by THIS middleware: the deactivation branch,
    // had it fired, would have invalidated the one session both guards share.
    expect(auth()->guard('admin')->check())->toBeTrue(
        'The admin was signed out by a middleware that only governs participants.'
    );
});

it('leaves admins to their own middleware, which sends them to the admin login', function () {
    // Both tables carry `is_active` now, each enforced by its own class on its
    // own guard. The participant one must never be the one that answers an admin.
    expect(Schema::hasColumn('users', 'is_active'))->toBeTrue()
        ->and(Schema::hasColumn('admins', 'is_active'))->toBeTrue()
        // The admin routes carry the admin one; the participant routes do not.
        ->and(app('router')->getRoutes()->getByName('admin.dashboard')->gatherMiddleware())->toContain('admin.active')
        ->and(app('router')->getRoutes()->getByName('dashboard')->gatherMiddleware())->not->toContain('admin.active');
});
