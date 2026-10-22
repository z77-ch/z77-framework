<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactKind;
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

    /**
     * One page of the CUSTOMER PICKER (owner 2026-10-15): the parties a NEW document may be
     * written to — active contacts that are an ACTIVE debtor or no debtor yet (D9: the profile
     * opens with the first booking; a contact whose profile is deactivated is no choice) — that
     * match every criterion of $search, each with its profile or null. The search runs in the
     * database (thousands of contacts); every value is bound, the ORDER BY comes from a fixed
     * map. Doctrine-only (SQL + DQL).
     *
     * @return list<array{contact: Contact, profile: ?DebtorProfile}>
     */
    public function searchCustomers(CustomerSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->customerWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            // A customer without a number (no debtor yet) sorts after the numbered ones either way.
            'number' => "p.customer_number IS NULL, p.customer_number {$dir}, c.id",
            default  => "IF(c.kind = '" . ContactKind::Organisation->value . "', c.company, c.last_name) {$dir}, c.last_name {$dir}, c.first_name {$dir}, c.id",
        };
        $ids = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT c.id FROM contact c LEFT JOIN debtor_profile p ON p.contact_id = c.id{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));
        if ($ids === []) {
            return [];
        }
        $contacts = [];
        foreach ($this->em()->getRepository(Contact::class)->findBy(['id' => $ids]) as $contact) {
            $contacts[(int) $contact->getId()] = $contact;
        }
        $profiles = $this->findForContacts($ids);
        $rows     = [];
        foreach ($ids as $id) {   // the SQL order — findBy() answers in the database's
            if (isset($contacts[$id])) {
                $rows[] = ['contact' => $contacts[$id], 'profile' => $profiles[$id] ?? null];
            }
        }

        return $rows;
    }

    /** How many customers match $search — the picker's pager and badge. Doctrine-only (SQL). */
    public function countCustomers(CustomerSearch $search): int
    {
        [$where, $params, $types] = $this->customerWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM contact c LEFT JOIN debtor_profile p ON p.contact_id = c.id{$where}", $params, $types);
    }

    /**
     * The WHERE of {@see searchCustomers()} / {@see countCustomers()}: the choosable parties, then
     * the criteria AND-ed, one bound value each; `LIKE … ESCAPE '!'` with the wildcards escaped
     * (holds under `NO_BACKSLASH_ESCAPES`, contact.md rule).
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function customerWhere(CustomerSearch $search): array
    {
        $where  = ['c.active = 1', '(p.id IS NULL OR p.active = 1)'];
        $params = [];
        $types  = [];
        $like   = static fn(string $value): string => '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if ($search->name !== null) {
            $where[] = "(c.company LIKE ? ESCAPE '!' OR c.last_name LIKE ? ESCAPE '!' OR c.first_name LIKE ? ESCAPE '!'"
                . " OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? ESCAPE '!' OR CONCAT(c.last_name, ' ', c.first_name) LIKE ? ESCAPE '!')";
            array_push($params, ...array_fill(0, 5, $like($search->name)));
            array_push($types, ...array_fill(0, 5, ParameterType::STRING));
        }
        if ($search->city !== null) {
            $where[]  = "EXISTS (SELECT 1 FROM contact_address ca JOIN address a ON a.id = ca.address_id WHERE ca.contact_id = c.id AND a.city LIKE ? ESCAPE '!')";
            $params[] = $like($search->city);
            $types[]  = ParameterType::STRING;
        }
        if ($search->number !== null) {
            $where[]  = 'p.customer_number = ?';
            $params[] = $search->number;
            $types[]  = ParameterType::INTEGER;
        }
        if ($search->email !== null) {
            $where[]  = "c.email LIKE ? ESCAPE '!'";
            $params[] = $like($search->email);
            $types[]  = ParameterType::STRING;
        }

        return [' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /**
     * Locks the profile row of a contact until the caller's commit and answers its id, or
     * null when the contact has none (any more). The pair that keeps «Debitor off» and a new
     * document apart (owner 2026-10-12): `DebtorProfileService::delete()` takes it EXCLUSIVE
     * before it counts the documents, `InvoicingService::invoice()` SHARED before it inserts
     * a document for an existing profile — whichever comes second waits and then sees the
     * other's result (the document, or no profile). Inside an open unit of work only.
     * Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockForContact(int $contactId, bool $exclusive): ?int
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockForContact() needs an open unit of work — the row lock holds until commit');
        }
        $id = $this->connection()->fetchOne(
            'SELECT id FROM debtor_profile WHERE contact_id = ? ' . ($exclusive ? 'FOR UPDATE' : 'LOCK IN SHARE MODE'),
            [$contactId]
        );

        return $id === false ? null : (int) $id;
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

    /**
     * The distinct «Betreffname» defaults the debtors carry (owner 2026-10-21) — suggestions of the
     * document screens' `<datalist>`, at most $limit, alphabetical. Doctrine-only (DQL).
     *
     * @return list<string>
     */
    public function defaultSubjectLabels(int $limit): array
    {
        return array_map('strval', $this->em()->createQueryBuilder()
            ->select('DISTINCT p.defaultSubjectLabel')
            ->from(DebtorProfile::class, 'p')
            ->where('p.defaultSubjectLabel IS NOT NULL')
            ->orderBy('p.defaultSubjectLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
