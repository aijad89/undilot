<?php

use App\Models\Event;
use App\Models\Plot;
use App\Models\SalesBookEntry;
use App\Models\SalesBookTab;
use App\Models\SalesBookUpload;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__.'/../Support/sales-book.php';

/*
 * The filled sales book, uploaded back (owner, 2026-11-25): HQ types the sales
 * into the workbook undilot made and uploads it; every sale is kept, one row
 * per exhibitor × day × payment type, and shown on the Sales page: a list of
 * events, each title opening that event's tables.
 *
 * All or nothing, and each upload replaces the last. The world is
 * tests/Support/sales-book.php: TBPS01
 * held by BATRAS COUTURE, TBPS02 empty, RKH with 3X3-01 held and RKH01; days
 * 24–26 Sep in H, I, J.
 */

function asUpload(Spreadsheet $book, string $name = 'buku.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up').'.xlsx';
    (new Xlsx($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

function uploadSalesBook(Event $event, UploadedFile $file)
{
    return test()->post(route('admin.polling.sales-book.upload', $event), ['workbook' => $file]);
}

it('keeps every sale typed into the workbook, linked to its lot, and shows it', function () {
    $event = salesBookEvent();
    SalesBookTab::factory()->create(['event_id' => $event->id, 'name' => 'KARYANEKA', 'blocks' => 2]);
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100)->setCellValue('I5', '1,250.50')->setCellValue('J6', 30)->setCellValue('H7', '-');
    $book->getSheetByName('KARYANEKA')->setCellValue('C9', 'KEDAI B')->setCellValue('J12', 5);

    uploadSalesBook($event, asUpload($book))->assertSessionHasNoErrors()->assertSessionHas('success', '4 sales read.');

    $sales = SalesBookEntry::orderBy('id')->get();
    expect($sales->map(fn ($s) => [$s->sheet, $s->lot, $s->company_name, $s->payment_type, $s->sale_date->toDateString(), (float) $s->amount])->all())->toBe([
        ['TBPS', 'TBPS01', 'BATRAS COUTURE', 'TUNAI', '2026-09-24', 100.0],
        ['TBPS', 'TBPS01', 'BATRAS COUTURE', 'TUNAI', '2026-09-25', 1250.5],
        ['TBPS', 'TBPS01', 'BATRAS COUTURE', 'KAD KREDIT', '2026-09-26', 30.0],
        ['KARYANEKA', null, 'KEDAI B', 'RUNDINGAN', '2026-09-26', 5.0],
    ]);

    $lot = Plot::where('event_id', $event->id)->where('plot_code', 'TBPS01')->first();
    expect($sales->first()->plot_id)->toBe($lot->id)
        ->and($sales->first()->company_id)->toBe($lot->company_id)
        ->and($sales->last()->plot_id)->toBeNull();

    $this->get(route('admin.sales.show', [$event, 'sheet' => 'TBPS']))->assertOk()
        ->assertSee('Sales by sheet')->assertSee('1,380.50')->assertSee('1,385.50')
        ->assertSee('TBPS: sales by exhibitor')->assertSee('BATRAS COUTURE');
});

it('lists every event on the Sales page, each title opening its tables', function () {
    $event = salesBookEvent();
    $quiet = Event::factory()->create(['title' => 'Pameran Tanpa Jualan']);
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 1500);
    uploadSalesBook($event, asUpload($book))->assertSessionHasNoErrors();

    $this->get(route('admin.sales.index'))->assertOk()
        ->assertSee(route('admin.sales.show', $event), false)
        ->assertSee(route('admin.sales.show', $quiet), false)
        ->assertSee('1,500.00')
        ->assertSee('Nothing uploaded');

    $this->get(route('admin.sales.show', $quiet))->assertOk()->assertSee('No sales uploaded for this event yet');
    $this->get(route('admin.polling.sales-book.show', $event))->assertOk()->assertSee(route('admin.sales.show', $event), false);
});

it('keeps the Sales page from a state admin', function () {
    $event = salesBookEvent();
    $this->actingAs(stateAdmin(), 'admin');

    $this->get(route('admin.sales.index'))->assertForbidden();
    $this->get(route('admin.sales.show', $event))->assertForbidden();
});

it('saves nothing when a cell is not a number, and names the cell', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100)->setCellValue('I6', 'seratus');

    uploadSalesBook($event, asUpload($book))->assertSessionHasErrors(['workbook' => 'TBPS!I6: "seratus" is not a number']);

    expect(SalesBookEntry::count())->toBe(0)->and(SalesBookUpload::count())->toBe(0);
});

it('replaces the last upload, so a figure cleared in Excel is cleared here', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100)->setCellValue('H6', 40);
    uploadSalesBook($event, asUpload($book, 'first.xlsx'))->assertSessionHasNoErrors();

    $book->getSheetByName('TBPS')->setCellValue('H6', null);
    uploadSalesBook($event, asUpload($book, 'second.xlsx'))->assertSessionHasNoErrors();

    expect(SalesBookUpload::pluck('filename')->all())->toBe(['second.xlsx'])
        ->and(SalesBookEntry::pluck('amount')->map(fn ($a) => (float) $a)->all())->toBe([100.0]);
});

it('refuses a workbook with no sales in it, and keeps what was there', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100);
    uploadSalesBook($event, asUpload($book))->assertSessionHasNoErrors();

    uploadSalesBook($event, asUpload(downloadSalesBook($event)))->assertSessionHasErrors('workbook');

    expect(SalesBookEntry::count())->toBe(1);
});

it('reads a sheet HQ copied in Excel, and says which sheets it left out', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');

    $book = downloadSalesBook($event);
    $copy = clone $book->getSheetByName('RKH');
    $copy->setTitle('JAFIQA');
    $book->addSheet($copy);
    $copy->setCellValue('H5', 9);
    $book->createSheet()->setTitle('NOTA')->setCellValue('A1', 'nota pejabat');

    uploadSalesBook($event, asUpload($book))->assertSessionHas('success', '1 sales read. Left out, not in the sales-sheet shape: NOTA.');

    expect(SalesBookEntry::first()->sheet)->toBe('JAFIQA');
});

it('is HQ\'s: a state admin may not upload', function () {
    $event = salesBookEvent();
    $this->actingAs(superAdmin(), 'admin');
    $book = downloadSalesBook($event);
    $book->getSheetByName('TBPS')->setCellValue('H5', 100);

    auth()->forgetGuards();
    $this->actingAs(stateAdmin(), 'admin');
    uploadSalesBook($event, asUpload($book))->assertForbidden();

    expect(SalesBookEntry::count())->toBe(0);
});
