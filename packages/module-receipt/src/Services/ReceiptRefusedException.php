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
    /**
     * The receipt's input tax is not caught up: dated in a settled VAT period of another calendar year
     * than the running period (owner F-1, ADR-041 addendum 2026-11-25, point 9) — post it «ohne MWST»,
     * the input tax by hand as a correction entry.
     */
    public const INPUT_TAX_REFUSED = 'input-tax-refused';
    /**
     * The receipt's reverse charge is not posted: its date lies in a POSTED VAT period (owner O-3 /
     * O-8, `ReverseChargeTiming`) — post it «ohne MWST», the reverse charge by hand as a correction
     * entry naming that period. Never caught up through the transit account.
     */
    public const REVERSE_CHARGE_REFUSED = 'reverse-charge-refused';
    /** The bank movement cannot be taken or released, or the receipt is linked to one (ADR-061 stage C5). */
    public const BANK          = 'bank';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
