<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorLearned;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorLearned}: what a creditor learned, and which creditors
 * hold the features a text carries (owner 2026-11-16, learning per creditor). Doctrine-only (DQL).
 */
class CreditorLearnedRepository extends DoctrineRepository
{
    /** @return list<CreditorLearned> everything one creditor learned — grouped by kind and field, oldest first */
    public function forProfile(int $profileId): array
    {
        if ($profileId <= 0) {
            return [];
        }

        return $this->em()->createQueryBuilder()->select('l')->from(CreditorLearned::class, 'l')
            ->where('IDENTITY(l.profile) = :id')->setParameter('id', $profileId)
            ->orderBy('l.kind', 'DESC')->addOrderBy('l.field', 'ASC')->addOrderBy('l.id', 'ASC')
            ->getQuery()->getResult();
    }

    /** @return array<string, CreditorLearned> field → the label one creditor learned for it */
    public function labelsOf(int $profileId): array
    {
        $out = [];
        foreach ($this->forProfile($profileId) as $row) {
            if ($row->isLabel()) {
                $out[$row->getField()] = $row;
            }
        }

        return $out;
    }

    /**
     * The ACTIVE creditors that learned any of $values of $kind — `[profile id, value]` pairs, so
     * the caller sees how many creditors hold one value (only a value one creditor holds counts).
     *
     * @param list<string> $values
     * @return list<array{0: int, 1: string}>
     */
    public function holders(string $kind, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn($v): bool => is_string($v) && $v !== '')));
        if ($values === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $rows = $this->em()->createQueryBuilder()->select('IDENTITY(l.profile) AS profile', 'l.value AS value')->from(CreditorLearned::class, 'l')
                ->join('l.profile', 'p')
                ->where('l.kind = :kind')->andWhere('l.value IN (:values)')->andWhere('p.active = true')
                ->setParameter('kind', $kind)->setParameter('values', $chunk)
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $out[] = [(int) $row['profile'], (string) $row['value']];
            }
        }

        return $out;
    }

    /** @return list<string> the values of $kind one creditor learned */
    public function valuesOf(CreditorProfile $profile, string $kind): array
    {
        return array_map(static fn(CreditorLearned $row): string => $row->getValue(), array_values(array_filter(
            $this->forProfile((int) $profile->getId()),
            static fn(CreditorLearned $row): bool => $row->getKind() === $kind
        )));
    }
}
