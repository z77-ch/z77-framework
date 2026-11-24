<?php

namespace Z77\Module\Financial\Entities;

/**
 * Close state of a {@see Period} (ADR-042 decision 10, amended by the
 * addendum 2026-11-24, point 2). A period is `open` or `closed`; since the
 * owner decisions of 2026-09-30 (ADR-042 addendum) an ADMIN may reopen a
 * closed YEAR with a protocol entry — `closed` → `open` — through
 * `FiscalYearCloseService`.
 *
 *   - `open`: generated entries post; manual entries can be created, edited
 *     and deleted.
 *   - `closed`: nothing changes; a correction is a reversal in an open period.
 *
 * «VAT-settled» is NOT a period state: a date is settled while a posted VAT
 * return covers it, read from the returns (`VatReturn\VatSettlement`) —
 * one source, and reopening a year cannot lose it. The former case
 * `vat-settled` was removed with stage S2 of the VAT return; nothing ever
 * wrote it.
 *
 * Every period is created `open`. Whole years move between `open` and
 * `closed` (`Period::transitionTo()`). Domain only, no labels.
 */
enum PeriodState: string
{
    case Open   = 'open';
    case Closed = 'closed';
}
