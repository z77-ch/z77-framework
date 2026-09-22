<?php

namespace Z77\Module\Financial\Services;

/**
 * `FiscalYearService::delete()` refused to delete a fiscal year (owner
 * decision 2026-09-22, FIN-FY-002): only the LATEST year, and only while
 * nothing was ever posted in it — no journal entry, no change-log row of a
 * deleted manual entry, and its range `journal-entry.{code}` still at 0.
 */
final class FiscalYearNotDeletableException extends FinancialException
{
    /** The year is gone (deleted in the meantime) — the screen reloads. */
    public const NOT_FOUND   = 'not-found';
    /** A later year follows — deleting this one would break contiguity. */
    public const NOT_LATEST  = 'not-latest';
    /** A journal entry references the year. */
    public const HAS_ENTRIES = 'has-entries';
    /** A manual entry of the year was deleted: its change row documents a consumed number. */
    public const HAD_ENTRIES = 'had-entries';
    /** The year's range has drawn a number. */
    public const RANGE_USED  = 'range-used';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
