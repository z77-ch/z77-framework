<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Shared\Money\Money;

/**
 * An amount kept EXACT where the VAT return must not round: the tax of a
 * rate field is base × rate, and the payable amount is rounded ONCE from the
 * sum of the exact products (ADR-041 addendum 2026-11-24, point 7; review
 * C3 — rounding per field gives another payable amount).
 *
 * Integer arithmetic only, like {@see Money} (no float anywhere): the value
 * is counted in ten-thousandths of a minor unit. A rate is an integer in
 * hundredths of a percent (810 = 8.1 %), so base × rate / 10 000 minor units
 * is exactly `base->minor × rate` of these units — no rational needed, and a
 * DECIMAL(15,2) base times a rate below 100 % stays far inside int64.
 *
 * Immutable; one currency.
 */
final class ExactAmount
{
    /** Units of this class per minor unit (one Rappen = 10 000). */
    public const PER_MINOR = 10000;

    private function __construct(
        public readonly int $units,
        public readonly string $currency,
    ) {}

    public static function zero(string $currency): self
    {
        return new self(0, Money::zero($currency)->currency);
    }

    /** A money amount, exact by nature. */
    public static function ofMoney(Money $money): self
    {
        return new self(self::multiply($money->minor, self::PER_MINOR), $money->currency);
    }

    /**
     * The inverse of {@see toDecimal()}: "958.822110" → the exact amount, through integers only
     * (a stored `DECIMAL(21,6)` figure of a posted return, `VatReturnFigure`). At most six decimals
     * (two of the currency plus four); fewer are padded.
     */
    public static function fromDecimal(string $decimal, string $currency): self
    {
        $scale   = Money::SCALE + 4;
        $decimal = trim($decimal);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,' . $scale . '}))?$/', $decimal, $m)) {
            throw new \InvalidArgumentException("Not an exact amount with at most {$scale} decimals: '{$decimal}'");
        }
        $whole = self::multiply((int) $m[2], 10 ** $scale);
        $units = $whole + (int) str_pad($m[3] ?? '', $scale, '0');
        if (!is_int($units)) {
            throw new \OverflowException('ExactAmount: integer overflow in addition');
        }

        return new self($m[1] === '-' ? -$units : $units, Money::zero($currency)->currency);
    }

    /** base × rate, exact — `$rate` an integer in hundredths of a percent (810 = 8.1 %); a float is a TypeError, not coerced. */
    public static function taxOf(Money $base, mixed $rate): self
    {
        if (!is_int($rate)) {
            throw new \TypeError('ExactAmount::taxOf() takes the rate as an integer, ' . get_debug_type($rate) . ' given');
        }

        return new self(self::multiply($base->minor, $rate), $base->currency);
    }

    public function add(self $other): self
    {
        $this->requireSameCurrency($other);
        $sum = $this->units + $other->units;
        if (!is_int($sum)) {
            throw new \OverflowException('ExactAmount: integer overflow in addition');
        }

        return new self($sum, $this->currency);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function negate(): self
    {
        return new self(-$this->units, $this->currency);
    }

    /** Rounded half away from zero to minor units — the ONE rounding of the return. */
    public function rounded(): Money
    {
        return Money::of($this->units, $this->currency)->multiplyByRatio(1, self::PER_MINOR);
    }

    /** True when no fraction of a minor unit is left (a posted amount, a sum of bases). */
    public function isWhole(): bool
    {
        return $this->units % self::PER_MINOR === 0;
    }

    public function isNegative(): bool
    {
        return $this->units < 0;
    }

    public function isZero(): bool
    {
        return $this->units === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->units === $other->units;
    }

    /** "958.822110" — every decimal kept (two of the currency plus four). */
    public function toDecimal(): string
    {
        $scale = Money::SCALE + 4;
        $abs   = abs($this->units);
        $base  = 10 ** $scale;

        return ($this->units < 0 ? '-' : '') . intdiv($abs, $base) . '.' . str_pad((string) ($abs % $base), $scale, '0', STR_PAD_LEFT);
    }

    private function requireSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }

    private static function multiply(int $a, int $b): int
    {
        $product = $a * $b;
        if (!is_int($product)) {
            throw new \OverflowException('ExactAmount: integer overflow in multiplication');
        }

        return $product;
    }
}
