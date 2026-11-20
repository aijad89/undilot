<?php

use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The public site: published events, and nothing a participant should not see.
 */
it('lists published events for anyone, upcoming first, and never a draft or a lot count', function () {
    Event::factory()->create(['title' => 'Draft Fair', 'status' => 'draft']);
    Event::factory()->create(['title' => 'Later Fair', 'status' => 'open', 'start_date' => now()->addMonths(2), 'end_date' => now()->addMonths(2)->addDay()]);
    Event::factory()->create(['title' => 'Sooner Fair', 'status' => 'open', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDay(), 'total_plots' => 98765]);
    Event::factory()->create(['title' => 'Old Fair', 'status' => 'completed', 'start_date' => now()->subMonth(), 'end_date' => now()->subMonth()->addDay()]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Sooner Fair', 'Later Fair', 'Past events', 'Old Fair'])
        ->assertDontSee('Draft Fair')
        ->assertDontSeeText('98765');
});

it('opens a published event on its own page, and a draft is a 404 there too', function () {
    $open = Event::factory()->create(['title' => 'Open Fair', 'description' => 'Woodcarving and batik.', 'status' => 'open', 'total_plots' => 98765]);
    $draft = Event::factory()->create(['title' => 'Draft Fair', 'status' => 'draft']);

    $this->get(route('home'))->assertSee(route('events.show', $open));

    $this->get(route('events.show', $open))
        ->assertOk()
        ->assertSee('Open Fair')
        ->assertSee('Woodcarving and batik.')
        ->assertDontSeeText('98765');

    $this->get(route('events.show', $draft))->assertNotFound();
});

it('gives each public event one stage, the event dates winning over the draw', function () {
    $make = fn (array $a) => Event::factory()->create(array_merge(['status' => 'open', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDay()], $a));

    expect($make(['polling_status' => 'polling', 'polling_deadline' => now()->addDay()])->publicStage())->toBe('open')
        ->and($make(['polling_status' => 'polling', 'polling_deadline' => now()->subMinute()])->publicStage())->toBe('closed')
        ->and($make(['polling_status' => 'completed'])->publicStage())->toBe('closed')
        ->and($make(['polling_status' => 'draft'])->publicStage())->toBe('register')
        ->and($make(['polling_status' => 'draft', 'registration_open_at' => now()->addDay(), 'registration_close_at' => now()->addDays(2)])->publicStage())->toBe('soon')
        ->and($make(['polling_status' => 'polling', 'polling_deadline' => now()->addDay(), 'start_date' => now()->subDay()])->publicStage())->toBe('now')
        ->and($make(['polling_status' => 'polling', 'polling_deadline' => now()->addDay(), 'start_date' => now()->subWeek(), 'end_date' => now()->subDay()])->publicStage())->toBe('ended');
});

it('shows past events six at a time, and More asks for six more', function () {
    foreach (range(1, 8) as $i) {
        Event::factory()->create(['title' => "Old Fair $i", 'status' => 'completed', 'start_date' => now()->subMonths($i)->subDay(), 'end_date' => now()->subMonths($i)]);
    }

    $this->get(route('home'))->assertSee('Old Fair 6')->assertDontSee('Old Fair 7')->assertSee('past=12', false);
    $this->get(route('home', ['past' => 12]))->assertSee('Old Fair 8')->assertDontSee('past=18', false);
});

it('gives a signed-in participant the same portal bar on the public pages too', function () {
    $event = Event::factory()->create(['status' => 'open']);
    $this->actingAs(\App\Models\User::factory()->create(), 'web');

    // One bar, three pages: a participant walking from the event list to their
    // dashboard never sees it change. The dashboard link and the text-size
    // toggle stand for it — the notification bell used to, until the bar was
    // narrowed to what a participant has to act on.
    foreach ([route('home'), route('events.show', $event), route('dashboard')] as $url) {
        $this->get($url)->assertOk()
            ->assertSee(route('dashboard'))
            ->assertSee('apTextSizeToggle', false);
    }
});
