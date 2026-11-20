<?php

use App\Models\Company;
use App\Models\User;

/*
 * The account card on the company form carries the owner's postal address:
 * typed by the office, stored on the user, read back into the form.
 */

function addressedCompany(?string $address = null): Company
{
    return Company::factory()->create([
        'name' => 'Batik Alamat', 'state' => 'Perak',
        'user_id' => User::factory()->create(['address' => $address])->id,
    ]);
}

function companyPut(Company $company, array $overrides = []): array
{
    return array_merge([
        'name' => $company->name, 'state' => 'Perak',
        'owner_name' => $company->user->name,
        'ic_number' => $company->user->ic_number, 'community_id' => 'KOM-0001',
        'is_active' => '1', 'account_active' => '1',
    ], $overrides);
}

it('saves the address from the edit form, and shows it back', function () {
    $company = addressedCompany();
    $address = "No. 12, Jalan Batik 3,\n31400 Ipoh, Perak";

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => $address]))
        ->assertSessionHas('success');

    expect($company->user->fresh()->address)->toBe($address);

    $this->get(route('admin.companies.edit', $company))->assertOk()
        ->assertSee('<textarea id="address" name="address"', false)
        ->assertSee(e($address), false);
});

it('saves the address when the company is created', function () {
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.companies.store'), [
        'name' => 'Baru Beralamat', 'state' => 'Perak',
        'owner_name' => 'Siti',
        'ic_number' => '900101-08-1111', 'community_id' => 'KOM-0002',
        'address' => 'Lot 5, Kampung Kraf, Kuala Kangsar',
    ])->assertSessionHasNoErrors();

    expect(User::where('community_id', 'KOM-0002')->value('address'))->toBe('Lot 5, Kampung Kraf, Kuala Kangsar');
});

it('clears the address when the field is emptied', function () {
    $company = addressedCompany('Old address');

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => '']))
        ->assertSessionHas('success');

    expect($company->user->fresh()->address)->toBeNull();
});

it('refuses an address longer than 500 characters', function () {
    $company = addressedCompany('Kept');

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => str_repeat('a', 501)]))
        ->assertSessionHasErrors('address');

    expect($company->user->fresh()->address)->toBe('Kept');
});

it('puts the password fields side by side, under one note', function () {
    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.companies.edit', addressedCompany()))->getContent();

    // Both fields come before the note: the note no longer sits under one of
    // them, making it taller than its neighbour.
    expect(strpos($html, 'id="password"'))->toBeLessThan(strpos($html, 'id="password_confirmation"'))
        ->and(strpos($html, 'id="password_confirmation"'))->toBeLessThan(strpos($html, 'Leave both empty to keep the current password.'));
});

it('edits the old profile fields the import brought in, and the participant sees them read-only', function () {
    $company = addressedCompany();
    $fields = ['salutation' => 'Puan', 'postcode' => '31400', 'district' => 'Kinta', 'registration_no' => 'SA0123456-X'];

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, $fields))
        ->assertSessionHas('success');

    expect($company->user->fresh()->only('salutation', 'postcode', 'district'))
        ->toBe(['salutation' => 'Puan', 'postcode' => '31400', 'district' => 'Kinta'])
        ->and($company->fresh()->registration_no)->toBe('SA0123456-X');
    $this->get(route('admin.companies.edit', $company))
        ->assertSee('<option value="Puan" selected>', false)->assertSee('value="31400"', false)
        ->assertSee('value="Kinta"', false)->assertSee('value="SA0123456-X"', false);

    $user = $company->user->fresh();
    $this->actingAs($user, 'web')->get(route('profile'))
        ->assertSeeInOrder(['Puan '.$user->name, 'Postcode', '31400', 'District', 'Kinta', 'Registration no.', 'SA0123456-X'])
        ->assertDontSee('name="postcode"', false)->assertDontSee('name="registration_no"', false);

    // Emptied: cleared, as the address is.
    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, array_fill_keys(array_keys($fields), '')));
    expect($company->fresh()->registration_no)->toBeNull()
        ->and($company->user->fresh()->district)->toBeNull();
});

it('takes the old profile fields when the company is created', function () {
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.companies.store'), [
        'name' => 'Baru Lengkap', 'state' => 'Perak', 'owner_name' => 'Siti',
        'ic_number' => '900101-08-2222', 'community_id' => 'KOM-0003',
        'salutation' => 'Cik', 'postcode' => '33000', 'district' => 'Kuala Kangsar', 'registration_no' => 'JM0999',
    ])->assertSessionHasNoErrors();

    $user = User::where('community_id', 'KOM-0003')->first();
    expect($user->only('salutation', 'postcode', 'district'))->toBe(['salutation' => 'Cik', 'postcode' => '33000', 'district' => 'Kuala Kangsar'])
        ->and($user->company->registration_no)->toBe('JM0999');
});

it('offers salutations as a list, and keeps one from the old data that is not on it', function () {
    $company = addressedCompany();
    $company->user->update(['salutation' => 'Tok']);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.companies.edit', $company))
        ->assertSee('<select id="salutation" name="salutation"', false)
        ->assertSee('<option value="Encik"', false)
        ->assertSee('<option value="Tok" selected>', false);

    // Saved untouched, it stays Tok rather than the first option.
    $this->put(route('admin.companies.update', $company), companyPut($company->fresh('user'), ['salutation' => 'Tok']))
        ->assertSessionHas('success');
    expect($company->user->fresh()->salutation)->toBe('Tok');
});
