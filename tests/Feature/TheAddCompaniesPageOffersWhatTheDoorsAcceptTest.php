<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

/*
 * The Add Companies page: every company in the admin's zone, in or out of one
 * draw, with the action its state allows. It decides nothing — its buttons post
 * to the draw list's own doors (store, update, destroy), which hold every rule
 * and have their own tests. What this holds is that the page OFFERS the right
 * thing: the right companies, the right button on each, and nothing a door
 * would refuse dressed up as possible.
 */

function companyIn(string $zone, string $name, array $extra = []): Company
{
    return Company::factory()->create($extra + [
        'user_id' => User::factory()->create()->id,
        'name' => $name,
        'state' => $zone,
    ]);
}

function onList(Event $event, Company $company, array $extra = []): EventRegistration
{
    return EventRegistration::factory()->create($extra + [
        'event_id' => $event->id,
        'company_id' => $company->id,
    ]);
}

function addPage(Event $event, array $query = []): string
{
    return route('admin.events.registrations.companies', [$event, ...$query]);
}

it('lists the zone\'s companies, each marked in or out of the draw', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $in = companyIn('Johor', 'Batik Dalam');
    companyIn('Johor', 'Batik Luar');
    companyIn('Kedah', 'Batik Kedah');
    onList($event, $in);

    $page = $this->actingAs(stateAdmin('Johor'), 'admin')->get(addPage($event))->assertOk();

    $page->assertSee('Batik Dalam')->assertSee('Batik Luar')->assertDontSee('Batik Kedah')
        ->assertSeeInOrder(['Batik Dalam', 'In the draw', 'Batik Luar', 'Not in the draw']);
});

it('shows HQ every zone, narrowed by the zone filter', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    companyIn('Johor', 'Batik Johor');
    companyIn('Kedah', 'Batik Kedah');
    $hq = Admin::factory()->create(['role' => 'hq', 'state' => null]);

    $this->actingAs($hq, 'admin')->get(addPage($event))
        ->assertSee('Batik Johor')->assertSee('Batik Kedah');

    $this->get(addPage($event, ['zone' => 'Kedah']))
        ->assertSee('Batik Kedah')->assertDontSee('Batik Johor');
});

it('filters to the companies in, or out of, the draw', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    onList($event, companyIn('Johor', 'Batik Dalam'));
    companyIn('Johor', 'Batik Luar');

    $this->actingAs(stateAdmin('Johor'), 'admin');

    $this->get(addPage($event, ['status' => 'in']))->assertSee('Batik Dalam')->assertDontSee('Batik Luar');
    $this->get(addPage($event, ['status' => 'out']))->assertSee('Batik Luar')->assertDontSee('Batik Dalam');
});

it('offers Add on a company out of the draw, and adding it lands back on this page', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $company = companyIn('Johor', 'Batik Luar');

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(addPage($event))
        ->assertSee('value="'.$company->id.'"', false)
        ->assertSee('Add selected');

    $this->from(addPage($event))
        ->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]])
        ->assertRedirect(addPage($event));

    expect($event->registrations()->where('company_id', $company->id)->exists())->toBeTrue();
});

it('leaves a switched-off company out unless it is already in the draw', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    companyIn('Johor', 'Batik Tutup', ['is_active' => false]);
    $stillIn = companyIn('Johor', 'Batik Tutup Dalam', ['is_active' => false]);
    onList($event, $stillIn);

    $this->actingAs(stateAdmin('Johor'), 'admin')->get(addPage($event))
        ->assertDontSee('Batik Tutup</div>', false)
        ->assertSee('Batik Tutup Dalam');
});

it('offers a zone no Add once the draw has started, and says why', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'polling']);
    $company = companyIn('Johor', 'Batik Luar');

    $this->actingAs(stateAdmin('Johor'), 'admin')->get(addPage($event))
        ->assertSee('only HQ can add companies now')
        ->assertDontSee('Add selected')
        ->assertDontSee('name="company_id[]" value="'.$company->id.'"', false);
});

it('still offers HQ Add once the draw has started, for a latecomer', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'polling']);
    companyIn('Johor', 'Batik Lewat');

    $this->actingAs(Admin::factory()->create(['role' => 'hq', 'state' => null]), 'admin')
        ->get(addPage($event))
        ->assertSee('Add selected');
});

it('offers Edit while the draw is a draft, and not once it has started', function () {
    $draft = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $running = Event::factory()->create(['status' => 'open', 'polling_status' => 'polling']);
    $company = companyIn('Johor', 'Batik Dalam');
    onList($draft, $company);
    onList($running, $company);

    $this->actingAs(stateAdmin('Johor'), 'admin');

    // The Edit control itself — its URL is shared with Remove.
    $this->get(addPage($draft))->assertSee('name="_method" value="PUT"', false);
    $this->get(addPage($running))->assertDontSee('name="_method" value="PUT"', false);
});

it('offers a zone Remove only on a row the company was never told of', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $quiet = onList($event, companyIn('Johor', 'Batik Senyap'), ['verified_at' => null, 'first_verified_at' => null]);
    $told = onList($event, companyIn('Johor', 'Batik Tahu'));

    // Asked of the buttons, not the URL: update (PUT) and destroy (DELETE)
    // share `…/registrations/{id}`, so the Edit form carries the same address.
    $page = $this->actingAs(stateAdmin('Johor'), 'admin')->get(addPage($event));

    $page->assertSee('data-confirm="Remove Batik Senyap from this draw?"', false)
        ->assertDontSee('Remove Batik Tahu from this draw?')
        ->assertDontSee('name="removal_reason"', false)
        ->assertSee('Only HQ can remove');
});

it('asks HQ for the reason when removing a company already told', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $told = onList($event, companyIn('Johor', 'Batik Tahu'));

    $this->actingAs(Admin::factory()->create(['role' => 'hq', 'state' => null]), 'admin')
        ->get(addPage($event))
        ->assertSee(route('admin.events.registrations.destroy', [$event, $told]), false)
        ->assertSee('name="removal_reason"', false);
});

it('is reached from the draw list by its button', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee(addPage($event), false)
        ->assertSee('Add companies');
});

it('refuses a state admin a draft event, as every screen of the event does', function () {
    $event = Event::factory()->create(['status' => 'draft']);

    $this->actingAs(stateAdmin('Johor'), 'admin')->get(addPage($event))->assertForbidden();
});
