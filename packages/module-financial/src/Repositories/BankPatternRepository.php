<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankPattern;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankPattern} — the one pattern of a bank account, direction and
 * key. Doctrine-only (ADR-039 decision 8).
 */
class BankPatternRepository extends DoctrineRepository
{
    /** The pattern of that key, whatever it says and however it is narrowed; null for an empty key. */
    public function findFor(int $bankAccountId, string $direction, string $matchKey): ?BankPattern
    {
        return $matchKey === '' ? null : $this->findOneBy(['bankAccount' => $bankAccountId, 'direction' => $direction, 'matchKey' => $matchKey]);
    }
}
