<?php

namespace Z77\Module\Financial\Entities;

/**
 * Close state of a {@see Period} (ADR-042 decision 10). A period moves one
 * way: `open` → `vat-settled` → `closed`.
 *
 *   - `open`: generated entries post; manual entries can be created, edited
 *     and deleted.
 *   - `vat-settled`: the VAT return is posted and filed; every line WITH a
 *     tax code in the period is frozen.
 *   - `closed`: nothing changes; a correction is a reversal in an open period.
 *
 * P2 part 1 creates every period `open` and has no transition — the moves
 * arrive with the VAT return and the close (P5). The column exists now
 * because the posting service (part 2) refuses by it. Domain only, no
 * labels.
 */
enum PeriodState: string
{
    case Open       = 'open';
    case VatSettled = 'vat-settled';
    case Closed     = 'closed';
}
