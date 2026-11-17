<?php

namespace Z77\Module\Debtor\Services;

/**
 * `BankImportService` (or its reader) refused — the file, the message or
 * an action on a transaction. `reason` is a stable code, `getMessage()`
 * German and says what to do.
 */
final class BankImportRefusedException extends DebtorException
{
    public const NOT_XML            = 'not-xml';
    public const NOT_CAMT054        = 'not-camt054';
    public const DUPLICATE_MESSAGE  = 'duplicate-message';
    public const TARGET_UNKNOWN     = 'target-unknown';
    public const NOT_FOUND          = 'not-found';
    public const STATE              = 'state';
    public const INVOICE_UNKNOWN    = 'invoice-unknown';
    public const INVOICE_NOT_FINAL  = 'invoice-not-final';
    public const NOTHING_TO_BOOK    = 'nothing-to-book';
    /** The camt.054 file could not be archived (import: nothing stored), or its archived file is missing / altered. */
    public const ARCHIVE            = 'archive';
    /** A link to the camt.053 movement was refused — amount / count disagree, the movement is taken, gone or not this entry's (ADR-061 stage C3). */
    public const BANK_LINK          = 'bank-link';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
