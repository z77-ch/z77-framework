<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

/**
 * One field of a computed return as the tab «Formular» and the PDF list it — EVERY field of the
 * form edition comes as one of these, in form order; which are shown is the flag `shown`
 * (screen decision 4 of the review, 2026-11-24: a field with 0.00 is not shown, the totals
 * always are).
 *
 *   - `value` — the exact figure ({@see VatReturnForm::value()}); `rounded` the same to the
 *     Rappen, half away from zero — what the screen prints (decision 5: the exact figures are
 *     the PDF's and the help's);
 *   - `base` — the base of a rate or acquisition field, null for every other kind;
 *   - `lines` — the coded journal lines behind the field (0 for a total);
 *   - `shown` — the field has an amount (value or base not zero), or it is one of the totals
 *     (289, 299, 399, 479) or the one of 500 / 510 the payable amount stands in (500 when it is
 *     zero).
 */
final class VatReturnField
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly FieldKind $kind,
        public readonly ExactAmount $value,
        public readonly Money $rounded,
        public readonly ?Money $base,
        public readonly int $lines,
        public readonly bool $shown,
    ) {}
}
