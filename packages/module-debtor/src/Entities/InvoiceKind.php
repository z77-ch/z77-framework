<?php

namespace Z77\Module\Debtor\Entities;

/**
 * What kind of document an {@see Invoice} row is (plan §6.2). ONE entity
 * carries both: a credit note is an invoice with the opposite sign in the
 * ledger and against the open amount — same lines, same VAT summary, same
 * snapshot, same states, same number mechanics on its own range. Two
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

    /** The `NumberRange` the number of this kind is drawn from — one range per kind, gapless each. */
    public function numberRange(): string
    {
        return $this->value;
    }

    /** German, for a journal text or a screen; the code stays English. */
    public function label(): string
    {
        return match ($this) {
            self::Invoice    => 'Rechnung',
            self::CreditNote => 'Gutschrift',
        };
    }

    /** +1 for an invoice, −1 for a credit note — the sign the kind gives an amount in the books. */
    public function sign(): int
    {
        return $this === self::Invoice ? 1 : -1;
    }
}
