<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;
use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

/**
 * One corrected period of the block «Korrekturen früherer Perioden — separat im ePortal melden»
 * (ADR-042 addendum 2 of 2026-11-25, points 1 and 2; addendum 3, points 5 and 6) — what the return
 * of the period the correction entries are dated in carries for ONE earlier, settled period. Plain
 * data, immutable; built by `VatReturnService` — computed from the journal for an open period
 * ({@see VatReturnService::correctionBlocks()}), read back from the stored rows for a posted one
 * ({@see VatReturnService::declaredCorrectionBlocks()}).
 *
 *   - `period` — the corrected period; `correctedReturn` — its live return (whose STORED figures are
 *     «ursprünglich»), null only when the period lost its return (data no rule lets arise);
 *   - `entries` — the correction entries behind it, `{ref: «2027/214», date}` in journal order;
 *   - `rows` — per form field WITH a correction, plus the tax total and the payable amount (board
 *     S3-2): `field`, `label` (the field's name in the corrected period's form edition), `kind`,
 *     `original` (as declared), `earlier` (Σ of the corrections of this period carried by earlier
 *     live returns — the column «frühere Korrekturen»), `correction` (this return's), `new` =
 *     original + earlier + correction — every one EXACT ({@see ExactAmount}, ten-thousandths of a
 *     Rappen); the screen shows `->rounded()` and the exact value small beneath, so «neu» is rounded
 *     ONCE from the exact sum (B-2). A rate or acquisition field carries its turnover in the four
 *     `…Base` keys (Money), every other field null;
 *   - `items` — the block's tax on the VAT accounts, in the shape of `VatAccountExpectation`'s extra
 *     items (`{key, account, sign, tax, field}`, the posted tax of the correction lines, by
 *     `VatAccountMap::taxLines()` at the corrected period's last day): what the reconciliation
 *     expects on top of the ordinary form and what the settlement entry clears onto the settlement
 *     account. Empty on a block read back from a posted return (it is settled).
 */
final class VatReturnCorrectionBlock
{
    /**
     * @param list<array{ref: string, date: \DateTimeImmutable}> $entries
     * @param list<array{field: string, label: string, kind: FieldKind, original: ExactAmount, earlier: ExactAmount, correction: ExactAmount, new: ExactAmount, originalBase: ?Money, earlierBase: ?Money, correctionBase: ?Money, newBase: ?Money}> $rows
     * @param list<array{key: string, account: ?string, sign: int, tax: Money, field: string}> $items
     */
    public function __construct(
        public readonly VatPeriod $period,
        public readonly ?VatReturn $correctedReturn,
        public readonly array $entries,
        public readonly array $rows,
        public readonly array $items = [],
    ) {}

    /** Whether a column «frühere Korrekturen» is shown — an earlier correction of this period exists (C-C). */
    public function hasEarlier(): bool
    {
        foreach ($this->rows as $row) {
            if (!$row['earlier']->isZero() || ($row['earlierBase'] !== null && !$row['earlierBase']->isZero())) {
                return true;
            }
        }

        return false;
    }

    /** The row of one form field, null when the block has none for it. */
    public function row(string $field): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['field'] === $field) {
                return $row;
            }
        }

        return null;
    }

    /** «2027/214», «2027/214, 2027/220» — the correction entries by number. */
    public function entryRefs(): string
    {
        return implode(', ', array_map(static fn(array $e): string => $e['ref'], $this->entries));
    }

    /**
     * The block's posted tax, owed positive: Σ over its tax items, the owed account counting −sign ×
     * tax and an input account +sign × tax subtracted — what the settlement entry moves onto the
     * settlement account for this period (Cr when positive). Zero on a block read back.
     */
    public function payableTax(string $currency): Money
    {
        $sum = Money::zero($currency);
        foreach ($this->items as $item) {
            // debit − credit per unit of tax is `sign`; the account carries that, the settlement clears its opposite.
            $sum = $sum->subtract($item['tax']->multiply($item['sign']));
        }

        return $sum;
    }
}
