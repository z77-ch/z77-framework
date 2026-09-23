<?php

namespace Z77\Module\Debtor\Invoicing;

use Z77\Module\Debtor\Entities\LineType;
use Z77\Shared\Money\Money;

/**
 * One line of an {@see InvoiceDraft} as a source hands it in (plan §6.2):
 * the type, the text, what the type needs (quantity and unit price for a
 * service, a price for a lump sum, nothing for a text line), the discount in
 * hundredths of a percent, the tax code, the revenue account BY NUMBER, an
 * opaque origin, and — for a priced line — the lines printed BENEATH it
 * (`children`, one level: a package with its contents at 0.00, §13).
 *
 * Plain data: the rules (tax code required on a priced line, a child under a
 * priced parent only, …) are `InvoicingService`'s, which refuses with a
 * reason the caller can show. The rounding line is never drafted — the
 * service adds it.
 */
final class LineDraft
{
    /** @param list<LineDraft> $children lines printed beneath this one; only a priced line may carry them */
    public function __construct(
        public readonly LineType $type,
        public readonly string $text,
        public readonly ?string $quantity = null,
        public readonly ?string $unit = null,
        public readonly ?Money $unitPrice = null,
        public readonly int $discountPercent = 0,
        public readonly ?string $taxCode = null,
        public readonly ?string $revenueAccount = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceRef = null,
        public readonly array $children = [],
    ) {}

    /** quantity × unit price, with unit; the usual line. */
    public static function service(string $text, string $quantity, ?string $unit, Money $unitPrice, string $taxCode, string $revenueAccount, int $discountPercent = 0, ?string $sourceType = null, ?string $sourceRef = null): self
    {
        return new self(LineType::Service, $text, $quantity, $unit, $unitPrice, $discountPercent, $taxCode, $revenueAccount, $sourceType, $sourceRef);
    }

    /** One price, no quantity. */
    public static function lumpSum(string $text, Money $price, string $taxCode, string $revenueAccount, int $discountPercent = 0, ?string $sourceType = null, ?string $sourceRef = null): self
    {
        return new self(LineType::LumpSum, $text, null, null, $price, $discountPercent, $taxCode, $revenueAccount, $sourceType, $sourceRef);
    }

    /** Prints only. */
    public static function text(string $text, ?string $sourceType = null, ?string $sourceRef = null): self
    {
        return new self(LineType::Text, $text, sourceType: $sourceType, sourceRef: $sourceRef);
    }

    /** The same line with $children printed beneath it. */
    public function beneath(LineDraft ...$children): self
    {
        return new self(
            $this->type, $this->text, $this->quantity, $this->unit, $this->unitPrice, $this->discountPercent,
            $this->taxCode, $this->revenueAccount, $this->sourceType, $this->sourceRef, array_values($children)
        );
    }
}
