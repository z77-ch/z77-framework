<?php

namespace Z77\Module\Financial\Services;

use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Validators\AccountValidator;

/**
 * The account failed validation; the validator carries the field errors for
 * the form, `$draft` the values as they were submitted — for an existing
 * account that is the detached draft, NOT the managed entity, which stays
 * untouched (ADR-039 decision 9).
 */
final class InvalidAccountException extends FinancialException
{
    public function __construct(public readonly AccountValidator $validator, public readonly Account $draft)
    {
        parent::__construct('Account ' . $draft->getNumber() . ' is not valid: '
            . implode(' | ', [...$validator->getErrors(), ...$validator->getFieldErrors()]));
    }
}
