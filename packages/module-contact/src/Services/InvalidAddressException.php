<?php

namespace Z77\Module\Contact\Services;

use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Validators\AddressValidator;
use Z77\Module\Contact\Validators\ContactAddressValidator;

/**
 * A typed address failed validation — the link part (type code, title) or
 * the address fields, or both. Both validators travel with the exception so
 * a form can show every field error at once.
 */
final class InvalidAddressException extends ContactException
{
    public function __construct(
        public readonly ContactAddress $link,
        public readonly ContactAddressValidator $linkValidator,
        public readonly AddressValidator $addressValidator,
    ) {
        parent::__construct('Address is not valid: ' . implode(' | ', [
            ...$linkValidator->getErrors(), ...$linkValidator->getFieldErrors(),
            ...$addressValidator->getErrors(), ...$addressValidator->getFieldErrors(),
        ]));
    }
}
