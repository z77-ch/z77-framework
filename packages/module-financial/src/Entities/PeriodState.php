<?php

namespace Z77\Module\Financial\Entities;

/**
 * Close state of a {@see Period} (ADR-042 decision 10). A period moves
 * `open` → `vat-settled` → `closed`; since the owner decisions of 2026-09-30
 * (ADR-042 addendum) an ADMIN may reopen a closed YEAR with a protocol
 * entry — `closed` → `open` — through `FiscalYearCloseService`.
 *
 *   - `open`: generated entries post; manual entries can be created, edited
 *     and deleted.
 *   - `vat-settled`: the VAT return is posted and filed; every line WITH a
 *     tax code in the period is frozen.
 *   - `closed`: nothing changes; a correction is a reversal in an open period.
 *
 * Every period is created `open`. P5 part 1 moves whole years between
 * `open` and `closed` (`Period::transitionTo()`); `vat-settled` arrives
 * with the VAT return (P5 part 2). Domain only, no labels.
 */
enum PeriodState: string
{
    case Open       = 'open';
    case VatSettled = 'vat-settled';
    case Closed     = 'closed';
}
