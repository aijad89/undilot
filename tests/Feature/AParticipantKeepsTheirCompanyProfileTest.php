<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\Event;
use App\Models\EventJoinRequest;
use App\Models\KrafBidang;
use App\Models\KrafPengelasan;
use App\Models\KrafProduk;
use App\Models\KrafSubBidang;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The part of the MICF entry form that is the same for every event — contact,
 * recognition, certificates, crafts — is kept on the participant's profile, so
 * a request to join only adds its photos and payment.
 */
beforeEach(fn () => Storage::fake('local'));

/** The profile form at its plainest, with any answers given over it. */
function profileForm(array $answers = []): array
{
    return $answers + ['recognition' => 'none', 'classification' => 'no', 'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']]];
}

/** A certificate as the profile form sends it. */
function profileCert(string $field, string $number = 'PKT-001', ?string $end = null, bool $file = true): array
{
    return [
        "{$field}_number" => $number,
        "{$field}_start" => now()->subYear()->toDateString(),
        "{$field}_end" => $end ?? now()->addYear()->toDateString(),
    ] + ($file ? ["{$field}_file" => UploadedFile::fake()->create("{$field}.pdf", 100, 'application/pdf')] : []);
}

it('saves the contact details and answers, and a request to join starts from them', function () {
    $company = Company::factory()->create(['state' => 'Johor']);
    $this->actingAs($company->user, 'web');

    $this->get(route('profile'))->assertSee('Still needed before you can join an event: recognition, classification certificate, staff to contact.');

    // Each card saves on its own.
    $this->put(route('profile.contact'), [
        'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']],
        'workshop_address' => "Lot 5, Kampung Seramik\n41000 Klang", 'office_phone' => '03-3344 5566',
        'facebook' => 'https://www.facebook.com/seramikklang',
    ])
        ->assertRedirect(route('profile'))
        ->assertSessionHas('success', 'Information saved.');
    $this->get(route('profile'))->assertSee('Still needed before you can join an event: recognition, classification certificate.');

    $this->put(route('profile.update'), profileForm([
        'recognition' => 'adiguru', 'classification' => 'yes',
        'recognition_file' => UploadedFile::fake()->create('adiguru.pdf', 100, 'application/pdf'),
    ] + profileCert('classification')))
        ->assertRedirect(route('profile'))
        ->assertSessionHas('success', 'Details saved.');

    $company->refresh();
    expect($company->workshop_address)->toBe("Lot 5, Kampung Seramik\n41000 Klang")
        ->and($company->office_phone)->toBe('03-3344 5566')
        ->and($company->facebook)->toBe('https://www.facebook.com/seramikklang')
        ->and($company->credentials['recognition'])->toBe('adiguru')
        ->and($company->credentials['classification_cert']['number'])->toBe('PKT-001');
    Storage::disk('local')->assertExists($company->credentials['classification_cert']['path']);

    $this->get(route('profile'))
        ->assertDontSee('Still needed before you can join')
        ->assertSee('value="03-3344 5566"', false)
        ->assertSee('On file: classification.pdf');

    // The join form only confirms them, and the request carries the certificate.
    $event = Event::factory()->create();
    $this->get(route('events.show', $event))->assertSeeInOrder(['Siti Aminah', 'Lot 5, Kampung Seramik', '03-3344 5566', 'Adiguru Kraf', 'file: adiguru.pdf', 'PKT-001', 'file: classification.pdf']);
    $this->post(route('events.join', $event), [
        'declaration' => 1, 'details_confirmed' => 1, 'payment_method' => 'cash',
        'receipt' => UploadedFile::fake()->create('slip.pdf', 10, 'application/pdf'),
        'product_images' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 5)),
    ])->assertSessionHas('success');

    $request = EventJoinRequest::sole();
    expect($request->answers['recognition'])->toBe('adiguru')
        ->and(array_column($request->documents, 'name'))->toContain('Recognition', 'Classification certificate');
});

it('keeps a list of staff to contact, dropping a row left blank', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');

    $this->put(route('profile.contact'), profileForm(['staff' => [
        ['name' => 'Siti Aminah', 'phone' => '012-345 6789'],
        ['name' => '', 'phone' => ''],
        ['name' => 'Ahmad', 'phone' => '019-111 2222'],
    ]]))->assertSessionHasNoErrors();

    expect($company->fresh()->credentials['staff'])->toBe([
        ['name' => 'Siti Aminah', 'phone' => '012-345 6789'],
        ['name' => 'Ahmad', 'phone' => '019-111 2222'],
    ]);
    $this->get(route('profile'))->assertSee('name="staff[1][name]"', false)->assertSee('value="Ahmad"', false);

    // Every row blank is no staff at all.
    $this->put(route('profile.contact'), profileForm(['staff' => [['name' => '', 'phone' => '']]]))
        ->assertSessionHasErrors(['staff' => 'Add at least one staff member to contact.']);
});

it('wants proof of a recognition, keeps it while the answer stands, and drops it with Not applicable', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');

    $this->put(route('profile.update'), profileForm(['recognition' => 'tokoh']))
        ->assertSessionHasErrors(['recognition_file' => 'Upload proof of the recognition.']);

    $this->put(route('profile.update'), profileForm(['recognition' => 'tokoh', 'recognition_file' => UploadedFile::fake()->create('tokoh.pdf', 100, 'application/pdf')]))
        ->assertSessionHasNoErrors();
    $path = $company->fresh()->credentials['recognition_cert']['path'];
    Storage::disk('local')->assertExists($path);
    $this->get(route('profile'))->assertSee('On file: tokoh.pdf');

    // Same answer, no file sent: the one on file stays.
    $this->put(route('profile.update'), profileForm(['recognition' => 'tokoh']))->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['recognition_cert']['path'])->toBe($path);

    // A different recognition needs its own proof.
    $this->put(route('profile.update'), profileForm(['recognition' => 'adiguru']))->assertSessionHasErrors('recognition_file');

    // Not applicable: nothing kept, and the file goes.
    $this->put(route('profile.update'), profileForm())->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['recognition_cert'])->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('asks for the certificate in full while the answer is yes, and refuses an expired one', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');

    $this->put(route('profile.update'), [])->assertSessionHasErrors(['recognition', 'classification']);
    $this->put(route('profile.update'), profileForm(['recognition' => 'both', 'classification' => 'maybe']))
        ->assertSessionHasErrors(['recognition', 'classification']);
    $this->put(route('profile.contact'), [])->assertSessionHasErrors(['staff' => 'Add at least one staff member to contact.']);
    $this->put(route('profile.contact'), ['staff' => [['name' => 'Siti', 'phone' => '03-1234 5678'], ['name' => '', 'phone' => '012-345 6789']]])
        ->assertSessionHasErrors(['staff.0.phone', 'staff.1.name']);

    $this->put(route('profile.update'), profileForm(['classification' => 'yes']))
        ->assertSessionHasErrors(['classification_number', 'classification_start', 'classification_end', 'classification_file']);
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', end: now()->subDay()->toDateString())))
        ->assertSessionHasErrors(['classification_end' => 'This certificate has expired. Renew it first.']);

    expect($company->fresh()->credentials)->toBeNull();
});

it('keeps the certificate file while it is the same certificate, and wants a new one for a renewal', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification')));
    $path = $company->fresh()->credentials['classification_cert']['path'];

    // The same certificate, no file sent: the one on file stays.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes', 'staff' => [['name' => 'Ahmad', 'phone' => '019-111 2222']]] + profileCert('classification', file: false)))
        ->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['classification_cert']['path'])->toBe($path);

    // Renewed (a new end date): its new file is needed.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', end: now()->addYears(2)->toDateString(), file: false)))
        ->assertSessionHasErrors('classification_file');

    // Renewed with its file: the old one goes, since no request points at it.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', 'PKT-002')))
        ->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($path);

    // No certificate any more: its answer is no, and nothing is kept for it.
    $this->put(route('profile.update'), profileForm())->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['classification_cert'])->toBeNull();
});

it('asks the quality question of Batik, Songket and Tenun only, offering their own standard', function () {
    KrafBidang::factory()->code('TTN')->create(['pecahan_bidang' => 'TENUN']);
    KrafBidang::factory()->code('TTH')->create(['pecahan_bidang' => 'TEKATAN']);

    $tenun = Company::factory()->create(['kraf_code' => 'TTN']);
    $this->actingAs($tenun->user, 'web');
    $this->get(route('profile'))
        ->assertSee('Quality compliance')
        ->assertSee('Songket / Tenunan Standard entrepreneur')
        ->assertDontSee('Batik Standard entrepreneur');

    $this->put(route('profile.update'), profileForm())->assertSessionHasErrors('quality');
    $this->put(route('profile.update'), profileForm(['quality' => 'batik']))->assertSessionHasErrors('quality');
    $this->put(route('profile.update'), profileForm(['quality' => 'songket_tenun']))->assertSessionHasErrors('quality_number');
    $this->put(route('profile.update'), profileForm(['quality' => 'songket_tenun'] + profileCert('quality', 'SPK-9')))
        ->assertSessionHasNoErrors();
    expect($tenun->fresh()->credentials['quality_cert']['number'])->toBe('SPK-9');

    // Tekatan has no standard: not asked, and not missing.
    auth()->forgetGuards();
    $tekat = Company::factory()->create(['kraf_code' => 'TTH']);
    $this->actingAs($tekat->user, 'web');
    $this->get(route('profile'))->assertDontSee('Quality compliance');
    $this->put(route('profile.contact'), profileForm())->assertSessionHasNoErrors();
    $this->put(route('profile.update'), profileForm())->assertSessionHasNoErrors();
    expect(\App\Support\CompanyCredentials::missing($tekat->fresh()))->toBe([]);
});

it('changes only the company contact and answers: never the name, zone, craft code or booth size', function () {
    $company = Company::factory()->create(['name' => 'Seramik Klang', 'state' => 'Selangor', 'kraf_code' => 'TTK', 'plot_size' => '3x3']);

    $this->actingAs($company->user, 'web');
    foreach (['profile.contact', 'profile.update'] as $route) {
        $this->put(route($route), profileForm(['name' => 'Other', 'state' => 'Johor', 'kraf_code' => 'TBPS', 'plot_size' => '3x6', 'is_active' => 0, 'user_id' => 999]))
            ->assertSessionHasNoErrors();
    }

    expect($company->fresh()->only('name', 'state', 'kraf_code', 'plot_size', 'is_active', 'user_id'))
        ->toBe(['name' => 'Seramik Klang', 'state' => 'Selangor', 'kraf_code' => 'TTK', 'plot_size' => '3x3', 'is_active' => true, 'user_id' => $company->user_id]);
});

it('saves each card without touching the other', function () {
    $company = Company::factory()->create(['office_phone' => '03-1111 2222']);
    $this->actingAs($company->user, 'web');
    $this->put(route('profile.contact'), ['staff' => [['name' => 'Siti', 'phone' => '012-345 6789']], 'office_phone' => '03-3344 5566']);
    $this->put(route('profile.update'), profileForm(['recognition' => 'none', 'office_phone' => '09-9999 9999', 'staff' => [['name' => 'Lain', 'phone' => '019-000 0000']]]))
        ->assertSessionHasNoErrors();

    $company->refresh();
    expect($company->office_phone)->toBe('03-3344 5566')
        ->and($company->credentials['staff'])->toBe([['name' => 'Siti', 'phone' => '012-345 6789']])
        ->and($company->credentials['recognition'])->toBe('none');

    // The contact save keeps the answers.
    $this->put(route('profile.contact'), ['staff' => [['name' => 'Ahmad', 'phone' => '019-111 2222']], 'recognition' => 'tokoh']);
    expect($company->fresh()->credentials['recognition'])->toBe('none');
});

it('lets a company switched off change nothing', function () {
    $company = Company::factory()->create(['is_active' => false]);

    // Turned away at the door (user.active), before the page is reached.
    $this->actingAs($company->user, 'web')->put(route('profile.update'), profileForm())
        ->assertRedirect()
        ->assertSessionMissing('success');
    expect($company->fresh()->credentials)->toBeNull();
});

it('adds, stars and removes the crafts the company makes, and no one else’s', function () {
    $category = KrafBidang::factory()->create(['name' => 'TEKSTIL', 'code' => null]);
    $batik = KrafSubBidang::factory()->create(['bidang_id' => $category->id, 'name' => 'Batik']);
    $songket = KrafSubBidang::factory()->create(['bidang_id' => $category->id, 'name' => 'Songket']);
    $class = KrafPengelasan::factory()->create(['sub_bidang_id' => $batik->id, 'name' => 'Batik Canting']);
    $product = KrafProduk::factory()->create(['sub_bidang_id' => $batik->id, 'bidang_id' => $category->id, 'name' => 'Kain Sarung']);

    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');

    $this->get(route('profile'))->assertSee('What you make')->assertSee('TEKSTIL');

    $this->post(route('profile.kraf.store'), ['bidang_id' => $category->id, 'sub_bidang_id' => [$batik->id], 'pengelasan_id' => [$class->id], 'produk_id' => [$product->id]])
        ->assertRedirect(route('profile').'#crafts');
    $this->post(route('profile.kraf.store'), ['bidang_id' => $category->id, 'sub_bidang_id' => [$songket->id]]);

    [$first, $second] = $company->krafSelections()->orderBy('id')->get()->all();
    expect($first->is_primary)->toBeTrue()
        ->and($second->is_primary)->toBeFalse()
        ->and($first->pengelasan->pluck('name')->all())->toBe(['Batik Canting'])
        ->and($first->produks->pluck('name')->all())->toBe(['Kain Sarung']);

    $this->get(route('profile'))->assertSeeInOrder(['TEKSTIL', 'Batik', 'Main', 'Batik Canting', 'Kain Sarung', 'Songket', 'Make main']);

    $this->put(route('profile.kraf.primary', $second));
    expect($first->fresh()->is_primary)->toBeFalse()->and($second->fresh()->is_primary)->toBeTrue();

    $this->delete(route('profile.kraf.destroy', $first));
    expect(CompanyKraf::whereKey($first->id)->exists())->toBeFalse();

    // Another company's craft: not there, from here.
    $other = Company::factory()->create();
    $theirs = CompanyKraf::create(['company_id' => $other->id, 'bidang_id' => $category->id, 'sub_bidang_id' => $batik->id, 'is_primary' => true]);
    $this->delete(route('profile.kraf.destroy', $theirs))->assertNotFound();
    $this->put(route('profile.kraf.primary', $theirs))->assertNotFound();
    expect($theirs->fresh())->not->toBeNull();

    // A sub-category from another category is refused, as on the admin page.
    $other = KrafBidang::factory()->create(['name' => 'HASIL RIMBA', 'code' => null]);
    $this->post(route('profile.kraf.store'), ['bidang_id' => $other->id, 'sub_bidang_id' => [$batik->id]])
        ->assertSessionHas('error', 'Pick a sub-category that belongs to that craft category.');
});

it('is for a participant signed in, never an admin or a guest', function () {
    $this->put(route('profile.update'), profileForm())->assertRedirect(route('login'));
    $this->post(route('profile.kraf.store'), [])->assertRedirect(route('login'));
});
