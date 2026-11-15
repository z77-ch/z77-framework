<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Services\CreditorRefusedException;
use Z77\Module\Financial\Ledger\PostingRequest;

/**
 * HOW a creditor invoice reaches the ledger (creditor plan §2.4, ADR-059) — the switch between
 * the accounting variants. One strategy per variant; the invoice carries its variant as a SNAPSHOT
 * (`creditor_invoice.posting_variant`), {@see CreditorPostingVariant::strategyFor()} resolves it from
 * the INVOICE, so a later switch of the installation never re-interprets an existing invoice.
 *
 *   - `direct` ({@see DirectPosting}, built): nothing when the invoice arrives; on payment ONE entry
 *     — expense (+ input VAT) to the payment method's account at the payment date;
 *   - `collective` (later): the invoice as a liability on the payables account (2000) at the
 *     invoice date, the payment from 2000 to the bank — two entries, keys `creditor:{id}:invoice`
 *     and `creditor:{id}:payment`.
 *
 * Nothing here posts: the answer is the GENERATED request `LedgerService::post()` takes.
 */
interface CreditorPostingStrategy
{
    /** The variant's key — what `posting_variant` stores ('direct' | 'collective'). */
    public function key(): string;

    /**
     * The entry when the invoice is booked as a liability — null when the variant books none
     * (direct).
     *
     * @param list<CreditorInvoiceLine> $lines the invoice's lines, in position order
     * @throws CreditorRefusedException NOT_POSTABLE with the German reason
     */
    public function invoicePosting(CreditorInvoice $invoice, array $lines): ?PostingRequest;

    /**
     * The entry when the invoice is paid — direct: the expense (+ input VAT) to the credit account
     * at `paid_on`.
     *
     * @param list<CreditorInvoiceLine> $lines the invoice's lines, in position order
     * @throws CreditorRefusedException NOT_POSTABLE with the German reason
     */
    public function paymentPosting(CreditorInvoice $invoice, array $lines): ?PostingRequest;
}
