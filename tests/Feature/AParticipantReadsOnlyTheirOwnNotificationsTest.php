<?php

use App\Models\Event;
use App\Models\User;
use App\Notifications\RemovedFromDraw;

/*
 * The participant's inbox. It matters more than an inbox usually does: when HQ
 * takes a company off a draw it has already been told about, the reason lives
 * ONLY in the RemovedFromDraw notification — the registration row is deleted
 * and keeps nothing. So the inbox is the one place a participant can read why,
 * and nobody else may read it, or mark it read for them.
 *
 * The notification id travels in the URL, and ids are UUIDs, not secrets: they
 * leak into logs, referers and screenshots. Scoping is what protects a row, so
 * every lookup has to go through the signed-in user's own notifications.
 */

function removedFrom(User $user, string $reason): string
{
    $event = Event::factory()->create(['title' => 'Hari Kraf Kebangsaan']);

    $before = $user->notifications()->pluck('id')->all();

    $user->notifyNow(new RemovedFromDraw($event, $reason));

    // The new row by difference, NOT latest(): two notices sent in the same
    // second share created_at, and latest() may hand back the first one twice.
    $new = array_values(array_diff($user->notifications()->pluck('id')->all(), $before));

    expect($new)->toHaveCount(1);

    return $new[0];
}

it('shows the participant their own notice, reason included, and nobody else\'s', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    removedFrom($owner, 'Bayaran tidak diterima.');
    removedFrom($other, 'Syarikat menarik diri.');

    $this->actingAs($owner, 'web')
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertSee('Bayaran tidak diterima.')
        ->assertDontSee('Syarikat menarik diri.');
});

it('marks one of the participant\'s own notifications read, and only that one', function () {
    $user = User::factory()->create();
    $first = removedFrom($user, 'Sebab pertama.');
    $second = removedFrom($user, 'Sebab kedua.');

    $this->actingAs($user, 'web')
        ->post(route('notifications.read', $first))
        ->assertRedirect();

    expect($user->notifications()->find($first)->read_at)->not->toBeNull()
        ->and($user->notifications()->find($second)->read_at)->toBeNull();
});

it('refuses to mark another participant\'s notification read, by id in the URL', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $id = removedFrom($owner, 'Bayaran tidak diterima.');

    $this->actingAs($intruder, 'web')
        ->post(route('notifications.read', $id))
        ->assertNotFound();

    expect($owner->notifications()->find($id)->read_at)->toBeNull();
});

it('marks all of the participant\'s own notifications read, and leaves everyone else\'s unread', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    removedFrom($user, 'Sebab pertama.');
    removedFrom($user, 'Sebab kedua.');
    $othersId = removedFrom($other, 'Sebab orang lain.');

    $this->actingAs($user, 'web')
        ->post(route('notifications.read-all'))
        ->assertRedirect();

    expect($user->unreadNotifications()->count())->toBe(0)
        ->and($other->notifications()->find($othersId)->read_at)->toBeNull();
});

it('keeps the inbox behind the participant sign-in', function () {
    $id = removedFrom(User::factory()->create(), 'Bayaran tidak diterima.');

    $this->get(route('notifications.index'))->assertRedirect(route('login'));
    $this->post(route('notifications.read', $id))->assertRedirect(route('login'));
    $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
});
