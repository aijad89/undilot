<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The event's sales book (Buku Jualan) as HQ keeps it in Excel, in the shape
 * of the MICF 2026 book (owner, 2026-11-25):
 *
 *   - four summary sheets first — RINGKASAN (who drew what, by state), then
 *     RINGKASAN KESELURUHAN, RING1 and SALEBYDAY, which are formulas over the
 *     data sheets, so they fill in as HQ types;
 *   - one data sheet per craft code, one block of four payment rows per lot,
 *     one column per event day, the lot holders filled in from the draw;
 *   - one data sheet per SalesBookTab — a programme outside the draw — its
 *     exhibitor blocks left empty for HQ.
 *
 * Sales are never read back into undilot.
 */
class SalesBook
{
    /** The payment rows under every lot, in the book's order. */
    public const PAYMENT_TYPES = ['TUNAI', 'KAD KREDIT', 'TEMPAHAN', 'RUNDINGAN'];

    private const FOOTER_LABELS = [
        'JUMLAH JUALAN TUNAI (RM)',
        'JUMLAH JUALAN KAD KREDIT (RM)',
        'JUMLAH TEMPAHAN (RM)',
        'JUMLAH RUNDINGAN (RM)',
    ];

    public const SUMMARY_SHEETS = ['RINGKASAN', 'RINGKASAN KESELURUHAN', 'RING1', 'SALEBYDAY'];

    /** Excel refuses these in a sheet name. */
    public const SHEET_NAME_FORBIDDEN = ['\\', '/', '?', '*', '[', ']', ':'];

    /** Lots whose plot and draw entry both lack a code. */
    public const NO_CODE = 'TANPA KOD';

    /** The category of a code missing from the coding sheet. */
    private const NO_CATEGORY = 'LAIN-LAIN';

    private const FIRST_DAY_COLUMN = 8; // H on a data sheet, after BIL … JENIS BAYARAN

    private const FIRST_LOT_ROW = 5;

    public static function for(Event $event): Spreadsheet
    {
        $event->loadMissing('salesBookTabs');
        $byCode = self::lotsByCode($event);
        $categories = KrafBidang::codes()->pluck('name', 'code');

        $days = collect(CarbonPeriod::create($event->start_date->copy()->startOfDay(), $event->end_date->copy()->startOfDay()))->values();

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        // Each data sheet, and where its totals sit, for the summaries.
        $sheets = [];

        foreach ($byCode as $code => $plots) {
            $rows = $plots->values()->map(function ($plot) {
                $company = $plot->is_allocated ? $plot->company : null;

                return [$plot->plot_code, $company?->name, $company?->user?->name, $company?->state, $company?->user?->community_id];
            });
            $sheets[] = self::sheet($book->createSheet(), (string) $code, "{$code} ({$plots->count()} lot)", $rows, $days)
                + ['group' => $categories[$code] ?? self::NO_CATEGORY, 'tab' => false];
        }

        foreach ($event->salesBookTabs->sortBy('id') as $tab) {
            $sheets[] = self::sheet($book->createSheet(), $tab->name, $tab->name, collect(array_fill(0, $tab->blocks, [])), $days)
                + ['group' => $tab->name, 'tab' => true];
        }

        // Built back to front, each at index 0, so they read in SUMMARY_SHEETS order.
        $saleByDay = self::saleByDay($book->createSheet(0), $event, $sheets, $days);
        self::ring1($book->createSheet(0), $event, $sheets, $days);
        self::overall($book->createSheet(0), $event, $saleByDay, $days);
        self::participation($book->createSheet(0), $event, $byCode, $categories);

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * The event's plots, one group per code sheet, in sheet order.
     *
     * @return Collection<string, Collection<int, \App\Models\Plot>>
     */
    public static function lotsByCode(Event $event)
    {
        $event->loadMissing('plots.bidang', 'plots.plotSize', 'plots.company.user');

        // A plot's draw entry: the open-pool plots carry no code of their own.
        $entries = EventRegistration::where('event_id', $event->id)
            ->whereNotNull('plot_id')->pluck('code', 'plot_id');

        $byCode = $event->plots
            ->sortBy('plot_code', SORT_NATURAL)
            ->groupBy(fn ($plot) => $plot->bidang?->code ?: ($entries[$plot->id] ?? null) ?: self::NO_CODE);

        // Coding-sheet order, not alphabetical; anything off the sheet last.
        $order = KrafBidang::codes()->orderBy('bil')->orderBy('id')->pluck('code')->flip();

        return $byCode->sortBy(fn ($plots, $code) => [$order[$code] ?? PHP_INT_MAX, $code]);
    }

    /** How many 3x3 lots a stall of this size counts as: 3x6 is 2, 3x12 is 4. */
    public static function lotWeight(?string $size): int
    {
        if (! preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/i', (string) $size, $m)) {
            return 1;
        }

        return max(1, (int) round($m[1] * $m[2] / 9));
    }

    /* ── The data sheets ─────────────────────────────────────────────────── */

    /**
     * Each of $rows is [lot, company, entrepreneur, state, community ID], or []
     * for a blank block.
     *
     * @return array{name: string, footer: int, last: int}
     */
    private static function sheet(Worksheet $ws, string $name, string $heading, Collection $rows, Collection $days): array
    {
        // Excel caps a sheet name at 31 characters.
        $ws->setTitle(mb_substr(str_replace(self::SHEET_NAME_FORBIDDEN, '', $name), 0, 31));

        [$firstDay, $lastDay, $total, $grand] = self::dayColumns(self::FIRST_DAY_COLUMN, $days);

        self::title($ws, $heading);
        foreach (['BIL', 'NO.LOT', 'NAMA SYARIKAT', 'NAMA USAHAWAN', 'NEGERI', 'NO ID KOMUNITI', 'JENIS BAYARAN'] as $i => $label) {
            $c = self::col($i + 1);
            $ws->setCellValue("{$c}2", $label)->mergeCells("{$c}2:{$c}4");
        }
        self::dayHeader($ws, self::FIRST_DAY_COLUMN, $days, 'JUMLAH JUALAN', 'JUMLAH KESELURUHAN JUALAN');

        $row = self::FIRST_LOT_ROW;
        foreach ($rows->values() as $n => $details) {
            $block = $row + count(self::PAYMENT_TYPES) - 1;

            foreach ([$n + 1, ...array_pad($details, 5, null)] as $i => $value) {
                // Explicit string: a community ID of digits must keep its zeros.
                $i === 0
                    ? $ws->setCellValue(self::col($i + 1).$row, $value)
                    : $ws->setCellValueExplicit(self::col($i + 1).$row, (string) $value, DataType::TYPE_STRING);
                $ws->mergeCells(self::col($i + 1)."{$row}:".self::col($i + 1).$block);
            }

            foreach (self::PAYMENT_TYPES as $k => $type) {
                $r = $row + $k;
                $ws->setCellValue("G{$r}", $type);
                $ws->setCellValue("{$total}{$r}", "=SUM({$firstDay}{$r}:{$lastDay}{$r})");
            }
            $ws->setCellValue("{$grand}{$row}", "=SUM({$total}{$row}:{$total}{$block})")->mergeCells("{$grand}{$row}:{$grand}{$block}");

            $row = $block + 1;
        }
        $last = $row - 1;

        // SUMIF on column G rather than a hand-kept list of every fourth row,
        // so a block HQ inserts by hand is still counted.
        $footer = self::footer($ws, $row, 'G', self::FIRST_DAY_COLUMN, $days, fn ($type, $c) => "=SUMIF(\$G\$5:\$G\${$last},\"{$type}\",{$c}5:{$c}{$last})");

        self::looks($ws, 'G', self::FIRST_DAY_COLUMN, $days, $footer + count(self::PAYMENT_TYPES));
        $ws->getStyle("A5:F{$last}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        foreach (['A' => 5, 'B' => 10, 'C' => 30, 'D' => 30, 'E' => 14, 'F' => 16, 'G' => 14] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->freezePane("{$firstDay}5");

        return ['name' => $ws->getTitle(), 'footer' => $footer, 'last' => $last];
    }

    /* ── The summaries ───────────────────────────────────────────────────── */

    /**
     * Every data sheet's four payment rows by day, codes under their category
     * with a subtotal; each tab stands alone.
     *
     * @return list<array{label: string, ref: \Closure}> per group, the formula for one day column
     */
    private static function saleByDay(Worksheet $ws, Event $event, array $sheets, Collection $days): array
    {
        $ws->setTitle('SALEBYDAY');
        $first = 5; // E
        [$firstDay, $lastDay, $total, $grand] = self::dayColumns($first, $days);

        self::title($ws, "LAPORAN JUALAN HARIAN — {$event->title}");
        foreach (['BIL', 'BIDANG', 'KOD', 'JENIS BAYARAN'] as $i => $label) {
            $c = self::col($i + 1);
            $ws->setCellValue("{$c}2", $label)->mergeCells("{$c}2:{$c}4");
        }
        self::dayHeader($ws, $first, $days, 'JUMLAH JUALAN', 'JUMLAH KESELURUHAN JUALAN');

        $groups = [];
        $row = 5;
        $bil = 1;
        foreach (collect($sheets)->groupBy(fn ($s) => ($s['tab'] ? 'tab:' : 'code:').$s['group']) as $group) {
            $start = $row;
            foreach ($group as $sheet) {
                $ref = self::ref($sheet['name']);
                $block = $row + count(self::PAYMENT_TYPES) - 1;
                foreach ([$bil++, $sheet['group'], $sheet['name']] as $i => $value) {
                    $ws->setCellValue(self::col($i + 1).$row, $value)->mergeCells(self::col($i + 1)."{$row}:".self::col($i + 1).$block);
                }
                foreach (self::PAYMENT_TYPES as $k => $type) {
                    $r = $row + $k;
                    $ws->setCellValue("D{$r}", $type);
                    foreach ($days as $i => $day) {
                        $ws->setCellValue(self::col($first + $i).$r, "={$ref}!".self::col(self::FIRST_DAY_COLUMN + $i).($sheet['footer'] + $k));
                    }
                    $ws->setCellValue("{$total}{$r}", "=SUM({$firstDay}{$r}:{$lastDay}{$r})");
                }
                $ws->setCellValue("{$grand}{$row}", "=SUM({$total}{$row}:{$total}{$block})")->mergeCells("{$grand}{$row}:{$grand}{$block}");
                $row = $block + 1;
            }
            $end = $row - 1;

            $label = $group->first()['group'];
            if ($group->first()['tab']) {
                $groups[] = ['label' => $label, 'ref' => fn ($c) => "SUM(SALEBYDAY!{$c}{$start}:{$c}{$end})"];

                continue;
            }

            $ws->setCellValue("A{$row}", "JUMLAH {$label}")->mergeCells("A{$row}:D{$row}");
            for ($i = $first; $i <= $first + $days->count(); $i++) {
                $c = self::col($i);
                $ws->setCellValue("{$c}{$row}", "=SUM({$c}{$start}:{$c}{$end})");
            }
            $ws->getStyle("A{$row}:{$grand}{$row}")->getFont()->setBold(true);
            $sub = $row++;
            $groups[] = ['label' => $label, 'ref' => fn ($c) => "SALEBYDAY!{$c}{$sub}"];
        }
        $last = $row - 1;

        // Subtotal rows carry nothing in D, so SUMIF on D counts each sale once.
        $footer = self::footer($ws, $row, 'D', $first, $days, fn ($type, $c) => "=SUMIF(\$D\$5:\$D\${$last},\"{$type}\",{$c}5:{$c}{$last})");
        self::looks($ws, 'D', $first, $days, $footer + count(self::PAYMENT_TYPES));
        foreach (['A' => 5, 'B' => 24, 'C' => 24, 'D' => 14] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        $ws->freezePane("{$firstDay}5");

        // The footer rows, for the overall sheet's second table.
        $groups['footer'] = $footer;

        return $groups;
    }

    /** Sales by category (or programme) by day, then by payment type by day. */
    private static function overall(Worksheet $ws, Event $event, array $saleByDay, Collection $days): void
    {
        $ws->setTitle('RINGKASAN KESELURUHAN');
        $first = 3; // C
        $footer = $saleByDay['footer'];
        unset($saleByDay['footer']);

        self::title($ws, "LAPORAN JUALAN — {$event->title}");

        $table = function (int $top, string $heading, array $lines, string $totalLabel) use ($ws, $first, $days) {
            [$firstDay, , $total] = self::dayColumns($first, $days);
            $ws->setCellValue("A{$top}", 'BIL')->mergeCells("A{$top}:A".($top + 2));
            $ws->setCellValue("B{$top}", $heading)->mergeCells("B{$top}:B".($top + 2));
            self::dayHeader($ws, $first, $days, 'JUMLAH (RM)', null, $top);

            $row = $top + 3;
            foreach (array_values($lines) as $n => [$label, $formula]) {
                $ws->setCellValue("A{$row}", $n + 1)->setCellValue("B{$row}", $label);
                foreach ($days as $i => $day) {
                    $ws->setCellValue(self::col($first + $i).$row, '='.$formula(self::col(5 + $i)));
                }
                $ws->setCellValue("{$total}{$row}", "=SUM({$firstDay}{$row}:".self::col($first + $days->count() - 1)."{$row})");
                $row++;
            }
            $ws->setCellValue("A{$row}", $totalLabel)->mergeCells("A{$row}:B{$row}");
            for ($i = $first; $i <= $first + $days->count(); $i++) {
                $c = self::col($i);
                $ws->setCellValue("{$c}{$row}", "=SUM({$c}".($top + 3).":{$c}".($row - 1).')');
            }
            $ws->getStyle("A{$top}:{$total}".($top + 2))->getFont()->setBold(true);
            $ws->getStyle("A{$top}:{$total}".($top + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $ws->getStyle("A{$row}:{$total}{$row}")->getFont()->setBold(true);
            $ws->getStyle(self::col($first).($top + 3).":{$total}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle("A{$top}:{$total}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            return $row;
        };

        $end = $table(3, 'BIDANG', array_map(fn ($g) => [$g['label'], $g['ref']], $saleByDay), 'JUMLAH KESELURUHAN (RM)');
        $table($end + 2, 'JENIS BAYARAN', array_map(
            fn ($k) => [self::PAYMENT_TYPES[$k], fn ($c) => "SALEBYDAY!{$c}".($footer + $k)],
            array_keys(self::PAYMENT_TYPES)
        ), 'JUMLAH KESELURUHAN JUALAN (RM)');

        $ws->getColumnDimension('A')->setWidth(6);
        $ws->getColumnDimension('B')->setWidth(32);
        for ($i = $first; $i <= $first + $days->count(); $i++) {
            $ws->getColumnDimension(self::col($i))->setWidth(13);
        }
    }

    /** Each data sheet's total per payment type, and how many exhibitors it names. */
    private static function ring1(Worksheet $ws, Event $event, array $sheets, Collection $days): void
    {
        $ws->setTitle('RING1');
        $totalCol = self::col(self::FIRST_DAY_COLUMN + $days->count());

        self::title($ws, "RINGKASAN LAPORAN JUALAN — {$event->title}");
        foreach (['BIL', 'BIDANG', 'KOD', 'USHW', ...self::PAYMENT_TYPES, 'JUMLAH (RM)'] as $i => $label) {
            $ws->setCellValue(self::col($i + 1).'3', $label);
        }

        $row = 4;
        foreach ($sheets as $n => $sheet) {
            $ref = self::ref($sheet['name']);
            $ws->setCellValue("A{$row}", $n + 1)->setCellValue("B{$row}", $sheet['group'])->setCellValue("C{$row}", $sheet['name']);
            // A company name of at least one character: blank blocks hold ''.
            $ws->setCellValue("D{$row}", "=COUNTIF({$ref}!C".self::FIRST_LOT_ROW.":C{$sheet['last']},\"?*\")");
            foreach (self::PAYMENT_TYPES as $k => $type) {
                $ws->setCellValue(self::col(5 + $k).$row, "={$ref}!{$totalCol}".($sheet['footer'] + $k));
            }
            $ws->setCellValue("I{$row}", "=SUM(E{$row}:H{$row})");
            $row++;
        }
        $ws->setCellValue("A{$row}", 'JUMLAH')->mergeCells("A{$row}:C{$row}");
        foreach (range('D', 'I') as $c) {
            $ws->setCellValue("{$c}{$row}", "=SUM({$c}4:{$c}".($row - 1).')');
        }

        $ws->getStyle("A3:I3")->getFont()->setBold(true);
        $ws->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
        $ws->getStyle("E4:I{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("A3:I{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        foreach (['A' => 6, 'B' => 24, 'C' => 24, 'D' => 8, 'E' => 14, 'F' => 14, 'G' => 14, 'H' => 14, 'I' => 16] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
    }

    /**
     * Who drew a lot, by state × code × stall size, and what that comes to in
     * 3x3 lots. Fixed numbers from the draw, not formulas: Excel does not
     * change who holds what. Draw lots only — the tabs are not in it.
     */
    private static function participation(Worksheet $ws, Event $event, Collection $byCode, Collection $categories): void
    {
        $ws->setTitle('RINGKASAN');

        $held = $byCode->map(fn ($plots) => $plots->filter(fn ($p) => $p->is_allocated && $p->company));

        // One column per (code, size) held, the bigger stall first as in the book.
        $columns = $held->flatMap(fn ($plots, $code) => $plots->map(fn ($p) => $p->plotSize?->size ?? '—')->unique()
            ->sortByDesc(fn ($size) => self::lotWeight($size))
            ->map(fn ($size) => [$code, $size])->values())->values();

        $states = collect(AdminZones::all())
            ->merge($held->collapse()->map(fn ($p) => $p->company->state ?: '—'))->unique()->values();

        self::title($ws, "RINGKASAN PENYERTAAN USAHAWAN — {$event->title}");
        $ws->setCellValue('A2', $event->location.', '.$event->start_date->format('d/m/Y').' – '.$event->end_date->format('d/m/Y'));
        $ws->setCellValue('A3', 'BIL')->mergeCells('A3:A5');
        $ws->setCellValue('B3', 'NEGERI')->mergeCells('B3:B5');

        foreach ($columns as $i => [$code, $size]) {
            $c = self::col(3 + $i);
            $ws->setCellValue("{$c}3", $categories[$code] ?? self::NO_CATEGORY);
            $ws->setCellValue("{$c}4", $code);
            $ws->setCellValue("{$c}5", strtoupper($size));
        }
        $totalCol = self::col(3 + $columns->count());
        $ws->setCellValue("{$totalCol}3", 'JUMLAH')->mergeCells("{$totalCol}3:{$totalCol}5");

        $row = 6;
        foreach ($states as $n => $state) {
            $ws->setCellValue("A{$row}", $n + 1)->setCellValue("B{$row}", $state);
            foreach ($columns as $i => [$code, $size]) {
                $count = $held[$code]->filter(fn ($p) => ($p->company->state ?: '—') === $state && ($p->plotSize?->size ?? '—') === $size)->count();
                if ($count) {
                    $ws->setCellValue(self::col(3 + $i).$row, $count);
                }
            }
            $ws->setCellValue("{$totalCol}{$row}", $columns->isEmpty() ? 0 : "=SUM(C{$row}:".self::col(2 + $columns->count())."{$row})");
            $row++;
        }

        $people = $row;
        $ws->setCellValue("A{$people}", 'JUMLAH USAHAWAN')->mergeCells("A{$people}:B{$people}");
        $lots = $people + 1;
        $ws->setCellValue("A{$lots}", 'JUMLAH LOT')->mergeCells("A{$lots}:B{$lots}");
        foreach ($columns as $i => [$code, $size]) {
            $c = self::col(3 + $i);
            $ws->setCellValue("{$c}{$people}", "=SUM({$c}6:{$c}".($people - 1).')');
            $ws->setCellValue("{$c}{$lots}", "={$c}{$people}*".self::lotWeight($size));
        }
        $span = $columns->isEmpty() ? null : 'C%1$d:'.self::col(2 + $columns->count()).'%1$d';
        $ws->setCellValue("{$totalCol}{$people}", $span ? '=SUM('.sprintf($span, $people).')' : 0);
        $ws->setCellValue("{$totalCol}{$lots}", $span ? '=SUM('.sprintf($span, $lots).')' : 0);

        $ws->getStyle("A3:{$totalCol}5")->getFont()->setBold(true);
        $ws->getStyle("A3:{$totalCol}5")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle("A{$people}:{$totalCol}{$lots}")->getFont()->setBold(true);
        $ws->getStyle("A3:{$totalCol}{$lots}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $ws->getColumnDimension('A')->setWidth(6);
        $ws->getColumnDimension('B')->setWidth(20);
    }

    /* ── Shared pieces ───────────────────────────────────────────────────── */

    private static function col(int $i): string
    {
        return Coordinate::stringFromColumnIndex($i);
    }

    /** A sheet name as a formula reference: quoted, its apostrophes doubled. */
    private static function ref(string $sheet): string
    {
        return "'".str_replace("'", "''", $sheet)."'";
    }

    /** @return array{string, string, string, string} first day, last day, total, grand total */
    private static function dayColumns(int $first, Collection $days): array
    {
        return [self::col($first), self::col($first + $days->count() - 1), self::col($first + $days->count()), self::col($first + $days->count() + 1)];
    }

    private static function title(Worksheet $ws, string $text): void
    {
        $ws->setCellValue('A1', $text);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    /** HARI / TARIKH over the day columns, day names under it, dates under those; then the total headings. */
    private static function dayHeader(Worksheet $ws, int $first, Collection $days, string $total, ?string $grand, int $top = 2): void
    {
        [$firstDay, $lastDay, $totalCol, $grandCol] = self::dayColumns($first, $days);
        $ws->setCellValue("{$firstDay}{$top}", 'HARI / TARIKH')->mergeCells("{$firstDay}{$top}:{$lastDay}{$top}");
        $ws->setCellValue("{$totalCol}{$top}", $total)->mergeCells("{$totalCol}{$top}:{$totalCol}".($top + 2));
        if ($grand) {
            $ws->setCellValue("{$grandCol}{$top}", $grand)->mergeCells("{$grandCol}{$top}:{$grandCol}".($top + 2));
        }

        foreach ($days as $i => $day) {
            $c = self::col($first + $i);
            $ws->setCellValue($c.($top + 1), mb_strtoupper($day->locale('ms')->dayName));
            $ws->setCellValue($c.($top + 2), ExcelDate::PHPToExcel($day));
        }
        $ws->getStyle("{$firstDay}".($top + 2).":{$lastDay}".($top + 2))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }

    /** The four payment-type totals and the grand total under a sheet. Returns the first footer row. */
    private static function footer(Worksheet $ws, int $row, string $labelEnd, int $first, Collection $days, \Closure $formula): int
    {
        foreach (self::PAYMENT_TYPES as $k => $type) {
            $r = $row + $k;
            $ws->setCellValue("A{$r}", self::FOOTER_LABELS[$k])->mergeCells("A{$r}:{$labelEnd}{$r}");
            for ($i = $first; $i <= $first + $days->count(); $i++) {
                $ws->setCellValue(self::col($i).$r, $formula($type, self::col($i)));
            }
        }

        $sum = $row + count(self::PAYMENT_TYPES);
        $ws->setCellValue("A{$sum}", 'JUMLAH KESELURUHAN JUALAN (RM)')->mergeCells("A{$sum}:{$labelEnd}{$sum}");
        for ($i = $first; $i <= $first + $days->count(); $i++) {
            $c = self::col($i);
            $ws->setCellValue("{$c}{$sum}", "=SUM({$c}{$row}:{$c}".($sum - 1).')');
        }
        [, , $total, $grand] = self::dayColumns($first, $days);
        $ws->setCellValue("{$grand}{$sum}", "=SUM({$total}{$row}:{$total}".($sum - 1).')');

        return $row;
    }

    private static function looks(Worksheet $ws, string $labelEnd, int $first, Collection $days, int $sum): void
    {
        [$firstDay, , , $grand] = self::dayColumns($first, $days);
        $footer = $sum - count(self::PAYMENT_TYPES);

        $ws->getStyle("A2:{$grand}4")->getFont()->setBold(true);
        $ws->getStyle("A2:{$grand}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle("A5:C{$footer}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle("A{$footer}:{$grand}{$sum}")->getFont()->setBold(true);
        $ws->getStyle("{$firstDay}5:{$grand}{$sum}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("A2:{$grand}{$sum}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        for ($i = $first; $i <= $first + $days->count() + 1; $i++) {
            $ws->getColumnDimension(self::col($i))->setWidth(12);
        }
    }
}
