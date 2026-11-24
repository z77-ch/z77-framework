<?php

namespace Z77\Module\Mandator\Services;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;

/**
 * The mandator's VAT-return settings as ONE immutable answer (ADR-041 addendum 2026-11-24, points
 * 1–4) — what the VAT return, its period calculator and its menu entry read, through
 * {@see CurrentMandator::vatSettings()}. Each setting is null while NOT SET: there is no silent
 * default, the consumer refuses («nicht festgelegt»). The two accounts are not here — an account is
 * read through its module's access point (`mandator.md` rules).
 *
 * The decisions stay with the consumers: only effective + agreed is built (point 1), the period grid
 * and the short first period (points 2 and 3), the screen kept while a period up to `until` is not
 * settled (point 3). This object only reports what is stored.
 */
final readonly class VatSettings
{
    public function __construct(
        /** Liable to VAT — the default of {@see CurrentMandator::liableToVat()} applies (no record = liable). */
        public bool $liable,
        public ?VatMethod $method,
        public ?VatConsideration $consideration,
        public ?VatRhythm $rhythm,
        /** «Abrechnung ab» — nothing before it is recomputed. */
        public ?\DateTimeImmutable $from,
        /** «Abrechnung bis» — the end of the liability in z77; null = open-ended. */
        public ?\DateTimeImmutable $until,
    ) {}

    public static function of(Mandator $mandator): self
    {
        return new self(
            $mandator->isLiableToVat(),
            $mandator->getVatMethod(),
            $mandator->getVatConsideration(),
            $mandator->getVatSettlementRhythm(),
            $mandator->getVatSettlementFrom(),
            $mandator->getVatSettlementUntil(),
        );
    }

    /** No record saved yet: liable (the documented default), nothing set. */
    public static function none(): self
    {
        return new self(true, null, null, null, null, null);
    }
}
