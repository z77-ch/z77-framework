<?php

namespace Z77\Module\Receipt\Services;

use Z77\Persistence\Validation\EntityValidator;

/**
 * A receipt template or a payment method failed its validator inside its service. Carries the
 * validator, so a screen re-renders the form with the field errors (the debtor
 * `InvalidMasterDataException` model). The row the caller handed in is still the draft — a file
 * row is never managed.
 */
final class InvalidMasterDataException extends ReceiptException
{
    public function __construct(public readonly EntityValidator $validator)
    {
        parent::__construct('Master data refused: ' . implode('; ', array_merge($validator->getErrors(), array_values($validator->getFieldErrors()))));
    }
}
