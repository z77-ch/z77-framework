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

    /**
     * financial's reason passed through: a posted VAT return covers the date and the posting carries
     * a tax line (ADR-042 addendum 2026-11-24, point 3) — `getMessage()` is financial's German
     * sentence naming the VAT period, with its full stop. Named here so debtor can tell it apart
     * without naming financial.
     */
    public const VAT_PERIOD_SETTLED = 'vat-period-settled';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
