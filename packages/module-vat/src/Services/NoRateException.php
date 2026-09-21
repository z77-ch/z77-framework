<?php

namespace Z77\Module\Vat\Services;

/**
 * The code exists but no {@see \Z77\Module\Vat\Entities\TaxRate} is in effect
 * on the service date — a date before the first row (the CH seed starts at
 * 2018-01-01) or a code that never got a rate. Deliberately loud: a silent
 * 0 % would be wdv's «Kein MwSt-Pflichtiger Betrag» fallback, which turned a
 * data gap into a tax-free invoice.
 */
final class NoRateException extends VatException
{
    public function __construct(public readonly string $taxCode, public readonly \DateTimeImmutable $serviceDate)
    {
        parent::__construct("No rate for tax code '{$taxCode}' on {$serviceDate->format('Y-m-d')}");
    }
}
