<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\SalesBookTab;
use App\Support\EventVisibility;
use App\Support\SalesBook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * An event's sales book: the code sheets undilot builds from the draw, the
 * extra tabs HQ adds for programmes outside it, and the .xlsx download.
 * HQ/super only, by the `manage-sales-book` gate on the routes.
 */
class SalesBookController extends Controller
{
    public function show(Event $event)
    {
        EventVisibility::authorize($event);

        return view('admin.sales-book.show', [
            'event' => $event,
            'codeSheets' => SalesBook::lotsByCode($event)->map->count(),
            'tabs' => $event->salesBookTabs()->orderBy('id')->get(),
        ]);
    }

    public function storeTab(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $request->merge(['name' => Str::upper(trim((string) $request->input('name')))]);

        // A sheet name must be unique in the workbook, case aside — Excel
        // refuses two sheets "Karyaneka" and "KARYANEKA" — and the summary
        // sheets' names are taken too.
        $taken = $event->salesBookTabs()->pluck('name')
            ->merge(KrafBidang::codes()->pluck('code'))
            ->push(SalesBook::NO_CODE, ...SalesBook::SUMMARY_SHEETS)
            ->map(fn ($name) => Str::upper($name))->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:31', Rule::notIn($taken), function ($attribute, $value, $fail) {
                if (Str::contains($value, SalesBook::SHEET_NAME_FORBIDDEN)) {
                    $fail('A tab name cannot hold any of '.implode(' ', SalesBook::SHEET_NAME_FORBIDDEN));
                }
            }],
            'blocks' => ['required', 'integer', 'min:1', 'max:200'],
        ], [
            'name.not_in' => 'There is already a tab or craft code by that name.',
        ]);

        $event->salesBookTabs()->create($data);

        return back()->with('success', "Tab {$data['name']} added.");
    }

    public function destroyTab(Event $event, SalesBookTab $salesBookTab)
    {
        EventVisibility::authorize($event);

        $salesBookTab->delete();

        return back()->with('success', "Tab {$salesBookTab->name} removed.");
    }

    public function download(Event $event)
    {
        EventVisibility::authorize($event);

        if ($event->plots()->doesntExist() && $event->salesBookTabs()->doesntExist()) {
            return back()->with('error', 'This event has no plots and no tabs yet, so there is no sales book to make.');
        }

        $book = SalesBook::for($event);

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            'buku-jualan-'.(Str::slug($event->title) ?: $event->id).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
