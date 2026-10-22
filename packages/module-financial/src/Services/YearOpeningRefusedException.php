<?php

namespace Z77\Module\Financial\Services;

/**
 * `YearOpeningService` refused to build or to write the opening entry of a
 * fiscal year (P5, owner decisions 2026-10-22). The reason is a code; the
 * screen turns it into German (`FiscalYearControllerTrait::fiscalYearOpeningMessage()`).
 * `$items` names what the sentence needs: the account, the entry numbers,
 * the accounts with a balance.
 */
final class YearOpeningRefusedException extends FinancialException
{
    /** The target year is gone (deleted in the meantime). */
    public const NOT_FOUND         = 'not-found';
    /** The target year is closed — its opening is frozen with it. */
    public const TARGET_CLOSED     = 'target-closed';
    /** The target is the earliest year — there is nothing to carry forward. */
    public const NO_PREDECESSOR    = 'no-predecessor';
    /** A year-opening account is not set on the mandator, or not a postable active account (`$items`: the mandator key, the number or ''). */
    public const ACCOUNT_MISSING   = 'account-missing';
    /** The contra account already carries entries in the target year that the generator did not make (`$items`: the account, then the entry numbers). */
    public const FOREIGN_OPENING   = 'foreign-opening';
    /** An account with a closing balance in the predecessor is inactive (`$items`: «number name» per account). */
    public const ACCOUNT_INACTIVE  = 'account-inactive';
    /** The predecessor has no balance at all, and no opening exists to replace. */
    public const NOTHING_TO_CARRY  = 'nothing-to-carry';

    /** @param list<string> $items */
    public function __construct(public readonly string $reason, string $message, public readonly array $items = [])
    {
        parent::__construct($message);
    }
}
