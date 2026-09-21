<?php

namespace Z77\Module\Vat\Calculation;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Shared\Money\Money;

/**
 * One document line as the calculator sees it: an opaque reference the caller
 * recognises its line by, the line amount (net or gross — the document's
 * {@see PriceMode} says which; negative on a credit note) and the tax code.
 *
 * `Money` in the signature is what keeps a float out: there is no way to
 * hand the calculator an amount that is not integer minor units.
 */
final class VatLine
{
    public readonly string $taxCode;

    public function __construct(
        public readonly string $ref,
        public readonly Money $amount,
        string $taxCode,
    ) {
        $this->taxCode = TaxCode::normalizeCode($taxCode);
        if ($this->taxCode === '') {
            throw new \InvalidArgumentException("VatLine '{$ref}' has no tax code");
        }
    }
}
