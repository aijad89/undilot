<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * The sales book (owner, 2026-11-25): the .xlsx HQ types the exhibitors' paper
 * books into. undilot fills in who holds which lot, one sheet per craft code in
 * coding-sheet order, four payment rows per lot, one column per event day.
 */

function salesBookEvent(): Event
{
    $event = Event::factory()->create([
        'start_date' => '2026-09-24 09:00:00', // a Thursday
        'end_date' => '2026-09-26 22:00:00',
    ]);
    $size = PlotSize::factory()->create(['event_id' => $event->id]);

    $rkh = KrafBidang::factory()->code('RKH')->create(['bil' => 9]);
    $tbps = KrafBidang::factory()->code('TBPS')->create(['bil' => 1]);

    $user = User::factory()->create(['name' => 'SITI NABILAH', 'community_id' => '00123']);
    $company = Company::factory()->create(['user_id' => $user->id, 'name' => 'BATRAS COUTURE', 'state' => 'Kelantan']);

    Plot::factory()->on($size)->coded($tbps)->create(['plot_code' => 'TBPS02']);
    Plot::factory()->on($size)->coded($tbps)->create([
        'plot_code' => 'TBPS01', 'company_id' => $company->id, 'allocated_to' => $user->id, 'is_allocated' => true,
    ]);
    Plot::factory()->on($size)->coded($rkh)->create(['plot_code' => 'RKH01']);

    // An open-pool plot has no code; it files under its holder's draw entry.
    $other = Company::factory()->create();
    $pool = Plot::factory()->on($size)->create([
        'plot_code' => '3X3-01', 'company_id' => $other->id, 'allocated_to' => $other->user_id, 'is_allocated' => true,
    ]);
    EventRegistration::factory()->create([
        'event_id' => $event->id, 'company_id' => $other->id, 'code' => 'RKH', 'plot_id' => $pool->id,
    ]);

    return $event;
}

function downloadSalesBook(Event $event)
{
    $response = test()->get(route('admin.polling.sales-book', $event))->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'book').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    return IOFactory::load($path);
}

it('builds one sheet per code, in coding-sheet order, with each lot and its holder', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);

    expect($book->getSheetNames())->toBe(['TBPS', 'RKH']);

    $tbps = $book->getSheetByName('TBPS');
    expect([$tbps->getCell('B5')->getValue(), $tbps->getCell('C5')->getValue(), $tbps->getCell('D5')->getValue(),
        $tbps->getCell('E5')->getValue(), $tbps->getCell('F5')->getValue()])
        ->toBe(['TBPS01', 'BATRAS COUTURE', 'SITI NABILAH', 'Kelantan', '00123']);

    // Four payment rows per lot; the next lot starts below them, still empty.
    expect(array_map(fn ($r) => $tbps->getCell("G{$r}")->getValue(), range(5, 8)))->toBe(App\Support\SalesBook::PAYMENT_TYPES);
    expect($tbps->getCell('B9')->getValue())->toBe('TBPS02')
        ->and($tbps->getCell('C9')->getValue())->toBeEmpty();

    expect($book->getSheetByName('RKH')->getCell('B5')->getValue())->toBe('3X3-01')
        ->and($book->getSheetByName('RKH')->getCell('B9')->getValue())->toBe('RKH01');
});

it('gives one column per event day, totals each row and each payment type', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $ws = downloadSalesBook($event)->getSheetByName('TBPS');

    // 24–26 Sep: H, I, J; then the two total columns.
    expect([$ws->getCell('H3')->getValue(), $ws->getCell('I3')->getValue(), $ws->getCell('J3')->getValue()])
        ->toBe(['KHAMIS', 'JUMAAT', 'SABTU'])
        ->and($ws->getCell('K2')->getValue())->toBe('JUMLAH JUALAN')
        ->and($ws->getCell('L2')->getValue())->toBe('JUMLAH KESELURUHAN JUALAN');

    $ws->setCellValue('H5', 100)->setCellValue('I5', 50)->setCellValue('H6', 30)->setCellValue('H9', 7);

    expect($ws->getCell('K5')->getCalculatedValue())->toEqual(150)
        ->and($ws->getCell('L5')->getCalculatedValue())->toEqual(180)
        ->and($ws->getCell('A13')->getValue())->toBe('JUMLAH JUALAN TUNAI (RM)')
        ->and($ws->getCell('H13')->getCalculatedValue())->toEqual(107)
        ->and($ws->getCell('H14')->getCalculatedValue())->toEqual(30)
        ->and($ws->getCell('L17')->getCalculatedValue())->toEqual(187);
});

it('is HQ\'s: a state admin may not download it', function () {
    $event = salesBookEvent();
    $this->actingAs(stateAdmin(), 'admin');

    $this->get(route('admin.polling.sales-book', $event))->assertForbidden();
});

