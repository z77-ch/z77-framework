<?php

namespace Z77\Module\Debtor\Entities;

/**
 * The two states of an {@see Invoice} (plan §6.2, decided 2026-09-18 — the
 * proven wdv «if» state kept):
 *
 *   - `invoicing` (in Fakturierung): a DRAFT — the snapshot exists, NO number
 *     yet (owner 2026-10-10: the number is drawn at the booking print); it
 *     can be edited as often as needed (`InvoicingService::reinvoice()`) and
 *     discarded (`InvoicingService::discard()`, owner 2026-10-13 — a real
 *     delete); NOTHING is posted, there is no open amount;
 *   - `final` («verbucht»): numbered at the booking print, immutable, posted
 *     through the accounting port, the open amount exists. A correction is a
 *     credit note (plan §1, correction principle) — never an edit, never a
 *     deletion.
 *
 * One way only: `invoicing` → `final`. There is no state for a deleted or
 * cancelled document: a draft that is not wanted is deleted (it carries no
 * number and nothing was posted, so nothing is left to correct — ADR-040
 * addendum 2026-10-13), and a booked one is corrected by its counterpart.
 */
enum InvoiceState: string
{
    case Invoicing = 'invoicing';
    case Final     = 'final';
}
