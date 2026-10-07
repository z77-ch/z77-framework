<?php

namespace Z77\Module\Debtor\Payments;

/**
 * One transaction as {@see CamtReader} read it from a CAMT.054 file —
 * data only, nothing resolved. `amount` is the decimal string of the file
 * (`Money::fromDecimal()` builds the value in `currency`).
 */
final class CamtEntry
{
    public function __construct(
        public readonly string $txRef,
        public readonly \DateTimeImmutable $valueDate,
        public readonly ?\DateTimeImmutable $bookingDate,
        public readonly string $amount,
        public readonly string $currency,
        public readonly bool $isCredit,
        public readonly bool $isReversal,
        public readonly string $referenceType,
        public readonly string $reference,
        public readonly string $remittance,
        public readonly string $debtorName,
        public readonly string $debtorCity,
    ) {}
}
