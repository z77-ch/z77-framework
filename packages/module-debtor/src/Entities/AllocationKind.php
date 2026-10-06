<?php

namespace Z77\Module\Debtor\Entities;

/**
 * What a {@see PaymentAllocation} clears on an invoice (plan §6.3): money
 * that arrived (`payment`), Skonto granted for paying early (`discount`), a
 * receivable written off (`loss`). Discount and loss reduce the invoice's
 * turnover AND its VAT per tax code, so they post with the tax data of the
 * document; a payment only moves money from the receivable to the bank.
 *
 * The plan's fourth kind, `fee`, is NOT an allocation: a dunning fee is a
 * DOCUMENT of its own kind (owner 2026-10-06 — «eigene Belegart»), so it
 * has a number, a payment part and an open amount like an invoice, and is
 * cleared by these three kinds like one (P4 part 3).
 */
enum AllocationKind: string
{
    case Payment  = 'payment';
    case Discount = 'discount';
    case Loss     = 'loss';

    /** German, for the screen and the journal text; the code stays English. */
    public function label(): string
    {
        return match ($this) {
            self::Payment  => 'Zahlung',
            self::Discount => 'Skonto',
            self::Loss     => 'Verlust',
        };
    }

    /** The `DebtorAccounts` key the kind posts against — null for a payment (the bank account of the target). */
    public function accountKey(): ?string
    {
        return match ($this) {
            self::Payment  => null,
            self::Discount => 'discount',
            self::Loss     => 'loss',
        };
    }

    /** True for the kinds that reduce turnover and VAT (post with the document's tax data). */
    public function reducesTurnover(): bool
    {
        return $this !== self::Payment;
    }
}
