<?php

namespace Z77\Module\Financial\Services;

/**
 * `ManualEntryService` refused an edit or a delete (ADR-042 decisions 7 and
 * 10, owner 2026-09-22): the entry is GENERATED (never editable), its period
 * — old or new — is `closed`, a `vat-settled` period is involved and the old
 * or the new version carries a tax line, or the new date lies in another
 * fiscal year (the number belongs to the year — delete and re-create).
 */
final class EntryNotEditableException extends FinancialException
{
    public const GENERATED           = 'generated';
    public const PERIOD_CLOSED       = 'period-closed';
    public const PERIOD_VAT_SETTLED  = 'period-vat-settled';
    public const FISCAL_YEAR_CHANGED = 'fiscal-year-changed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
