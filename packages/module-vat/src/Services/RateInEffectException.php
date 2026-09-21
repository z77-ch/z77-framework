<?php

namespace Z77\Module\Vat\Services;

use Z77\Module\Vat\Entities\TaxRate;

/** The row's validity has started: it is history and stays (ADR-041 decision 2). A correction is a new row. */
final class RateInEffectException extends VatException
{
    public function __construct(public readonly TaxRate $rate)
    {
        parent::__construct(
            "Rate of '{$rate->getCode()}' valid from {$rate->getValidFrom()} is in effect and cannot be removed — add a new row instead"
        );
    }
}
