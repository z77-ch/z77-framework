<?php

namespace Z77\Module\Debtor\Pdf;

/**
 * The line table of a printed document — invoice, credit note, the order's quote and confirmation
 * (module-order's `pdf/order` uses it too): ONE column set, so the documents look alike.
 *
 * A row's cells are, in this order: text · quantity · unit · price · discount · VAT rate · amount
 * (`KEYS`; a summary row fills the same seven — `pdf/documentSummary`). Two columns are DROPPABLE,
 * and a dropped column's cell is dropped from every row; the text column takes its width:
 *
 *   - the DISCOUNT column (owner 2026-10-19: «Rabatt fehlt» — it was a suffix «(Rabatt x %)» in the
 *     text) is printed only when a row carries one;
 *   - the VAT column is printed only when the document was computed WITH VAT (owner 2026-10-21:
 *     the mandator's «mehrwertsteuerpflichtig» is binding — `Invoice::carriesVat()`, handed in by
 *     the PDF class as `$liableToVat`).
 *
 * The rule lives here, not in the layouts: `pdf/invoice` and `pdf/order` differ in VAT
 * presentation only through this input.
 *
 * The UNIT column is one line and never cut (owner 2026-10-21: «.25Std» printed as «.2…»): its
 * width follows the longest unit of the rows, MEASURED in the table's font by the layout's
 * `$measure` (`PdfDocument::textWidth()` at 9 pt — `unitWidth()`); without a measure an estimate
 * per character stands in. The text column gives the room. A unit may be
 * `InvoiceLine::UNIT_LENGTH` characters long; only an absurd one (wider than the cap) is still cut.
 */
final class DocumentColumns
{
    /** The cell order of a row. */
    public const KEYS = ['text', 'quantity', 'unit', 'price', 'discount', 'vat', 'amount'];

    private const DISCOUNT = 4;
    private const UNIT     = 2;

    /** The unit column: its base width, the cell padding of `pdf/table` (2 × 1.5 mm), the mm one character takes at 9 pt without a measure, its cap. */
    private const UNIT_WIDTH_MIN   = 10;
    private const UNIT_PADDING     = 3;
    private const UNIT_MM_PER_CHAR = 1.6;
    private const UNIT_WIDTH_MAX   = 34;

    /**
     * The columns for $rows and the rows cut to them. $withVat = false drops the VAT column;
     * $measure (text → mm in the table's font) sizes the unit column exactly.
     *
     * @param list<array{cells: list<string>}> $rows
     * @param (callable(string): float)|null $measure
     * @return array{0: list<array{key: string, label: string, width: float|int, align?: string}>, 1: list<array<string, mixed>>}
     */
    public static function table(string $currency, array $rows, bool $withVat = true, ?callable $measure = null): array
    {
        $withDiscount = false;
        foreach ($rows as $row) {
            $withDiscount = $withDiscount || (($row['cells'][self::DISCOUNT] ?? '') !== '');
        }
        $unitWidth = self::unitWidth(array_map(static fn(array $row): string => (string) ($row['cells'][self::UNIT] ?? ''), $rows), $measure);
        $columns = [
            ['key' => 'text',     'label' => 'Bezeichnung', 'width' => 76 - ($unitWidth - self::UNIT_WIDTH_MIN) + ($withDiscount ? 0 : 12) + ($withVat ? 0 : 14)],
            ['key' => 'quantity', 'label' => 'Menge',       'width' => 12, 'align' => 'R'],
            ['key' => 'unit',     'label' => 'Einheit',     'width' => $unitWidth],
            ['key' => 'price',    'label' => 'Preis',       'width' => 22, 'align' => 'R'],
            ['key' => 'discount', 'label' => 'Rabatt',      'width' => 12, 'align' => 'R'],
            ['key' => 'vat',      'label' => 'MWST',        'width' => 14, 'align' => 'R'],
            ['key' => 'amount',   'label' => $currency,     'width' => 24, 'align' => 'R'],
        ];
        $columns = array_values(array_filter($columns, static fn(array $c) => ($c['key'] !== 'discount' || $withDiscount) && ($c['key'] !== 'vat' || $withVat)));

        return [$columns, self::fit($columns, $rows)];
    }

    /**
     * The unit column's width in mm for $units: the base width, or what the widest unit needs plus
     * the cell padding — measured by $measure (the layout's font), else estimated per character —, capped.
     *
     * @param list<string> $units
     * @param (callable(string): float)|null $measure
     */
    public static function unitWidth(array $units, ?callable $measure = null): int
    {
        $widest = 0.0;
        foreach ($units as $unit) {
            $unit   = trim($unit);
            $widest = max($widest, $measure !== null ? (float) $measure($unit) : mb_strlen($unit) * self::UNIT_MM_PER_CHAR);
        }

        return (int) max(self::UNIT_WIDTH_MIN, min(self::UNIT_WIDTH_MAX, ceil($widest) + self::UNIT_PADDING));
    }

    /**
     * $rows (cells in `KEYS` order) cut to $columns — the summary rows below a table take the
     * columns the line table chose.
     *
     * @param list<array{key: string}> $columns
     * @param list<array{cells: list<string>}> $rows
     * @return list<array<string, mixed>>
     */
    public static function fit(array $columns, array $rows): array
    {
        $index = array_flip(self::KEYS);
        foreach ($rows as &$row) {
            $row['cells'] = array_map(static fn(array $c) => (string) ($row['cells'][$index[$c['key']]] ?? ''), $columns);
        }
        unset($row);

        return $rows;
    }
}
