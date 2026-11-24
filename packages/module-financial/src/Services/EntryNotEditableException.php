<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\VatReturn\VatPeriod;

/**
 * `ManualEntryService` refused an edit or a delete (ADR-042 decisions 7 and
 * 10, owner 2026-09-22): the entry is GENERATED (never editable), its period
 * — old or new — is `closed`, the old or the new date lies in a settled VAT
 * period and the old or the new version carries a tax line (ADR-042 addendum
 * 2026-11-24, point 3), or the new date lies in another
 * fiscal year (the number belongs to the year — delete and re-create).
 */
final class EntryNotEditableException extends FinancialException
{
    public const GENERATED           = 'generated';
    public const PERIOD_CLOSED       = 'period-closed';
    public const VAT_PERIOD_SETTLED  = 'vat-period-settled';
    public const FISCAL_YEAR_CHANGED = 'fiscal-year-changed';
    // The source's amend / retract of ITS generated entry (ADR-042 addendum 2026-10-06)
    public const NOT_FOUND           = 'not-found';
    public const MANUAL              = 'manual';
    public const SOURCE_MISMATCH     = 'source-mismatch';
    public const REVERSED            = 'reversed';
    /** `amend()` with another `sourceRef` than the entry carries — the origin is immutable (review 2026-10-13). */
    public const SOURCE_REF_CHANGED  = 'source-ref-changed';

    /** @param VatPeriod|null $vatPeriod the settled VAT period of a VAT_PERIOD_SETTLED refusal — what `LedgerService::periodRefusalText()` names */
    public function __construct(public readonly string $reason, string $message, public readonly ?VatPeriod $vatPeriod = null)
    {
        parent::__construct($message);
    }
}
