<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Services\FinancialException;

/**
 * «Geschäftlich buchen …» or «Buchung zurücknehmen …» refused (ADR-061 decisions 14–21, stage
 * C2). `reason` is a stable code, `field` the form field the sentence belongs under (null = the
 * whole form), `getMessage()` German and says what to do.
 */
final class BankPostingRefusedException extends FinancialException
{
    /** The contra account is missing, unknown, a group or inactive — or the configured «Privat» / transfer account is. */
    public const CONTRA   = 'contra';
    /** The tax code is refused (unknown, inactive, not liable, not with «Privat» / a transfer). */
    public const TAX_CODE = 'tax-code';
    /** A file is required (a tax code from the threshold on) or the file is not a PDF / JPEG / PNG or too large. */
    public const VOUCHER  = 'voucher';
    /** The ledger refuses the date: no fiscal year, a closed period, a VAT-settled period with a tax line. */
    public const PERIOD   = 'period';
    /** The movement has no direct posting to take back. */
    public const NOTHING  = 'nothing';
    /** Anything else the entry builder or the ledger refuses. */
    public const ENTRY    = 'entry';

    public function __construct(public readonly string $reason, string $message, public readonly ?string $field = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
