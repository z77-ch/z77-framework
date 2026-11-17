<?php

namespace Z77\Module\Financial\Bank;

/**
 * One booked entry of a statement as {@see Camt053Reader} read it — data only, nothing stored.
 * Amounts are two-decimal strings, `amount` unsigned (`isCredit` gives the side). `entryRef` is
 * the bank's entry reference or null; `fingerprint` (SHA-256, 64 hex) tells the entry apart when
 * there is none. The fields mirror {@see \Z77\Module\Financial\Entities\BankMovement}.
 */
final class MovementData
{
    public function __construct(
        public readonly int $position,
        public readonly ?string $entryRef,
        public readonly string $fingerprint,
        public readonly bool $isCredit,
        public readonly bool $isReversal,
        public readonly \DateTimeImmutable $bookingDate,
        public readonly ?\DateTimeImmutable $valueDate,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $originalAmount,
        public readonly ?string $originalCurrency,
        public readonly ?string $exchangeRate,
        public readonly string $bankTxCode,
        public readonly string $counterpartyName,
        public readonly string $counterpartyIban,
        public readonly string $referenceType,
        public readonly string $reference,
        public readonly string $text,
        public readonly int $txCount,
    ) {}
}
