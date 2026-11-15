<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorIntake}: the intake row of a file by the SHA-256 of its
 * bytes — the idempotency of the intake (owner answer 4: the identical file is never read twice).
 */
class CreditorIntakeRepository extends DoctrineRepository
{
    public function findBySha256(string $sha256): ?CreditorIntake
    {
        return $sha256 === '' ? null : $this->findOneBy(['sha256' => strtolower($sha256)]);
    }
}
