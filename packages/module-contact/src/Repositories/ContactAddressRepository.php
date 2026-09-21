<?php

namespace Z77\Module\Contact\Repositories;

use Z77\Module\Contact\Entities\Address;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see ContactAddress}. Three Doctrine-only
 * seams (ADR-039 decision 8), each documented as the deviation it is:
 *
 *   - the two list reads are DQL with a FETCH-JOIN on the address — one
 *     query per screen instead of a lazy proxy per link (the list renders
 *     every link's address; 200 contacts would otherwise be ~400 queries);
 *   - the counts are SQL on the driver's connection: they decide whether an
 *     address row is still referenced and whether a type is in use, and
 *     hydrating every link to count it would be the wrong tool.
 */
class ContactAddressRepository extends DoctrineRepository
{
    /** @return list<ContactAddress> the contact's links in creation order, addresses loaded. Doctrine-only (fetch-join). */
    public function findByContact(Contact $contact): array
    {
        return $this->findForContacts([$contact])[(int) $contact->getId()] ?? [];
    }

    /**
     * The links of several contacts in ONE query, addresses fetch-joined,
     * grouped by contact id — for the list screen. Doctrine-only.
     *
     * @param list<Contact> $contacts
     * @return array<int, list<ContactAddress>> contact id → links in creation order
     */
    public function findForContacts(array $contacts): array
    {
        if ($contacts === []) {
            return [];
        }
        /** @var list<ContactAddress> $links */
        $links = $this->em()->createQueryBuilder()
            ->select('l', 'a')
            ->from(ContactAddress::class, 'l')
            ->join('l.address', 'a')
            ->where('l.contact IN (:contacts)')
            ->orderBy('l.id', 'ASC')
            ->setParameter('contacts', array_values($contacts))
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($links as $link) {
            $grouped[(int) $link->getContact()->getId()][] = $link;
        }

        return $grouped;
    }

    /** How many links point at this address row — 1 means «only the one being removed». Doctrine-only. */
    public function countByAddress(Address $address): int
    {
        if ($address->getId() === null) {
            return 0;
        }

        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM contact_address WHERE address_id = ?',
            [$address->getId()]
        );
    }

    /** How many links carry this type code — shown on the type list so a deactivation is an informed one. Doctrine-only. */
    public function countByTypeCode(string $typeCode): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM contact_address WHERE type_code = ?',
            [$typeCode]
        );
    }
}
