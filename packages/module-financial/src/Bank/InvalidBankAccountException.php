<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Services\FinancialException;
use Z77\Module\Financial\Validators\BankAccountValidator;

/**
 * The bank account failed validation; the validator carries the field errors for the form,
 * `$draft` the values as they were submitted — for an existing bank account the detached draft,
 * NOT the managed entity, which stays untouched (ADR-039 decision 9).
 */
final class InvalidBankAccountException extends FinancialException
{
    public function __construct(public readonly BankAccountValidator $validator, public readonly BankAccount $draft)
    {
        parent::__construct('Bank account «' . $draft->getCode() . '» is not valid: '
            . implode(' | ', [...$validator->getErrors(), ...$validator->getFieldErrors()]));
    }
}
