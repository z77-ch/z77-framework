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
}
