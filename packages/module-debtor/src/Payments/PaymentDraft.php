<?php

namespace Z77\Module\Debtor\Payments;

use Z77\Shared\Money\Money;

/**
 * What a caller hands `PaymentService::record()` (plan §6.3, P4 part 1):
 * ONE settlement on ONE final invoice — the money that arrived
 * (`payment`, on `paymentTargetCode`), the Skonto granted (`discount`),
 * the amount written off (`loss`), the charges a card terminal, payment
 * provider or bank kept back (`charges`, «Gebühren» — a NAMED argument,
 * 0.00 when left out, DEBTOR-FEE-KIND-001); each may be 0.00, at least one
 * is not. Σ of the four may not exceed what is open on the invoice.
 *
 * A payment covering several invoices is several drafts in part 1 (one
 * payment row each); the CAMT.054 import of part 2 builds its drafts from
 * the transactions. Validation happens in the service — a draft is data.
 */
final class PaymentDraft
{
    /**
     * @param string $paymentTargetCode the target the money came through ('' = none); its ledger account is the default for $account
     * @param string $account           the LEDGER ACCOUNT the money went to — bank, cash register, a clearing account
     *                                  (owner 2026-10-06); '' = the target's account
     */
    /** «Gebühren»: what a card terminal, a payment provider or a bank kept back — 0.00 when the caller names none. */
    public readonly Money $charges;

    public function __construct(
        public readonly int $invoiceId,
        public readonly \DateTimeImmutable $date,
        public readonly Money $payment,
        public readonly Money $discount,
        public readonly Money $loss,
        public readonly string $paymentTargetCode = '',
        public readonly ?string $note = null,
        public readonly string $account = '',
        public readonly string $sourceType = 'manual',
        public readonly ?string $sourceRef = null,
        ?Money $charges = null,
    ) {
        $this->charges = $charges ?? Money::zero($payment->currency);
    }

    /** Σ payment + discount + loss + charges. */
    public function total(): Money
    {
        return $this->payment->add($this->discount)->add($this->loss)->add($this->charges);
    }
}
