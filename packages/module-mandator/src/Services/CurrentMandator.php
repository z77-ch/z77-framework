<?php

namespace Z77\Module\Mandator\Services;

use Z77\Core\DI;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Repositories\MandatorRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * How the rest of the framework reaches THE mandator (owner decision E1):
 * one record, read fixed, never created on access. Three answers, like
 * {@see LedgerAccountCheck} (review 2026-09-23):
 *
 *   - a `Mandator` — saved and readable;
 *   - `null` — none saved yet: a letterhead prints empty, an account
 *     resolver refuses with a message naming the mandator screen;
 *   - {@see MandatorUnavailableException} — the record CANNOT be read: the
 *     module is not registered, or its table is missing. Both are
 *     installation states of an upgrade half done, and both carry a German
 *     sentence naming the next step; the readers show it as a refusal,
 *     never as a 500. {@see unavailableReason()} is the same question
 *     asked without throwing, for a screen that wants to show the band.
 *
 * Nothing fatals on a missing or unavailable mandator.
 */
final class CurrentMandator
{
    /** The module key that must be registered for the entity to be announced at all. */
    public const MODULE = 'mandator';

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The one record, or null when none was ever saved.
     *
     * @throws MandatorUnavailableException the module is not registered, or the table is missing
     */
    public function find(): ?Mandator
    {
        if (DI::getModuleManager()->getModuleConfig(self::MODULE) === null) {
            throw MandatorUnavailableException::moduleNotRegistered();
        }

        return $this->repository()->theOne();
    }

    /** @throws MandatorUnavailableException see {@see find()} */
    public function exists(): bool
    {
        return $this->find() !== null;
    }

    /**
     * THE answer to «is the company liable to VAT» (`liable_to_vat`, ADR-041 addendum 2026-10-21) —
     * the one place of its default: no record saved, or a record that cannot be read, counts as
     * LIABLE (the absence of a record is no declaration of «not liable»). Read by debtor's
     * `InvoicingService::chargesVat()` (documents), module-article's validator and window (the tax
     * code is optional while not liable) and the setup checks that only matter while liable.
     */
    public function liableToVat(): bool
    {
        try {
            return $this->find()?->isLiableToVat() ?? true;
        } catch (MandatorUnavailableException) {
            return true;
        }
    }

    /**
     * The VAT-return settings (ADR-041 addendum 2026-11-24) as one immutable answer — method axes,
     * rhythm, «Abrechnung ab» / «bis», liable yes / no. No record → {@see VatSettings::none()}
     * (liable, nothing set — the consumer refuses). Unlike {@see liableToVat()} an UNREADABLE record
     * throws: a return must refuse naming the reason, not run on a guess.
     *
     * @throws MandatorUnavailableException see {@see find()}
     */
    public function vatSettings(): VatSettings
    {
        $mandator = $this->find();

        return $mandator === null ? VatSettings::none() : VatSettings::of($mandator);
    }

    /** The German sentence of {@see MandatorUnavailableException} when the record cannot be read, null when it can (saved or not). */
    public function unavailableReason(): ?string
    {
        try {
            $this->find();
        } catch (MandatorUnavailableException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function repository(): MandatorRepository
    {
        return $this->em->getRepository(Mandator::class);
    }
}
