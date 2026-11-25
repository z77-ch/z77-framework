<?php

namespace Z77\Module\Receipt\Services;

use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\VatReturn\InputTaxDeferral;

/**
 * What «Journal verbuchen» books for ONE receipt ({@see ReceiptPostingBuilder::posting()}) — the
 * preview shows it, the posting posts it:
 *
 *   - `expense` — the entry on the receipt date, key `receipt:{id}` (always; a reverse-charge receipt:
 *     its ONE entry with the pair of tax lines);
 *   - `tax` — the TAX entry of a receipt dated in a settled VAT period whose input tax is caught up
 *     in the running period (ADR-041 addendum 2026-11-25, point 9): dated on `deferral->taxDate`,
 *     key `receipt:{id}:vat`; null when there is one entry only (also a catch-up whose tax rounds
 *     to 0.00 — nothing to move);
 *   - `deferral` — the input-tax rule's answer when it caught up (`InputTaxDeferral::isCatchUp()`),
 *     null otherwise;
 *   - `reverseChargeAfterEnd` — a reverse-charge receipt dated after «Abrechnung bis»: posted WITHOUT
 *     the reverse-charge tax (owner D-4) — the window shows a grey hint, nothing blocks.
 */
final class ReceiptPosting
{
    public function __construct(
        public readonly PostingRequest $expense,
        public readonly ?PostingRequest $tax = null,
        public readonly ?InputTaxDeferral $deferral = null,
        public readonly bool $reverseChargeAfterEnd = false,
    ) {}

    /** The requests in posting order — the expense entry, then the tax entry. @return list<PostingRequest> */
    public function requests(): array
    {
        return $this->tax === null ? [$this->expense] : [$this->expense, $this->tax];
    }
}
