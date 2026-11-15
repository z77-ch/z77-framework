<?php

namespace Z77\Module\Financial\Services;

/**
 * {@see GrossEntryBuilder} cannot build the lines. The message is German and meant for the user;
 * `field` says what it is about, so a form puts it under the right input and a module names it
 * («Soll: …»); `part` is the index of the gross part (null = the counter account or the entry).
 */
final class GrossEntryRefusedException extends FinancialException
{
    /** The part's account (Soll). */
    public const ACCOUNT    = 'account';
    /** The counter account (Haben). */
    public const COUNTER    = 'counter';
    public const AMOUNT     = 'amount';
    public const TAX_CODE   = 'tax_code';
    public const TAX_AMOUNT = 'tax_amount';
    /** Not one field — the combination or the mandator's setup. */
    public const ENTRY      = 'entry';

    public function __construct(public readonly string $field, string $message, public readonly ?int $part = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
