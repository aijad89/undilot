<?php

use App\Models\Company;
use App\Models\KrafBidang;
use App\Models\KrafPengelasan;
use App\Models\KrafSubBidang;
use Illuminate\Support\Facades\DB;

/*
 * Two write doors no test named: adding a code to the coding sheet, and taking
 * a craft selection off a company.
 */

/* ── Adding a craft code ──────────────────────────────────────────────── */

function addCode(array $overrides = [])
{
    return test()->post(route('admin.settings.kraf-codes.store'), $overrides + [
        'code' => 'TBPS',
        'name' => 'Batik',
        'bil' => 7,
        'pecahan_bidang' => 'Tekstil',
        'pecahan_sub_bidang' => null,
    ]);
}

it('adds a code to the coding sheet, offered at once', function () {
    $this->actingAs(superAdmin(), 'admin');
    addCode()->assertSessionHas('success', 'Code TBPS added.');

    // On the CODE half of kraf_bidangs, never the category half, and active.
    $row = KrafBidang::codes()->where('code', 'TBPS')->sole();

    expect($row->is_active)->toBeTrue()
        ->and(KrafBidang::categories()->where('name', 'Batik')->exists())->toBeFalse();
});

it('refuses a code already on the sheet', function () {
    KrafBidang::factory()->code('TBPS')->create();

    $this->actingAs(superAdmin(), 'admin');
    addCode()->assertSessionHasErrors('code');

    expect(KrafBidang::codes()->where('code', 'TBPS')->count())->toBe(1);
});

it('refuses a code with no text, which would land on the category half', function () {
    // code NULL is what makes a row a category. An empty code must not become one.
    $this->actingAs(superAdmin(), 'admin');
    addCode(['code' => ''])->assertSessionHasErrors('code');

    expect(KrafBidang::count())->toBe(0);
});

it('leaves the coding sheet to the roles that manage settings', function () {
    $this->actingAs(stateAdmin('Johor'), 'admin');
    addCode()->assertForbidden();

    expect(KrafBidang::count())->toBe(0);
});

/* ── Taking a craft selection off a company ───────────────────────────── */

/** A company in $zone with one craft selection and one classification ticked. */
function selectionFor(string $zone): array
{
    $category = KrafBidang::factory()->create(['name' => 'TEKSTIL']);
    $sub = KrafSubBidang::factory()->create(['bidang_id' => $category->id, 'name' => 'Batik']);
    $canting = KrafPengelasan::factory()->create(['sub_bidang_id' => $sub->id]);
    $company = Company::factory()->create(['state' => $zone]);

    test()->actingAs(superAdmin(), 'admin')->post(route('admin.companies.kraf.store', $company), [
        'bidang_id' => $category->id, 'sub_bidang_id' => [$sub->id], 'pengelasan_id' => [$canting->id],
    ]);
    auth()->forgetGuards();

    return [$company, $company->krafSelections()->sole(), $canting];
}

it('takes a selection off a company, and its ticked classifications with it', function () {
    [$company, $selection] = selectionFor('Johor');

    // There to lose, or the count below proves nothing.
    expect(DB::table('company_kraf_pengelasan')->count())->toBe(1);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->delete(route('admin.companies.kraf.destroy', [$company, $selection]))
        ->assertSessionHas('success', 'Craft selection removed.');

    expect($company->krafSelections()->count())->toBe(0)
        ->and(DB::table('company_kraf_pengelasan')->count())->toBe(0);
});

it('refuses a selection that belongs to another company, named through this one', function () {
    [$owner, $selection] = selectionFor('Johor');
    $other = Company::factory()->create(['state' => 'Johor']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->delete(route('admin.companies.kraf.destroy', [$other, $selection]))
        ->assertNotFound();

    expect($owner->krafSelections()->count())->toBe(1);
});

it('refuses a state admin of another zone', function () {
    [$company, $selection] = selectionFor('Johor');

    $this->actingAs(stateAdmin('Kedah'), 'admin')
        ->delete(route('admin.companies.kraf.destroy', [$company, $selection]))
        ->assertForbidden();

    expect($company->krafSelections()->count())->toBe(1);
});
