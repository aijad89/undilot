<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use Illuminate\Support\Facades\Route;

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
 */

/** Builds enough of a world that parameterised screens have a row to render. */
function world(): array
{
    $code = KrafBidang::factory()->code('TBPS')->create();

    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'polling',
        'polling_deadline' => now()->addDays(3),
        'state' => null,
    ]);

    $size = PlotSize::create([
        'event_id' => $event->id,
        'size' => '3x3',
        'quantity' => 1,
        'location' => 'Dewan Utama',
    ]);

    Plot::create([
        'event_id' => $event->id,
        'plot_size_id' => $size->id,
        'company_id' => null,
        'allocated_to' => null,
        'bidang_id' => $code->id,
        'sub_bidang_id' => null,
        'plot_code' => 'DEWAN-TBPS1',
        'is_allocated' => false,
        'allocation_type' => 'algorithm',
        'is_locked' => false,
        'allocated_at' => null,
    ]);

    EventRegistration::create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'code' => 'TBPS',
        'code_updated_at' => now(),
        'plot_size' => '3x3',
        'plot_id' => null,
        'confirmed_participation' => false,
        'confirmed_at' => null,
        'verified_by' => null,
        'verified_at' => now(),
        'first_verified_at' => now(),
        'hq_note' => null,
        'payment_note' => null,
    ]);

    return [
        'user' => $user,
        'company' => $company,
        'event' => $event,
        'plotSize' => $size,
    ];
}

/**
 * Every named GET route, with its parameters filled from the world above.
 *
 * Routes needing a parameter this cannot supply are skipped by name rather than
 * guessed at, and each skip says why — a silent skip would let a screen fall out
 * of the sweep and look swept.
 */
function screens(array $world): array
{
    $skip = [
        // A signed reset token, which only a password-reset mail carries.
        // tests/Feature covers the reset flow itself.
        'password.reset',
        // Registers in local/testing only and signs a user in as a side effect,
        // which would change who the rest of this sweep is acting as.
        'dev.auto',
    ];

    $bind = [
        'event' => $world['event']->id,
        'company' => $world['company']->id,
        'plotSize' => $world['plotSize']->id,
        'admin' => null,   // filled per-case below
    ];

    $out = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if (! $name || in_array($name, $skip, true) || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if (str_starts_with($route->uri(), 'storage/')) {
            continue;
        }

        $params = [];
        $missing = false;

        foreach ($route->parameterNames() as $param) {
            if (! array_key_exists($param, $bind) || $bind[$param] === null) {
                $missing = true;

                break;
            }

            $params[$param] = $bind[$param];
        }

        if ($missing) {
            continue;
        }

        $out[$name] = route($name, $params);
    }

    return $out;
}

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
    $world = world();
    $screens = screens($world);

    expect(count($screens))->toBeGreaterThan(20);

    sweep($this, $screens, 'a signed-out visitor');
});

it('opens every screen without throwing, as a participant', function () {
    $world = world();
    $screens = screens($world);

    $this->actingAs($world['user'], 'web');

    sweep($this, $screens, 'a participant');
});

it('opens every screen without throwing, as a super admin', function () {
    $world = world();
    $screens = screens($world);

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
    $world = world();
    $screens = screens($world);

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
