<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;

/**
 * What a tax code showed at the moment of use — the snapshot a document keeps
 * (ADR-041 decision 6, ADR-043 decision 19). Once stored on an invoice line,
 * nothing downstream asks the code table again: a later rate change or a
 * relabelled code cannot alter an issued document.
 *
 * The storage shape (a JSON column or file-based fields) is the consumer's:
 * it arrives with the invoice in P3 (`vat.md` pending).
 */
final class ResolvedRate
{
    public function __construct(
        public readonly string $code,
        public readonly string $country,
        public readonly string $category,
        public readonly string $label,
        public readonly int $rate,
        public readonly string $validFrom,
    ) {}

    public static function fromEntities(TaxCode $code, TaxRate $rate): self
    {
        return new self(
            $code->getCode(),
            $code->getCountry(),
            $code->getCategory(),
            $code->getLabel(),
            $rate->getRate(),
            $rate->getValidFrom(),
        );
    }
}
