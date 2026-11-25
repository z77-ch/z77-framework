<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * The answer of the REVERSE-CHARGE date rule ({@see ReverseChargeTiming::forInvoiceDate()}) for one
 * invoice — ADR-041 addendum 2026-11-25, point 10, made precise by addendum 2026-11-25 b, point 3.
 * Year and period of the reverse charge are decided by the INVOICE date (the moment the tax claim
 * arises), never by the payment date; a posted period of that date always goes the correction path.
 *
 *   - {@see NONE} — the rule does not apply: the mandator is not liable, «Abrechnung ab» is not set,
 *     the mandator record cannot be read, or the invoice date lies after «Abrechnung bis» (after the
 *     end of the liability z77 settles nothing). The ordinary rules decide. The last case says so
 *     ({@see isAfterEnd()}): the caller posts NO reverse-charge tax and shows its hint that the tax
 *     may have to be declared outside z77 (owner, 2026-11-25) — it never compares the date with
 *     «Abrechnung bis» itself.
 *   - {@see BEFORE_START} — the invoice date lies before «Abrechnung ab»: nothing is caught up; the
 *     caller gives its existing «ohne MWST» sentence.
 *   - {@see POST_AT} — the invoice date lies in no posted VAT period: the tax entry is dated AT the
 *     invoice date ({@see $taxDate}) — never moved to another day.
 *   - {@see REFUSED} — the invoice date lies in a POSTED VAT period ({@see $settledPeriod}), in the
 *     same calendar year as the running period or not: nothing is posted automatically; the caller
 *     refuses with {@see refusalText()} — the voucher is posted «ohne MWST» and the reverse charge
 *     entered by hand as a correction entry naming that period.
 *
 * A value object of its own: it shares nothing with the answer of the input-tax catch-up rule
 * (owner, 2026-11-25 — two rules in two places, no common date check).
 */
final class ReverseChargeDate
{
    public const NONE         = 'none';
    public const BEFORE_START = 'before-start';
    public const POST_AT      = 'post-at';
    public const REFUSED      = 'refused';

    private function __construct(
        public readonly string $kind,
        /** POST_AT: the day the tax entry is dated on — the invoice date itself. */
        public readonly ?\DateTimeImmutable $taxDate = null,
        /** REFUSED: the posted VAT period the invoice date lies in. */
        public readonly ?VatPeriod $settledPeriod = null,
        /** NONE only: the invoice date lies after «Abrechnung bis» of a liable mandator that settles in z77. */
        private readonly bool $afterEnd = false,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** {@see NONE} because the invoice date lies after «Abrechnung bis» — the one `none` a caller tells apart. */
    public static function afterEnd(): self
    {
        return new self(self::NONE, null, null, true);
    }

    public static function beforeStart(): self
    {
        return new self(self::BEFORE_START);
    }

    public static function postAt(\DateTimeImmutable $invoiceDate): self
    {
        return new self(self::POST_AT, $invoiceDate->setTime(0, 0));
    }

    public static function refused(VatPeriod $settledPeriod): self
    {
        return new self(self::REFUSED, null, $settledPeriod);
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }

    /**
     * True when the rule does not apply BECAUSE the invoice date lies after «Abrechnung bis»: z77
     * posts no reverse-charge tax for that day and requires none; the caller shows a hint. Any other
     * `none` (not liable, no «Abrechnung ab») answers false.
     */
    public function isAfterEnd(): bool
    {
        return $this->kind === self::NONE && $this->afterEnd;
    }

    public function isBeforeStart(): bool
    {
        return $this->kind === self::BEFORE_START;
    }

    public function isPostAt(): bool
    {
        return $this->kind === self::POST_AT;
    }

    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /**
     * The owner's sentence of a refusal (ADR-041 addendum 2026-11-25 b, point 3), WITH its full
     * stop: «Die Bezugsteuer gehört in die Periode 2. Quartal 2026, sie ist abgerechnet — die
     * Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu 2. Quartal 2026
     * erfassen.» $voucher names the document («die Rechnung», «den Beleg»).
     *
     * @throws \LogicException not a refusal
     */
    public function refusalText(string $voucher = 'die Rechnung'): string
    {
        if (!$this->isRefused() || $this->settledPeriod === null) {
            throw new \LogicException('refusalText() is the sentence of a refused reverse-charge date — this one is ' . $this->kind);
        }
        $period = $this->settledPeriod->label();

        return 'Die Bezugsteuer gehört in die Periode ' . $period . ', sie ist abgerechnet — ' . $voucher
            . ' «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu ' . $period . ' erfassen.';
    }
}
