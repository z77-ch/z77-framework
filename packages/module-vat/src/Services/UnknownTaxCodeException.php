<?php

namespace Z77\Module\Vat\Services;

/** The code names no {@see \Z77\Module\Vat\Entities\TaxCode} at all — a typo or a code this installation never had. */
final class UnknownTaxCodeException extends VatException
{
    public function __construct(public readonly string $taxCode)
    {
        parent::__construct("Unknown tax code '{$taxCode}'");
    }
}
