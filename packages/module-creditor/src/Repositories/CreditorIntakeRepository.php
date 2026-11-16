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

    /**
     * The folder files the intake refused — waiting in `failed/` for the owner (tab «Nicht eingelesen»),
     * oldest first.
     *
     * @return list<CreditorIntake>
     */
    public function failed(int $limit = 200): array
    {
        return $this->em()->createQueryBuilder()->select('i')->from(CreditorIntake::class, 'i')
            ->where('i.state = :state')->setParameter('state', CreditorIntake::STATE_FAILED)
            ->orderBy('i.receivedAt', 'ASC')->addOrderBy('i.id', 'ASC')->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    /**
     * What the journal's live listener compares for the refused files (owner 2026-11-16): their count
     * and highest id — a new refused file, one retried or removed change it.
     */
    public function failedFingerprint(): string
    {
        $row = $this->em()->createQueryBuilder()->select('COUNT(i.id) AS n', 'MAX(i.id) AS top')->from(CreditorIntake::class, 'i')
            ->where('i.state = :state')->setParameter('state', CreditorIntake::STATE_FAILED)
            ->getQuery()->getSingleResult();

        return (int) ($row['n'] ?? 0) . '.' . (int) ($row['top'] ?? 0);
    }

    public function countFailed(): int
    {
        return (int) $this->em()->createQueryBuilder()->select('COUNT(i.id)')->from(CreditorIntake::class, 'i')
            ->where('i.state = :state')->setParameter('state', CreditorIntake::STATE_FAILED)
            ->getQuery()->getSingleScalarResult();
    }
}
