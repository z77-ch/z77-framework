<?php

namespace Z77\Module\Receipt\Services;

/**
 * The receipt journal refused a write as a whole — the message is German and names the next step;
 * `reason` is the stable code a caller branches on. Nothing was written (a refused posting rolled
 * the whole unit of work back).
 */
final class ReceiptRefusedException extends ReceiptException
{
    /** The receipt is gone, posted, or carries another version than the caller saw. */
    public const CHANGED       = 'changed';
    /** «Journal verbuchen» was asked to post nothing. */
    public const EMPTY         = 'empty';
    /** A receipt to post lacks text, an account or the VAT decision. */
    public const INCOMPLETE    = 'incomplete';
    /** The posting cannot be built — an account inactive, a VAT code without rate, no VAT account on the mandator. */
    public const NOT_POSTABLE  = 'not-postable';
    /** The ledger refused — no fiscal year, a closed period, a tax line in a settled VAT period, an account it does not take. */
    public const LEDGER        = 'ledger';
    /** The bank movement cannot be taken or released, or the receipt is linked to one (ADR-061 stage C5). */
    public const BANK          = 'bank';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
