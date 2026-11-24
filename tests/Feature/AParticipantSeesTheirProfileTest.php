<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * A participant's profile: the account as the office keeps it, read-only, and
 * the company's contact, certificates and crafts, theirs to keep. No password:
 * they sign in with community ID + IC.
 */
function profileOwner(): Company
{
    $user = User::factory()->create([
        'name' => 'Siti Aminah', 'phone' => '012-345 6789',
        'ic_number' => '800101-14-5678', 'password' => 'lama1234',
    ]);

    return Company::factory()->create(['user_id' => $user->id, 'name' => 'Seramik Klang Studio', 'state' => 'Selangor', 'plot_size' => '3x6', 'kraf_code' => 'TTK']);
}

it('lays the account out as the office form does, the IC number in full', function () {
    $company = profileOwner();

    $this->actingAs($company->user, 'web')->get(route('profile'))
        ->assertOk()
        // The office's own groups, labels and order: the company, then the account.
        ->assertSeeInOrder(['Your account', 'The company', 'Name', 'Seramik Klang Studio', 'Zone', 'Selangor', 'Registration no.',
            'The account', 'Salutation', 'Owner&#039;s name', 'Siti Aminah', 'Phone', '012-345 6789', 'IC number', '800101-14-5678',
            'Community ID', 'Address', 'Postcode', 'District', 'Save account'], false)
        // The booth size is the office's, not shown here.
        ->assertDontSee('Usual booth size')->assertDontSee('3x6')
        ->assertDontSee('Your company on record')
        // The craft code is the office's, never shown to the participant.
        ->assertDontSee('TTK');
});

it('is reached from the menu, and only when signed in', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));

    $company = profileOwner();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('href="'.route('profile').'"', false);
});

it('links to the password page, and shows the email they sign in with, read-only', function () {
    $company = profileOwner();

    $this->actingAs($company->user, 'web')->get(route('profile'))->assertOk()
        ->assertSee(route('password.edit'), false)
        ->assertSee('value="'.$company->user->email.'" disabled', false);
});

it('shows the community ID they sign in with', function () {
    $company = profileOwner();
    $company->user->update(['community_id' => 'KOM-0042']);

    $this->actingAs($company->user, 'web')->get(route('profile'))
        ->assertSeeInOrder(['Community ID', 'KOM-0042', 'Address'])
        // No email line at all: the project keeps none.
        ->assertDontSee('<dt>Email</dt>', false);
});

it('stacks its cards down one column: account, general information, crafts, certificates', function () {
    $this->actingAs(profileOwner()->user, 'web')->get(route('profile'))->assertOk()
        ->assertSeeInOrder(['class="ap-profile-stack"', 'Your account', 'General information', 'What you make', 'Recognition and certificates'], false)
        // The two side-by-side columns are gone, not just restyled.
        ->assertDontSee('class="ap-dash-side"', false)
        ->assertDontSee('ap-dash-grid ap-profile-grid', false);
});

it('fills the address in, its line breaks kept, or leaves it empty when there is none', function () {
    $company = profileOwner();
    $company->user->update(['address' => "No. 3, Jalan Seramik,\n41000 Klang"]);

    $this->actingAs($company->user, 'web')->get(route('profile'))->assertOk()
        ->assertSeeInOrder(['Your account', 'IC number', 'name="address"', "No. 3, Jalan Seramik,\n41000 Klang</textarea>", 'Staff to contact'], false);

    $company->user->update(['address' => null]);

    $this->get(route('profile'))
        ->assertSeeInOrder(['name="address"', '></textarea>', 'Staff to contact'], false);
});

it('is as wide as every other portal page: the column sets no width of its own', function () {
    $html = $this->actingAs(profileOwner()->user, 'web')->get(route('profile'))->getContent();

    preg_match('/\.ap-profile-stack\s*\{([^}]*)\}/', $html, $rule);

    expect($rule)->not->toBeEmpty()
        ->and(preg_match('/\b(max-)?width\s*:/', $rule[1]))->toBe(0);
});

it('lets the participant change their account, but never the zone, IC or community ID', function () {
    $company = profileOwner();
    $user = $company->user;
    $user->update(['community_id' => 'KOM-0042', 'salutation' => 'Tok']);
    $this->actingAs($user, 'web');

    // Shown, not sent: disabled fields with no name.
    $this->get(route('profile'))
        ->assertSee('id="acc_zone" value="Selangor" disabled', false)
        ->assertSee('id="acc_ic" value="800101-14-5678" disabled', false)
        ->assertSee('id="acc_community_id" value="KOM-0042" disabled', false)
        // An old salutation not on the list is still offered, chosen.
        ->assertSee('<option value="Tok" selected>', false);

    $this->put(route('profile.account'), [
        'name' => 'Seramik Klang Baharu', 'registration_no' => 'SA-77',
        'salutation' => 'Puan', 'owner_name' => 'Siti Aminah binti Ali', 'phone' => '019-222 3333',
        'address' => "Lot 9,\nKlang", 'postcode' => '41000', 'district' => 'Klang',
        // Sent anyway: ignored.
        'state' => 'Johor', 'ic_number' => '990101-01-1111', 'community_id' => 'KOM-HACK', 'kraf_code' => 'TBA', 'plot_size' => '3x9',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Account saved.');

    $company->refresh();
    $user->refresh();
    expect($company->only('name', 'registration_no', 'state', 'kraf_code', 'plot_size'))
        ->toBe(['name' => 'Seramik Klang Baharu', 'registration_no' => 'SA-77', 'state' => 'Selangor', 'kraf_code' => 'TTK', 'plot_size' => '3x6'])
        ->and($user->only('salutation', 'name', 'phone', 'address', 'postcode', 'district', 'ic_number', 'community_id'))
        ->toBe(['salutation' => 'Puan', 'name' => 'Siti Aminah binti Ali', 'phone' => '019-222 3333', 'address' => "Lot 9,\nKlang",
            'postcode' => '41000', 'district' => 'Klang', 'ic_number' => '800101-14-5678', 'community_id' => 'KOM-0042']);

    // Names are required; a salutation must come from the list.
    $this->put(route('profile.account'), ['name' => '', 'owner_name' => ''])->assertSessionHasErrors(['name', 'owner_name']);
    $this->put(route('profile.account'), ['name' => 'X', 'owner_name' => 'Y', 'salutation' => 'Raja'])->assertSessionHasErrors('salutation');
});
