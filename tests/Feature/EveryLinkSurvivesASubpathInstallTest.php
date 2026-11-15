<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Screens;

/*
 * The app may be served at /undilotnew rather than the domain root, so every
 * link and redirect goes through route() / url(). This mounts the whole app
 * under a subpath and opens every screen as every kind of visitor, failing on
 * any link that escapes to the domain root.
 *
 * WHY A SWEEP AND NOT A GREP. A grep for `href="/` finds the hand-typed link in
 * Blade, and misses everything else: a URL assembled in PHP, a
 * `fetch('/admin/...')` in an inline script, an asset URL from a disk that
 * answers root-relative (the banner bug in
 * TheBannerIsServedFromTheDiskItWasWrittenToTest). Rendering the pages catches
 * them all by their output, wherever they were spelled. And on a dev machine
 * none of them shows, because Herd serves the app at the root — the breakage
 * lives only on the server.
 *
 * NOT `redirect('/path')`, and that is measured, not missed: redirect() resolves
 * a path against the request root, so under this mount `redirect('/admin/x')`
 * sends `http://localhost/undilotnew/admin/x`. It is subpath-safe. Swapping a
 * route() redirect for one was tried as a mutation and correctly survived. The
 * reason to avoid `redirect('/')` is where it lands — `/` is the public event
 * list — not that it escapes.
 *
 * HOW THE SUBPATH IS MOUNTED is how a real one is: SCRIPT_NAME says the front
 * controller lives at /undilotnew/index.php, and Symfony derives the base URL
 * from it — so route() builds links under the subpath by itself, as it does
 * behind an nginx alias. URL::forceRootUrl() was not used: it would make links
 * correct by decree, which is the thing being tested.
 *
 * Root-relative URLs that point INTO the subpath are fine; so are external
 * hosts (the CDNs) and protocol-relative `//`. An absolute URL on this host
 * that skips the subpath is not.
 */

const SUBPATH = '/undilotnew';
const SUBPATH_HOST = 'http://localhost';

/** Serve the request as if the app were installed at SUBPATH. */
function underSubpath($test): void
{
    config(['app.url' => SUBPATH_HOST.SUBPATH]);

    $test->withServerVariables([
        'SCRIPT_NAME' => SUBPATH.'/index.php',
        'PHP_SELF' => SUBPATH.'/index.php',
        'SCRIPT_FILENAME' => public_path('index.php'),
    ]);
}

/**
 * First path segments of every route — `admin`, `events`, `dashboard`… A quoted
 * string starting `/<one of these>` in a page is an app URL somebody typed,
 * whether it sits in an attribute or in an inline script.
 */
function appPrefixes(): array
{
    $prefixes = [];

    foreach (Route::getRoutes() as $route) {
        $first = explode('/', trim($route->uri(), '/'))[0];

        if ($first !== '' && ! str_starts_with($first, '{')) {
            $prefixes[$first] = true;
        }
    }

    // Not a route, but where the public disk serves banners from.
    $prefixes['storage'] = true;

    return array_keys($prefixes);
}

/** True when the URL leaves the subpath for the domain root. */
function escapesSubpath(string $url): bool
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5);

    if (str_starts_with($url, '//')) {
        return false;   // protocol-relative: another host, a CDN
    }

    if (str_starts_with($url, '/')) {
        return $url !== SUBPATH && ! str_starts_with($url, SUBPATH.'/')
            && ! str_starts_with($url, SUBPATH.'?') && ! str_starts_with($url, SUBPATH.'#');
    }

    if (str_starts_with($url, SUBPATH_HOST)) {
        $rest = substr($url, strlen(SUBPATH_HOST));

        if ($rest === '') {
            return true;    // the bare domain root
        }

        // `http://localhost:8080/…` is another origin, not this one.
        return $rest[0] === '/' && escapesSubpath($rest);
    }

    return false;   // relative, external, #anchor, mailto:, javascript:
}

/** Every URL on the response that leaves the subpath, as readable strings. */
function escapedLinks(TestResponse $response): array
{
    $found = [];

    if ($location = $response->headers->get('Location')) {
        if (escapesSubpath($location)) {
            $found[] = "Location: {$location}";
        }
    }

    $body = (string) $response->getContent();

    // Attributes that hold a URL.
    preg_match_all('/\b(href|src|action|formaction)\s*=\s*(["\'])(.*?)\2/i', $body, $m, PREG_SET_ORDER);

    foreach ($m as [, $attr, , $url]) {
        if (escapesSubpath($url)) {
            $found[] = "{$attr}=\"{$url}\"";
        }
    }

    // App paths typed into scripts or data attributes: '/admin/…', "/events/…".
    $prefixes = implode('|', array_map(fn ($p) => preg_quote($p, '/'), appPrefixes()));
    preg_match_all('/(["\'`])(\/(?:'.$prefixes.')(?:[\/?#][^"\'`\s]*)?)\1/', $body, $m, PREG_SET_ORDER);

    foreach ($m as [, , $url]) {
        $found[] = "string {$url}";
    }

    return array_values(array_unique($found));
}

/**
 * Opens every screen under the subpath, signing in fresh before each request
 * when $signIn is given, and returns every escaping link keyed by screen.
 */
function sweepUnderSubpath($test, array $paths, ?Closure $signIn = null): array
{
    $escaped = [];

    foreach ($paths as $name => $path) {
        auth()->forgetGuards();

        if ($signIn) {
            $signIn();
        }

        underSubpath($test);

        // `/` needs the `?`: the test client trims a trailing slash, and
        // `/undilotnew` without one is not recognised as the subpath, so the
        // public home page 404s and drops out of the sweep. nginx sends
        // `/undilotnew/` — which is what this spells.
        $response = $test->get(SUBPATH_HOST.SUBPATH.($path === '/' ? '/?' : $path));

        // A path that matched NO route means the mount broke — and a sweep of
        // pages that never rendered finds no links and passes. A 404 from a
        // matched route is the app answering (a slip before any lot, a past
        // event that is not past yet), so only the unmatched kind counts.
        if ($response->getStatusCode() === 404 && $response->baseRequest->route() === null) {
            $escaped[] = "{$name}: 404 under the subpath";
        }

        if ($links = escapedLinks($response)) {
            $escaped[] = "{$name}: ".implode(', ', $links);
        }
    }

    return $escaped;
}

it('mounts the app under the subpath, so the sweep below is not vacuous', function () {
    // If the subpath were not really mounted, every request would 404 on a
    // route that does not exist, no page would render a link, and the sweeps
    // would pass on nothing. This proves the mount: the page renders, and
    // route() put its links under the subpath by itself.
    $this->actingAs(superAdmin(), 'admin');
    underSubpath($this);

    $response = $this->get(SUBPATH_HOST.SUBPATH.'/admin/dashboard')->assertOk();

    expect($response->getContent())->toContain('href="'.SUBPATH_HOST.SUBPATH.'/admin/events"');
});

it('keeps every link and redirect under the subpath, for a signed-out visitor', function () {
    $paths = Screens::urls(Screens::world(), absolute: false);

    expect(sweepUnderSubpath($this, $paths))->toBe([]);
});

it('keeps every link and redirect under the subpath, for a participant', function () {
    $world = Screens::world();
    $paths = Screens::urls($world, absolute: false);

    expect(sweepUnderSubpath($this, $paths, fn () => $this->actingAs($world['user'], 'web')))->toBe([]);
});

it('keeps every link and redirect under the subpath, for a super admin', function () {
    $paths = Screens::urls(Screens::world(), absolute: false);
    $admin = superAdmin();

    expect(sweepUnderSubpath($this, $paths, fn () => $this->actingAs($admin, 'admin')))->toBe([]);
});

it('keeps every link and redirect under the subpath, for a state admin', function () {
    $paths = Screens::urls(Screens::world(), absolute: false);
    $admin = stateAdmin('Johor');

    expect(sweepUnderSubpath($this, $paths, fn () => $this->actingAs($admin, 'admin')))->toBe([]);
});
