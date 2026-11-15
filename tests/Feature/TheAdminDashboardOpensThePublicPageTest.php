<?php

use App\Models\Admin;

/*
 * An admin had no way to the public events list short of typing its URL in a
 * new tab. The dashboard carries the link, for every role, opening in a new
 * tab so the admin's own page stays put.
 */

dataset('admin roles', [
    'state admin' => fn () => Admin::factory()->create(),
    'hq' => fn () => Admin::factory()->hq()->create(),
    'super' => fn () => Admin::factory()->super()->create(),
]);

it('links the dashboard to the public events list, in a new tab', function (Admin $admin) {
    $html = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->toMatch('/<a href="'.preg_quote(route('home'), '/').'"[^>]*target="_blank"[^>]*>.*?Public event page/s');

    $this->get(route('home'))->assertOk();
})->with('admin roles');

/*
 * And from there back. An admin is a guest on `web`, so the public bar used to
 * offer them the participant Sign In; signed in as admin, it is Dashboard.
 */

dataset('public pages', [
    'events list' => fn () => route('home'),
    'event page' => fn () => route('events.show', \App\Models\Event::factory()->create(['status' => 'open'])),
]);

it('turns the public bar\'s Sign In into the admin dashboard for a signed-in admin', function (string $url) {
    $admin = Admin::factory()->create();

    // actingAs() makes "admin" the default guard; a real request to a public
    // page keeps "web", which is what the bar is asking about.
    $this->actingAs($admin, 'admin');
    auth()->shouldUse('web');

    $html = $this->get($url)->assertOk()->getContent();

    expect(substr_count($html, 'href="'.route('admin.dashboard').'"'))->toBe(2)   // bar and drawer
        ->and(preg_match('/<a href="'.preg_quote(route('login'), '/').'" class="ap-(signin|nav-item)"/', $html))->toBe(0)
        ->and($html)->not->toContain(route('dev.auto'));
})->with('public pages');

it('still offers Sign In to a visitor who is signed in as nobody', function (string $url) {
    $this->get($url)->assertOk()
        ->assertSee('href="'.route('login').'" class="ap-signin"', false)
        ->assertDontSee(route('admin.dashboard'));
})->with('public pages');
