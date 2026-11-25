<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\VatReturn\VatPeriod;

/**
 * `LedgerService::post()` refused a posting for a reason only the database
 * knows (plan §5.4): no fiscal year or period for the date, a closed period,
 * a tax line dated in a settled VAT period or before «Abrechnung ab», an unknown / non-postable /
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
    /** A tax line dated in a SETTLED VAT period — a posted return covers the date (ADR-042 addendum 2026-11-24, point 3). */
    public const VAT_PERIOD_SETTLED   = 'vat-period-settled';
    /**
     * A tax line dated before the mandator's «Abrechnung ab» (ADR-041 addendum 2026-11-24, point 3:
     * before that day z77 settles no VAT, a posting with a tax code is refused) — only for a liable
     * mandator that named the day. `LedgerService::periodRefusalText()` names the day.
     */
    public const BEFORE_VAT_START     = 'before-vat-start';
    public const ACCOUNT_UNKNOWN      = 'account-unknown';
    public const ACCOUNT_NOT_POSTABLE = 'account-not-postable';
    public const ACCOUNT_INACTIVE     = 'account-inactive';
    public const TAX_CODE_UNKNOWN     = 'tax-code-unknown';
    public const IDEMPOTENCY_CONFLICT = 'idempotency-conflict';
    /** A MANUAL entry names an account «nur durch Module bebuchbar» (`Account::$modulePostingOnly`, owner 2026-10-13). */
    public const ACCOUNT_MODULE_ONLY  = 'account-module-only';

    /**
     * @param string|null $account the account the refusal is about («1100 Forderungen …»), when it is
     *                             about one — the screen names it in its German sentence
     * @param VatPeriod|null $vatPeriod the settled VAT period of a VAT_PERIOD_SETTLED refusal — what
     *                             `LedgerService::periodRefusalText()` names
     */
    public function __construct(public readonly string $reason, string $message, public readonly ?string $account = null, public readonly ?VatPeriod $vatPeriod = null)
    {
        parent::__construct($message);
    }
}
