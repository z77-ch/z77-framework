<?php

namespace Z77\Module\Contact\Services;

/** An existing type's code was about to change — the key `contact_address.type_code` carries (ADR-043 decision 19). */
final class AddressTypeCodeChangedException extends ContactException
{
    public function __construct(public readonly string $storedCode, public readonly string $newCode)
    {
        parent::__construct("Address type code is immutable: '{$storedCode}' cannot become '{$newCode}' — a different kind of address is a new type");
    }
}
