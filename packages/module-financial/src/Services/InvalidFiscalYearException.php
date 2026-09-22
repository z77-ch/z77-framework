<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Validators\FiscalYearValidator;

/** The fiscal year failed validation; the validator carries the field errors, `$year` the submitted values. */
final class InvalidFiscalYearException extends FinancialException
{
    public function __construct(public readonly FiscalYearValidator $validator, public readonly FiscalYear $year)
    {
        parent::__construct('Fiscal year «' . $year->getCode() . '» is not valid: '
            . implode(' | ', [...$validator->getErrors(), ...$validator->getFieldErrors()]));
    }
}
