<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventJoinRequest;
use App\Models\EventRegistration;
use App\Notifications\AddedToDraw;
use App\Notifications\JoinRequestRejected;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A participant asks to join with documents of their own choosing; the office
 * of their zone approves onto the draw list or rejects with a reason.
 */
beforeEach(fn () => Storage::fake('local'));

function joinable(array $attributes = []): Event
{
    return Event::factory()->create($attributes);
}

/** The photos step: five product photos. The rest is the profile (withProfile()). */
function credentials(): array
{
    return ['product_images' => photos()];
}

/** A set of product photos. */
function photos(int $count = 5): array
{
    return array_map(fn ($i) => UploadedFile::fake()->image("product{$i}.jpg"), range(1, $count));
}

/** The names of a request's files, the product photos left out. */
function fileNames(array $documents): array
{
    return array_values(array_filter(array_column($documents, 'name'), fn ($name) => ! str_starts_with($name, 'Product photo')));
}

/** A valid join: declared, credentials, how they paid, and the receipt. */
function payment(string $method = 'transfer'): array
{
    return ['declaration' => 1, 'details_confirmed' => 1, 'payment_method' => $method, 'receipt' => UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf')] + credentials();
}

it('needs how they paid and a receipt, and keeps nothing else from a paid request', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event))
        ->assertSessionHasErrors(['declaration', 'payment_method', 'receipt'])
        ->assertSessionDoesntHaveErrors('documents');

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), ['payment_method' => 'bitcoin', 'receipt' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertSessionHasErrors(['payment_method', 'receipt']);

    // A waiver's supporting documents are checked like any file.
    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), ['declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver', 'waiver_category' => 'award', 'documents' => [UploadedFile::fake()->create('virus.exe', 10)]])
        ->assertSessionHasErrors('documents.0');

    // Back on the event page: one message saying it was sent, not two.
    $this->actingAs($company->user, 'web')
        ->from(route('events.show', $event))
        ->followingRedirects()
        ->post(route('events.join', $event), payment('cheque') + ['documents' => [UploadedFile::fake()->image('booth.jpg')]])
        ->assertSee('The office is reviewing your documents')
        ->assertDontSee('Sent. The office will review');

    $request = EventJoinRequest::sole();
    expect($request->status)->toBe('pending')
        ->and($request->payment_method)->toBe('cheque')
        // Paid: the receipt only; a supporting document sent anyway is not kept.
        ->and(fileNames($request->documents))->toBe(['Payment receipt']);
    Storage::disk('local')->assertExists($request->documents[0]['path']);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('Cheque')
        ->assertSee('Payment receipt');
});

it('sends a request with the receipt alone', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), payment('cash'))
        ->assertSessionHas('success');

    expect(EventJoinRequest::sole()->payment_method)->toBe('cash');
});

it('refuses a join request outside the registration window', function () {
    $event = joinable(['registration_open_at' => now()->addDay(), 'registration_close_at' => now()->addDays(3)]);
    $company = Company::factory()->withProfile()->create();

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), payment())
        ->assertSessionHas('error');

    expect(EventJoinRequest::count())->toBe(0);
});

it('lets the office of the zone approve onto the draw list, and no other zone; HQ verifying settles the request', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor', 'kraf_code' => 'TBPS']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs(stateAdmin('Kedah'), 'admin')
        ->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertForbidden();

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('Join requests (1)');

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertSessionHas('success');

    // On the list, waiting for HQ: the participant still sees a request with the office.
    $row = EventRegistration::sole();
    expect($row->company_id)->toBe($company->id)
        ->and($row->code)->toBe('TBPS')
        ->and($row->verified_at)->toBeNull()
        ->and($request->fresh()->status)->toBe('pending');
    $this->get(route('admin.events.registrations.index', $event))->assertDontSee('Join requests (1)')->assertSee('Waiting for HQ');

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertSee('With the office');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.events.registrations.update', [$event, $row]), ['code' => 'TBPS', 'plot_size' => '3x3']);
    expect($row->fresh()->verified_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe('approved');

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee($event->title)->assertDontSee('With the office');
});

it('shows a rejection reason to the participant, who may send again', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.events.registrations.reject', [$event, $request]), ['reject_reason' => 'SSM certificate has expired.'])
        ->assertSessionHas('success');

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('events.show', $event))
        ->assertSee('SSM certificate has expired.')
        ->assertSee('I want to join this event, and I declare');

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), payment())
        ->assertSessionHas('success');

    expect($request->fresh()->status)->toBe('pending')->and($request->fresh()->reject_reason)->toBeNull();
});

it('serves a document to its own company and the zone office only', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    Storage::disk('local')->put('join-requests/x.pdf', 'pdf');
    $request = EventJoinRequest::factory()->create([
        'event_id' => $event->id, 'company_id' => $company->id,
        'documents' => [['name' => 'SSM certificate', 'path' => 'join-requests/x.pdf', 'original' => 'ssm.pdf']],
    ]);
    $url = route('join-requests.document', [$request, 0]);

    // Nobody else learns it exists: the same 404 as an id that does not.
    $this->actingAs(Company::factory()->withProfile()->create()->user, 'web')->get($url)->assertNotFound();
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Kedah'), 'admin')->get($url)->assertNotFound();
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')->get($url)->assertOk();
    // A draft is the company's alone until it is sent, zone office included.
    $request->update(['status' => 'draft']);
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')->get($url)->assertNotFound();
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get($url)->assertOk();
    $request->update(['status' => 'pending']);
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get($url)->assertOk();
});

it('settles a pending request when the office adds the company directly, once HQ verifies', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]]);
    expect($request->fresh()->status)->toBe('pending');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.events.registrations.bulk', $event), [
        'action' => 'verify', 'registration_id' => [EventRegistration::sole()->id],
    ])->assertSessionHas('success');

    expect($request->fresh()->status)->toBe('approved');
});

it('says Register now on the home page only while registration is open', function () {
    joinable(['title' => 'Open Fair']);
    joinable(['title' => 'Later Fair', 'registration_open_at' => now()->addDay(), 'registration_close_at' => now()->addDays(2)]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($html, 'Register now'))->toBeGreaterThan(0)
        ->and($html)->toContain('Coming soon');
    expect(App\Models\Event::firstWhere('title', 'Open Fair')->publicStage())->toBe('register')
        ->and(App\Models\Event::firstWhere('title', 'Later Fair')->publicStage())->toBe('soon');
});

it('asks one question per step, in order, and opens on the first step that failed', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    // Recognition, certificates and the staff to contact are the profile's: the
    // form shows them to confirm, and asks only for this event's photos and payment.
    $this->get(route('events.show', $event))
        ->assertSeeInOrder([
            'data-step="declaration"', 'I want to join this event, and I declare',
            'data-step="profile"', 'Check your company details', 'change it on your profile', 'Siti Aminah', 'I have checked these details and they are correct.',
            'data-step="photos"', 'Product photos',
            'data-step="payment"', 'How did you pay?',
        ], false)
        ->assertDontSee('name="recognition"', false)
        ->assertDontSee('name="staff[0][name]"', false)
        ->assertSee('data-start="declaration"', false);

    $failsOn = fn (array $sent) => $this->from(route('events.show', $event))->followingRedirects()
        ->post(route('events.join', $event), $sent);

    // Details not confirmed: back on the details.
    $failsOn(['declaration' => 1] + credentials())->assertSee('data-start="profile"', false)
        ->assertSee('Check your company details and tick to confirm they are correct.');
    $failsOn(['declaration' => 1, 'details_confirmed' => 1])->assertSee('data-start="photos"', false);
    $failsOn(['product_images' => photos(4), 'declaration' => 1, 'details_confirmed' => 1])->assertSee('data-start="photos"', false);
    $failsOn(['declaration' => 1, 'details_confirmed' => 1] + credentials())->assertSee('data-start="payment"', false);

    // Not declared: back on the declaration, whatever else is wrong.
    $failsOn([])
        ->assertSee('data-start="declaration"', false)
        ->assertSee('Tick the declaration to join.');
});

it('sends a participant whose profile is not complete to fill it in first', function () {
    $event = joinable();
    $company = Company::factory()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    $this->get(route('events.show', $event))
        ->assertSee('Complete your profile first')
        ->assertSee('recognition, classification certificate, staff to contact')
        ->assertSee('href="'.route('profile').'"', false)
        ->assertDontSee('id="join-form"', false)
        ->assertDontSee('form="join-form"', false);

    // Refused on the far side too, with nothing kept.
    $this->post(route('events.join', $event), payment())
        ->assertSessionHas('error', 'Complete your profile first: entrepreneur category, recognition, classification certificate, staff to contact.');
    expect(EventJoinRequest::count())->toBe(0);

    // An expired certificate on the profile counts as missing.
    $company->update(['credentials' => array_merge(Company::factory()->withProfile()->make()->credentials, [
        'classification' => 'yes',
        'classification_cert' => ['number' => 'PKT-1', 'start' => now()->subYears(2)->toDateString(), 'end' => now()->subDay()->toDateString(), 'path' => 'certificates/x.pdf', 'original' => 'x.pdf'],
    ])]);
    // A fresh user: the signed-in one still holds the company it loaded before.
    $this->actingAs($company->user->fresh(), 'web')
        ->post(route('events.join', $event), payment())
        ->assertSessionHas('error', 'Complete your profile first: classification certificate.');

    // A draft can still be saved while the profile is being finished.
    $this->post(route('events.join', $event), ['draft' => 1, 'payment_method' => 'cash'])
        ->assertSessionHas('success');
    expect(EventJoinRequest::sole()->status)->toBe('draft');
});

it('refuses a request that is not declared, even with everything else in order', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), ['declaration' => null] + payment())
        ->assertSessionHasErrors('declaration');

    expect(EventJoinRequest::count())->toBe(0);
});

it('takes a fee waiver instead of a receipt, with a certificate where the category needs one', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    // A waiver needs a category, and no receipt.
    $this->post(route('events.join', $event), ['declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver'])
        ->assertSessionHasErrors('waiver_category')
        ->assertSessionDoesntHaveErrors('receipt');

    // Every waiver needs a document that proves it, OKU and first-timer alike.
    foreach (['disabled', 'first_time'] as $category) {
        $this->post(route('events.join', $event), ['declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver', 'waiver_category' => $category])
            ->assertSessionHasErrors('documents');
    }

    $this->post(route('events.join', $event), [
        'declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver', 'waiver_category' => 'first_time',
        'documents' => [UploadedFile::fake()->create('letter.pdf', 100, 'application/pdf')],
    ] + credentials())->assertSessionHas('success');

    $request = EventJoinRequest::sole();
    expect($request->payment_method)->toBe('waiver')
        ->and($request->waiver_category)->toBe('first_time')
        ->and(fileNames($request->documents))->toBe(['letter.pdf']);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('Fee waiver')
        ->assertSee('First time joining a promotion with Kraftangan Malaysia');
});

it('takes the OKU certificate from Supporting documents, and drops a category sent with a payment', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    $this->post(route('events.join', $event), [
        'declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver', 'waiver_category' => 'disabled',
        'documents' => [UploadedFile::fake()->create('oku.pdf', 100, 'application/pdf')],
    ] + credentials())->assertSessionHas('success');

    expect(fileNames(EventJoinRequest::sole()->documents))->toBe(['oku.pdf']);

    $other = joinable();
    $this->post(route('events.join', $other), payment('cash') + ['waiver_category' => 'award'])->assertSessionHas('success');
    expect(EventJoinRequest::where('event_id', $other->id)->sole()->waiver_category)->toBeNull();
});

/** The profile form as sent: the plainest answers, with any given over them. */
function profile(array $answers = []): array
{
    return $answers + ['recognition' => 'none', 'classification' => 'no', 'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']]];
}

/** A certificate as the form sends it. */
function certificate(string $field, string $number = 'PKT-001', ?string $end = null, bool $file = true): array
{
    return [
        "{$field}_number" => $number,
        "{$field}_start" => now()->subYear()->toDateString(),
        "{$field}_end" => $end ?? now()->addYear()->toDateString(),
    ] + ($file ? ["{$field}_file" => UploadedFile::fake()->create("{$field}.pdf", 100, 'application/pdf')] : []);
}

it('asks for exactly five product photos, kept for next time', function () {
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');
    $event = joinable();

    $this->post(route('events.join', $event), ['product_images' => null] + payment())
        ->assertSessionHasErrors('product_images');
    $this->post(route('events.join', $event), ['product_images' => photos(4)] + payment())
        ->assertSessionHasErrors(['product_images' => 'Upload exactly 5 photos of your products.']);
    $this->post(route('events.join', $event), ['product_images' => [...photos(4), UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]] + payment())
        ->assertSessionHasErrors('product_images.4');

    $this->post(route('events.join', $event), payment())->assertSessionHas('success');
    $request = EventJoinRequest::sole();
    expect(array_slice(array_column($request->documents, 'name'), -5))->toBe(['Product photo 1', 'Product photo 2', 'Product photo 3', 'Product photo 4', 'Product photo 5'])
        // The profile travels with the request as it stood.
        ->and($request->answers['staff'][0]['phone'])->toBe('012-345 6789');

    // Next time: the photos on file stand without a new set.
    $second = joinable();
    $this->get(route('events.show', $second))->assertSee('5 photos on file');
    $this->post(route('events.join', $second), ['product_images' => null] + payment())->assertSessionHas('success');
    expect(EventJoinRequest::where('event_id', $second->id)->sole()->documents[1]['path'])->toBe($request->documents[1]['path']);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertSee('Staff to contact: Siti Aminah (012-345 6789)')
        ->assertSee('Product photo 5');
});

it('saves a draft with whatever is filled in, which the office does not see', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    // Nothing required, but what is given is still checked for form.
    $this->post(route('events.join', $event), ['draft' => 1, 'product_images' => photos(4)])
        ->assertSessionHasErrors('product_images');

    $this->post(route('events.join', $event), ['draft' => 1, 'at' => 'payment', 'payment_method' => 'cash'])
        ->assertSessionHas('success', 'Draft saved. Come back to finish it any time before registration closes.')
        ->assertSessionHas('step', 'payment');

    expect(EventJoinRequest::sole()->status)->toBe('draft')
        ->and(EventJoinRequest::sole()->payment_method)->toBe('cash');

    // Right after: back on the step it was saved from, saying so once.
    $this->get(route('events.show', $event))
        ->assertSee('data-start="payment"', false)
        ->assertSee('Draft saved.')
        ->assertDontSee('You saved a draft on');
    // A later visit: reminded it was not sent.
    $this->get(route('events.show', $event))
        ->assertSee('You saved a draft on')
        ->assertSee('value="cash" checked', false);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertDontSee('Join requests (');
    $this->actingAs(stateAdmin('Johor'), 'admin')->get(route('admin.dashboard'))->assertDontSee('join request');
});

it('keeps a receipt saved with the draft until Join, and drops a replaced one', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    $this->post(route('events.join', $event), ['draft' => 1, 'payment_method' => 'cash', 'receipt' => UploadedFile::fake()->create('first.pdf', 10, 'application/pdf')]);
    $first = EventJoinRequest::sole()->documents[0]['path'];

    $this->get(route('events.show', $event))
        ->assertSee('Saved with your draft: first.pdf')
        ->assertSee('value="cash" checked', false);

    // Replaced in a second draft: the first file goes.
    $this->post(route('events.join', $event), ['draft' => 1, 'payment_method' => 'cash', 'receipt' => UploadedFile::fake()->create('second.pdf', 10, 'application/pdf')]);
    $second = EventJoinRequest::sole()->documents[0]['path'];
    Storage::disk('local')->assertMissing($first);

    // Switching to a waiver: the receipt does not count as its documents.
    $this->post(route('events.join', $event), ['declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'waiver', 'waiver_category' => 'award'] + credentials())
        ->assertSessionHasErrors('documents');

    // Nor in a draft: saved as a waiver, it holds no receipt, and the receipt goes.
    $this->post(route('events.join', $event), ['draft' => 1, 'payment_method' => 'waiver']);
    expect(EventJoinRequest::sole()->documents)->toBe([]);
    Storage::disk('local')->assertMissing($second);

    $this->post(route('events.join', $event), ['draft' => 1, 'payment_method' => 'cash', 'receipt' => UploadedFile::fake()->create('third.pdf', 10, 'application/pdf')]);
    $second = EventJoinRequest::sole()->documents[0]['path'];

    // Join with no new receipt: the draft's is sent.
    $sent = payment('cash');
    unset($sent['receipt']);
    $this->post(route('events.join', $event), $sent)->assertSessionHas('success', 'Sent. The office will review your documents.');

    $request = EventJoinRequest::sole();
    expect($request->status)->toBe('pending')
        ->and($request->documents[0]['path'])->toBe($second);
    Storage::disk('local')->assertExists($second);
});

it('keeps the rejection reason in view on a draft until it is sent again', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id, 'status' => 'rejected', 'reject_reason' => 'Receipt is unreadable.']);
    $this->actingAs($company->user, 'web');

    $this->post(route('events.join', $event), ['draft' => 1, 'recognition' => 'none']);
    expect($request->fresh()->status)->toBe('draft');

    $this->get(route('events.show', $event));
    $this->get(route('events.show', $event))->assertSee('Receipt is unreadable.');

    $this->post(route('events.join', $event), payment())->assertSessionHas('success');
    expect($request->fresh()->reject_reason)->toBeNull();
    $this->get(route('events.show', $event));
    $this->get(route('events.show', $event))->assertDontSee('Receipt is unreadable.');
});

it('shows the participant what they sent on their dashboard, files included, and no one else', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);

    $this->actingAs($company->user, 'web')
        ->put(route('profile.update'), profile(['classification' => 'yes'] + certificate('classification', 'PKT-777')))
        ->assertSessionHasNoErrors();
    $this->post(route('events.join', $event), payment());
    $request = EventJoinRequest::sole();

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Requests to join')
        ->assertSee($event->title)
        ->assertSee('With the office')
        ->assertSee('Siti Aminah')
        ->assertSee('PKT-777')
        ->assertSee(route('join-requests.document', [$request, 0]))
        ->assertSee('Product photo 5')
        ->assertDontSee('Change and send again');

    $other = Company::factory()->withProfile()->create();
    auth()->forgetGuards();
    $this->actingAs($other->user, 'web')->get(route('dashboard'))
        ->assertDontSee('Requests to join')
        ->assertDontSee('PKT-777');
});

it('tells a participant on the dashboard that a draft was never sent once registration closes', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create();

    $this->actingAs($company->user, 'web')->post(route('events.join', $event), ['draft' => 1, 'staff' => [['name' => 'Siti Aminah']]]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Draft, not sent')
        ->assertSee('Finish and send')
        ->assertDontSee('Registration is not open');

    $event->update(['registration_close_at' => now()->subDay()]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee("Registration is not open, so this can't be sent. This draft never reached the office.", false)
        ->assertDontSee('Finish and send');
});

it('leaves off the dashboard a request for an event the company is already in', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $company = Company::factory()->withProfile()->create();
    EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id, 'status' => 'draft']);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertSee('Finish and send');

    // The office put the company on the draw list itself; the draft is moot.
    EventRegistration::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Pesta Kraf Utara')
        ->assertDontSee('Requests to join')
        ->assertDontSee('Finish and send');
});

it('drops a cancelled event while registration runs, and says Event cancelled once it has closed', function () {
    $event = joinable(['polling_status' => 'cancelled']);
    $company = Company::factory()->withProfile()->create();
    EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id, 'status' => 'pending']);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertDontSee('Requests to join');

    $event->update(['registration_close_at' => now()->subDay()]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Event cancelled')
        ->assertSee('This event was cancelled.')
        ->assertDontSee('With the office');
});

it('dates a rejected request by its review, says why it cannot be sent again, and does not call the dashboard empty', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create();
    $this->travelTo(now()->subDays(5));
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id, 'status' => 'pending']);
    $this->travelBack();
    $request->update(['status' => 'rejected', 'reject_reason' => 'Blurry receipt', 'reviewed_at' => now()->subDays(2)]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Answered '.now()->subDays(2)->format('d M Y'))
        ->assertDontSee('Sent '.now()->format('d M Y'))
        ->assertSee('You are not in a lot draw yet')
        ->assertSee('<h2 class="ap-section-title">Requests to join</h2>', false)
        ->assertSee('Change and send again');

    $event->update(['registration_close_at' => now()->subDay()]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee("Registration is not open, so this can't be sent again.", false)
        ->assertDontSee('Change and send again');
});

it('tells the participant the office answer, in the app, whichever way the company was approved', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    [$a, $b, $c] = Company::factory()->count(3)->create(['state' => 'Johor']);
    [$ra, $rb, $rc] = collect([$a, $b, $c])->map(fn ($co) => EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $co->id]))->all();

    $office = $this->actingAs(stateAdmin('Johor'), 'admin');
    $office->post(route('admin.events.registrations.reject', [$event, $ra]), ['reject_reason' => 'Blurry receipt']);
    $office->post(route('admin.events.registrations.approve', [$event, $rb]), ['plot_size' => '3x3']);
    // Added straight to the list: the pending request is settled, and said so.
    $office->post(route('admin.events.registrations.store', $event), ['company_id' => [$c->id]]);

    // Nothing for b or c until HQ verifies.
    expect($b->user->notifications()->count() + $c->user->notifications()->count())->toBe(0);
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.events.registrations.bulk', $event), [
        'action' => 'verify', 'registration_id' => EventRegistration::pluck('id')->all(),
    ]);

    foreach ([[$a, false], [$b, true], [$c, true]] as [$company, $approved]) {
        $note = $company->user->notifications()->sole();
        expect($note->type)->toBe($approved ? AddedToDraw::class : JoinRequestRejected::class)
            ->and($note->data['approved'])->toBe($approved)
            ->and($note->data['join_event_id'])->toBe($event->id)
            ->and($note->data)->not->toHaveKey('event_id');
    }
    expect($a->user->notifications()->sole()->data['message'])->toContain('Blurry receipt');

    auth()->forgetGuards();
    $this->actingAs($a->user, 'web')->get(route('notifications.index'))
        ->assertSee('Request not approved')
        ->assertSee(route('events.show', $event))
        ->assertSee('Open the event')
        ->assertDontSee('Confirm Lot');

    auth()->forgetGuards();
    $this->actingAs($b->user, 'web')->get(route('notifications.index'))
        ->assertSee('You are in the draw')
        ->assertSee('Open dashboard')
        ->assertDontSee('Confirm Lot');

    // In-app only: no email in this app.
    expect((new JoinRequestRejected($event, 'Blurry receipt'))->via($a->user))->toBe(['database']);
});

it('does not answer a request on a cancelled event', function () {
    $event = joinable(['polling_status' => 'cancelled']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $office = $this->actingAs(stateAdmin('Johor'), 'admin');
    $office->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])->assertSessionHas('error');
    $office->post(route('admin.events.registrations.reject', [$event, $request]), ['reject_reason' => 'x'])->assertSessionHas('error');

    expect($request->fresh()->status)->toBe('pending')
        ->and(EventRegistration::count())->toBe(0)
        ->and($company->user->notifications()->count())->toBe(0);
});

it('lets a company the office took off the draw list ask again, and puts it back on Undo', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $office = $this->actingAs(stateAdmin('Johor'), 'admin');
    $office->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3']);
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->put(route('admin.events.registrations.update', [$event, EventRegistration::sole()]), ['plot_size' => '3x3']);
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->delete(route('admin.events.registrations.destroy', [$event, EventRegistration::sole()]), ['removal_reason' => 'Payment refunded']);

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Taken off the draw list')
        ->assertSee('Ask to join again');
    $this->actingAs($company->user, 'web')->get(route('events.show', $event))
        ->assertSee("The office took your company off this event's draw list.", false)
        ->assertSee('I want to join this event, and I declare');

    // Undo puts the company back: in the draw, nothing to ask.
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')->post(route('admin.events.registrations.undo', $event));
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Pesta Kraf Utara')
        ->assertDontSee('Taken off the draw list');

    // Taken off again, and this time it asks again.
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->delete(route('admin.events.registrations.destroy', [$event, EventRegistration::sole()]), ['removal_reason' => 'Payment refunded']);
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), payment())
        ->assertSessionHas('success');

    expect($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->reviewed_at)->toBeNull();
});

it('tells a company the office adds directly that it is in the draw, once HQ verifies, and once only', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $zone = stateAdmin('Johor');
    $hq = superAdmin();

    $this->actingAs($zone, 'admin')->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]]);
    // Added again: already on the list, nothing new to say.
    $this->actingAs($zone, 'admin')->post(route('admin.events.registrations.store', $event), ['company_id' => [$company->id]]);
    expect($company->user->notifications()->count())->toBe(0);

    $row = EventRegistration::sole();
    $this->actingAs($hq, 'admin')->put(route('admin.events.registrations.update', [$event, $row]), ['plot_size' => '3x3']);
    // The zone changes the size: back to HQ, who verifies again. Still one message.
    $this->actingAs($zone, 'admin')->put(route('admin.events.registrations.update', [$event, $row]), ['plot_size' => '3x6']);
    expect($row->fresh()->verified_at)->toBeNull();
    $this->actingAs($hq, 'admin')->put(route('admin.events.registrations.update', [$event, $row]), ['plot_size' => '3x6']);

    $note = $company->user->notifications()->sole();
    expect($note->type)->toBe(AddedToDraw::class)
        ->and($note->data['approved'])->toBeTrue()
        ->and($note->data['message'])->toBe('Your company is in the lot draw for Pesta Kraf Utara.');
});

it('still answers a request on an event unpublished when registration closed', function () {
    // What auto_close leaves behind: registration over, the event unpublished,
    // a request sent before the close still with the office.
    $event = joinable(['status' => 'closed', 'registration_close_at' => now()->subDay()]);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('With the office')
        ->assertDontSee('Event cancelled');

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertSessionHas('success');
    expect(EventRegistration::where('company_id', $company->id)->exists())->toBeTrue();
});

it('treats an event put back to draft as called off: not answered, and on the dashboard as cancelled', function () {
    $event = joinable(['status' => 'draft']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertSessionHas('error', 'This event was cancelled or put back to draft. Its requests cannot be answered.');
    expect($request->fresh()->status)->toBe('pending');

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertDontSee('Requests to join');

    $event->update(['registration_close_at' => now()->subDay()]);
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Event cancelled')
        ->assertDontSee('With the office');
});

it('deletes a replaced certificate or photo once nothing points at it, and not before', function () {
    $company = Company::factory()->withProfile()->create();
    $this->actingAs($company->user, 'web');
    $this->put(route('profile.update'), profile(['classification' => 'yes'] + certificate('classification')));
    $first = joinable();
    $this->post(route('events.join', $first), payment());
    $old = $company->fresh()->credentials;

    // Renewed on the profile: the first request still shows the old file.
    $this->put(route('profile.update'), profile(['classification' => 'yes'] + certificate('classification', 'PKT-002')))
        ->assertSessionHasNoErrors();
    Storage::disk('local')->assertExists($old['classification_cert']['path']);

    // Photos picked again on every Save draft: the draft's earlier set goes.
    $third = joinable();
    $this->post(route('events.join', $third), ['draft' => 1, 'product_images' => photos()]);
    $draftPhotos = array_column($third->joinRequests()->sole()->answers['product_images'], 'path');
    $this->post(route('events.join', $third), ['draft' => 1, 'product_images' => photos()]);
    foreach ($draftPhotos as $path) {
        Storage::disk('local')->assertMissing($path);
    }

    // The first request, not approved and sent again with new photos: it now
    // carries the renewed certificate, nothing points at the old file or the
    // old photos, and they go.
    $secondCert = $company->fresh()->credentials['classification_cert']['path'];
    $first->joinRequests()->sole()->update(['status' => 'rejected']);
    $this->post(route('events.join', $first), payment())->assertSessionHas('success');
    Storage::disk('local')->assertMissing($old['classification_cert']['path']);
    foreach (array_column($old['product_images'], 'path') as $path) {
        Storage::disk('local')->assertMissing($path);
    }
    Storage::disk('local')->assertExists($secondCert);
});

it('names a product photo that is really another kind of file, whatever it is called', function () {
    $event = joinable();
    $company = Company::factory()->withProfile()->create();
    // A WebP saved as .jpg, and a PDF saved as .png: told apart by what is inside.
    // Real uploads, not fakes: a fake reports its type from its name.
    $upload = function (string $name, string $content) {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    };
    $webp = $upload('kedai.jpg', 'RIFF'.pack('V', 30).'WEBPVP8 '.pack('V', 18).str_repeat("\0", 18));
    $pdf = $upload('katalog.png', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n");
    // A real JPEG named .jpeg, as a camera saves it: fine.
    $drawn = UploadedFile::fake()->image('x.jpg');
    $jpeg = $upload('gerai.jpeg', file_get_contents($drawn->getPathname()));

    $this->actingAs($company->user, 'web')
        ->post(route('events.join', $event), ['product_images' => [...photos(2), $jpeg, $webp, $pdf]] + payment())
        ->assertSessionHasErrors([
            'product_images.3' => 'kedai.jpg is named like a photo but is really a WebP image. Save it as JPG, JPEG or PNG (or take a screenshot of it) and pick it again.',
            'product_images.4' => 'katalog.png is named like a photo but is really a PDF. Save it as JPG, JPEG or PNG (or take a screenshot of it) and pick it again.',
        ])
        ->assertSessionDoesntHaveErrors(['product_images.0', 'product_images.1', 'product_images.2']);
});

it('puts what to do first on the dashboard, beside what is open and what is on record', function () {
    $company = Company::factory()->withProfile()->create(['name' => 'Seramik Klang Studio', 'state' => 'Selangor', 'credentials' => [
        'recognition' => 'tokoh', 'classification' => 'yes',
        'classification_cert' => ['number' => 'PKT-555', 'start' => now()->subYear()->toDateString(), 'end' => now()->subDay()->toDateString()],
        'quality' => null, 'quality_cert' => null,
        'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']], 'product_images' => [],
    ]]);
    $draw = joinable(['title' => 'Pesta Kraf Utara', 'polling_status' => 'polling', 'polling_deadline' => now()->addDay()]);
    EventRegistration::factory()->create(['event_id' => $draw->id, 'company_id' => $company->id]);
    $drafted = joinable(['title' => 'Karnival Batik']);
    EventJoinRequest::factory()->create(['event_id' => $drafted->id, 'company_id' => $company->id, 'status' => 'draft']);
    joinable(['title' => 'Ekspo Seramik Johor']);
    joinable(['title' => 'Yang Sudah Tutup', 'registration_close_at' => now()->subDay()]);

    $page = $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Seramik Klang Studio · Selangor')
        // Most pressing first: the lot and the expired certificate, then the draft.
        ->assertSeeInOrder([
            'What to do now',
            'Confirm your lot for Pesta Kraf Utara',
            'Your classification certificate expired on '.now()->subDay()->format('d M Y'),
            'Finish your request to join Karnival Batik',
            // The requests right under, before the draws.
            'Requests to join',
            'Your lot draws',
        ])
        // Open, not yet asked for; neither the one asked for nor a closed one.
        ->assertSeeInOrder(['Open for registration', 'Ekspo Seramik Johor', 'View and join'])
        // The record is on Past events now, not here.
        ->assertDontSee('Your company on record');

    $this->get(route('past-events'))
        ->assertSeeInOrder(['Your company on record', 'Siti Aminah', 'Tokoh Kraf Negara', 'PKT-555', 'Expired', 'None yet']);

    expect(substr_count($page->getContent(), 'Karnival Batik'))->toBe(2) // the to-do and its request card
        ->and($page->getContent())->not->toContain('Yang Sudah Tutup')
        ->and($page->getContent())->not->toContain('Nothing is waiting on you right now.');
});

it('says nothing is waiting when nothing is, and that no company record exists yet', function () {
    $company = Company::factory()->create();

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Nothing is waiting on you right now.')
        ->assertSee('You are not in a lot draw yet')
        ->assertSee('No other event is open for registration right now.');
    $this->get(route('past-events'))->assertSee('Nothing on record yet.');
});

it('warns on the dashboard of a certificate that runs out within two months', function () {
    $company = Company::factory()->withProfile()->create(['credentials' => [
        'recognition' => 'none', 'classification' => 'yes',
        'classification_cert' => ['number' => 'PKT-1', 'start' => now()->subYear()->toDateString(), 'end' => now()->addMonth()->toDateString()],
    ]]);

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('Your classification certificate expires on '.now()->addMonth()->format('d M Y'));
    $this->get(route('past-events'))->assertSee('Expires soon');
});


it('builds the user menu from the nav list', function () {
    $company = Company::factory()->withProfile()->create();

    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSeeInOrder(['ap-dropdown-menu', 'href="'.route('dashboard').'"', 'Dashboard', 'href="'.route('profile').'"', 'Profile', 'Sign Out'], false);
});

it('tells a company the office is adding that it is being added, and takes no request from it', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $row = EventRegistration::factory()->unverified()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    $this->actingAs($company->user, 'web')->get(route('events.show', $event))
        ->assertSee('The office is adding your company to this draw.')
        ->assertDontSee('I want to join this event, and I declare');
    $this->get(route('dashboard'))->assertDontSee('Pesta Kraf Utara');
    $this->post(route('events.join', $event), payment())
        ->assertSessionHas('error', 'The office is adding your company to this draw. You will be told once HQ confirms it.');
    expect(EventJoinRequest::count())->toBe(0);

    // Verified: in the draw.
    $row->update(['verified_at' => now(), 'first_verified_at' => now()]);
    $this->get(route('events.show', $event))->assertSee("Your company is in this event's draw.", false);
});

it('keeps a company in its own portal while HQ re-checks a zone change', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $company = Company::factory()->withProfile()->create(['state' => 'Johor', 'plot_size' => '3x3']);
    $row = EventRegistration::factory()->create(['event_id' => $event->id, 'company_id' => $company->id]);

    // The zone changes the booth size on the company page; the row goes back to HQ.
    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(route('admin.companies.kraf-code.update', $company), ['kraf_code' => '', 'plot_size' => '3x6']);
    expect($row->fresh()->verified_at)->toBeNull();

    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))->assertSee('Pesta Kraf Utara');
    $this->get(route('events.show', $event))->assertSee("Your company is in this event's draw.", false);

    // Verified again by HQ: nothing new to tell.
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->put(route('admin.events.registrations.update', [$event, $row]), ['plot_size' => '3x6']);
    expect($company->user->notifications()->count())->toBe(0);
});

it('tells a company HQ takes off the draw why, and tells one never told nothing', function () {
    $event = joinable(['title' => 'Pesta Kraf Utara']);
    $told = Company::factory()->withProfile()->create(['state' => 'Johor']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $told->id, 'status' => 'approved']);
    $toldRow = EventRegistration::factory()->create(['event_id' => $event->id, 'company_id' => $told->id]);
    $waitingRow = EventRegistration::factory()->unverified()->create(['event_id' => $event->id, 'company_id' => Company::factory()->withProfile()->create(['state' => 'Johor'])->id]);
    $hq = superAdmin();

    // A told company needs a reason, one at a time and in bulk.
    $this->actingAs($hq, 'admin')->delete(route('admin.events.registrations.destroy', [$event, $toldRow]))->assertSessionHas('error');
    $this->post(route('admin.events.registrations.bulk', $event), ['action' => 'remove', 'registration_id' => [$toldRow->id, $waitingRow->id]])
        ->assertSessionHas('error', '1 of these companies was told they are in the draw. Give a reason; it is sent to them.');
    expect(EventRegistration::count())->toBe(2);

    $this->post(route('admin.events.registrations.bulk', $event), [
        'action' => 'remove', 'registration_id' => [$toldRow->id, $waitingRow->id], 'removal_reason' => 'Payment refunded',
    ])->assertSessionHas('success');

    $note = $told->user->notifications()->sole();
    expect($note->type)->toBe(App\Notifications\RemovedFromDraw::class)
        ->and($note->data['message'])->toContain('Reason: Payment refunded')
        ->and($request->fresh()->removed_reason)->toBe('Payment refunded')
        ->and($waitingRow->company->user->notifications()->count())->toBe(0);

    auth()->forgetGuards();
    $this->actingAs($told->user, 'web')->get(route('dashboard'))->assertSee('Reason: Payment refunded');
    $this->get(route('events.show', $event))->assertSee('Payment refunded');

    // Asking again clears it.
    $this->post(route('events.join', $event), payment())->assertSessionHas('success');
    expect($request->fresh()->removed_reason)->toBeNull();
});

it('carries the payment onto the draw list row, and lets only HQ approve once the draw has started', function () {
    $event = joinable();
    [$paid, $waived, $late] = Company::factory()->count(3)->create(['state' => 'Johor']);
    $receipt = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $paid->id, 'payment_method' => 'transfer', 'documents' => [
        ['name' => 'Payment receipt', 'path' => "join-requests/{$event->id}/r.pdf", 'original' => 'resit.pdf'],
    ]]);
    $waiver = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $waived->id, 'payment_method' => 'waiver', 'waiver_category' => 'first_time']);

    $zone = stateAdmin('Johor');
    $this->actingAs($zone, 'admin')->post(route('admin.events.registrations.approve', [$event, $receipt]), ['plot_size' => '3x3']);
    $this->actingAs($zone, 'admin')->post(route('admin.events.registrations.approve', [$event, $waiver]), ['plot_size' => '3x3']);

    $row = fn ($company) => EventRegistration::where('company_id', $company->id)->sole();
    expect($row($paid)->payment_status)->toBe('paid')
        ->and($row($paid)->payment_note)->toBe('Online transfer, receipt uploaded with the join request')
        ->and($row($paid)->verified_at)->toBeNull()
        ->and($row($waived)->payment_status)->toBeNull()
        ->and($row($waived)->payment_note)->toStartWith('Waiver: First time joining');

    // The draw list shows the receipt the company sent above the officer's own record.
    $this->actingAs($zone, 'admin')->get(route('admin.events.registrations.index', $event))
        ->assertSeeInOrder(['Online transfer', 'Payment receipt', 'name="payment_status"'], false)
        ->assertSee(route('join-requests.document', [$receipt, 0]), false);

    // The draw has started: the zone adds no one, HQ still may, verified at once.
    $event->update(['polling_status' => 'polling']);
    $request = EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $late->id]);
    $this->actingAs($zone, 'admin')->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertSessionHas('error', 'The draw has started: only HQ can add companies now.');
    expect(EventRegistration::where('company_id', $late->id)->exists())->toBeFalse();

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.events.registrations.approve', [$event, $request]), ['plot_size' => '3x3'])
        ->assertSessionHas('success');
    expect($row($late)->verified_at)->not->toBeNull()
        ->and($request->fresh()->status)->toBe('approved');
});
