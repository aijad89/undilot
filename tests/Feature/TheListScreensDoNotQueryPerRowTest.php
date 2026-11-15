<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * A list screen must cost about the same whether it shows three rows or thirty.
 *
 * The test is written as a COMPARISON rather than a budget, deliberately: a
 * fixed number like "under 20 queries" is a number somebody raises when it
 * fails, and it says nothing about the shape of the problem. Rendering the same
 * screen at two sizes and asserting the difference is small catches the thing
 * that actually hurts — one more query per row — and keeps passing when
 * somebody legitimately adds a query that runs once.
 *
 * The system this replaces reached 53 queries on a twelve-row admin screen.
 */

/** Queries run while rendering $url, as a count. */
function queriesFor($test, string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->get($url)->assertSuccessful();

    $n = count(DB::getQueryLog());

    DB::disableQueryLog();
    DB::flushQueryLog();

    return $n;
}

/** Puts $n companies into $event's draw list, each with a plot to win. */
function fillDraw(Event $event, int $n, KrafBidang $code, PlotSize $size): void
{
    for ($i = 0; $i < $n; $i++) {
        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id, 'state' => 'Johor']);

        EventRegistration::create([
            'event_id' => $event->id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'code' => $code->code,
            'code_updated_at' => now(),
            'plot_size' => $size->size,
            'plot_id' => null,
            'confirmed_participation' => false,
            'confirmed_at' => null,
            'verified_by' => null,
            'verified_at' => now(),
            'first_verified_at' => now(),
            'hq_note' => null,
            'payment_note' => 'Resit '.$i,
        ]);

        Plot::create([
            'event_id' => $event->id,
            'plot_size_id' => $size->id,
            'company_id' => null,
            'allocated_to' => null,
            'bidang_id' => $code->id,
            'sub_bidang_id' => null,
            'plot_code' => 'DEWAN-TBPS'.$i,
            'is_allocated' => false,
            'allocation_type' => 'algorithm',
            'is_locked' => false,
            'allocated_at' => null,
        ]);
    }
}

function drawWorld(int $companies): array
{
    $code = KrafBidang::factory()->code('TBPS')->create();

    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'draft',
        'polling_deadline' => now()->addDays(3),
        'state' => null,
    ]);

    $size = PlotSize::create([
        'event_id' => $event->id,
        'size' => '3x3',
        'quantity' => $companies,
        'location' => 'Dewan Utama',
    ]);

    fillDraw($event, $companies, $code, $size);

    return [$event, $size];
}

it('renders the draw list at the same cost for 3 rows and 30', function () {
    $admin = superAdmin();

    [$small] = drawWorld(3);
    [$big] = drawWorld(30);

    $this->actingAs($admin, 'admin');

    $few = queriesFor($this, route('admin.events.registrations.index', $small));
    $many = queriesFor($this, route('admin.events.registrations.index', $big));

    // 27 more rows. Anything close to 27 more queries is one per row.
    expect($many - $few)->toBeLessThan(10,
        "The draw list ran {$few} queries for 3 rows and {$many} for 30 — that is a query per row.");
})->group('performance');

it('renders the Add Companies page at the same cost for 3 companies and 30', function () {
    // Half in the draw, half out: both kinds of row, each with its own actions.
    $admin = superAdmin();
    [$small] = drawWorld(3);
    [$big] = drawWorld(30);

    for ($i = 0; $i < 30; $i++) {
        Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor']);
    }

    $this->actingAs($admin, 'admin');

    $few = queriesFor($this, route('admin.events.registrations.companies', [$small, 'status' => 'in']));
    $many = queriesFor($this, route('admin.events.registrations.companies', [$big, 'status' => 'in']));
    $mixed = queriesFor($this, route('admin.events.registrations.companies', $big));

    expect($many - $few)->toBeLessThan(10,
        "Add Companies ran {$few} queries for 3 rows and {$many} for 30 — that is a query per row.")
        ->and($mixed - $few)->toBeLessThan(10,
            "Add Companies ran {$mixed} queries for a full page of rows in and out of the draw.");
})->group('performance');

it('renders the companies list at the same cost for 3 companies and 30', function () {
    $admin = superAdmin();

    for ($i = 0; $i < 3; $i++) {
        Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor']);
    }

    $this->actingAs($admin, 'admin');
    $few = queriesFor($this, route('admin.companies.index'));

    for ($i = 0; $i < 27; $i++) {
        Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor']);
    }

    $many = queriesFor($this, route('admin.companies.index'));

    expect($many - $few)->toBeLessThan(10,
        "The companies list ran {$few} queries for 3 and {$many} for 30 — that is a query per row.");
})->group('performance');

it('renders the public event list at the same cost for 3 events and 30', function () {
    for ($i = 0; $i < 3; $i++) {
        Event::factory()->create(['status' => 'open', 'state' => null]);
    }

    $few = queriesFor($this, route('home'));

    for ($i = 0; $i < 27; $i++) {
        Event::factory()->create(['status' => 'open', 'state' => null]);
    }

    $many = queriesFor($this, route('home'));

    expect($many - $few)->toBeLessThan(10,
        "The public event list ran {$few} queries for 3 events and {$many} for 30.");
})->group('performance');

it('renders the admin event list at the same cost for 3 events and 30', function () {
    $this->actingAs(superAdmin(), 'admin');

    for ($i = 0; $i < 3; $i++) {
        Event::factory()->create(['status' => 'open', 'state' => null]);
    }

    $few = queriesFor($this, route('admin.events.index'));

    for ($i = 0; $i < 27; $i++) {
        Event::factory()->create(['status' => 'open', 'state' => null]);
    }

    $many = queriesFor($this, route('admin.events.index'));

    expect($many - $few)->toBeLessThan(10,
        "The admin event list ran {$few} queries for 3 events and {$many} for 30.");
})->group('performance');
