<?php

namespace Z77\Module\Financial\Services;

/**
 * `LedgerService::reverse()` refused (ADR-042 decisions 7 and 8): the entry
 * does not exist, is MANUAL (manual entries are edited or deleted, never
 * reversed), is itself a reversal, was reversed already, or no reason was
 * given. The period rules for the reversal's date are
 * {@see PostingRefusedException}s, as for any posting.
 */
final class ReversalRefusedException extends FinancialException
{
    public const NOT_FOUND        = 'not-found';
    public const MANUAL           = 'manual';
    public const IS_REVERSAL      = 'is-reversal';
    public const ALREADY_REVERSED = 'already-reversed';
    public const NO_REASON        = 'no-reason';
    public const REASON_TOO_LONG  = 'reason-too-long';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
