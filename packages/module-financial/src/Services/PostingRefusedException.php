<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\VatReturn\VatPeriod;
use Z77\Shared\Money\Money;

/**
 * `LedgerService::post()` refused a posting for a reason only the database
 * knows (plan §5.4): no fiscal year or period for the date, a closed period,
 * a tax line dated in a settled VAT period or before «Abrechnung ab», an unknown / non-postable /
 * inactive account, an unknown tax code, a VAT account that does not carry exactly the computed tax
 * (the lock of the VAT accounts). The structural refusals (balance,
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
     * The lock of the VAT accounts (ADR-042 addendum 2 of 2026-11-25, point 7): what the entry moves
     * on a locked VAT account is not the tax its coded lines compute for that account — an uncoded
     * amount there, a typed tax that differs, a tax line on the wrong VAT account.
     * `PostingRules::vatLineRefusalText()` puts it into words.
     */
    public const VAT_LINE_NOT_COMPUTED = 'vat-line-not-computed';
    /**
     * The lock of the VAT accounts: a line with a tax code stands ON a locked VAT account that is not
     * the input-side account its code maps to (an output, zero or exempt code there; an input code on
     * the owed or on the other input account) — `VatAccountMap::mayCarryCode()`.
     */
    public const TAX_CODE_ON_VAT_ACCOUNT = 'tax-code-on-vat-account';
    /** The reasons of the lock of the VAT accounts — what `LedgerService::vatLineRefusalText()` puts into words. */
    public const VAT_LOCK_REASONS = [self::VAT_LINE_NOT_COMPUTED, self::TAX_CODE_ON_VAT_ACCOUNT];

    /**
     * @param string|null $account the account the refusal is about («1100 Forderungen …»), when it is
     *                             about one — the screen names it in its German sentence
     * @param VatPeriod|null $vatPeriod the settled VAT period of a VAT_PERIOD_SETTLED refusal — what
     *                             `LedgerService::periodRefusalText()` names
     * @param string|null $taxCode the tax code of a TAX_CODE_ON_VAT_ACCOUNT refusal
     * @param Money|null  $booked   VAT_LINE_NOT_COMPUTED: what the entry moves on the account (debit − credit)
     * @param Money|null  $computed VAT_LINE_NOT_COMPUTED: what its coded lines compute for it (debit − credit)
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $account = null,
        public readonly ?VatPeriod $vatPeriod = null,
        public readonly ?string $taxCode = null,
        public readonly ?Money $booked = null,
        public readonly ?Money $computed = null,
    ) {
        parent::__construct($message);
    }
}
