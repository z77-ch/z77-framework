<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Services\FinancialException;

/**
 * The VAT return cannot be computed — carries the {@see VatReturnRefusal} (reason, German
 * message, the place that clears it). The screen shows `getMessage()` as it is, never a 500.
 */
final class VatReturnRefusedException extends FinancialException
{
    /**
     * @param list<VatReturnFinding> $findings what a refusal of «MWST buchen» refers to — the blocking
     *                                         findings (`blocked`) or the warnings (`warnings-unconfirmed` /
     *                                         `warnings-changed`) as found under the lock; empty otherwise
     */
    public function __construct(public readonly VatReturnRefusal $refusal, ?\Throwable $previous = null, public readonly array $findings = [])
    {
        parent::__construct($refusal->message, 0, $previous);
    }
}
