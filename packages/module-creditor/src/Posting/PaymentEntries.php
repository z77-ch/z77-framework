<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Shared\Money\Money;

/**
 * What a strategy's payment posting answers ({@see CreditorPostingStrategy::paymentPosting()}): the
 * EXPENSE entry (key `creditor:{id}`, the payment date) and, when the input tax is caught up
 * («Vorsteuer nachholen», ADR-041 addendum 2026-11-25 point 9 — the payment date lies in a settled
 * VAT period of the same calendar year), the TAX entry (key `creditor:{id}:vat`, the first day of
 * the running period) with the settled period and the tax it moves — at most two entries per
 * invoice (ADR-059 addendum 2026-11-25, point 1). Nothing here posts.
 */
final class PaymentEntries
{
    public function __construct(
        public readonly PostingRequest $expense,
        public readonly ?PostingRequest $tax = null,
        /** The settled period the payment date lies in — only with a tax entry. */
        public readonly ?VatPeriod $settledPeriod = null,
        /** The input tax the tax entry moves — only with a tax entry. */
        public readonly ?Money $caughtUp = null,
    ) {
        if (($tax === null) !== ($settledPeriod === null) || ($tax === null) !== ($caughtUp === null)) {
            throw new \LogicException('A tax entry comes with its settled period and the tax it moves — or none of the three');
        }
    }

    /** @return list<PostingRequest> the entries in posting order — the expense first */
    public function requests(): array
    {
        return $this->tax === null ? [$this->expense] : [$this->expense, $this->tax];
    }
}
