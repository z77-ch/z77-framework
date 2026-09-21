<?php

namespace Z77\Module\Vat\Services;

/**
 * The `code` of an existing {@see \Z77\Module\Vat\Entities\TaxCode} was
 * changed: it is the key documents and journal lines carry (ADR-043 decision
 * 19) and is immutable after creation. A different kind of tax is a new code.
 */
final class TaxCodeChangedException extends VatException
{
    public function __construct(public readonly string $storedCode, public readonly string $newCode)
    {
        parent::__construct("Tax code '{$storedCode}' cannot be renamed to '{$newCode}' — the code is the key documents reference; create a new code instead");
    }
}
