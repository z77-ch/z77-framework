<?php

namespace Z77\Module\Debtor\Services;

use Z77\Shared\Money\Money;

/**
 * The payment state of ONE booked document as the receivables side sees it now — what
 * {@see PaymentStates::of()} answers a source (an order's list and window, plan §7). Derived,
 * never stored (plan §6.3): from the gross, the final credit notes against it and the
 * allocations (payment, discount, loss).
 *
 *   - `open`     — nothing settled yet (open = gross);
 *   - `partial`  — something settled, something open (0 < open < gross);
 *   - `paid`     — nothing open (open ≤ 0) and at least part of it settled by allocations;
 *   - `credited` — nothing open, and the credit notes alone cover the gross (no allocation):
 *                  the invoice was cancelled by credit note, not paid.
 *
 * A negative open amount (more credited than invoiced — DEBTOR-CREDIT-001) is `paid` /
 * `credited` with the negative `open` carried as it is.
 */
final class PaymentState
{
    public const OPEN     = 'open';
    public const PARTIAL  = 'partial';
    public const PAID     = 'paid';
    public const CREDITED = 'credited';

    /** German labels for a screen. */
    public const LABELS = [self::OPEN => 'offen', self::PARTIAL => 'teilbezahlt', self::PAID => 'bezahlt', self::CREDITED => 'gutgeschrieben'];

    public function __construct(
        public readonly string $status,
        public readonly Money $gross,
        public readonly Money $open,
    ) {
        if (!isset(self::LABELS[$status])) {
            throw new \InvalidArgumentException("Unknown payment state «{$status}»");
        }
    }

    /** From the figures: gross, Σ final credit notes, Σ allocations. */
    public static function of(Money $gross, Money $credited, Money $allocated): self
    {
        $open = $gross->subtract($credited)->subtract($allocated);
        $status = match (true) {
            $open->isPositive() && $credited->isZero() && $allocated->isZero() => self::OPEN,
            $open->isPositive()                                                => self::PARTIAL,
            $allocated->isZero() && !$credited->isZero()                       => self::CREDITED,
            default                                                            => self::PAID,
        };

        return new self($status, $gross, $open);
    }

    public function label(): string
    {
        return self::LABELS[$this->status];
    }
}
