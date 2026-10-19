<?php

namespace Z77\Module\Debtor\Pdf;

/**
 * The line table of a printed document — invoice, credit note, the order's quote and confirmation
 * (module-order's `pdf/order` uses it too): ONE column set, so the documents look alike.
 *
 * A row's cells are, in this order: text · quantity · unit · price · discount · VAT rate · amount
 * (a summary row fills the same seven). The DISCOUNT column (owner 2026-10-19: «Rabatt fehlt» — it
 * was a suffix «(Rabatt x %)» in the text) is printed only when a row carries one; without it the
 * text column takes its width and every row drops that cell.
 */
final class DocumentColumns
{
    private const DISCOUNT = 4;

    /**
     * @param list<array{cells: list<string>}> $rows
     * @return array{0: list<array{label: string, width: float|int, align?: string}>, 1: list<array<string, mixed>>}
     */
    public static function table(string $currency, array $rows): array
    {
        $withDiscount = false;
        foreach ($rows as $row) {
            $withDiscount = $withDiscount || (($row['cells'][self::DISCOUNT] ?? '') !== '');
        }
        $columns = [
            ['label' => 'Bezeichnung', 'width' => $withDiscount ? 76 : 88],
            ['label' => 'Menge',       'width' => 12, 'align' => 'R'],
            ['label' => 'Einheit',     'width' => 10],
            ['label' => 'Preis',       'width' => 22, 'align' => 'R'],
            ['label' => 'Rabatt',      'width' => 12, 'align' => 'R'],
            ['label' => 'MWST',        'width' => 14, 'align' => 'R'],
            ['label' => $currency,     'width' => 24, 'align' => 'R'],
        ];
        if ($withDiscount) {
            return [$columns, $rows];
        }
        array_splice($columns, self::DISCOUNT, 1);
        foreach ($rows as &$row) {
            array_splice($row['cells'], self::DISCOUNT, 1);
        }
        unset($row);

        return [$columns, $rows];
    }
}
