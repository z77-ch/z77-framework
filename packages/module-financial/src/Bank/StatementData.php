<?php

namespace Z77\Module\Financial\Bank;

/**
 * One statement (`Stmt`) of a camt.053 file as {@see Camt053Reader} read it — data only. The
 * balances are SIGNED two-decimal strings (negative = overdrawn); the reader has already checked
 * that opening balance + booked entries = closing balance. `skipped` counts entries that are not
 * booked (`Sts` ≠ `BOOK`) and were left out.
 */
final class StatementData
{
    /** @param list<MovementData> $movements in file order */
    public function __construct(
        public readonly string $messageId,
        public readonly \DateTimeImmutable $createdOn,
        public readonly string $statementId,
        public readonly ?int $electronicSequence,
        public readonly ?int $legalSequence,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
        public readonly string $iban,
        public readonly string $currency,
        public readonly string $openingBalance,
        public readonly \DateTimeImmutable $openingDate,
        public readonly string $closingBalance,
        public readonly \DateTimeImmutable $closingDate,
        public readonly array $movements,
        public readonly int $skipped,
    ) {}
}
