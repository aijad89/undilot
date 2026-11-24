<?php

use App\Models\Company;

/*
 * Every error page is undilot's own, says what happened in plain words, and
 * offers a way back that fits who is looking: the admin side for an /admin
 * URL, the participant's dashboard when signed in, the event list otherwise.
 */

it('shows a missing page with a way back to the event list', function () {
    $this->get('/no-such-page')->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('href="'.route('home').'"', false)
        ->assertSee('Go back')
        ->assertDontSee('NOT FOUND');
});

it('sends a signed-in participant back to their dashboard', function () {
    $company = Company::factory()->withProfile()->create();

    $this->actingAs($company->user, 'web')->get('/no-such-page')->assertNotFound()
        ->assertSee('Go to your dashboard')
        ->assertSee('href="'.route('dashboard').'"', false);
});

it('sends an admin back to the admin side, and shows the reason a page was refused', function () {
    $this->actingAs(stateAdmin('Johor'), 'admin')->get('/admin/no-such-page')->assertNotFound()
        ->assertSee('href="'.route('admin.dashboard').'"', false);

    // A state admin on a super-only screen: refused, in plain words, with the way back.
    $this->get(route('admin.audit-trails.index'))->assertForbidden()
        ->assertSee('Not allowed')
        ->assertSee('This action is unauthorized.')
        ->assertSee('Go to the admin dashboard');
});

it('has a page for an expired session, too many tries, an error and maintenance', function (string $view, string $words) {
    $html = view("errors.{$view}", ['exception' => new Exception])->render();

    expect($html)->toContain($words)
        ->and($html)->toContain('undilot');
})->with([
    'expired session' => ['419', 'Your session expired'],
    'too many tries' => ['429', 'Too many tries'],
    'server error' => ['500', 'Something went wrong'],
    'maintenance' => ['503', 'Down for maintenance'],
]);
