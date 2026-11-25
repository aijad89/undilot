<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\SalesBookTab;
use App\Models\User;
use App\Support\SalesBook;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
 * The sales book (owner, 2026-11-25): the .xlsx HQ types the exhibitors' paper
 * books into. undilot fills in who holds which lot, one sheet per craft code in
 * coding-sheet order, four payment rows per lot, one column per event day.
 * Programmes outside the draw are tabs HQ adds on the sales-book page; each
 * becomes the same sheet with empty exhibitor blocks.
 */

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

it('builds one sheet per code, in coding-sheet order, with each lot and its holder', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);

    expect($book->getSheetNames())->toBe([...SalesBook::SUMMARY_SHEETS, 'TBPS', 'RKH']);

    $tbps = $book->getSheetByName('TBPS');
    expect([$tbps->getCell('B5')->getValue(), $tbps->getCell('C5')->getValue(), $tbps->getCell('D5')->getValue(),
        $tbps->getCell('E5')->getValue(), $tbps->getCell('F5')->getValue()])
        ->toBe(['TBPS01', 'BATRAS COUTURE', 'SITI NABILAH', 'Kelantan', '00123']);

    // Four payment rows per lot; the next lot starts below them, still empty.
    expect(array_map(fn ($r) => $tbps->getCell("G{$r}")->getValue(), range(5, 8)))->toBe(SalesBook::PAYMENT_TYPES);
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

it('sums every sheet into SALEBYDAY, RINGKASAN KESELURUHAN and RING1, codes under their category', function () {
    $event = salesBookEvent();
    SalesBookTab::factory()->create(['event_id' => $event->id, 'name' => 'KARYANEKA', 'blocks' => 2]);
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100)->setCellValue('I5', 50)->setCellValue('H6', 30);
    $book->getSheetByName('RKH')->setCellValue('H5', 7);
    $book->getSheetByName('KARYANEKA')->setCellValue('H5', 5)->setCellValue('C5', 'KEDAI A');
    $value = fn (string $sheet, string $cell) => $book->getSheetByName($sheet)->getCell($cell)->getCalculatedValue();

    // SALEBYDAY: TEKSTIL › TBPS on rows 5–8 and its subtotal on 9, HASIL RIMBA
    // › RKH on 10–14, the tab on 15–18, the footer from 19. Days E–G.
    $day = $book->getSheetByName('SALEBYDAY');
    expect([$day->getCell('B5')->getValue(), $day->getCell('C5')->getValue(), $day->getCell('A9')->getValue(), $day->getCell('C15')->getValue()])
        ->toBe(['TEKSTIL', 'TBPS', 'JUMLAH TEKSTIL', 'KARYANEKA'])
        ->and([$value('SALEBYDAY', 'E5'), $value('SALEBYDAY', 'F5'), $value('SALEBYDAY', 'H5'), $value('SALEBYDAY', 'I5')])->toEqual([100, 50, 150, 180])
        ->and($value('SALEBYDAY', 'E9'))->toEqual(130)
        ->and($value('SALEBYDAY', 'E10'))->toEqual(7)
        ->and([$value('SALEBYDAY', 'E19'), $value('SALEBYDAY', 'E20')])->toEqual([112, 30])
        ->and($value('SALEBYDAY', 'I23'))->toEqual(192);

    // By category, then by payment type.
    expect([$value('RINGKASAN KESELURUHAN', 'B6'), $value('RINGKASAN KESELURUHAN', 'C6'), $value('RINGKASAN KESELURUHAN', 'F6')])->toEqual(['TEKSTIL', 130, 180])
        ->and([$value('RINGKASAN KESELURUHAN', 'C7'), $value('RINGKASAN KESELURUHAN', 'C8'), $value('RINGKASAN KESELURUHAN', 'C9')])->toEqual([7, 5, 142])
        ->and([$value('RINGKASAN KESELURUHAN', 'B14'), $value('RINGKASAN KESELURUHAN', 'C14')])->toEqual(['TUNAI', 112]);

    // RING1 counts the names on each sheet: the drawn lot, and the one HQ typed.
    expect([$value('RING1', 'D4'), $value('RING1', 'E4'), $value('RING1', 'F4'), $value('RING1', 'I4')])->toEqual([1, 150, 30, 180])
        ->and([$value('RING1', 'D6'), $value('RING1', 'E6')])->toEqual([1, 5])
        ->and([$value('RING1', 'D7'), $value('RING1', 'I7')])->toEqual([3, 192]);
});

it('counts who drew a lot by state, code and size in RINGKASAN, a 3x6 as two lots', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $ws = downloadSalesBook($event)->getSheetByName('RINGKASAN');
    $rows = collect(range(6, $ws->getHighestRow()))->mapWithKeys(fn ($r) => [(string) $ws->getCell("A{$r}")->getValue().'|'.$ws->getCell("B{$r}")->getValue() => $r])
        ->mapWithKeys(fn ($r, $k) => [str_contains($k, 'JUMLAH') ? explode('|', $k)[0] : explode('|', $k)[1] => $r]);

    // Columns C, D: TBPS 3X3, RKH 3X6. Then JUMLAH.
    expect([$ws->getCell('C4')->getValue(), $ws->getCell('C5')->getValue(), $ws->getCell('D4')->getValue(), $ws->getCell('D5')->getValue(), $ws->getCell('D3')->getValue()])
        ->toBe(['TBPS', '3X3', 'RKH', '3X6', 'HASIL RIMBA'])
        ->and($ws->getCell('C'.$rows['Kelantan'])->getValue())->toEqual(1)
        ->and($ws->getCell('D'.$rows['Johor'])->getValue())->toEqual(1)
        ->and($ws->getCell('E'.$rows['JUMLAH USAHAWAN'])->getCalculatedValue())->toEqual(2)
        ->and($ws->getCell('E'.$rows['JUMLAH LOT'])->getCalculatedValue())->toEqual(3);
});

it('counts a stall as its size in 3x3 lots', function (string $size, int $lots) {
    expect(SalesBook::lotWeight($size))->toBe($lots);
})->with([['3x3', 1], ['3x6', 2], ['3X9', 3], ['3x12', 4], ['6 x 6', 4], ['', 1]]);

it('adds a tab on the page and builds it after the code sheets, its blocks empty', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $this->get(route('admin.polling.sales-book.show', $event))->assertOk()->assertSee('TBPS')->assertSee('Download workbook');
    $this->post(route('admin.polling.sales-book.tabs.store', $event), ['name' => ' karyaneka ', 'blocks' => 3])
        ->assertSessionHasNoErrors();

    expect($event->salesBookTabs()->pluck('name')->all())->toBe(['KARYANEKA']);

    $book = downloadSalesBook($event);
    expect($book->getSheetNames())->toBe([...SalesBook::SUMMARY_SHEETS, 'TBPS', 'RKH', 'KARYANEKA']);

    $ws = $book->getSheetByName('KARYANEKA');
    expect([$ws->getCell('A5')->getValue(), $ws->getCell('A9')->getValue(), $ws->getCell('A13')->getValue()])->toEqual([1, 2, 3])
        ->and($ws->getCell('B5')->getValue())->toBeEmpty()
        ->and($ws->getCell('A17')->getValue())->toBe('JUMLAH JUALAN TUNAI (RM)');
});

it('refuses a tab name Excel would refuse or that is already a sheet', function (string $name) {
    $event = salesBookEvent();
    SalesBookTab::factory()->create(['event_id' => $event->id, 'name' => 'PUSPANITA']);
    $this->actingAs(superAdmin(), 'admin');

    $this->post(route('admin.polling.sales-book.tabs.store', $event), ['name' => $name, 'blocks' => 5])
        ->assertSessionHasErrors('name');

    expect($event->salesBookTabs()->count())->toBe(1);
})->with(['taken' => 'puspanita', 'a craft code' => 'tbps', 'a summary sheet' => 'ring1', 'the no-code sheet' => 'tanpa kod', 'a slash' => 'A/B', 'too long' => str_repeat('X', 32)]);

it('removes a tab, but only through its own event', function () {
    $event = salesBookEvent();
    $tab = SalesBookTab::factory()->create(['event_id' => $event->id]);
    $other = SalesBookTab::factory()->create();
    $this->actingAs(superAdmin(), 'admin');

    $this->delete(route('admin.polling.sales-book.tabs.destroy', [$event, $other]))->assertNotFound();
    $this->delete(route('admin.polling.sales-book.tabs.destroy', [$event, $tab]))->assertRedirect();

    expect(SalesBookTab::pluck('id')->all())->toBe([$other->id]);
});

it('is HQ\'s: a state admin may not open, change or download it', function () {
    $event = salesBookEvent();
    $tab = SalesBookTab::factory()->create(['event_id' => $event->id]);
    $this->actingAs(stateAdmin(), 'admin');

    $this->get(route('admin.polling.sales-book.show', $event))->assertForbidden();
    $this->get(route('admin.polling.sales-book.download', $event))->assertForbidden();
    $this->post(route('admin.polling.sales-book.tabs.store', $event), ['name' => 'X', 'blocks' => 1])->assertForbidden();
    $this->delete(route('admin.polling.sales-book.tabs.destroy', [$event, $tab]))->assertForbidden();

    expect(SalesBookTab::count())->toBe(1);
});

