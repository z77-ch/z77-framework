<?php

namespace Z77\Module\Financial\Ledger;

/**
 * What {@see \Z77\Module\Financial\Services\GrossEntryBuilder::build()} answers: the balanced
 * posting lines (hand them to `PostingRequest::generated()` / `::manual()`) and, per part in the
 * order given, its VAT split — null for a part «ohne MWST».
 *
 * {@see \Z77\Module\Financial\Services\GrossEntryBuilder::buildSplit()} answers the same shape
 * for TWO entries: `lines` is the EXPENSE entry (at the voucher's date), `taxLines` the TAX entry
 * (at the tax date the caller named) — balanced each. `taxLines` is empty after `build()`, and
 * after `buildSplit()` when no part carries tax to move (then only the expense entry is posted).
 */
final class GrossEntry
{
    /**
     * @param list<PostingLine>     $lines
     * @param list<GrossSplit|null> $splits
     * @param list<PostingLine>     $taxLines
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $splits,
        public readonly array $taxLines = [],
    ) {}
}
