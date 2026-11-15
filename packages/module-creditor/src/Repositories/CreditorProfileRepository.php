<?php

namespace Z77\Module\Creditor\Repositories;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactKind;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see CreditorProfile}. Doctrine-only seams (ADR-039 decision 8): the
 * list search in SQL (thousands of contacts), the row lock of the optimistic write.
 */
class CreditorProfileRepository extends DoctrineRepository
{
    /** The profile of one contact, or null — the unique index makes this a single row. */
    public function findByContact(Contact|int $contact): ?CreditorProfile
    {
        $id = $contact instanceof Contact ? $contact->getId() : $contact;
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->findOneBy(['contact' => $id]);
    }

    /** The profile carrying this UID (canonical form), or null. */
    public function findByUid(string $uid): ?CreditorProfile
    {
        return $uid === '' ? null : $this->findOneBy(['uid' => $uid]);
    }

    /**
     * One page of the creditor list: the profiles matching every criterion of $search, contact
     * fetch-joined, in the SQL order. Doctrine-only (SQL + DQL).
     *
     * @return list<CreditorProfile>
     */
    public function search(CreditorSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = "IF(c.kind = '" . ContactKind::Organisation->value . "', c.company, c.last_name) {$dir}, c.last_name {$dir}, c.first_name {$dir}, p.id";
        $ids   = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT p.id FROM creditor_profile p JOIN contact c ON c.id = p.contact_id{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('p', 'c')->from(CreditorProfile::class, 'p')->join('p.contact', 'c')
                     ->where('p.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult() as $profile) {
            $byId[(int) $profile->getId()] = $profile;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /** How many profiles match $search — the list's pager and badge. SQL. */
    public function countSearch(CreditorSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM creditor_profile p JOIN contact c ON c.id = p.contact_id{$where}", $params, $types);
    }

    /**
     * Locks the profile row until the caller's commit and answers its version, or null when it is
     * gone. Inside an open unit of work only. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockVersion(int $id): ?int
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockVersion() needs an open unit of work — the row lock holds until commit');
        }
        $version = $this->connection()->fetchOne('SELECT version FROM creditor_profile WHERE id = ? FOR UPDATE', [$id], [ParameterType::INTEGER]);

        return $version === false ? null : (int) $version;
    }

    /** The profile REFRESHED from the database (`HINT_REFRESH`) — call it after {@see lockVersion()}. */
    public function refreshed(int $id): ?CreditorProfile
    {
        return $this->em()->createQueryBuilder()
            ->select('p')
            ->from(CreditorProfile::class, 'p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * The WHERE of {@see search()} / {@see countSearch()}: the criteria AND-ed, one bound value each;
     * `LIKE … ESCAPE '!'` with the wildcards escaped (holds under `NO_BACKSLASH_ESCAPES`, the
     * contact.md rule).
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function searchWhere(CreditorSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $like   = static fn(string $value): string => '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if ($search->name !== null && trim($search->name) !== '') {
            $where[] = "(c.company LIKE ? ESCAPE '!' OR c.last_name LIKE ? ESCAPE '!' OR c.first_name LIKE ? ESCAPE '!'"
                . " OR CONCAT(c.first_name, ' ', c.last_name) LIKE ? ESCAPE '!')";
            array_push($params, ...array_fill(0, 4, $like(trim($search->name))));
            array_push($types, ...array_fill(0, 4, ParameterType::STRING));
        }
        if ($search->uid !== null && trim($search->uid) !== '') {
            $where[]  = "p.uid LIKE ? ESCAPE '!'";
            $params[] = $like(mb_strtoupper(trim($search->uid)));
            $types[]  = ParameterType::STRING;
        }
        $iban = $search->iban === null ? '' : (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($search->iban));
        if ($iban !== '') {
            $where[]  = "EXISTS (SELECT 1 FROM creditor_iban i WHERE i.creditor_profile_id = p.id AND i.iban LIKE ? ESCAPE '!')";
            $params[] = $like($iban);
            $types[]  = ParameterType::STRING;
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }
}
