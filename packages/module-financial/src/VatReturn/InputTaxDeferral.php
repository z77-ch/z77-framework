<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * The answer of the INPUT-TAX catch-up rule ({@see VatSettlement::inputTaxDeferral()}) for one
 * expense — «Vorsteuer nachholen», ADR-041 addendum 2026-11-25, point 9. It is about input tax
 * only: reverse charge has a rule of its own (decided by the INVOICE date, a posted period always
 * by correction entry — point 10 there), which this class does not answer and must not be used for.
 *
 *   - {@see NONE} — the payment date lies in no settled VAT period: this rule does not apply; the
 *     expense and its input tax are posted as usual (the ordinary period rules decide, also «vor
 *     Abrechnung ab»).
 *   - {@see CATCH_UP} — settled, and the running period (the first VAT period not yet posted)
 *     starts in the SAME calendar year as the payment date: the expense at its date without VAT
 *     plus the tax on the transit account `vat-input-pending`, the tax moved to the input account on
 *     {@see $taxDate} (the first day of the running period).
 *   - {@see REFUSED} — settled, and the running period starts in another calendar year (or there is
 *     none left after «Abrechnung bis»): nothing is caught up; the caller refuses with
 *     {@see refusalText()} — the voucher is posted «ohne MWST» and the input tax entered by hand as a
 *     correction entry naming {@see $settledPeriod}.
 */
final class InputTaxDeferral
{
    public const NONE     = 'none';
    public const CATCH_UP = 'catch-up';
    public const REFUSED  = 'refused';

    private function __construct(
        public readonly string $kind,
        /** CATCH_UP: the day the input tax is posted on — the first day of the running period. */
        public readonly ?\DateTimeImmutable $taxDate = null,
        /** CATCH_UP / REFUSED: the settled period the payment date lies in. */
        public readonly ?VatPeriod $settledPeriod = null,
        /** REFUSED: the calendar year of the payment date. */
        public readonly ?int $year = null,
    ) {}

    public static function none(): self
    {
        return new self(self::NONE);
    }

    public static function catchUp(\DateTimeImmutable $taxDate, VatPeriod $settledPeriod): self
    {
        return new self(self::CATCH_UP, $taxDate->setTime(0, 0), $settledPeriod);
    }

    public static function refused(int $year, VatPeriod $settledPeriod): self
    {
        return new self(self::REFUSED, null, $settledPeriod, $year);
    }

    public function isNone(): bool
    {
        return $this->kind === self::NONE;
    }

    public function isCatchUp(): bool
    {
        return $this->kind === self::CATCH_UP;
    }

    public function isRefused(): bool
    {
        return $this->kind === self::REFUSED;
    }

    /**
     * The owner's sentence of a refusal (F-1), WITH its full stop: «Vorsteuer aus 2025 wird nicht
     * nachgeholt — die Rechnung «ohne MWST» verbuchen und die Vorsteuer als Korrekturbuchung zu
     * 4. Quartal 2025 erfassen.» $voucher names the document («die Rechnung», «den Beleg»).
     *
     * @throws \LogicException not a refusal
     */
    public function refusalText(string $voucher = 'die Rechnung'): string
    {
        if (!$this->isRefused()) {
            throw new \LogicException('refusalText() is the sentence of a refused input-tax deferral — this one is ' . $this->kind);
        }

        return 'Vorsteuer aus ' . $this->year . ' wird nicht nachgeholt — ' . $voucher . ' «ohne MWST» verbuchen und die Vorsteuer als Korrekturbuchung zu '
            . $this->settledPeriod?->label() . ' erfassen.';
    }
}
