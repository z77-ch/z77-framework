<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see DebtorProfile}. One Doctrine-only seam
 * (ADR-039 decision 8): {@see findForContacts()} reads the profiles of a
 * whole list page in ONE query with the contact fetch-joined — the debtor
 * list renders a row per contact and would otherwise issue one query per
 * contact.
 */
class DebtorProfileRepository extends DoctrineRepository
{
    /** The profile of one contact, or null — the uniqueness the index guarantees makes this a single row. */
    public function findByContact(Contact|int $contact): ?DebtorProfile
    {
        $id = $contact instanceof Contact ? $contact->getId() : $contact;
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->findForContacts([$id])[$id] ?? null;
    }

    /**
     * The profiles of several contacts in ONE query, contact fetch-joined,
     * keyed by contact id. Doctrine-only (fetch-join).
     *
     * @param list<Contact|int> $contacts
     * @return array<int, DebtorProfile> contact id → profile
     */
    public function findForContacts(array $contacts): array
    {
        $ids = [];
        foreach ($contacts as $contact) {
            $id = $contact instanceof Contact ? $contact->getId() : (int) $contact;
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var list<DebtorProfile> $profiles */
        $profiles = $this->em()->createQueryBuilder()
            ->select('p', 'c')
            ->from(DebtorProfile::class, 'p')
            ->join('p.contact', 'c')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byContact = [];
        foreach ($profiles as $profile) {
            $byContact[(int) $profile->getContact()->getId()] = $profile;
        }

        return $byContact;
    }

    /** How many profiles reference these payment terms — what the terms list shows before a deactivation. */
    public function countByPaymentTermsCode(string $code): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(DebtorProfile::class, 'p')
            ->where('p.paymentTermsCode = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
