<?php

namespace Z77\Module\Debtor\Entities;

/**
 * The two states of an {@see Invoice} (plan §6.2, decided 2026-09-18 — the
 * proven wdv «if» state kept):
 *
 *   - `invoicing` (in Fakturierung): number and snapshot exist, the document
 *     can be re-issued as often as needed under the SAME number
 *     (`InvoicingService::reinvoice()`); NOTHING is posted, there is no open
 *     amount;
 *   - `final` (Fakturierung abgeschlossen): immutable, posted through the
 *     accounting port, the open amount exists. A correction is a credit note
 *     (plan §1, correction principle) — never an edit, never a deletion.
 *
 * One way only: `invoicing` → `final`. There is no state for a deleted or
 * cancelled document: a document that was never wanted is re-issued into
 * something else while `invoicing`, and a posted one is corrected by its
 * counterpart.
 */
enum InvoiceState: string
{
    case Invoicing = 'invoicing';
    case Final     = 'final';
}
