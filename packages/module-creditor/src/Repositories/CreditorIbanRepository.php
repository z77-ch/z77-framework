<?php

namespace Z77\Module\Creditor\Repositories;

use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;
use Z77\Shared\Payment\Iban;

/**
 * Convention repository for {@see CreditorIban}: the IBANs of a creditor, of a list page, and the
 * creditors behind one IBAN — 0, 1 or n, because an IBAN may be shared (creditor plan §2.2).
 * Doctrine-only (DQL).
 */
class CreditorIbanRepository extends DoctrineRepository
{
    /** @return list<CreditorIban> the IBANs of one creditor, in the order they were added */
    public function forProfile(CreditorProfile $profile): array
    {
        if ($profile->getId() === null) {
            return [];
        }

        return $this->findBy(['profile' => $profile->getId()], ['id' => 'ASC']);
    }

    /**
     * The IBANs of several creditors in ONE query — a list page.
     *
     * @param list<int> $profileIds
     * @return array<int, list<CreditorIban>> profile id → its IBANs, in the order they were added
     */
    public function forProfiles(array $profileIds): array
    {
        $profileIds = array_values(array_filter(array_map('intval', $profileIds), static fn(int $id) => $id > 0));
        if ($profileIds === []) {
            return [];
        }
        $byProfile = [];
        foreach ($this->em()->createQueryBuilder()->select('i')->from(CreditorIban::class, 'i')
                     ->where('IDENTITY(i.profile) IN (:ids)')->setParameter('ids', $profileIds)
                     ->orderBy('i.id', 'ASC')->getQuery()->getResult() as $row) {
            $byProfile[(int) $row->getProfile()->getId()][] = $row;
        }

        return $byProfile;
    }

    /**
     * The creditors an IBAN is linked to — 0, 1 or n (a shared IBAN). The IBAN is normalised
     * first; $activeOnly leaves out deactivated links and deactivated creditors (what matching
     * may choose from). Ordered by profile id.
     *
     * @return list<CreditorProfile>
     */
    public function findProfilesByIban(string $iban, bool $activeOnly = true): array
    {
        $iban = Iban::normalize($iban);
        if ($iban === '') {
            return [];
        }
        $qb = $this->em()->createQueryBuilder()->select('i', 'p')->from(CreditorIban::class, 'i')->join('i.profile', 'p')
            ->where('i.iban = :iban')->setParameter('iban', $iban)->orderBy('p.id', 'ASC');
        if ($activeOnly) {
            $qb->andWhere('i.active = true')->andWhere('p.active = true');
        }

        return array_map(static fn(CreditorIban $row) => $row->getProfile(), $qb->getQuery()->getResult());
    }
}
