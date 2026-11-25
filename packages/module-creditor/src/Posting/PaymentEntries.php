<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Shared\Money\Money;

/**
 * What a strategy's payment posting answers ({@see CreditorPostingStrategy::paymentPosting()}): the
 * EXPENSE entry (key `creditor:{id}`, the payment date) and, when there is one, the TAX entry (key
 * `creditor:{id}:vat`) — at most two entries per invoice (ADR-059 addendum 2026-11-25, point 1):
 *
 *   - input tax caught up («Vorsteuer nachholen», ADR-041 addendum 2026-11-25 point 9 — the payment
 *     date lies in a settled VAT period of the same calendar year): on the first day of the running
 *     period, with the settled period and the tax it moves;
 *   - the REVERSE-CHARGE pair (point 10) when the invoice date differs from the payment date: at the
 *     invoice date, with the reverse-charge tax it posts;
 *   - both in one entry only when both fall on the same day.
 *
 * `reverseCharge` is also set for the ONE entry that carries the pair itself (invoice date = payment
 * date) — then without a tax entry. `reverseChargeAfterEnd`: the invoice date lies after «Abrechnung
 * bis» — z77 posts no reverse-charge tax (owner D-4); the window shows a grey line. Nothing here posts.
 */
final class PaymentEntries
{
    public function __construct(
        public readonly PostingRequest $expense,
        public readonly ?PostingRequest $tax = null,
        /** The settled period the payment date lies in — only with input tax caught up. */
        public readonly ?VatPeriod $settledPeriod = null,
        /** The input tax the tax entry moves — only with input tax caught up. */
        public readonly ?Money $caughtUp = null,
        /** The reverse-charge tax posted — in the tax entry, or in the one entry; null = none. */
        public readonly ?Money $reverseCharge = null,
        /** The invoice date lies after «Abrechnung bis»: its reverse-charge lines were posted without a code (D-4). */
        public readonly bool $reverseChargeAfterEnd = false,
    ) {
        if (($settledPeriod === null) !== ($caughtUp === null)) {
            throw new \LogicException('Input tax caught up comes with its settled period and the tax it moves — or neither');
        }
        if ($caughtUp !== null && $tax === null) {
            throw new \LogicException('Input tax caught up needs its tax entry');
        }
        if ($tax !== null && $caughtUp === null && $reverseCharge === null) {
            throw new \LogicException('A tax entry carries input tax caught up or a reverse-charge pair');
        }
    }

    /** @return list<PostingRequest> the entries in posting order — the expense first */
    public function requests(): array
    {
        return $this->tax === null ? [$this->expense] : [$this->expense, $this->tax];
    }

    /** Input tax is caught up in the tax entry. */
    public function catchesUp(): bool
    {
        return $this->caughtUp !== null;
    }

    /** The reverse-charge pair is a tax entry of its own (invoice date ≠ payment date). */
    public function separatesReverseCharge(): bool
    {
        return $this->tax !== null && $this->reverseCharge !== null;
    }
}
