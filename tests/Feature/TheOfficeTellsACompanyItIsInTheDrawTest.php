<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;
use App\Notifications\AddedToDraw;
use App\Notifications\RemovedFromDraw;
use Illuminate\Support\Facades\Notification;

/*
 * THE ONE MESSAGE THIS WHOLE MODULE REMOVAL COULD SILENTLY TAKE AWAY.
 *
 * A participant never asks to join: the office adds their company to the draw
 * list and HQ verifies it. This notification is the only thing that tells them
 * so — without it they sign in to a dashboard that says nothing, with a lot to
 * confirm they were never told about.
 *
 * It is sent ONCE, on HQ's FIRST verification. A row HQ verified before, edited
 * by a zone and verified again, says nothing new.
 */

function inDrawFixture(): array
{
    $admin = superAdmin();
    $user = User::factory()->create();
    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);

    $registration = EventRegistration::factory()->create([
        'event_id' => $event->id,
        'company_id' => $company->id,
        'user_id' => $user->id,
        'verified_at' => null,
        'first_verified_at' => null,
    ]);

    return [$admin, $user, $event, $registration];
}

it('tells the company when HQ verifies the row the office added', function () {
    Notification::fake();
    [$admin, $user, $event, $registration] = inDrawFixture();

    $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), [
            'code' => 'TBPS',
            'plot_size' => '3x3',
        ])
        ->assertRedirect();

    Notification::assertSentTo($user, AddedToDraw::class);
});

it('says it once, not again when HQ verifies a second time', function () {
    Notification::fake();
    [$admin, $user, $event, $registration] = inDrawFixture();

    $send = fn () => $this->actingAs($admin, 'admin')
        ->put(route('admin.events.registrations.update', [$event, $registration]), [
            'code' => 'TBPS',
            'plot_size' => '3x3',
        ]);

    $send();
    $send();

    // first_verified_at is never cleared, so the second pass is a re-check and
    // a re-check has nothing new to announce.
    Notification::assertSentToTimes($user, AddedToDraw::class, 1);
});

it('carries the keys the notifications screen reads', function () {
    $event = Event::factory()->create(['title' => 'Pesta Kraf']);
    $user = User::factory()->create();

    $payload = (new AddedToDraw($event))->toArray($user);

    expect($payload['join_event_id'])->toBe($event->id)
        ->and($payload['approved'])->toBeTrue()
        ->and($payload['event_name'])->toBe('Pesta Kraf')
        ->and($payload['type'])->toBe('success')
        ->and($payload['title'])->toBe('You are in the draw');

    expect(str_contains($payload['message'], 'Pesta Kraf'))
        ->toBeTrue('The message has to name the event; the screen shows it alone.');
});

it('tells the company when HQ removes it from a draw it was already told it is in, carrying the reason in the notification itself', function () {
    Notification::fake();
    [$admin, $user, $event, $registration] = inDrawFixture();
    $registration->update(['verified_at' => now(), 'first_verified_at' => now()]);

    $this->actingAs($admin, 'admin')
        ->delete(route('admin.events.registrations.destroy', [$event, $registration]), [
            'removal_reason' => 'Booth size no longer matches the plan.',
        ])
        ->assertRedirect();

    // The row that used to carry the reason is gone the moment it is removed, so
    // the reason has nowhere to live but the notification's own data.
    Notification::assertSentTo($user, RemovedFromDraw::class, function (RemovedFromDraw $notification) use ($user) {
        expect(str_contains($notification->toArray($user)['message'], 'Booth size no longer matches the plan.'))
            ->toBeTrue('The row is deleted by the time this is read; the reason must be in the notification payload.');

        return true;
    });
});
