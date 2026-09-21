<?php

namespace Z77\Module\Contact\Repositories;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactKind;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see Contact} (`…\Entities\Contact` →
 * `…\Repositories\ContactRepository`), extending the Doctrine base
 * (ADR-039 decision 6).
 *
 * The list screen's search is SQL on the driver's DBAL connection — a
 * documented Doctrine-only deviation from `RepositoryInterface` (decision 8):
 * `findBy()` cannot express LIKE, ORDER BY and LIMIT, and a contact table
 * grows past what hydrating everything would bear. The SQL selects ids only;
 * the entities come through `findBy(['id' => …])`, so hydration and the
 * Identity Map stay Doctrine's.
 */
class ContactRepository extends DoctrineRepository
{
    /** The LIKE escape character — explicit, so the query is safe under `NO_BACKSLASH_ESCAPES` as well. */
    private const LIKE_ESCAPE = '!';

    /**
     * Contacts whose company, name or e-mail contains $query (any order of
     * first and last name), sorted by the name the list shows (the company
     * of an organisation, the last name of a person) — or everything, sorted
     * the same way, when $query is empty. At most $limit rows. Doctrine-only
     * (decision 8). No index serves this ORDER BY (an expression) — the
     * limit keeps it cheap.
     *
     * @return list<Contact>
     */
    public function search(string $query, int $limit): array
    {
        [$where, $params] = $this->whereFor($query);
        $organisation = ContactKind::Organisation->value;
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT id FROM contact {$where} ORDER BY IF(kind = '{$organisation}', company, last_name), last_name, first_name, id LIMIT " . max(1, $limit),
            $params
        );
        if ($ids === []) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $contact) {
            $byId[$contact->getId()] = $contact;
        }

        // Back into the SQL order — findBy() returns rows in the database's order.
        return array_values(array_filter(array_map(static fn($id) => $byId[(int) $id] ?? null, $ids)));
    }

    /** How many contacts match $query (all, when empty) — for «n of m» on the list. Doctrine-only. */
    public function countMatching(string $query): int
    {
        [$where, $params] = $this->whereFor($query);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM contact {$where}", $params);
    }

    /** @return array{0: string, 1: list<string>} WHERE clause (or '') and its parameters */
    private function whereFor(string $query): array
    {
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? '');
        if ($query === '') {
            return ['', []];
        }
        // `!`, `%` and `_` in the query are literal characters, not wildcards.
        $escaped = str_replace([self::LIKE_ESCAPE, '%', '_'], [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'], $query);
        $like    = '%' . $escaped . '%';
        $match   = "LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";

        return [
            "WHERE company {$match} OR last_name {$match} OR first_name {$match} OR email {$match} "
            . "OR CONCAT(first_name, ' ', last_name) {$match} OR CONCAT(last_name, ' ', first_name) {$match}",
            [$like, $like, $like, $like, $like, $like],
        ];
    }
}
