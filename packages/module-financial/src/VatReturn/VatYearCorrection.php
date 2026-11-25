<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\VatReturn;

/**
 * One REPORTED correction of a tax year — a stored block «Korrekturen früherer Perioden» whose
 * corrected period lies in the year, carried by a LIVE return (ADR-042 addendum 2 of 2026-11-25,
 * points 1, 2 and 8; small rule 6 of S7). Immutable.
 *
 *   - `block` — the block as the carrying return stored it ({@see VatReturnCorrectionBlock}: the
 *     corrected period, its return, the correction entries, the rows «ursprünglich · frühere
 *     Korrekturen · Korrektur · neu», exact); read back, so its `items` are empty;
 *   - `carryingReturn` — the return that took it along; `reportedOn` its posting day («gemeldet mit
 *     Abrechnung {Periode} am {Datum}» — posted means filed);
 *   - `beforeDay240` — reported on or before day 240 of the tax year (true), after it (false), or
 *     null when the fiscal year is not the calendar year (no «vor / nach» then, S3-8);
 *   - `laterYear` — the carrying return lies in a LATER tax year: its correction entries are not in
 *     this year's books (they stand beside the turnover reconciliation, «später gemeldet», and are
 *     reconciled on the VAT accounts of that later year).
 */
final class VatYearCorrection
{
    public function __construct(
        public readonly VatReturnCorrectionBlock $block,
        public readonly VatReturn $carryingReturn,
        public readonly \DateTimeImmutable $reportedOn,
        public readonly ?bool $beforeDay240,
        public readonly bool $laterYear,
    ) {}
}
