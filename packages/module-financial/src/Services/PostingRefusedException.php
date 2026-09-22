<?php

namespace Z77\Module\Financial\Services;

/**
 * `LedgerService::post()` refused a posting for a reason only the database
 * knows (plan §5.4): no fiscal year or period for the date, a closed period,
 * a tax line into a `vat-settled` period, an unknown / non-postable /
 * inactive account, an unknown tax code. The structural refusals (balance,
 * one-sided lines, missing origin) are `InvalidArgumentException`s of the
 * DTOs — a programming error of the caller, not a state of the books.
 *
 * `$reason` is one of the constants, for a caller that reacts to a specific
 * refusal (the manual-entry screen phrases them in German); the message
 * says what exactly was refused.
 */
class PostingRefusedException extends FinancialException
{
    public const NO_FISCAL_YEAR       = 'no-fiscal-year';
    public const NO_PERIOD            = 'no-period';
    public const PERIOD_CLOSED        = 'period-closed';
    public const PERIOD_VAT_SETTLED   = 'period-vat-settled';
    public const ACCOUNT_UNKNOWN      = 'account-unknown';
    public const ACCOUNT_NOT_POSTABLE = 'account-not-postable';
    public const ACCOUNT_INACTIVE     = 'account-inactive';
    public const TAX_CODE_UNKNOWN     = 'tax-code-unknown';
    public const IDEMPOTENCY_CONFLICT = 'idempotency-conflict';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
