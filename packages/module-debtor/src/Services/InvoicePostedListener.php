<?php

namespace Z77\Module\Debtor\Services;

use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * A module that wants to know when debtor POSTS documents of the Faktura-Journal («Journal
 * verbuchen», ADR-054 decision 6) — module-order moves the orders of those invoices from
 * «Fakturiert» to «Gebucht» (owner 2026-10-24). Debtor never names the listening module (harness
 * N2): the module declares its class under the config key `invoicePostedListeners`
 * ({@see InvoicePostedListeners}).
 *
 * Called by {@see JournalPostingService::post()} INSIDE its unit of work, after the documents are
 * posted and flushed: whatever the listener writes commits with the posting or not at all — a
 * throw rolls the WHOLE posting back (the message reaches the screen). A listener therefore
 * writes database rows only (no file, no mail) and locks only rows that no writer holds while
 * waiting for an invoice row (debtor.md «Lock order of the module»).
 *
 * The class is instantiated without arguments (the registry checks it).
 */
interface InvoicePostedListener
{
    /**
     * @param list<int> $invoiceIds every document posted by this run (invoices and credit notes), in number order
     * @param string    $actor      who posted — for the listener's own log rows
     */
    public function invoicesPosted(array $invoiceIds, UnifiedEntityManager $em, string $actor): void;
}
