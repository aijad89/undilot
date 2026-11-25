<?php

/*
 * The world the sales-book tests share: an event of 24–26 Sep with TBPS
 * (TBPS01 held by BATRAS COUTURE, TBPS02 empty) and RKH (the open-pool 3x6
 * stall 3X3-01 held under RKH, RKH01 empty), and its workbook downloaded.
 * Plain functions, required by each test file that uses them.
 */

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

function salesBookEvent(): Event
{
    $event = Event::factory()->create([
        'start_date' => '2026-09-24 09:00:00', // a Thursday
        'end_date' => '2026-09-26 22:00:00',
    ]);
    $size = PlotSize::factory()->create(['event_id' => $event->id]);

    $rkh = KrafBidang::factory()->code('RKH')->create(['bil' => 9, 'name' => 'HASIL RIMBA']);
    $tbps = KrafBidang::factory()->code('TBPS')->create(['bil' => 1, 'name' => 'TEKSTIL']);

    $user = User::factory()->create(['name' => 'SITI NABILAH', 'community_id' => '00123']);
    $company = Company::factory()->create(['user_id' => $user->id, 'name' => 'BATRAS COUTURE', 'state' => 'Kelantan']);

    Plot::factory()->on($size)->coded($tbps)->create(['plot_code' => 'TBPS02']);
    Plot::factory()->on($size)->coded($tbps)->create([
        'plot_code' => 'TBPS01', 'company_id' => $company->id, 'allocated_to' => $user->id, 'is_allocated' => true,
    ]);
    Plot::factory()->on($size)->coded($rkh)->create(['plot_code' => 'RKH01']);

    // An open-pool plot has no code; it files under its holder's draw entry.
    // A 3x6 stall, which RINGKASAN counts as two lots.
    $other = Company::factory()->create(['state' => 'Johor']);
    $pool = Plot::factory()->on(PlotSize::factory()->create(['event_id' => $event->id, 'size' => '3x6']))->create([
        'plot_code' => '3X3-01', 'company_id' => $other->id, 'allocated_to' => $other->user_id, 'is_allocated' => true,
    ]);
    EventRegistration::factory()->create([
        'event_id' => $event->id, 'company_id' => $other->id, 'code' => 'RKH', 'plot_id' => $pool->id,
    ]);

    return $event;
}

function downloadSalesBook(Event $event)
{
    $response = test()->get(route('admin.polling.sales-book.download', $event))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    return IOFactory::load($path);
}
