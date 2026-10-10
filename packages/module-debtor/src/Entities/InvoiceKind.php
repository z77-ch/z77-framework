<?php

namespace Z77\Module\Debtor\Entities;

/**
 * What kind of document an {@see Invoice} row is (plan §6.2). ONE entity
 * carries both: a credit note is an invoice with the opposite sign in the
 * ledger and against the open amount — same lines, same VAT summary, same
 * snapshot, same states, same number series (owner 2026-10-10: invoices,
 * credit notes and fees share ONE series, `Invoicing\DocumentNumber`). Two
 * entities would duplicate every column and every rule and differ in one
 * word.
 *
 * The lines of a credit note are stored AS PRINTED (a positive «Gutschrift
 * 100.00»); the kind flips the posting and the open amount, so the same
 * document code reads either kind.
 */
enum InvoiceKind: string
{
    case Invoice    = 'invoice';
    case CreditNote = 'credit-note';

    /**
     * A dunning FEE (owner 2026-10-06: «eigene Belegart» — not an allocation
     * kind, not a separate open-item table): a document of its own with a
     * number, a payment part, an open amount and settlements like an
     * invoice; ONE lump-sum line without VAT (plan §6.5: damages for the
     * delay, not a supply) on the mandator's dunning-fee account, issued and
     * finalized by the dunning run (P4 part 3). It draws from the ONE
     * document series — the QR reference carries only the number
     * (positions 17–26), so a fee and an invoice must never share one.
     */
    case Fee        = 'fee';

    /** German, for a journal text or a screen; the code stays English. */
    public function label(): string
    {
        return match ($this) {
            self::Invoice    => 'Rechnung',
            self::CreditNote => 'Gutschrift',
            self::Fee        => 'Gebühr',
        };
    }

    /** A document the customer pays — an invoice or a fee; a credit note reduces one. */
    public function isPayable(): bool
    {
        return $this !== self::CreditNote;
    }

    /** +1 for a payable document (invoice, fee), −1 for a credit note — the sign the kind gives an amount in the books. */
    public function sign(): int
    {
        return $this->isPayable() ? 1 : -1;
    }
}
