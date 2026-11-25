<?php

namespace Z77\Module\Mandator\Repositories;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Mandator}. The one row has the fixed id
 * {@see Mandator::ID}, so «the mandator» is a primary-key read. The one
 * state this repository translates: the table does not exist yet (the
 * module was installed but `z77-db migrate` not run) — Doctrine's
 * `TableNotFoundException` becomes {@see MandatorUnavailableException}
 * with a German sentence saying what to do, so a screen shows a band
 * instead of a 500 (review 2026-09-23, P6d). Every other database failure
 * stays what it is.
 */
class MandatorRepository extends DoctrineRepository
{
    /**
     * THE mandator, or null when it was never saved. Never creates one.
     *
     * @throws MandatorUnavailableException the table is missing (migration not run)
     */
    public function theOne(): ?Mandator
    {
        try {
            /** @var Mandator|null $row */
            $row = $this->find(Mandator::ID);
        } catch (TableNotFoundException $e) {
            throw MandatorUnavailableException::tableMissing($e);
        }

        return $row;
    }

    /**
     * THE mandator RE-READ from the database — the identity map's copy refreshed in place, so
     * every later {@see theOne()} of this request answers the committed state too. For a writer
     * that decides after its row locks on what was committed, not on what an earlier read of the
     * request left in memory (financial's VAT return writer, `CurrentMandator::fresh()`). A change
     * made to the entity and not yet flushed is lost — call it only where nothing edits the record.
     * Doctrine-only (DQL).
     *
     * @throws MandatorUnavailableException the table is missing (migration not run)
     */
    public function fresh(): ?Mandator
    {
        try {
            return $this->em()->createQueryBuilder()
                ->select('m')
                ->from(Mandator::class, 'm')
                ->where('m.id = :id')
                ->setParameter('id', Mandator::ID)
                ->getQuery()
                ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
        } catch (TableNotFoundException $e) {
            throw MandatorUnavailableException::tableMissing($e);
        }
    }
}
