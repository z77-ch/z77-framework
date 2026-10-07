<?php

namespace Z77\Module\Debtor\Entities;

/**
 * Where a {@see BankTransaction} of a CAMT.054 message stands (plan §6.4):
 *
 *   - `unmatched` — no final invoice could be named from the QR reference or
 *     the message; the office assigns one or ignores the transaction;
 *   - `matched`   — an invoice is named, nothing is booked yet;
 *   - `booked`    — a {@see Payment} was recorded and posted for it;
 *   - `ignored`   — the office set it aside (a transfer that is no customer
 *     payment, a return, a duplicate the bank sent twice).
 *
 * `matched` and `unmatched` are OPEN WORK: a transaction in either state
 * dated in a fiscal year blocks that year's close (plan §5.3,
 * `Close/UnbookedTransactionsCheck`).
 */
enum TransactionState: string
{
    case Unmatched = 'unmatched';
    case Matched   = 'matched';
    case Booked    = 'booked';
    case Ignored   = 'ignored';

    /** German, for the screen. */
    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'nicht zugeordnet',
            self::Matched   => 'zugeordnet',
            self::Booked    => 'verbucht',
            self::Ignored   => 'ignoriert',
        };
    }

    public function isOpenWork(): bool
    {
        return $this === self::Unmatched || $this === self::Matched;
    }
}
