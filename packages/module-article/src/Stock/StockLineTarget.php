<?php

namespace Z77\Module\Article\Stock;

/**
 * The target of ONE source line (plan §8.1): which variant, and how much of it should be
 * RESERVED and CONSUMED now. Plain data, checked on construction.
 *
 * Quantities are decimal strings with exactly three places (`DECIMAL(12,3)`, the line's
 * quantity column) — never a float. {@see quantity()} normalises what a caller has («2»,
 * «-1.5» → «2.000», «-1.500»). A NEGATIVE quantity is an inflow (a return, ADR-043 decision 7);
 * the source computes the targets from its own status flags (reserved = quantity if the status
 * reserves, consumed = quantity if it consumes, else «0.000») — article never sees the flags.
 */
final class StockLineTarget
{
    /** `DECIMAL(12,3)`: up to nine digits before the point, three after, optional sign. */
    private const QUANTITY_PATTERN = '/^-?\d{1,9}(?:\.\d{1,3})?$/';

    public const ZERO = '0.000';

    public readonly string $lineRef;
    public readonly int $variantId;
    public readonly string $reserved;
    public readonly string $consumed;

    /**
     * @param string $lineRef   the source's opaque line reference (order: the line id) — never interpreted
     * @param int    $variantId the article variant (an order line references the VARIANT, A3)
     * @param string $reserved  decimal string
     * @param string $consumed  decimal string
     * @throws \InvalidArgumentException an empty reference, a variant id below 1, a quantity that is no decimal
     */
    public function __construct(string $lineRef, int $variantId, string $reserved = self::ZERO, string $consumed = self::ZERO)
    {
        $lineRef = trim($lineRef);
        if ($lineRef === '' || mb_strlen($lineRef) > 128) {
            throw new \InvalidArgumentException('A stock line target needs a line reference of 1–128 characters');
        }
        if ($variantId < 1) {
            throw new \InvalidArgumentException("A stock line target needs a variant id, got {$variantId}");
        }
        $this->lineRef   = $lineRef;
        $this->variantId = $variantId;
        $this->reserved  = self::quantity($reserved);
        $this->consumed  = self::quantity($consumed);
    }

    /**
     * «2», «-1.5», «0.250» → the three-place decimal string.
     *
     * @throws \InvalidArgumentException not a decimal of at most nine integer digits and three places
     */
    public static function quantity(string $value): string
    {
        $value = trim($value);
        if (!preg_match(self::QUANTITY_PATTERN, $value)) {
            throw new \InvalidArgumentException("'{$value}' is no stock quantity (a decimal with at most three places)");
        }
        $negative = str_starts_with($value, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $int  = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $norm = $int . '.' . str_pad($frac, 3, '0');

        return ($negative && $norm !== self::ZERO ? '-' : '') . $norm;
    }
}
