<?php

use Illuminate\Support\Facades\File;

/*
 * `$unreadNotificationCount` is handed to a NAMED LIST of views, and the list has
 * to match its readers exactly, because the two ways of being wrong fail in
 * opposite directions and neither announces itself:
 *
 *   A READER NOT ON THE LIST is a 500 — the readers do not guard with isset().
 *
 *   A NAME ON THE LIST THAT NO LONGER READS IT is a COUNT query per request for
 *   a number nothing renders. That is not hypothetical: `partials.portal-bar`
 *   stayed on the list after its bell was removed, and the bar is included by
 *   both layouts/frontend and layouts/public — so every participant page and
 *   every public page paid for it, silently.
 *
 * So this test asserts set equality, not "every reader is listed".
 */

/** The view names the composer is registered for, read out of the provider source. */
function composerList(): array
{
    $source = File::get(app_path('Providers/AppServiceProvider.php'));

    expect($source)->toContain("\$view->with('unreadNotificationCount'");

    // The View::composer([...]) call whose closure provides the variable.
    preg_match(
        '/View::composer\(\s*\[(.*?)\]\s*,\s*function[^}]*unreadNotificationCount/s',
        $source,
        $m
    );

    expect($m)->not->toBeEmpty();

    preg_match_all('/[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', $m[1], $names);

    return $names[1];
}

/** Every Blade view whose own markup reads the variable. */
function composerReaders(): array
{
    $readers = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        if (! str_contains($file->getContents(), 'unreadNotificationCount')) {
            continue;
        }

        $readers[] = str_replace(
            ['/', '\\', '.blade.php'],
            ['.', '.', ''],
            $file->getRelativePathname()
        );
    }

    return $readers;
}

it('hands the count to exactly the views that read it, no more and no fewer', function () {
    $listed = composerList();
    $readers = composerReaders();

    sort($listed);
    sort($readers);

    expect($readers)->not->toBeEmpty();
    expect($listed)->toBe($readers);
});

it('renders the dashboard, which reads the count, without an undefined variable', function () {
    // The half of the rule a static comparison cannot prove: that being on the
    // list is actually what makes the variable present.
    $user = App\Models\User::factory()->create();

    $this->actingAs($user, 'web')->get(route('dashboard'))->assertOk();
});

it('renders a public page, which does not read the count, while signed in', function () {
    // The other half: the pages the bar sits on must not need the variable. This
    // is the page that was paying for a COUNT it never displayed.
    $user = App\Models\User::factory()->create();

    $this->actingAs($user, 'web')->get(route('home'))->assertOk();
});
