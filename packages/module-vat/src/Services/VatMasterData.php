<?php

namespace Z77\Module\Vat\Services;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Validators\TaxRateValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of the master data — the few rules that must hold no matter
 * which screen or script writes (ADR-041 decision 2, ADR-043 decision 19,
 * owner decisions of 2026-09-21):
 *
 *   - a tax code is never deleted, only deactivated: there is no method for it;
 *   - the `code` of an existing row is immutable — documents reference it;
 *   - a rate is added, never edited; a backdated `validFrom` is refused unless
 *     it backfills history before the code's earliest row (the validator holds
 *     that rule, {@see TaxRateValidator});
 *   - a rate in effect is never removed — except a row entered TODAY for
 *     today, the same-day typo; a future row may go.
 *
 * Validation of the fields themselves is the validators' job; this class
 * holds what a validator cannot express: what may happen to a row at all —
 * and runs the rate validator itself, so a writer that is not the backend
 * (a future import) is bound to the same rules.
 */
final class VatMasterData
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * Persist a new or edited code. For an existing row the stored `code` is
     * compared against what is about to be written; a change is refused.
     *
     * @throws TaxCodeChangedException the code of an existing row differs from what is stored
     */
    public function saveCode(TaxCode $code): void
    {
        if ($code->getId() !== null) {
            $stored = $this->em->getRepository(TaxCode::class)->find($code->getId());
            if ($stored !== null && $stored->getCode() !== $code->getCode()) {
                throw new TaxCodeChangedException($stored->getCode(), $code->getCode());
            }
        }

        $this->em->persist($code);
        $this->em->flush();
    }

    public function setActive(TaxCode $code, bool $active): void
    {
        $code->setActive($active);
        $this->saveCode($code);
    }

    /**
     * Add a NEW rate row (the only way a rate gets in). Stamps `createdOn`
     * with $today, runs {@see TaxRateValidator} with the backdating rule and
     * persists when valid.
     *
     * @throws \LogicException      the row already has an id — rates are never edited
     * @throws InvalidRateException the validator refused it; the exception carries the validator
     */
    public function addRate(TaxRate $rate, \DateTimeImmutable $today): void
    {
        if ($rate->getId() !== null) {
            throw new \LogicException('A tax rate is never edited — a rate change is a new row (ADR-041 decision 2)');
        }
        $rate->setCreatedOn($today->format('Y-m-d'));

        $validator = new TaxRateValidator(
            $rate,
            $this->em->getRepository(TaxCode::class),
            $this->em->getRepository(TaxRate::class),
            $today,
        );
        if (!$validator->isValid()) {
            throw new InvalidRateException($validator);
        }

        $this->em->persist($rate);
        $this->em->flush();
    }

    /**
     * A row may go while its validity has not started on $today — or when it
     * was entered today for today (a same-day typo). A seed row carries no
     * `createdOn` and is therefore never the second case.
     */
    public function canRemoveRate(TaxRate $rate, \DateTimeImmutable $today): bool
    {
        $day = $today->format('Y-m-d');

        return !$rate->isEffectiveOn($today)
            || ($rate->getValidFrom() === $day && $rate->getCreatedOn() === $day);
    }

    /**
     * @throws RateInEffectException the row is (or was) in effect — history stays
     */
    public function removeRate(TaxRate $rate, \DateTimeImmutable $today): void
    {
        if (!$this->canRemoveRate($rate, $today)) {
            throw new RateInEffectException($rate);
        }
        $this->em->remove($rate);
    }
}
