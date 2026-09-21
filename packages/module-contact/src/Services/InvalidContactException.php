<?php

namespace Z77\Module\Contact\Services;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Validators\ContactValidator;

/**
 * The contact failed validation; the validator carries the field errors for
 * the form, `$draft` the values as they were submitted — for an existing
 * contact that is the detached draft, NOT the managed entity, which stays
 * untouched (ADR-039 decision 9).
 */
final class InvalidContactException extends ContactException
{
    public function __construct(public readonly ContactValidator $validator, public readonly Contact $draft)
    {
        parent::__construct('Contact is not valid: ' . implode(' | ', [...$validator->getErrors(), ...$validator->getFieldErrors()]));
    }
}
