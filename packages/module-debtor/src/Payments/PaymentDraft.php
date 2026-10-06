<?php

namespace Z77\Module\Debtor\Payments;

use Z77\Shared\Money\Money;

/**
 * What a caller hands `PaymentService::record()` (plan §6.3, P4 part 1):
 * ONE settlement on ONE final invoice — the money that arrived
 * (`payment`, on `paymentTargetCode`), the Skonto granted (`discount`),
 * the amount written off (`loss`); each may be 0.00, at least one is not.
 * Σ of the three may not exceed what is open on the invoice.
 *
 * A payment covering several invoices is several drafts in part 1 (one
 * payment row each); the CAMT.054 import of part 2 builds its drafts from
 * the transactions. Validation happens in the service — a draft is data.
 */
final class PaymentDraft
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly \DateTimeImmutable $date,
        public readonly Money $payment,
        public readonly Money $discount,
        public readonly Money $loss,
        public readonly string $paymentTargetCode = '',
        public readonly ?string $note = null,
        public readonly string $sourceType = 'manual',
        public readonly ?string $sourceRef = null,
    ) {}

    /** Σ payment + discount + loss. */
    public function total(): Money
    {
        return $this->payment->add($this->discount)->add($this->loss);
    }
}
