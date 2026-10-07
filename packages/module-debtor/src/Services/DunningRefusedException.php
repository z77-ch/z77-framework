<?php

namespace Z77\Module\Debtor\Services;

/**
 * `DunningService` refused — a run or a notice. `reason` is a stable code,
 * `getMessage()` German and says what to do.
 */
final class DunningRefusedException extends DebtorException
{
    public const NOT_FOUND   = 'not-found';
    public const NOT_DUE     = 'not-due';
    public const NO_LEVELS   = 'no-levels';
    public const NOTHING     = 'nothing';
    public const DATE        = 'date';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
