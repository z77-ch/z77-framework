<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * A correction FOR a tax year that is entered but not yet reported — the section «Erfasst, noch
 * nicht abgerechnet» of the yearly overview (small rule 5 of S7): correction entries naming a period
 * of the year, dated in a period whose return is not posted yet. Immutable.
 *
 *   - `block` — the block the return of `carryingPeriod` will carry for the corrected period,
 *     computed from the journal as that return's preview computes it ({@see VatReturnCorrectionBlock}
 *     with its entries and rows);
 *   - `carryingPeriod` — the unposted period the entries are dated in («kommt mit {Periode}»).
 *
 * Not in the sum per field: it joins it once the carrying return is posted.
 */
final class VatYearPending
{
    public function __construct(
        public readonly VatReturnCorrectionBlock $block,
        public readonly VatPeriod $carryingPeriod,
    ) {}
}
