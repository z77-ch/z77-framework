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
 * gets its row, with an empty cell in the others — in the CHART TREE's order (review
 * 2026-10-13: a plain sort by number broke the tree as soon as a group's number does not
 * prefix its accounts' — KMU `1850` sits in group `180`): each section's lines are its tree
 * walk (parent before children, siblings by number, `LedgerReports::section()`), the parent
 * of a line is the nearest line above it with a smaller depth; the trees of all periods are
 * united — the NEWEST period that has a line decides its name, depth and parent — and walked
 * again, siblings by number. A row missing in the newest column therefore stands under its
 * parent among its siblings, never at the end. The accounts a section could not place in the
 * tree (`StatementSection::$unplaced`, a cycle or an orphaned group in the data) follow flat
 * at the end and are named in the block's `unplaced`, so the page can say so (financial.md).
 */
final class StatementComparison
{
    /**
     * @param list<string> $labels  one column label per period (the year's code), newest first
     * @param list<array{title: string, rows: list<array{number: string, name: string, isGroup: bool, depth: int, amounts: list<?Money>}>, extra: list<array{label: string, amounts: list<?Money>}>, totalLabel: string, totals: list<?Money>, unplaced?: list<string>}> $blocks
     * @param list<?string> $spans   per column the range it covers when that is NOT the whole year
     *                               («1.3.–31.3.2026», «per 31.3.2026» — `ReportRange::span()`); null = the whole year
     */
    public function __construct(
        public readonly array $labels,
        public readonly array $blocks,
        public readonly array $spans = [],
    ) {}

    /** The number of amount columns. */
    public function width(): int
    {
        return count($this->labels);
    }

    /** Whether any column covers less (or other) than its whole year — the header then names the ranges. */
    public function hasSpans(): bool
    {
        return array_filter($this->spans, static fn(?string $s) => $s !== null) !== [];
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
        $width    = count($sections);
        $rows     = [];   // number → row
        $children = [];   // parent number ('' = a root) → child numbers
        $flat     = [];   // number → true: unplaced in the period that brought it
        foreach ($sections as $i => $section) {
            $unplaced = array_flip($section?->unplaced ?? []);
            $stack    = [];   // the open parent chain: list of [depth, number]
            foreach ($section?->lines ?? [] as $line) {
                $number = $line->number;
                if (!isset($rows[$number])) {
                    $rows[$number] = [
                        'number'  => $number,
                        'name'    => $line->name,
                        'isGroup' => $line->isGroup,
                        'depth'   => $line->depth,
                        'amounts' => array_fill(0, $width, null),
                    ];
                    if (isset($unplaced[$number])) {
                        $flat[$number] = true;
                    }
                }
                $rows[$number]['amounts'][$i] = $line->amount;
                if (isset($unplaced[$number])) {
                    continue;   // appended flat by the section — no place in its tree
                }
                while ($stack !== [] && $stack[count($stack) - 1][0] >= $line->depth) {
                    array_pop($stack);
                }
                $parent = $stack === [] ? '' : $stack[count($stack) - 1][1];
                if (!isset($rows[$number]['placed'])) {
                    $rows[$number]['placed'] = true;
                    unset($flat[$number]);
                    $children[$parent][] = $number;
                }
                $stack[] = [$line->depth, $number];
            }
        }

        $ordered = [];
        $walk = static function (string $parent) use (&$walk, &$ordered, &$children, $rows): void {
            $kids = $children[$parent] ?? [];
            usort($kids, static fn($a, $b) => strcmp((string) $a, (string) $b));
            foreach ($kids as $number) {
                $row = $rows[$number];
                unset($row['placed']);
                $ordered[] = $row;
                $walk((string) $number);
            }
        };
        $walk('');
        $tail = array_keys($flat);
        usort($tail, static fn($a, $b) => strcmp((string) $a, (string) $b));
        foreach ($tail as $number) {
            $row = $rows[$number];
            unset($row['placed']);
            $ordered[] = $row;
        }

        return [
            'title'      => $title,
            'rows'       => $ordered,
            'extra'      => $extra,
            'totalLabel' => $totalLabel,
            'totals'     => $totals,
            'unplaced'   => array_map('strval', $tail),
        ];
    }
}
