<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * One node of the tree of the tab «Herkunft» (screen decision 8 of the review, 2026-11-24):
 * form field › display origin › account; an account node opens its entry lines
 * ({@see VatReturnService::lineSearch()}). Immutable.
 *
 *   - `level` — {@see FIELD}, {@see ORIGIN} or {@see ACCOUNT};
 *   - `key` — the field number, the origin key ({@see VatReturnSources::key()}), the account number;
 *   - `label` — German: the form's label, the origin's label, the account's name;
 *   - `accountId` — on an account node only (what the entry list is filtered by);
 *   - `lines` — the number of coded journal lines;
 *   - `base` / `tax` — the signed sums of `tax_base` / `tax_amount`, as the journal holds them;
 *   - `amount` — the node's share of the FIELD'S figure, so that the field node's amount IS the
 *     figure the form shows and every level adds up to its parent: the BASE for field 200, the
 *     deduction fields and the rate / acquisition fields (whose form value is the exact tax of
 *     that base — {@see VatReturnForm::base()}), the NEGATED base for the reduction field 235
 *     (the form shows it positive), the POSTED TAX for the input and input-correction fields.
 */
final class VatReturnBreakdown
{
    public const FIELD   = 'field';
    public const ORIGIN  = 'origin';
    public const ACCOUNT = 'account';

    /** @param list<VatReturnBreakdown> $children in display order */
    public function __construct(
        public readonly string $level,
        public readonly string $key,
        public readonly string $label,
        public readonly ?int $accountId,
        public readonly int $lines,
        public readonly Money $base,
        public readonly Money $tax,
        public readonly Money $amount,
        public readonly array $children = [],
    ) {}

    /** The child with this key, or null. */
    public function child(string $key): ?self
    {
        foreach ($this->children as $child) {
            if ($child->key === $key) {
                return $child;
            }
        }

        return null;
    }
}
