<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\SalesBookEntry;
use App\Support\EventVisibility;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            'overview' => $this->overview(),
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

    /**
     * The overview above the list, from every uploaded sales book: the latest event with sales, the top
     * five events, and all sales by payment type. HQ and super only.
     */
    private function overview(): array
    {
        $byEvent = DB::table('sales_book_entries')
            ->join('events', 'events.id', '=', 'sales_book_entries.event_id')
            ->selectRaw('events.id, events.title, events.start_date, sum(amount) as total')
            ->groupBy('events.id', 'events.title', 'events.start_date')
            ->get();
        $categories = KrafBidang::codes()->pluck('name', 'code');

        $latest = $byEvent->sortByDesc('start_date')->first();

        return [
            'latest' => $latest,
            'latestDaily' => $latest ? $this->dailySales($latest->id) : collect(),
            'latestByType' => $latest ? DB::table('sales_book_entries')->where('event_id', $latest->id)
                ->selectRaw('payment_type, sum(amount) as total')->groupBy('payment_type')
                ->pluck('total', 'payment_type') : collect(),
            'events' => $byEvent->count(),
            'top' => $byEvent->sortByDesc('total')->take(5)->values(),
            'byType' => DB::table('sales_book_entries')
                ->selectRaw('payment_type, sum(amount) as total')->groupBy('payment_type')
                ->pluck('total', 'payment_type'),
            // A code sheet falls under its category, as on SALEBYDAY; a programme tab stands alone.
            'byCategory' => $this->salesBy('sheet', fn (string $sheet) => $categories[$sheet] ?? $sheet),
            'exhibitors' => $this->topExhibitors(),
            // Typed into the sheet by hand: "Kelantan" and "KELANTAN " are one state.
            'byState' => $this->salesBy('state', fn (?string $state) => mb_strtoupper(trim((string) $state)) ?: 'NO STATE'),
        ];
    }

    /** One event's sales per day, in date order: Y-m-d => total. */
    private function dailySales(int $eventId)
    {
        return DB::table('sales_book_entries')->where('event_id', $eventId)
            ->selectRaw('sale_date, sum(amount) as total')->groupBy('sale_date')->orderBy('sale_date')
            ->get()
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->sale_date)->toDateString() => (float) $r->total]);
    }

    /**
     * The ten exhibitors who sold most, across every event. One linked to a
     * company by its lot counts as that company, however its name was typed;
     * one from outside the draw counts by the name on the sheet.
     */
    private function topExhibitors()
    {
        return DB::table('sales_book_entries')
            ->leftJoin('companies', 'companies.id', '=', 'sales_book_entries.company_id')
            ->whereNotNull('sales_book_entries.company_name')->orWhereNotNull('sales_book_entries.company_id')
            ->selectRaw('sales_book_entries.company_id, companies.name as linked, sales_book_entries.company_name, sales_book_entries.event_id, sum(amount) as total')
            ->groupBy('sales_book_entries.company_id', 'companies.name', 'sales_book_entries.company_name', 'sales_book_entries.event_id')
            ->get()
            ->groupBy(fn ($r) => $r->company_id ? "id:{$r->company_id}" : mb_strtoupper(trim($r->company_name)))
            ->map(fn ($rows) => (object) [
                'company_id' => $rows[0]->company_id,
                'name' => $rows[0]->linked ?? trim($rows[0]->company_name),
                'events' => $rows->pluck('event_id')->unique()->count(),
                'total' => $rows->sum('total'),
            ])
            ->sortByDesc('total')->take(10)->values();
    }

    /** Sales summed by a column, its values folded by $label, biggest first. */
    private function salesBy(string $column, \Closure $label)
    {
        return DB::table('sales_book_entries')
            ->selectRaw("{$column} as k, sum(amount) as total")->groupBy($column)->get()
            ->groupBy(fn ($r) => $label($r->k))
            ->map(fn ($rows) => $rows->sum('total'))
            ->sortDesc();
    }
}
