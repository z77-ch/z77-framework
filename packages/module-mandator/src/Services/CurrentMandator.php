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
