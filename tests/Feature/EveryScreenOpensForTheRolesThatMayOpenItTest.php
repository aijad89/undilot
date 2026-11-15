<?php

use Tests\Support\Screens;

/*
 * A sweep: open every GET screen as each role that may open it, and fail on a
 * 500.
 *
 * The suite around this one tests behaviour deeply, screen by screen, which
 * leaves one gap this closes: a screen that no test happens to name is found
 * broken only when somebody browses to it. The failures this catches are the
 * cheap, whole-screen kind — a renamed route a view still calls, a variable a
 * composer no longer supplies, a method deleted from a controller.
 *
 * WHAT COUNTS AS PASSING is deliberately loose: anything that is not a 500. A
 * redirect is an answer, a 403 is an answer, a 404 on a row this test did not
 * build is an answer. Only "the screen threw" is a failure. Tightening this to
 * assertOk() would make the sweep assert authorisation badly — which the
 * dedicated tests already assert well — and it would break every time a screen
 * legitimately redirects.
 *
 * ROUTE DISCOVERY IS LIVE, not a list, which is the point: a screen added
 * tomorrow is swept tomorrow without anybody remembering to add it here. A
 * hardcoded list would have exactly the blind spot this test exists to remove.
 *
 * The world and the route discovery live in Tests\Support\Screens, shared with
 * the subpath sweep.
 */

/** Asserts no screen threw, and names every one that did. */
function sweep($test, array $screens, string $who): void
{
    $threw = [];

    foreach ($screens as $name => $url) {
        $status = $test->get($url)->getStatusCode();

        if ($status >= 500) {
            $threw[] = "{$name} ({$status})";
        }

        // Each request may leave a guard resolved; the next one in the loop
        // would inherit it.
        auth()->forgetGuards();
    }

    expect($threw)->toBe([], "As {$who}, these screens threw: ".implode(', ', $threw));
}

it('opens every screen a signed-out visitor can reach without throwing', function () {
    $world = Screens::world();
    $screens = Screens::urls($world);

    expect(count($screens))->toBeGreaterThan(20);

    sweep($this, $screens, 'a signed-out visitor');
});

it('opens every screen without throwing, as a participant', function () {
    $world = Screens::world();
    $screens = Screens::urls($world);

    $this->actingAs($world['user'], 'web');

    sweep($this, $screens, 'a participant');
});

it('opens every screen without throwing, as a super admin', function () {
    $world = Screens::world();
    $screens = Screens::urls($world);

    $admin = superAdmin();

    foreach ($screens as $name => $url) {
        // A fresh act per request: a screen that resolves the other guard would
        // otherwise carry it into the next one.
        $this->actingAs($admin, 'admin');

        $status = $this->get($url)->getStatusCode();

        expect($status)->toBeLessThan(500, "Screen {$name} threw {$status} for a super admin.");
    }
});

it('opens every screen without throwing, as a state admin', function () {
    $world = Screens::world();
    $screens = Screens::urls($world);

    $admin = stateAdmin('Johor');

    foreach ($screens as $name => $url) {
        $this->actingAs($admin, 'admin');

        $status = $this->get($url)->getStatusCode();

        expect($status)->toBeLessThan(500, "Screen {$name} threw {$status} for a state admin.");
    }
});

it('opens the admin account screens for the role that owns them', function () {
    // `admin.admins.edit` binds an Admin, which the sweep above cannot supply
    // without deciding whose account it edits — so it is bound here, where the
    // answer is clear: a super admin editing a state admin.
    $target = stateAdmin('Kedah');

    $this->actingAs(superAdmin(), 'admin');

    $status = $this->get(route('admin.admins.edit', $target))->getStatusCode();

    expect($status)->toBeLessThan(500, "admin.admins.edit threw {$status}.");
});
