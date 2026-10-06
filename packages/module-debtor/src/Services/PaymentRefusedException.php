<?php

namespace Z77\Module\Debtor\Services;

/**
 * `PaymentService` refused a draft. `reason` is a stable code (the
 * `InvoiceRefusedException` model) a screen maps to a field or a sentence;
 * `getMessage()` is German and says what to fix.
 */
final class PaymentRefusedException extends DebtorException
{
    public const NOT_FOUND        = 'not-found';
    public const NOT_FINAL        = 'not-final';
    public const CREDIT_NOTE      = 'credit-note';
    public const CURRENCY         = 'currency';
    public const NOTHING          = 'nothing';
    public const AMOUNT           = 'amount';
    public const OVER_ALLOCATION  = 'over-allocation';
    public const TARGET_REQUIRED  = 'target-required';
    public const TARGET_UNKNOWN   = 'target-unknown';
    public const TARGET_INACTIVE  = 'target-inactive';
    public const TARGET_ACCOUNT   = 'target-account';
    public const DATE             = 'date';
    public const CONFLICT         = 'conflict';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
