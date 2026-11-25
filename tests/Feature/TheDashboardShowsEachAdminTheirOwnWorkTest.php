<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\KrafSubBidang;

/**
 * The admin dashboard: what needs fixing, the events, the companies, and
 * shortcuts. A state admin's counts are their own zone's and never include a
 * draft event; HQ and super count everything.
 */
function registered(Event $event, string $state, array $attributes = []): EventRegistration
{
    return EventRegistration::factory()->create($attributes + [
        'event_id' => $event->id,
        'company_id' => Company::factory()->create(['state' => $state])->id,
    ]);
}

it('counts draw-list rows with no code, in the admin\'s own zone only', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    registered($event, 'Perak', ['code' => null]);
    registered($event, 'Perak', ['code' => null]);
    registered($event, 'Perak', ['code' => 'TBP']);
    registered($event, 'Johor', ['code' => null]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('MICF Test: 2 draw-list rows with no craft code')
        ->assertSee('3 on draw list');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 3 draw-list rows with no craft code')
        ->assertSee('4 on draw list');
});

it('hides draft events from a state admin, and shows them to HQ and super', function () {
    Event::factory()->create(['title' => 'Secret Draft', 'status' => 'draft']);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))->assertDontSee('Secret Draft');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))->assertSee('Secret Draft');
});

it('flags companies with no craft category or no craft code, linking to the filtered list', function () {
    $category = KrafBidang::factory()->create(['name' => 'TEKSTIL']);
    $described = Company::factory()->create(['state' => 'Perak', 'kraf_code' => 'TBP', 'name' => 'Described Co']);
    CompanyKraf::factory()->create([
        'company_id' => $described->id, 'bidang_id' => $category->id,
        'sub_bidang_id' => KrafSubBidang::factory()->create(['bidang_id' => $category->id])->id,
    ]);
    Company::factory()->create(['state' => 'Perak', 'name' => 'Bare Co']);
    Company::factory()->create(['state' => 'Johor']);
    $admin = stateAdmin('Perak');

    $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 company with no craft category')
        ->assertSee('1 company with no craft code')
        ->assertSee(route('admin.companies.index', ['missing' => 'category']), false);

    $this->actingAs($admin, 'admin')->get(route('admin.companies.index', ['missing' => 'category']))
        ->assertSee('Bare Co')->assertDontSee('Described Co');
    $this->actingAs($admin, 'admin')->get(route('admin.companies.index', ['missing' => 'code']))
        ->assertSee('Bare Co')->assertDontSee('Described Co');
});

it('says all clear when nothing needs fixing', function () {
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertOk()->assertSee('All clear');
});

it('shows draw progress for an open draw, and who is still waiting', function () {
    $event = Event::factory()->create(['title' => 'Open Draw', 'polling_status' => 'polling', 'polling_deadline' => now()->addDays(2)]);
    registered($event, 'Perak', ['code' => 'TBP', 'confirmed_participation' => true]);
    registered($event, 'Perak', ['code' => 'TBP']);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('50% confirmed')
        ->assertSee('Open Draw: 1 company not confirmed yet');
});

it('gives each role only the shortcuts it may use, and zones only to HQ and super', function () {
    Company::factory()->create(['state' => 'Kedah']);

    $newEvent = route('admin.events.create');

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee(route('admin.companies.create'), false)
        ->assertDontSee($newEvent, false)->assertDontSee('By zone');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee($newEvent, false)->assertSee('By zone')->assertSee('Kedah');
});

it('tells HQ what waits to be verified, and each zone what HQ returned to it', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    registered($event, 'Perak', ['verified_at' => null]);
    registered($event, 'Johor', ['verified_at' => null]);
    registered($event, 'Perak', ['verified_at' => null, 'hq_note' => 'Wrong code.']);
    registered($event, 'Johor');

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 2 draw-list rows waiting for HQ to verify')
        ->assertDontSee('returned by HQ');

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 1 draw-list row returned by HQ')
        ->assertDontSee('waiting for HQ to verify');
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Johor'), 'admin')->get(route('admin.dashboard'))
        ->assertDontSee('returned by HQ');
});


it('does not count a join request the zone already approved as one to review', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    $approved = registered($event, 'Perak', ['verified_at' => null]);
    App\Models\EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => $approved->company_id]);
    App\Models\EventJoinRequest::factory()->create(['event_id' => $event->id, 'company_id' => Company::factory()->create(['state' => 'Perak'])->id]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 1 join request to review');
});

it('flags work on every current event, not only the few the events card lists', function () {
    // Six sooner events fill the card; the one with the request is seventh.
    Event::factory()->count(6)->create(['start_date' => now()->addMonth()]);
    $later = Event::factory()->create(['title' => 'Later Fair', 'start_date' => now()->addMonths(3)]);
    App\Models\EventJoinRequest::factory()->create(['event_id' => $later->id, 'company_id' => Company::factory()->create(['state' => 'Perak'])->id]);

    $page = $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('Later Fair: 1 join request to review');
    expect(substr_count($page->getContent(), 'Later Fair'))->toBe(1);
});

it('shows HQ what the zones rejected, with the reason, and not what HQ rejected itself', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    $zone = stateAdmin('Perak', ['name' => 'Zone Perak']);
    $reject = fn ($admin, $reason, $daysAgo = 0) => App\Models\EventJoinRequest::factory()->create([
        'event_id' => $event->id, 'company_id' => Company::factory()->create(['state' => 'Perak'])->id,
        'status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()->subDays($daysAgo),
    ]);
    $reject($zone, 'Receipt is blurry');
    $reject($zone, 'Old reason', 10);
    $reject(superAdmin(), 'HQ said no');

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 1 join request rejected by zones in the last 7 days');
    $this->get(route('admin.events.registrations.index', $event))
        ->assertSee('Rejected by zones (2)')
        ->assertSeeInOrder(['Receipt is blurry', 'Zone Perak'])
        ->assertDontSee('HQ said no');

    auth()->forgetGuards();
    $this->actingAs($zone, 'admin')->get(route('admin.dashboard'))->assertDontSee('rejected by zones');
    $this->get(route('admin.events.registrations.index', $event))->assertDontSee('Rejected by zones');
});

it('warns super and HQ of server setup that fails without an error, never on a dev machine', function () {
    // A notification stuck for an hour, one failed, and no scheduler heartbeat.
    \Illuminate\Support\Facades\DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subHour()->timestamp, 'created_at' => now()->subHour()->timestamp]);
    \Illuminate\Support\Facades\DB::table('failed_jobs')->insert(['uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

    // Here (testing) none of it is asked: a dev machine runs no worker or scheduler.
    expect(\App\Http\Controllers\Admin\DashboardController::serverProblems())->toBe([]);

    app()->detectEnvironment(fn () => 'production');
    $texts = array_column(\App\Http\Controllers\Admin\DashboardController::serverProblems(), 1);
    expect($texts[0])->toStartWith('Server: 1 notifications waiting since 1 hour ago — the queue worker is not running')
        ->and($texts[1])->toBe('Server: 1 notification could not be sent (failed_jobs).')
        ->and($texts[2])->toBe('Server: the scheduler has not run yet — draws will not open or close on their own.');

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('the queue worker is not running')->assertDontSee('All clear');
    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertDontSee('the queue worker is not running');

    // A fresh heartbeat and an empty queue: the scheduler and queue rows go.
    \Illuminate\Support\Facades\DB::table('jobs')->delete();
    \Illuminate\Support\Facades\DB::table('failed_jobs')->delete();
    \Illuminate\Support\Facades\Cache::forever(\App\Http\Controllers\Admin\DashboardController::SCHEDULER_HEARTBEAT, now()->timestamp);
    expect(array_column(\App\Http\Controllers\Admin\DashboardController::serverProblems(), 1))
        ->toBe(file_exists(public_path('storage')) ? [] : ['Server: php artisan storage:link has not been run — event banners do not show.']);
});

it('counts unpaid draw-list rows per event, linking to the filtered list', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    registered($event, 'Perak', ['payment_status' => 'unpaid']);
    registered($event, 'Perak', ['payment_status' => 'paid']);
    registered($event, 'Johor', ['payment_status' => 'unpaid']);

    $link = route('admin.events.registrations.index', [$event, 'payment' => 'unpaid']);
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('MICF Test: 1 draw-list row marked unpaid')
        ->assertSee($link, false);

    $this->get($link)->assertOk()->assertSee('Unpaid');
    expect($this->get($link)->viewData('registrations')->total())->toBe(1);
});

it('warns of certificates expired or expiring within 30 days, never a replaced one', function () {
    $soon = Company::factory()->create(['state' => 'Perak']);
    App\Models\CompanyCertificate::factory()->create(['company_id' => $soon->id, 'ends_on' => now()->addDays(10)->toDateString()]);
    $old = Company::factory()->create(['state' => 'Perak']);
    App\Models\CompanyCertificate::factory()->create(['company_id' => $old->id, 'ends_on' => now()->subYear()->toDateString(), 'replaced_at' => now()]);
    App\Models\CompanyCertificate::factory()->create(['company_id' => $old->id, 'ends_on' => now()->addYear()->toDateString()]);
    $other = Company::factory()->create(['state' => 'Johor']);
    App\Models\CompanyCertificate::factory()->create(['company_id' => $other->id, 'ends_on' => now()->subDay()->toDateString()]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 company with a certificate expired or expiring within 30 days')
        ->assertSee(route('admin.companies.index', ['missing' => 'certificate']), false);

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSee('2 companies with a certificate expired or expiring within 30 days');
    $this->get(route('admin.companies.index', ['missing' => 'certificate']))->assertOk()
        ->assertSee($soon->name)->assertSee($other->name)->assertDontSee($old->name);
});

it('counts companies that cannot sign in: no email, or never signed in', function () {
    $noEmail = Company::factory()->create(['state' => 'Perak', 'user_id' => App\Models\User::factory()->create(['email' => null, 'password_changed_at' => null])->id]);
    $never = Company::factory()->create(['state' => 'Perak', 'user_id' => App\Models\User::factory()->create(['password_changed_at' => null])->id]);
    $fine = Company::factory()->create(['state' => 'Perak']);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 company with no email')
        ->assertSee('1 company with an email but never signed in');

    $this->get(route('admin.companies.index', ['missing' => 'email']))->assertSee($noEmail->name)->assertDontSee($never->name)->assertDontSee($fine->name);
    $this->get(route('admin.companies.index', ['missing' => 'login']))->assertSee($never->name)->assertDontSee($noEmail->name)->assertDontSee($fine->name);
});

it('shows HQ the sales card, and never a state admin', function () {
    $old = Event::factory()->create(['title' => 'Pameran Lama', 'start_date' => now()->subYear(), 'end_date' => now()->subYear()->addDays(2)]);
    $new = Event::factory()->create(['title' => 'Pameran Baru', 'start_date' => now()->subMonth(), 'end_date' => now()->subMonth()->addDays(2)]);
    foreach ([[$old, 'TUNAI', 5000], [$new, 'TUNAI', 1000], [$new, 'KAD KREDIT', 250.5]] as [$event, $type, $amount]) {
        $upload = App\Models\SalesBookUpload::create(['event_id' => $event->id, 'admin_id' => null, 'filename' => 'x.xlsx', 'entries' => 1]);
        Illuminate\Support\Facades\DB::table('sales_book_entries')->insert([
            'upload_id' => $upload->id, 'event_id' => $event->id, 'sheet' => 'TBPS', 'sheet_index' => 0, 'block' => 1,
            'payment_type' => $type, 'sale_date' => now()->toDateString(), 'amount' => $amount,
        ]);
    }

    $this->actingAs(superAdmin(['role' => 'hq']), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Sales', 'RM 1,250.50', 'Pameran Baru', 'RM 6,250.50', '2', 'events with sales'])
        ->assertSeeInOrder(['Top events', 'Pameran Lama', 'Pameran Baru'])
        ->assertSeeInOrder(['TUNAI', 'RM 6,000.00', 'KAD KREDIT', 'RM 250.50']);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))->assertDontSee('events with sales');
});

it('breaks each event\'s join requests down, in the admin\'s own zone', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    $perak = fn (array $row) => App\Models\EventJoinRequest::factory()->create($row + [
        'event_id' => $event->id, 'company_id' => Company::factory()->create(['state' => 'Perak'])->id,
    ]);
    $perak(['status' => 'pending', 'payment_method' => 'waiver', 'waiver_category' => 'first_time']);
    $perak(['status' => 'approved']);
    $perak(['status' => 'rejected', 'reject_reason' => 'No receipt']);
    App\Models\EventJoinRequest::factory()->create([
        'event_id' => $event->id, 'company_id' => Company::factory()->create(['state' => 'Johor'])->id, 'status' => 'approved',
    ]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Join requests:', '1 to review', '1 approved', '1 rejected', '1 asking for a fee waiver']);

    auth()->forgetGuards();
    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Join requests:', '1 to review', '2 approved', '1 rejected']);
});

it('shows each event\'s lots by location and size, a set-aside lot free to a zone', function () {
    $event = Event::factory()->create(['title' => 'MICF Test']);
    $hall = App\Models\PlotSize::factory()->create(['event_id' => $event->id, 'location' => 'Dewan A', 'size' => '3x3', 'quantity' => 3]);
    $field = App\Models\PlotSize::factory()->create(['event_id' => $event->id, 'location' => 'Padang', 'size' => '3x6', 'quantity' => 1]);
    App\Models\Plot::factory()->create(['event_id' => $event->id, 'plot_size_id' => $hall->id, 'is_allocated' => true]);
    App\Models\Plot::factory()->create(['event_id' => $event->id, 'plot_size_id' => $hall->id]);
    // A preset nobody has confirmed yet: HQ sees it held, a zone sees it free.
    App\Models\Plot::factory()->create(['event_id' => $event->id, 'plot_size_id' => $hall->id, 'is_allocated' => true,
        'allocation_type' => 'preset', 'allocated_to' => App\Models\User::factory()->create()->id]);
    App\Models\Plot::factory()->create(['event_id' => $event->id, 'plot_size_id' => $field->id]);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Dewan A 3x3:', '2/3', 'Padang 3x6:', '0/1'], false);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Dewan A 3x3:', '1/3'], false);
});

it('lists the last finished events with their draw list, lots and — for HQ — sales', function () {
    $done = Event::factory()->create(['title' => 'Pameran Selesai', 'start_date' => now()->subMonth(), 'end_date' => now()->subMonth()->addDays(2)]);
    Event::factory()->create(['title' => 'Pameran Datang', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addDays(2)]);
    registered($done, 'Perak');
    registered($done, 'Johor');
    $upload = App\Models\SalesBookUpload::create(['event_id' => $done->id, 'admin_id' => null, 'filename' => 'x.xlsx', 'entries' => 1]);
    Illuminate\Support\Facades\DB::table('sales_book_entries')->insert([
        'upload_id' => $upload->id, 'event_id' => $done->id, 'sheet' => 'TBPS', 'sheet_index' => 0, 'block' => 1,
        'payment_type' => 'TUNAI', 'sale_date' => now()->toDateString(), 'amount' => 700,
    ]);

    $this->actingAs(superAdmin(['role' => 'hq']), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Recently finished', 'Pameran Selesai', '2 on draw list', 'RM 700.00 sales'])
        ->assertSee(route('admin.sales.show', $done), false);

    auth()->forgetGuards();
    $page = $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Recently finished', 'Pameran Selesai', '1 on draw list'])
        ->assertDontSee('RM 700.00 sales');
    expect(str($page->getContent())->after('Recently finished')->contains('Pameran Datang'))->toBeFalse();
});

it('flags officers not signed in for 30 days: state admins to HQ, everyone to super', function () {
    stateAdmin('Perak', ['last_seen_at' => now()->subDays(40)]);
    stateAdmin('Johor', ['last_seen_at' => now()->subDay()]);
    stateAdmin('Kedah', ['last_seen_at' => null, 'is_active' => false]);
    superAdmin(['role' => 'hq', 'last_seen_at' => now()->subDays(60)]);

    $hq = superAdmin(['role' => 'hq', 'last_seen_at' => now()]);
    $this->actingAs($hq, 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 state officer not signed in for 30 days')
        ->assertDontSee('HQ/super officer');

    auth()->forgetGuards();
    $this->actingAs(superAdmin(['last_seen_at' => now()]), 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 state officer not signed in for 30 days')
        ->assertSee('1 HQ/super officer not signed in for 30 days')
        ->assertSee(route('admin.admins.state', ['idle' => 1]), false);

    $list = $this->get(route('admin.admins.state', ['idle' => 1]))->assertOk()->viewData('admins');
    expect($list->pluck('state')->all())->toBe(['Perak']);
});

it('shows how many places of the event are filled, event-wide, even to a zone', function () {
    $event = Event::factory()->create(['title' => 'MICF Test', 'max_participants' => 3]);
    registered($event, 'Perak');
    registered($event, 'Johor');

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 on draw list')
        ->assertSee('2/3 places filled');

    registered($event, 'Kedah');
    $this->get(route('admin.dashboard'))->assertSee('<span class="dash-full">3/3 places filled</span>', false);
});

it('says when registration opens or closes, before the draw dates', function () {
    Event::factory()->create(['title' => 'Belum Buka', 'registration_open_at' => now()->addDays(5)->addHour(), 'registration_close_at' => now()->addDays(20)]);
    Event::factory()->create(['title' => 'Sedang Buka', 'registration_open_at' => now()->subDay(), 'registration_close_at' => now()->addDays(3)->addHour(),
        'polling_start_date' => now()->addDays(10)]);
    Event::factory()->create(['title' => 'Sudah Tutup', 'registration_open_at' => now()->subDays(9), 'registration_close_at' => now()->subDay(),
        'polling_start_date' => now()->addDays(2)->addHour()]);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['Belum Buka', 'Registration opens 5 days from now'], false)
        ->assertSeeInOrder(['Sedang Buka', 'Registration closes 3 days from now'], false)
        ->assertSeeInOrder(['Sudah Tutup', 'Draw opens 2 days from now'], false);
});

it('counts companies whose profile is not complete, in the admin\'s own zone', function () {
    $bare = Company::factory()->create(['state' => 'Perak']);
    $ready = Company::factory()->withProfile()->create(['state' => 'Perak']);
    Company::factory()->create(['state' => 'Johor']);
    Company::factory()->create(['state' => 'Perak', 'is_active' => false]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))
        ->assertSee('1 company with an incomplete profile')
        ->assertSee(route('admin.companies.index', ['missing' => 'profile']), false);

    $this->get(route('admin.companies.index', ['missing' => 'profile']))->assertOk()
        ->assertSee('Profile incomplete')->assertSee($bare->name)->assertDontSee($ready->name);
});

it('shows HQ how long it takes to verify a zone\'s row, leaving out rows HQ placed itself', function () {
    $event = Event::factory()->create();
    $placed = fn (string $state, $created, $verified) => registered($event, $state, [
        'created_at' => $created, 'first_verified_at' => $verified, 'verified_at' => $verified,
    ]);
    $placed('Perak', now()->subDays(10), now()->subDays(8));              // 2 days
    $placed('Perak', now()->subDays(10), now()->subDays(6));              // 4 days
    $placed('Johor', now()->subDays(5), now()->subDays(4));               // 1 day
    $placed('Johor', now()->subDays(3), now()->subDays(3)->addSeconds(5)); // HQ placed it: left out
    $placed('Kedah', now()->subDays(200), now()->subDays(100));           // before the 90 days

    $this->actingAs(superAdmin(['role' => 'hq']), 'admin')->get(route('admin.dashboard'))
        ->assertSeeInOrder(['HQ verification time', '2.3 days', 'over 3 rows', 'Perak', '3 days', 'Johor', '1 day<'], false);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.dashboard'))->assertDontSee('HQ verification time');
});
