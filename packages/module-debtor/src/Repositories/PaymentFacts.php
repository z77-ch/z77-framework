<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Shared\Money\Money;

/**
 * WHEN, HOW MUCH and THROUGH WHICH PAYMENT TARGET a final payable document was paid (owner
 * 2026-11-19: «the payment date shows only at the very bottom of the invoice window») — a read
 * model for the lists «Rechnungen» and «Debitoren», read per page in ONE query
 * ({@see InvoiceRepository::paymentFactsOf()}) or joined into the open-item rows; never persisted.
 *
 * Only MONEY counts here: the allocations of kind `payment`. A discount, a loss and a credit note
 * settle a document too, but nobody paid them — they are in the open-item list's «bezahlt»
 * (settled) and in the payment state, not in `paid`.
 *
 *   - `paidOn`      the date of the LAST payment (null: no money came);
 *   - `payments`    how many payments carry it — more than one shows as «01.10.2026 (2)»;
 *   - `paid`        Σ of the money allocated to the document;
 *   - `target`      the payment target code of the LAST payment («lukb»; '' = an account chosen
 *                   freely), `targets` how many different ones — more than one shows as «lukb +1»;
 *   - `creditNotes` the numbers of the FINAL credit notes issued against the document.
 */
final class PaymentFacts
{
    /** @param list<int> $creditNotes */
    public function __construct(
        public readonly ?\DateTimeImmutable $paidOn = null,
        public readonly int $payments = 0,
        public readonly ?Money $paid = null,
        public readonly string $target = '',
        public readonly int $targets = 0,
        public readonly array $creditNotes = [],
    ) {}

    /** «01.10.2026», with several payments «01.10.2026 (2)»; '' when no money came. */
    public function paidOnLabel(): string
    {
        if ($this->paidOn === null) {
            return '';
        }

        return $this->paidOn->format('d.m.Y') . ($this->payments > 1 ? ' (' . $this->payments . ')' : '');
    }

    /** «lukb», with several payment targets «lukb +1»; '' when none is named. */
    public function targetLabel(): string
    {
        if ($this->target === '') {
            return $this->targets > 1 ? '+' . ($this->targets - 1) : '';
        }

        return $this->target . ($this->targets > 1 ? ' +' . ($this->targets - 1) : '');
    }
}
