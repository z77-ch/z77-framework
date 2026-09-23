<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Shared\Money\Money;

/**
 * Gross mode: the lines of one tax code are so small that no line can carry
 * the code's tax and keep a positive net (a document of 0.01 lines). The
 * tax on the sum is right; a per-line split does not exist. Raised by
 * {@see TaxShares::distribute()}, turned into a refusal of the draft by
 * `InvoicingService::compose()` (reason `line-tax-share`) — at issue, never
 * at `finalize()`.
 */
final class NoTaxShareException extends \DomainException
{
    public function __construct(public readonly Money $largestAmount, public readonly Money $share)
    {
        parent::__construct("No line can carry the tax share {$share} — the largest line of the code is {$largestAmount}");
    }
}
