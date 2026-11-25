<?php

namespace App\Support;

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use Carbon\CarbonPeriod;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The event's sales book (Buku Jualan) as HQ keeps it in Excel: one sheet per
 * craft code, one block of four payment rows per lot, one column per event day.
 * undilot fills in who holds which lot; HQ types the sales in from the paper
 * book and adds the sheets for exhibitors outside the draw by hand.
 *
 * Shape copied from the MICF 2026 book (owner, 2026-11-25). Summary sheets
 * (SALEBYDAY, RINGKASAN) are not built yet — to be decided.
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

    /** Lots whose plot and draw entry both lack a code. */
    public const NO_CODE = 'TANPA KOD';

    private const FIRST_DAY_COLUMN = 8; // H, after BIL … JENIS BAYARAN

    private const FIRST_LOT_ROW = 5;

    public static function for(Event $event): Spreadsheet
    {
        $event->loadMissing('plots.bidang', 'plots.company.user');

        // A plot's draw entry: the open-pool plots carry no code of their own.
        $entries = EventRegistration::where('event_id', $event->id)
            ->whereNotNull('plot_id')->pluck('code', 'plot_id');

        $byCode = $event->plots
            ->sortBy('plot_code', SORT_NATURAL)
            ->groupBy(fn ($plot) => $plot->bidang?->code ?: ($entries[$plot->id] ?? null) ?: self::NO_CODE);

        // Coding-sheet order, not alphabetical; anything off the sheet last.
        $order = KrafBidang::codes()->orderBy('bil')->orderBy('id')->pluck('code')->flip();
        $byCode = $byCode->sortBy(fn ($plots, $code) => [$order[$code] ?? PHP_INT_MAX, $code]);

        $days = collect(CarbonPeriod::create($event->start_date->copy()->startOfDay(), $event->end_date->copy()->startOfDay()));

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($byCode as $code => $plots) {
            self::sheet($book->createSheet(), (string) $code, $plots, $days);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    private static function sheet(Worksheet $ws, string $code, $plots, $days): void
    {
        // Excel refuses these in a sheet name and caps it at 31 characters.
        $ws->setTitle(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $code), 0, 31));

        $col = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $firstDay = $col(self::FIRST_DAY_COLUMN);
        $lastDay = $col(self::FIRST_DAY_COLUMN + $days->count() - 1);
        $total = $col(self::FIRST_DAY_COLUMN + $days->count());
        $grand = $col(self::FIRST_DAY_COLUMN + $days->count() + 1);

        $ws->setCellValue('A1', "{$code} ({$plots->count()} lot)");
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        foreach (['BIL', 'NO.LOT', 'NAMA SYARIKAT', 'NAMA USAHAWAN', 'NEGERI', 'NO ID KOMUNITI', 'JENIS BAYARAN'] as $i => $heading) {
            $ws->setCellValue($col($i + 1).'2', $heading)->mergeCells($col($i + 1).'2:'.$col($i + 1).'4');
        }
        $ws->setCellValue("{$firstDay}2", 'HARI / TARIKH')->mergeCells("{$firstDay}2:{$lastDay}2");
        $ws->setCellValue("{$total}2", 'JUMLAH JUALAN')->mergeCells("{$total}2:{$total}4");
        $ws->setCellValue("{$grand}2", 'JUMLAH KESELURUHAN JUALAN')->mergeCells("{$grand}2:{$grand}4");

        foreach ($days->values() as $i => $day) {
            $c = $col(self::FIRST_DAY_COLUMN + $i);
            $ws->setCellValue("{$c}3", mb_strtoupper($day->locale('ms')->dayName));
            $ws->setCellValue("{$c}4", \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($day));
        }
        $ws->getStyle("{$firstDay}4:{$lastDay}4")->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $row = self::FIRST_LOT_ROW;
        foreach ($plots->values() as $n => $plot) {
            $company = $plot->is_allocated ? $plot->company : null;
            $block = $row + count(self::PAYMENT_TYPES) - 1;

            foreach ([$n + 1, $plot->plot_code, $company?->name, $company?->user?->name, $company?->state, $company?->user?->community_id] as $i => $value) {
                // Explicit string: a community ID of digits must keep its zeros.
                $i === 0
                    ? $ws->setCellValue($col($i + 1).$row, $value)
                    : $ws->setCellValueExplicit($col($i + 1).$row, (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $ws->mergeCells($col($i + 1)."{$row}:".$col($i + 1).$block);
            }

            foreach (self::PAYMENT_TYPES as $k => $type) {
                $r = $row + $k;
                $ws->setCellValue("G{$r}", $type);
                $ws->setCellValue("{$total}{$r}", "=SUM({$firstDay}{$r}:{$lastDay}{$r})");
            }
            $ws->setCellValue("{$grand}{$row}", "=SUM({$total}{$row}:{$total}{$block})")->mergeCells("{$grand}{$row}:{$grand}{$block}");

            $row = $block + 1;
        }
        $lastLotRow = $row - 1;

        // Totals per payment type: SUMIF on column G rather than a hand-kept
        // list of every fourth row, so a block inserted by hand is still counted.
        $footer = $row;
        foreach (self::PAYMENT_TYPES as $k => $type) {
            $r = $footer + $k;
            $ws->setCellValue("A{$r}", self::FOOTER_LABELS[$k])->mergeCells("A{$r}:G{$r}");
            for ($i = self::FIRST_DAY_COLUMN; $i <= self::FIRST_DAY_COLUMN + $days->count(); $i++) {
                $c = $col($i);
                $ws->setCellValue("{$c}{$r}", "=SUMIF(\$G\$5:\$G\${$lastLotRow},\"{$type}\",{$c}5:{$c}{$lastLotRow})");
            }
        }
        $sum = $footer + count(self::PAYMENT_TYPES);
        $ws->setCellValue("A{$sum}", 'JUMLAH KESELURUHAN JUALAN (RM)')->mergeCells("A{$sum}:G{$sum}");
        for ($i = self::FIRST_DAY_COLUMN; $i <= self::FIRST_DAY_COLUMN + $days->count(); $i++) {
            $c = $col($i);
            $ws->setCellValue("{$c}{$sum}", "=SUM({$c}{$footer}:{$c}".($sum - 1).')');
        }
        $ws->setCellValue("{$grand}{$sum}", "=SUM({$total}{$footer}:{$total}".($sum - 1).')');

        // Looks.
        $ws->getStyle("A2:{$grand}4")->getFont()->setBold(true);
        $ws->getStyle("A2:{$grand}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle("A5:F{$lastLotRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $ws->getStyle("A{$footer}:{$grand}{$sum}")->getFont()->setBold(true);
        $ws->getStyle("{$firstDay}5:{$grand}{$sum}")->getNumberFormat()->setFormatCode('#,##0.00');
        $ws->getStyle("A2:{$grand}{$sum}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach (['A' => 5, 'B' => 10, 'C' => 30, 'D' => 30, 'E' => 14, 'F' => 16, 'G' => 14] as $c => $w) {
            $ws->getColumnDimension($c)->setWidth($w);
        }
        for ($i = self::FIRST_DAY_COLUMN; $i <= self::FIRST_DAY_COLUMN + $days->count() + 1; $i++) {
            $ws->getColumnDimension($col($i))->setWidth(12);
        }
        $ws->freezePane("{$firstDay}5");
    }
}
