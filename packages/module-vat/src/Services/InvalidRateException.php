<?php

namespace Z77\Module\Vat\Services;

use Z77\Module\Vat\Validators\TaxRateValidator;

/**
 * {@see VatMasterData::addRate()} refused the row: the validator it ran
 * carries the field errors, so a screen can show them where they belong.
 */
final class InvalidRateException extends VatException
{
    public function __construct(public readonly TaxRateValidator $validator)
    {
        parent::__construct('Tax rate refused: ' . implode(' ', array_merge($validator->getErrors(), array_values($validator->getFieldErrors()))));
    }
}
