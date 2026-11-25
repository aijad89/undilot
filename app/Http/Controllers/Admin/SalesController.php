<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\SalesBookEntry;
use App\Support\EventVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The sales kept from uploaded sales books: a list of events, and per event
 * the sales by sheet, by day and, for one sheet, by exhibitor. Read only —
 * uploading is on the sales-book page. HQ/super, by `manage-sales-book`.
 */
class SalesController extends Controller
{
    public function index()
    {
        return view('admin.sales.index', [
            'events' => Event::query()
                ->with('salesBookUpload.admin')
                ->withSum('salesBookEntries as sales_total', 'amount')
                ->orderByDesc('start_date')
                ->paginate(20),
        ]);
    }

    public function show(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $entries = SalesBookEntry::where('event_id', $event->id);

        // Per sheet, per payment type; per day, per payment type.
        $bySheet = (clone $entries)
            ->selectRaw('sheet_index, sheet, payment_type, SUM(amount) AS total, COUNT(DISTINCT block) AS exhibitors')
            ->groupBy('sheet_index', 'sheet', 'payment_type')->orderBy('sheet_index')->get()
            ->groupBy('sheet');
        $byDay = (clone $entries)
            ->selectRaw('sale_date, payment_type, SUM(amount) AS total')
            ->groupBy('sale_date', 'payment_type')->orderBy('sale_date')->get()
            ->groupBy(fn ($row) => Carbon::parse($row->sale_date)->toDateString());

        // One sheet opened: its exhibitors, each with a total per payment type.
        $sheet = $request->query('sheet');
        $exhibitors = $sheet === null ? null : (clone $entries)->where('sheet', $sheet)
            ->orderBy('block')->get()->groupBy('block');

        return view('admin.sales.show', [
            'event' => $event,
            'upload' => $event->salesBookUpload()->with('admin')->first(),
            'bySheet' => $bySheet,
            'byDay' => $byDay,
            'sheet' => $sheet,
            'exhibitors' => $exhibitors,
        ]);
    }
}
