<?php

namespace Z77\Module\Financial\Reports;

use Z77\Shared\Money\Money;

/**
 * A statement over SEVERAL periods side by side (owner 2026-10-10: the balance sheet and the
 * income statement show the last three years, a checkbox switches to the one period). The
 * screen and the PDF render the same object, so they cannot disagree; with one period it is
 * simply one amount column.
 *
 * Built from one {@see StatementSection} per period (index 0 = the shown period, then the
 * older ones). Lines are merged by account NUMBER — an account booked in one year only still
 * gets its row, with an empty cell in the others — and ordered as the chart is: by the
 * number as a string (1, 10, 100, 1020, 110, 1100 …), which is the depth-first order of the
 * Swiss KMU chart. The name of the newest period that has the line wins.
 */
final class StatementComparison
{
    /**
     * @param list<string> $labels  one column label per period (the year's code), newest first
     * @param list<array{title: string, rows: list<array{number: string, name: string, isGroup: bool, depth: int, amounts: list<?Money>}>, extra: list<array{label: string, amounts: list<?Money>}>, totalLabel: string, totals: list<?Money>}> $blocks
     */
    public function __construct(
        public readonly array $labels,
        public readonly array $blocks,
    ) {}

    /** The number of amount columns. */
    public function width(): int
    {
        return count($this->labels);
    }

    /**
     * One block from the same section of every period.
     *
     * @param list<?StatementSection>       $sections  index = period; null = the period has no year
     * @param list<?Money>                  $totals    the block's total per period
     * @param list<array{label: string, amounts: list<?Money>}> $extra rows after the lines (the year's result in equity)
     * @return array<string, mixed>
     */
    public static function block(string $title, array $sections, string $totalLabel, array $totals, array $extra = []): array
    {
        $rows = [];
        foreach ($sections as $i => $section) {
            foreach ($section?->lines ?? [] as $line) {
                $rows[$line->number] ??= [
                    'number'  => $line->number,
                    'name'    => $line->name,
                    'isGroup' => $line->isGroup,
                    'depth'   => $line->depth,
                    'amounts' => array_fill(0, count($sections), null),
                ];
                $rows[$line->number]['amounts'][$i] = $line->amount;
            }
        }
        uksort($rows, static fn($a, $b) => strcmp((string) $a, (string) $b));

        return [
            'title'      => $title,
            'rows'       => array_values($rows),
            'extra'      => $extra,
            'totalLabel' => $totalLabel,
            'totals'     => $totals,
        ];
    }
}
