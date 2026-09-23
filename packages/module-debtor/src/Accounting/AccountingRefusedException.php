<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Module\Debtor\Services\DebtorException;

/**
 * The bookkeeping behind the port refused the posting — no fiscal year for
 * the date, a closed period, an unknown or inactive account, a VAT account
 * not configured, … `reason` is a stable code (financial's
 * `PostingRefusedException::$reason` passed through, or one of this class'
 * own), `getMessage()` says what to fix. The original exception, when there
 * is one, is `getPrevious()` — a caller that knows financial may look; one
 * that does not has everything it needs here.
 *
 * Ends the unit of work it was raised in (plan §6.6 contract): the caller
 * lets it propagate; nothing is retried inside.
 */
final class AccountingRefusedException extends DebtorException
{
    /** The VAT account of a category is not configured in the bookkeeping, or names an account it will not take. */
    public const VAT_ACCOUNT_MISSING = 'vat-account-missing';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
