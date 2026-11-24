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
    return $answers + ['entrepreneur_category' => 'Mikro, Kecil & Sederhana (MKS)', 'oku' => 'no', 'recognition' => 'none', 'classification' => 'no', 'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789']]];
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

    $this->get(route('profile'))->assertSee('Still needed before you can join an event: entrepreneur category, recognition, classification certificate, staff to contact.');

    // Each card saves on its own.
    $this->put(route('profile.contact'), [
        'entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no',
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
        ->and($company->profile()['classification_cert']['number'])->toBe('PKT-001');
    Storage::disk('local')->assertExists($company->profile()['classification_cert']['path']);

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

it('asks for the certificate in full while the answer is yes, and keeps an expired one', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');

    $this->put(route('profile.update'), [])->assertSessionHasErrors(['recognition', 'classification']);
    $this->put(route('profile.update'), profileForm(['recognition' => 'both', 'classification' => 'maybe']))
        ->assertSessionHasErrors(['recognition', 'classification']);
    $this->put(route('profile.contact'), [])->assertSessionHasErrors(['entrepreneur_category' => 'Choose your entrepreneur category.', 'staff' => 'Add at least one staff member to contact.']);
    $this->put(route('profile.contact'), ['entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no', 'staff' => [['name' => 'Siti', 'phone' => '03-1234 5678'], ['name' => '', 'phone' => '012-345 6789']]])
        ->assertSessionHasErrors(['staff.0.phone', 'staff.1.name']);

    $this->put(route('profile.update'), profileForm(['classification' => 'yes']))
        ->assertSessionHasErrors(['classification_number', 'classification_start', 'classification_end', 'classification_file']);
    expect($company->fresh()->credentials)->toBeNull();

    // An expired one is kept, not refused (owner, 2026-11-24), and the profile
    // still counts as complete for it.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', end: now()->subDay()->toDateString())))
        ->assertSessionHasNoErrors();
    expect($company->fresh()->profile()['classification_cert']['end'])->toBe(now()->subDay()->toDateString())
        ->and(\App\Support\CompanyCredentials::missing($company->fresh()))->not->toContain('classification certificate');
});

it('keeps the certificate file while it is the same certificate, and wants a new one for a renewal', function () {
    $company = Company::factory()->create();
    $this->actingAs($company->user, 'web');
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification')));
    $path = $company->fresh()->profile()['classification_cert']['path'];

    // The same certificate, no file sent: the one on file stays.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes', 'staff' => [['name' => 'Ahmad', 'phone' => '019-111 2222']]] + profileCert('classification', file: false)))
        ->assertSessionHasNoErrors();
    expect($company->fresh()->profile()['classification_cert']['path'])->toBe($path);

    // Renewed (a new end date): its new file is needed.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', end: now()->addYears(2)->toDateString(), file: false)))
        ->assertSessionHasErrors('classification_file');

    // Renewed with its file: the old one moves to the history, file kept.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', 'PKT-002')))
        ->assertSessionHasNoErrors();
    Storage::disk('local')->assertExists($path);
    $history = $company->fresh()->profile()['classification_history'];
    expect(array_column($history, 'number'))->toBe(['PKT-001'])
        ->and($history[0]['path'])->toBe($path);
    $this->get(route('profile'))->assertSeeInOrder(['Previous certificates', 'PKT-001', 'classification.pdf']);
    // Linked by its row id; the current certificate's id is no history entry.
    $this->get(route('companies.certificate', [$company, 'classification', 'history' => $history[0]['id']]))->assertOk();
    $this->get(route('companies.certificate', [$company, 'classification', 'history' => $company->fresh()->profile()['classification_cert']['id']]))->assertNotFound();

    // The office sees it too, on the company's page.
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSeeInOrder(['Classification certificate', 'PKT-002', 'Previous certificates', 'PKT-001'])
        ->assertSee(route('companies.certificate', [$company, 'classification', 'history' => $history[0]['id']]), false);
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web');

    // Saving the same certificate again adds nothing.
    $this->put(route('profile.update'), profileForm(['classification' => 'yes'] + profileCert('classification', 'PKT-002', file: false)))
        ->assertSessionHasNoErrors();
    expect($company->fresh()->profile()['classification_history'])->toHaveCount(1);

    // No certificate any more: its answer is no, and the last one joins the history.
    $this->put(route('profile.update'), profileForm())->assertSessionHasNoErrors();
    expect($company->fresh()->profile()['classification_cert'])->toBeNull()
        ->and(array_column($company->fresh()->profile()['classification_history'], 'number'))->toBe(['PKT-002', 'PKT-001']);
});

it('shows the office and the participant a quality history even once the main craft has no standard', function () {
    // No craft picked, so no quality question: only the history is left to see.
    $company = Company::factory()->withProfile()->create(['state' => 'Perak']);
    \App\Models\CompanyCertificate::factory()->create(['company_id' => $company->id, 'type' => 'quality', 'standard' => 'batik', 'number' => 'SPB-OLD', 'replaced_at' => now()]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSeeInOrder(['Quality compliance', 'Previous certificates', 'Batik Standard entrepreneur', 'SPB-OLD']);

    // The participant sees the same record, with no question to answer.
    auth()->forgetGuards();
    $this->actingAs($company->user, 'web')->get(route('profile'))
        ->assertSeeInOrder(['Quality compliance', 'Not asked for your main craft now', 'Batik Standard entrepreneur', 'SPB-OLD'])
        ->assertDontSee('name="quality"', false);
});

it('asks the quality question of a main craft of TEKSTIL › Batik, Songket or Tenunan only', function () {
    $tekstil = KrafBidang::factory()->create(['name' => 'TEKSTIL']);
    $sub = fn (string $name) => KrafSubBidang::factory()->create(['bidang_id' => $tekstil->id, 'name' => $name]);
    [$batik, $tenunan, $tekatan] = [$sub('Batik'), $sub('Tenunan'), $sub('Tekatan')];
    $makes = fn (Company $c, KrafSubBidang $s, bool $main = true) => CompanyKraf::create(['company_id' => $c->id, 'bidang_id' => $tekstil->id, 'sub_bidang_id' => $s->id, 'is_primary' => $main]);

    // Nothing picked yet, whatever the office's craft code: not asked.
    $tenun = Company::factory()->create(['kraf_code' => 'TTN']);
    $this->actingAs($tenun->user, 'web');
    $this->get(route('profile'))->assertDontSee('Quality compliance');

    $makes($tenun, $tenunan);
    $this->get(route('profile'))
        ->assertSee('Quality compliance')
        ->assertSee('Songket / Tenunan Standard entrepreneur')
        ->assertDontSee('Batik Standard entrepreneur');

    $this->put(route('profile.update'), profileForm())->assertSessionHasErrors('quality');
    $this->put(route('profile.update'), profileForm(['quality' => 'batik']))->assertSessionHasErrors('quality');
    $this->put(route('profile.update'), profileForm(['quality' => 'songket_tenun']))->assertSessionHasErrors('quality_number');
    $this->put(route('profile.update'), profileForm(['quality' => 'songket_tenun'] + profileCert('quality', 'SPK-9')))
        ->assertSessionHasNoErrors();
    expect($tenun->fresh()->profile()['quality_cert']['number'])->toBe('SPK-9');

    // Batik as a second craft: not the main one, so its standard is not offered.
    $second = $makes($tenun, $batik, false);
    $this->get(route('profile'))->assertDontSee('Batik Standard entrepreneur');
    $this->put(route('profile.update'), profileForm(['quality' => 'batik'] + profileCert('quality', 'SPB-1')))->assertSessionHasErrors('quality');

    // Made the main craft: now Batik is the one asked.
    \App\Support\KrafSelections::makePrimary($tenun, $second);
    $this->get(route('profile'))->assertSee('Batik Standard entrepreneur')->assertDontSee('Songket / Tenunan Standard entrepreneur')
        ->assertDontSee('value="SPK-9"', false);

    // The Songket / Tenunan answer stays on record, now counted as missing...
    $kept = $tenun->fresh()->profile();
    expect($kept['quality'])->toBe('songket_tenun')->and($kept['quality_cert']['number'])->toBe('SPK-9')
        ->and(\App\Support\CompanyCredentials::missing($tenun->fresh()))->toContain('quality compliance');
    // ...and its certificate is no Batik one, even sent back with the same number and dates.
    $this->put(route('profile.update'), profileForm(['quality' => 'batik'] + profileCert('quality', 'SPK-9', file: false)))
        ->assertSessionHasErrors('quality_file');

    $this->put(route('profile.update'), profileForm(['quality' => 'batik'] + profileCert('quality', 'SPB-1')))->assertSessionHasNoErrors();
    expect($tenun->fresh()->profile()['quality_cert']['path'])->not->toBe($kept['quality_cert']['path']);

    // Tekatan has no standard: not asked, and not missing.
    auth()->forgetGuards();
    $tekat = Company::factory()->create(['kraf_code' => 'TTH']);
    $makes($tekat, $tekatan);
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
    $this->put(route('profile.contact'), ['entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no', 'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']], 'office_phone' => '03-3344 5566']);
    $this->put(route('profile.update'), profileForm(['recognition' => 'none', 'office_phone' => '09-9999 9999', 'staff' => [['name' => 'Lain', 'phone' => '019-000 0000']]]))
        ->assertSessionHasNoErrors();

    $company->refresh();
    expect($company->office_phone)->toBe('03-3344 5566')
        ->and($company->credentials['staff'])->toBe([['name' => 'Siti', 'phone' => '012-345 6789']])
        ->and($company->credentials['recognition'])->toBe('none');

    // The contact save keeps the answers.
    $this->put(route('profile.contact'), ['entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no', 'staff' => [['name' => 'Ahmad', 'phone' => '019-111 2222']], 'recognition' => 'tokoh']);
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

it('shows the admin what the participant keeps on the profile, files opened only by the company or its zone', function () {
    $company = Company::factory()->create(['state' => 'Perak', 'workshop_address' => 'Lot 9, Kampung Kraf', 'office_phone' => '05-123 4567']);
    $this->actingAs($company->user, 'web');
    $this->put(route('profile.contact'), ['entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no', 'staff' => [['name' => 'Siti Aminah', 'phone' => '012-345 6789'], ['name' => 'Ahmad', 'phone' => '019-111 2222']], 'workshop_address' => 'Lot 9, Kampung Kraf', 'office_phone' => '05-123 4567']);
    $this->put(route('profile.update'), profileForm(['recognition' => 'adiguru', 'classification' => 'yes',
        'recognition_file' => UploadedFile::fake()->create('adiguru.pdf', 100, 'application/pdf')] + profileCert('classification')))
        ->assertSessionHasNoErrors();
    // The company opens its own file.
    $this->get(route('companies.certificate', [$company, 'recognition']))->assertOk();
    auth()->forgetGuards();

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSeeInOrder(["Participant's profile", 'Complete',
            'Staff to contact', 'Siti Aminah (012-345 6789)', 'Ahmad (019-111 2222)', 'Lot 9, Kampung Kraf', '05-123 4567',
            'Adiguru Kraf', 'adiguru.pdf', 'PKT-001', 'classification.pdf'])
        ->assertSee(route('companies.certificate', [$company, 'classification']), false);
    $this->get(route('companies.certificate', [$company, 'classification']))->assertOk();
    $this->get(route('companies.certificate', [$company, 'quality']))->assertNotFound();
    auth()->forgetGuards();

    // Another zone, or another company: not theirs.
    $this->actingAs(stateAdmin('Johor'), 'admin')->get(route('companies.certificate', [$company, 'recognition']))->assertNotFound();
    auth()->forgetGuards();
    $this->actingAs(Company::factory()->create()->user, 'web')->get(route('companies.certificate', [$company, 'recognition']))->assertNotFound();
    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('companies.certificate', [$company, 'recognition']))->assertOk();
});

it('tells the admin what a profile still lacks', function () {
    $company = Company::factory()->create(['state' => 'Perak']);
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSee('Incomplete')
        ->assertSee('Still missing before they can join: entrepreneur category, recognition, classification certificate, staff to contact.');
});

it('leaves the business type to the office: the participant neither sees nor sets it', function () {
    $company = Company::factory()->create(['business_type' => 'Pemborong']);
    $this->actingAs($company->user, 'web');

    $this->get(route('profile'))->assertDontSee('id="business_type"', false);

    $this->put(route('profile.contact'), [
        'entrepreneur_category' => 'Agensi Swasta', 'oku' => 'no',
        'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']],
        'business_type' => 'Pengeluar atau Pedagang',
    ])->assertSessionHasNoErrors();
    expect($company->fresh()->business_type)->toBe('Pemborong');
});

it('keeps a business type from the old data that is not on the list', function () {
    $old = 'Pentahapan 2 (Pengilang/Pedagang,Pengeluar,Agensi)';
    $company = Company::factory()->create(['state' => 'Perak', 'business_type' => $old]);
    $user = $company->user;

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSee('<option value="'.e($old).'" selected>', false);
    $this->put(route('admin.companies.update', $company), [
        'name' => $company->name, 'state' => 'Perak', 'owner_name' => $user->name, 'ic_number' => $user->ic_number,
        'community_id' => 'KOM-OLD', 'email' => $user->email, 'business_type' => $old,
    ])->assertSessionHasNoErrors();
    expect($company->fresh()->business_type)->toBe($old);
    // Another company may not be given it: it is not on the list.
    $other = Company::factory()->create(['state' => 'Perak', 'business_type' => null]);
    $this->put(route('admin.companies.update', $other), [
        'name' => $other->name, 'state' => 'Perak', 'owner_name' => 'X', 'ic_number' => '900101-08-3333',
        'community_id' => 'KOM-X', 'email' => $other->user->email, 'business_type' => $old,
    ])->assertSessionHasErrors('business_type');
});

it('asks the entrepreneur category on the profile, one of the MICF form choices, and the office may correct it', function () {
    $company = Company::factory()->create(['state' => 'Perak']);
    $this->actingAs($company->user, 'web');
    $staff = ['oku' => 'no', 'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']]];

    $this->get(route('profile'))->assertSee('Skim Pengeluaran Inkubator Kraf</option>', false);

    $this->put(route('profile.contact'), $staff + ['entrepreneur_category' => 'Anything'])->assertSessionHasErrors('entrepreneur_category');
    $this->put(route('profile.contact'), $staff + ['entrepreneur_category' => 'Skim Pengeluaran Inkubator Kraf'])->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['entrepreneur_category'])->toBe('Skim Pengeluaran Inkubator Kraf');

    // The other card keeps it.
    $this->put(route('profile.update'), profileForm(['entrepreneur_category' => 'Agensi Swasta']))->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['entrepreneur_category'])->toBe('Skim Pengeluaran Inkubator Kraf');
    auth()->forgetGuards();

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSee('<option value="Skim Pengeluaran Inkubator Kraf" selected>', false);

    $user = $company->user;
    $this->put(route('admin.companies.update', $company), [
        'name' => $company->name, 'state' => 'Perak', 'owner_name' => $user->name, 'ic_number' => $user->ic_number,
        'community_id' => 'KOM-MICF', 'email' => $user->email, 'entrepreneur_category' => 'Agensi Swasta',
    ])->assertSessionHasNoErrors();
    $held = $company->fresh()->credentials;
    expect($held['entrepreneur_category'])->toBe('Agensi Swasta')
        ->and($held['staff'])->toBe([['name' => 'Siti', 'phone' => '012-345 6789']]);

    $this->put(route('admin.companies.update', $company), [
        'name' => $company->name, 'state' => 'Perak', 'owner_name' => $user->name, 'ic_number' => $user->ic_number,
        'community_id' => 'KOM-MICF', 'email' => $user->email, 'entrepreneur_category' => 'Pemborong',
    ])->assertSessionHasErrors('entrepreneur_category');
});

it('asks the OKU status, and the card number and a copy while it is yes', function () {
    $company = Company::factory()->create(['state' => 'Perak']);
    $this->actingAs($company->user, 'web');
    $card = ['entrepreneur_category' => 'Agensi Swasta', 'staff' => [['name' => 'Siti', 'phone' => '012-345 6789']]];
    $copy = fn (string $name = 'oku.pdf') => UploadedFile::fake()->create($name, 100, 'application/pdf');

    $this->put(route('profile.contact'), $card)->assertSessionHasErrors(['oku' => 'Say whether you are OKU.']);
    $this->put(route('profile.contact'), $card + ['oku' => 'maybe'])->assertSessionHasErrors('oku');
    $this->put(route('profile.contact'), $card + ['oku' => 'yes'])
        ->assertSessionHasErrors(['oku_number' => 'Enter your OKU card number.', 'oku_file' => 'Upload your OKU card.']);

    $this->put(route('profile.contact'), $card + ['oku' => 'yes', 'oku_number' => 'OKU-123', 'oku_file' => $copy()])->assertSessionHasNoErrors();
    $held = $company->fresh()->credentials;
    expect($held['oku'])->toBe('yes')->and($held['oku_cert']['number'])->toBe('OKU-123');
    $first = $held['oku_cert']['path'];
    Storage::disk('local')->assertExists($first);

    // Same number: the copy on file counts. A new number needs a new copy.
    $this->put(route('profile.contact'), $card + ['oku' => 'yes', 'oku_number' => 'OKU-123'])->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['oku_cert']['path'])->toBe($first);
    $this->put(route('profile.contact'), $card + ['oku' => 'yes', 'oku_number' => 'OKU-999'])->assertSessionHasErrors('oku_file');

    // The other card keeps it.
    $this->put(route('profile.update'), profileForm())->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['oku_cert']['path'])->toBe($first);
    auth()->forgetGuards();

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSeeInOrder(['OKU status', 'Yes, OKU', 'card OKU-123'])
        ->assertSee(route('companies.certificate', [$company, 'oku']), false);
    $this->get(route('companies.certificate', [$company, 'oku']))->assertOk();
    auth()->forgetGuards();

    // No longer OKU: the card goes, file and all.
    $this->actingAs($company->user->fresh(), 'web');
    $this->put(route('profile.contact'), $card + ['oku' => 'no'])->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['oku_cert'])->toBeNull();
    Storage::disk('local')->assertMissing($first);
});

it('counts a missing OKU card before the company may join', function () {
    $company = Company::factory()->withProfile()->create();
    expect(\App\Support\CompanyCredentials::missing($company))->toBe([]);

    $company->update(['credentials' => ['oku' => 'yes', 'oku_cert' => null] + $company->credentials]);
    expect(\App\Support\CompanyCredentials::missing($company->fresh()))->toBe(['OKU card']);
});

it('lets the office set the OKU status and card on the company form', function () {
    $company = Company::factory()->withProfile()->create(['state' => 'Perak']);
    $user = $company->user;
    $form = fn (array $more) => [
        'name' => $company->name, 'state' => 'Perak', 'owner_name' => $user->name, 'ic_number' => $user->ic_number,
        'community_id' => 'KOM-OKU', 'email' => $user->email, 'entrepreneur_category' => 'Agensi Swasta',
    ] + $more;
    $this->actingAs(stateAdmin('Perak'), 'admin');

    $this->get(route('admin.companies.edit', $company))->assertSee('name="oku_file"', false);

    $this->put(route('admin.companies.update', $company), $form(['oku' => 'yes']))
        ->assertSessionHasErrors(['oku_number' => 'Enter your OKU card number.', 'oku_file' => 'Upload your OKU card.']);
    $this->put(route('admin.companies.update', $company), $form([
        'oku' => 'yes', 'oku_number' => 'OKU-777', 'oku_file' => UploadedFile::fake()->create('kad.pdf', 100, 'application/pdf'),
    ]))->assertSessionHasNoErrors();
    $held = $company->fresh()->credentials;
    expect($held['oku'])->toBe('yes')->and($held['oku_cert']['number'])->toBe('OKU-777')
        ->and($held['staff'])->toBe([['name' => 'Siti Aminah', 'phone' => '012-345 6789']]);
    Storage::disk('local')->assertExists($held['oku_cert']['path']);

    // Saved again with the same number: the copy stays.
    $this->put(route('admin.companies.update', $company), $form(['oku' => 'yes', 'oku_number' => 'OKU-777']))->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['oku_cert']['path'])->toBe($held['oku_cert']['path']);

    $this->put(route('admin.companies.update', $company), $form(['oku' => 'no']))->assertSessionHasNoErrors();
    expect($company->fresh()->credentials['oku_cert'])->toBeNull();
    Storage::disk('local')->assertMissing($held['oku_cert']['path']);
});
