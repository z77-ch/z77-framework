<?php

namespace Z77\Module\Financial\Services;

use Z77\Persistence\Doctrine\OpenWork\OpenWork;

/**
 * `FiscalYearCloseService` refused to close or to reopen a fiscal year
 * (owner decisions 2026-09-30). The reason is a code; the screen turns it
 * into German (`FiscalYearControllerTrait::fiscalYearCloseMessage()`). A
 * refusal by the close check carries the findings, so the screen can show
 * them again.
 */
final class FiscalYearCloseRefusedException extends FinancialException
{
    /** The year is gone (deleted in the meantime). */
    public const NOT_FOUND            = 'not-found';
    /** Close: the year has no periods (not opened through `FiscalYearService::open()`). */
    public const NO_PERIODS           = 'no-periods';
    /** Close: every period of the year is closed already. */
    public const ALREADY_CLOSED       = 'already-closed';
    /** Close: the year before it (ending the day before it starts) is not closed — years close in order. */
    public const PREDECESSOR_OPEN     = 'predecessor-open';
    /** Close: a registered close check reported a BLOCKING finding (`$openWork` names it). */
    public const BLOCKED              = 'blocked';
    /** Close: the close check reported warnings and the caller did not confirm them. */
    public const WARNINGS_UNCONFIRMED = 'warnings-unconfirmed';
    /** Close: the warnings under the lock are not the ones the closer confirmed (their fingerprint differs). */
    public const WARNINGS_CHANGED     = 'warnings-changed';
    /** Reopen: the year is not closed. */
    public const NOT_CLOSED           = 'not-closed';
    /** Reopen: the year after it is closed — years reopen in reverse order, the latest closed first. */
    public const SUCCESSOR_CLOSED     = 'successor-closed';
    /** Reopen: no reason given — the protocol needs one. */
    public const NO_REASON            = 'no-reason';
    /** Reopen: the reason is longer than the protocol keeps (`FiscalYearCloseLog::REASON_LENGTH`) — refused, never cut. */
    public const REASON_TOO_LONG      = 'reason-too-long';

    public function __construct(public readonly string $reason, string $message, public readonly ?OpenWork $openWork = null)
    {
        parent::__construct($message);
    }
}
