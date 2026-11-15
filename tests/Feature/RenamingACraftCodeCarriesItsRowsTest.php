<?php

use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\Poll;
use App\Models\PresetAllocation;
use App\Models\User;

/*
 * A craft code is held two ways, and a rename used to split them.
 *
 *   - by FOREIGN KEY: plots (and preset allocations) point at the kraf_bidangs
 *     row through `bidang_id`, so they follow a rename by themselves;
 *   - by STRING: `event_registrations.code`, `companies.kraf_code`,
 *     `polls.code` and `preset_allocations.code` hold the text.
 *
 * LotAllocator matches a registration's string against its plots' code. So
 * renaming TBPS to TBPX on the coding sheet moved every TBPS plot to TBPX and
 * left every TBPS company behind: no error, no warning, a plan and a draw list
 * that both looked right — and companies that could no longer win any lot.
 * Measured before the fix, not imagined.
 *
 * Codes change over time (the owner's word, and the reason the draw list's code
 * validation is loose), so a rename carries every row holding the old string,
 * in one transaction — as AdminZones::rename() carries a zone. It is a RELABEL,
 * not a re-coding: HQ verified the same craft under the old name, so nothing
 * is unverified and no company drops off its own dashboard. (Carrying the rows
 * through the model instead was tried as a mutation and survived, correctly:
 * booted() unverifies only for a state admin, who cannot reach this screen.)
 *
 * `plots.plot_code` is deliberately NOT carried: it is the booth label printed
 * on the stall and the slip (TBP01), not a key.
 *
 * Refused while a draw using the code is live: that is the one window where a
 * participant may be mid-reveal, and where the allocator may read the string in
 * one instant and the plots in the next.
 */

function codedWorld(string $status = 'draft'): array
{
    $code = KrafBidang::factory()->code('TBPS')->create();
    $user = User::factory()->create();

    $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);
    $company->kraf_code = 'TBPS';
    $company->save();

    $event = Event::factory()->create(['status' => 'open', 'polling_status' => $status, 'state' => null]);
    $size = PlotSize::create(['event_id' => $event->id, 'size' => '3x3', 'quantity' => 1, 'location' => 'Dewan']);

    $plot = Plot::create([
        'event_id' => $event->id, 'plot_size_id' => $size->id, 'company_id' => null, 'allocated_to' => null,
        'bidang_id' => $code->id, 'sub_bidang_id' => null, 'plot_code' => 'TBPS01', 'is_allocated' => false,
        'allocation_type' => 'algorithm', 'is_locked' => false, 'allocated_at' => null,
    ]);

    $verifiedAt = now()->subDay()->startOfSecond();

    $registration = EventRegistration::create([
        'event_id' => $event->id, 'company_id' => $company->id, 'user_id' => $user->id, 'code' => 'TBPS',
        'code_updated_at' => $verifiedAt, 'plot_size' => '3x3', 'plot_id' => null,
        'confirmed_participation' => false, 'confirmed_at' => null, 'verified_by' => null,
        'verified_at' => $verifiedAt, 'first_verified_at' => $verifiedAt, 'hq_note' => null, 'payment_note' => null,
    ]);

    $poll = Poll::create(['user_id' => $user->id, 'event_id' => $event->id, 'code' => 'TBPS']);

    $preset = PresetAllocation::create([
        'event_id' => $event->id, 'user_id' => $user->id, 'bidang_id' => $code->id, 'sub_bidang_id' => null,
        'plot_size_id' => $size->id, 'plot_number' => '1', 'plot_size' => '3x3', 'plot_code' => 'TBPS01',
        'code' => 'TBPS', 'priority_rank' => 1, 'is_confirmed' => false,
    ]);

    return compact('code', 'company', 'event', 'plot', 'registration', 'poll', 'preset', 'verifiedAt');
}

function renameTo(KrafBidang $code, string $to)
{
    return test()->put(route('admin.settings.kraf-codes.update', $code), [
        'code' => $to,
        'name' => $code->name,
        'bil' => $code->bil,
        'pecahan_bidang' => $code->pecahan_bidang,
        'pecahan_sub_bidang' => $code->pecahan_sub_bidang,
    ]);
}

it('carries every row holding the old code to the new one, so plots and companies still match', function () {
    $w = codedWorld();

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Code renamed TBPS → TBPX. 4 rows carried across.');

    expect($w['plot']->fresh()->bidang->code)->toBe('TBPX')          // by key, as before
        ->and($w['registration']->fresh()->code)->toBe('TBPX')       // the string the allocator matches
        ->and($w['company']->fresh()->kraf_code)->toBe('TBPX')
        ->and($w['poll']->fresh()->code)->toBe('TBPX')
        ->and($w['preset']->fresh()->code)->toBe('TBPX');
});

it('leaves the printed booth label alone', function () {
    $w = codedWorld();

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX');

    expect($w['plot']->fresh()->plot_code)->toBe('TBPS01')
        ->and($w['preset']->fresh()->plot_code)->toBe('TBPS01');
});

it('relabels without unverifying, so no company drops off its own dashboard', function () {
    $w = codedWorld();

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX');

    $row = $w['registration']->fresh();

    expect($row->verified_at?->toDateTimeString())->toBe($w['verifiedAt']->toDateTimeString())
        ->and($row->first_verified_at?->toDateTimeString())->toBe($w['verifiedAt']->toDateTimeString());
});

it('carries rows in a draw that has finished, which still show their code', function () {
    $w = codedWorld('completed');

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX')->assertSessionHasNoErrors();

    expect($w['registration']->fresh()->code)->toBe('TBPX');
});

it('touches no row carrying a different code', function () {
    $w = codedWorld();
    KrafBidang::factory()->code('RKP')->create();

    $other = Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor']);
    $other->kraf_code = 'RKP';
    $other->save();

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX');

    expect($other->fresh()->kraf_code)->toBe('RKP');
});

it('refuses a rename while a draw using the code is live, and changes nothing', function () {
    $w = codedWorld('polling');

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX')->assertSessionHas('error');

    expect($w['code']->fresh()->code)->toBe('TBPS')
        ->and($w['registration']->fresh()->code)->toBe('TBPS')
        ->and($w['company']->fresh()->kraf_code)->toBe('TBPS');
});

it('still lets the name be edited while a draw using the code is live', function () {
    $w = codedWorld('polling');

    $this->actingAs(superAdmin(), 'admin');
    $this->put(route('admin.settings.kraf-codes.update', $w['code']), [
        'code' => 'TBPS',
        'name' => 'Batik Pelangi',
        'bil' => $w['code']->bil,
        'pecahan_bidang' => $w['code']->pecahan_bidang,
        'pecahan_sub_bidang' => null,
    ])->assertSessionHas('success', 'Code saved.');

    expect($w['code']->fresh()->name)->toBe('Batik Pelangi');
});

it('writes one audit row naming both codes and what was carried', function () {
    $w = codedWorld();

    $this->actingAs(superAdmin(), 'admin');
    renameTo($w['code'], 'TBPX');

    $row = AuditTrail::where('action', 'kraf_code_renamed')->sole();

    expect($row->old_values)->toBe(['code' => 'TBPS'])
        ->and($row->new_values['code'])->toBe('TBPX')
        // toEqual: MySQL's JSON type reorders keys.
        ->and($row->new_values['carried'])->toEqual([
            'event_registrations' => 1,
            'companies' => 1,
            'polls' => 1,
            'preset_allocations' => 1,
        ]);
});
