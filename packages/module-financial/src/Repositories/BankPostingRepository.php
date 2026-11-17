<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankPosting;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankPosting} — the direct postings of a bank movement.
 * Doctrine-only (ADR-039 decision 8).
 */
class BankPostingRepository extends DoctrineRepository
{
    /** The posting that books the movement NOW (not taken back), or null. */
    public function currentOf(int $movementId): ?BankPosting
    {
        return $this->findOneBy(['movement' => $movementId, 'takenBackAt' => null]);
    }
}
