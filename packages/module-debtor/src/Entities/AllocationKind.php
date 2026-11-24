<?php

namespace Z77\Module\Debtor\Entities;

/**
 * What a {@see PaymentAllocation} clears on an invoice (plan §6.3): money
 * that arrived (`payment`), Skonto granted for paying early (`discount`), a
 * receivable written off (`loss`), the CHARGES a card terminal, a payment
 * provider or the payer's bank kept back from the payment (`charges`,
 * «Gebühren» — ADR-041 addendum 2026-11-24, point 15; DEBTOR-FEE-KIND-001).
 *
 * Discount and loss reduce the invoice's turnover AND its VAT per tax code,
 * so they post with the tax data of the document. Payment and charges do
 * not: a payment only moves money from the receivable to the bank, and
 * charges are an EXPENSE of the business — not a reduction of the
 * consideration (MWST-Info 07, 1.1: «Kreditkartenkommissionen … Gebühren /
 * Kommissionen von Zahlungsdienstleistern … dürfen nicht vom Entgelt
 * abgezogen werden»), so the turnover stays gross and the posting carries
 * no tax data.
 *
 * The plan's fourth kind `fee` is NOT an allocation: a dunning fee is a
 * DOCUMENT of its own kind (`InvoiceKind::Fee`, owner 2026-10-06 — «eigene
 * Belegart»), so it has a number, a payment part and an open amount like an
 * invoice, and is cleared by these kinds like one (P4 part 3). The kind of
 * 2026-11-24 is named `charges` to stay apart from that word.
 */
enum AllocationKind: string
{
    case Payment  = 'payment';
    case Discount = 'discount';
    case Loss     = 'loss';
    case Charges  = 'charges';

    /** German, for the screen and the journal text; the code stays English. */
    public function label(): string
    {
        return match ($this) {
            self::Payment  => 'Zahlung',
            self::Discount => 'Skonto',
            self::Loss     => 'Verlust',
            self::Charges  => 'Gebühren',
        };
    }

    /** The `DebtorAccounts` key the kind posts against — null for a payment (the money's own account). */
    public function accountKey(): ?string
    {
        return match ($this) {
            self::Payment  => null,
            self::Discount => 'discount',
            self::Loss     => 'loss',
            self::Charges  => 'charges',
        };
    }

    /** True for the kinds that reduce turnover and VAT (post with the document's tax data): discount and loss. */
    public function reducesTurnover(): bool
    {
        return match ($this) {
            self::Discount, self::Loss     => true,
            self::Payment, self::Charges   => false,
        };
    }
}
