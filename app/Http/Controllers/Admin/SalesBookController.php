<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\SalesBookEntry;
use App\Models\SalesBookUpload;
use App\Models\SalesBookTab;
use App\Support\EventVisibility;
use App\Support\SalesBook;
use App\Support\SalesBookImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * An event's sales book: the code sheets undilot builds from the draw, the
 * extra tabs HQ adds for programmes outside it, the .xlsx download, and the
 * filled book uploaded back, whose sales are kept and shown here.
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
            'upload' => $event->salesBookUpload()->with('admin')->first(),
        ]);
    }

    /**
     * The filled book back. Replaces the event's sales wholesale: the workbook
     * is the whole book, so a figure HQ cleared in Excel is cleared here too.
     */
    public function upload(Request $request, Event $event)
    {
        EventVisibility::authorize($event);

        $request->validate(['workbook' => ['required', 'file', 'mimes:xlsx', 'max:20480']]);
        $file = $request->file('workbook');

        try {
            $import = SalesBookImport::read($file->getRealPath(), $event);
        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            return back()->withErrors(['workbook' => 'The file could not be read as an Excel workbook.']);
        }

        if ($import->errors) {
            return back()->withErrors(['workbook' => $import->errors]);
        }
        if (! $import->entries) {
            return back()->withErrors(['workbook' => 'No sales were found in the file. Nothing was changed.']);
        }

        DB::transaction(function () use ($event, $file, $import, $request) {
            // Each delete is audited, entries go with it by cascade.
            SalesBookUpload::where('event_id', $event->id)->get()->each->delete();

            $upload = SalesBookUpload::create([
                'event_id' => $event->id,
                'admin_id' => $request->user('admin')->id,
                'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                'entries' => count($import->entries),
            ]);

            $now = now();
            foreach (array_chunk($import->entries, 500) as $chunk) {
                SalesBookEntry::insert(array_map(fn ($entry) => $entry + [
                    'upload_id' => $upload->id, 'event_id' => $event->id, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        });

        $message = count($import->entries).' sales read.';
        if ($import->skipped) {
            $message .= ' Left out, not in the sales-sheet shape: '.implode(', ', $import->skipped).'.';
        }

        return back()->with('success', $message);
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
