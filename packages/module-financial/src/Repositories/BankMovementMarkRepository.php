<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankMovementMark;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMovementMark} — the office's decision by hand for one
 * movement. Doctrine-only (ADR-039 decision 8).
 */
class BankMovementMarkRepository extends DoctrineRepository
{
    public function findByMovement(int $movementId): ?BankMovementMark
    {
        return $this->findOneBy(['movement' => $movementId]);
    }
}
