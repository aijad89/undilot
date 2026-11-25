<?php

namespace App\Support;

use App\Models\Event;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads a filled sales book back: every sheet in the data-sheet shape SalesBook
 * writes — a code sheet, a programme tab, or one HQ added in Excel by copying
 * one — one entry per exhibitor block × day × payment type with a sale in it.
 *
 * All or nothing: a cell that is neither empty, "-" nor a number is an error,
 * and any error means nothing is read. A typo must not become a silent zero.
 */
class SalesBookImport
{
    private const MAX_ERRORS = 20;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> sheets not in the data-sheet shape, left out */
    public array $skipped = [];

    /** @var list<array<string, mixed>> */
    public array $entries = [];

    public static function read(string $path, Event $event): self
    {
        $import = new self;

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        $plots = $event->plots()->get(['id', 'plot_code', 'company_id', 'is_allocated'])->keyBy('plot_code');

        foreach ($book->getWorksheetIterator() as $index => $ws) {
            if (in_array($ws->getTitle(), SalesBook::SUMMARY_SHEETS, true)) {
                continue;
            }
            if (! self::isDataSheet($ws)) {
                $import->skipped[] = $ws->getTitle();

                continue;
            }
            $import->sheet($ws, $index, $plots);
        }

        if ($import->errors) {
            $import->entries = [];
        }

        return $import;
    }

    private static function isDataSheet(Worksheet $ws): bool
    {
        $text = fn ($cell) => mb_strtoupper(trim((string) $ws->getCell($cell)->getValue()));

        return $text('G2') === 'JENIS BAYARAN' && $text('H2') === 'HARI / TARIKH';
    }

    private function sheet(Worksheet $ws, int $index, $plots): void
    {
        $name = $ws->getTitle();

        // The day columns: H onwards, each with its date on row 4.
        $days = [];
        for ($c = 8; ($value = $ws->getCell(Coordinate::stringFromColumnIndex($c).'4')->getValue()) !== null && $value !== ''; $c++) {
            $date = self::date($value);
            $date
                ? $days[$c] = $date
                : $this->error("{$name}!".Coordinate::stringFromColumnIndex($c)."4: \"{$value}\" is not a date");
        }

        $details = null;
        $block = 0;
        $last = $ws->getHighestDataRow();
        for ($row = 5; $row <= $last; $row++) {
            $bil = $ws->getCell("A{$row}")->getValue();

            // The footer: JUMLAH JUALAN TUNAI (RM) and the rest.
            if (is_string($bil) && str_starts_with(mb_strtoupper(trim($bil)), 'JUMLAH')) {
                break;
            }

            // A BIL number opens the next exhibitor's block of payment rows.
            if ($bil !== null && $bil !== '') {
                $block++;
                $details = array_map(
                    fn ($col) => ($v = trim((string) $ws->getCell("{$col}{$row}")->getValue())) === '' ? null : $v,
                    ['B', 'C', 'D', 'E', 'F']
                );
            }

            $type = mb_strtoupper(trim((string) $ws->getCell("G{$row}")->getValue()));
            if (! $details || ! in_array($type, SalesBook::PAYMENT_TYPES, true)) {
                continue;
            }

            foreach ($days as $c => $date) {
                $coordinate = Coordinate::stringFromColumnIndex($c).$row;
                $amount = self::amount($ws->getCell($coordinate));

                if ($amount === false) {
                    $this->error("{$name}!{$coordinate}: \"{$ws->getCell($coordinate)->getValue()}\" is not a number");
                } elseif ($amount) {
                    [$lot, $company, $entrepreneur, $state, $community] = $details;
                    $plot = $lot !== null ? $plots->get($lot) : null;

                    $this->entries[] = [
                        'sheet' => $name,
                        'sheet_index' => $index,
                        'block' => $block,
                        'lot' => $lot,
                        'company_name' => $company,
                        'entrepreneur' => $entrepreneur,
                        'state' => $state,
                        'community_id' => $community !== null ? mb_substr($community, 0, 50) : null,
                        'plot_id' => $plot?->id,
                        'company_id' => $plot?->is_allocated ? $plot->company_id : null,
                        'payment_type' => $type,
                        'sale_date' => $date,
                        'amount' => round($amount, 2),
                    ];
                }
            }
        }
    }

    /** A date as SalesBook writes it (an Excel serial), or as typed: 24/09/2026. */
    private static function date(mixed $value): ?string
    {
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        try {
            return Carbon::createFromFormat('d/m/Y', trim((string) $value))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The sale in a cell: null for none, false for something that is not a number. */
    private static function amount(Cell $cell): float|false|null
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($value === null || (is_string($value) && in_array(trim($value), ['', '-'], true))) {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace([',', 'RM', ' '], '', $value);
        }

        return is_numeric($value) ? ((float) $value ?: null) : false;
    }

    private function error(string $message): void
    {
        if (count($this->errors) < self::MAX_ERRORS) {
            $this->errors[] = $message;
        }
    }
}
