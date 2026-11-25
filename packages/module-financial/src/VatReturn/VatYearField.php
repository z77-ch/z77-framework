<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Vat\CountryPack\FieldKind;
use Z77\Shared\Money\Money;

/**
 * One form field of a tax year — the table «Steuerjahr {Jahr} — Summe pro Ziffer» (ADR-042
 * addendum 2 of 2026-11-25, point 8): what the posted returns of the year DECLARED, the corrections
 * FOR the year (the stored blocks of every live carrying return, also of a later year — what goes
 * into the Jahresabstimmung), and the new figure. Immutable; stored figures only, nothing
 * recomputed.
 *
 *   - `declared` — Σ of the stored exact figures (`vat_return_figure.value_exact`) of the year's
 *     live returns; `corrections` — Σ of the stored exact corrections of the year's periods;
 *     {@see newValue()} = declared + corrections. Every one EXACT: the screen shows `->rounded()`
 *     and the exact value small beneath — each column rounded ONCE from its exact sum (B-2);
 *   - the PAYABLE field (500) is the exception the rounding trap asks for: `declared` is Σ of the
 *     PAYABLE AMOUNTS AS POSTED (`vat_return.payable`, signed — a credit counts negative), never
 *     Σ of the exact figures re-rounded (four quarters of 41.00486 declared 164.00, not 164.02 —
 *     no Rappen a supply did not cause); its corrections are the blocks' payable differences
 *     (credit corrections subtracted). The credit field (510) is folded into it and not listed;
 *   - `…Base` — the turnover of a rate or acquisition field (Money), null for every other kind;
 *   - `shown` — a live return showed the field (an amount, or one of the totals), or the year has a
 *     correction of it.
 */
final class VatYearField
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly FieldKind $kind,
        public readonly ExactAmount $declared,
        public readonly ?Money $declaredBase,
        public readonly ExactAmount $corrections,
        public readonly ?Money $correctionsBase,
        public readonly bool $shown,
    ) {}

    /** «neu» — declared + corrections, exact; rounded once by the reader (`->rounded()`). */
    public function newValue(): ExactAmount
    {
        return $this->declared->add($this->corrections);
    }

    /** The new base of a rate or acquisition field — null for every other kind. */
    public function newBase(): ?Money
    {
        if ($this->declaredBase === null && $this->correctionsBase === null) {
            return null;
        }
        $zero = Money::zero($this->declared->currency);

        return ($this->declaredBase ?? $zero)->add($this->correctionsBase ?? $zero);
    }

    /** True when a correction for the year changes the field (its tax or its base). */
    public function hasCorrection(): bool
    {
        return !$this->corrections->isZero() || ($this->correctionsBase !== null && !$this->correctionsBase->isZero());
    }
}
