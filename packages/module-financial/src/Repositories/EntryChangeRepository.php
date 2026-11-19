<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Persistence\Doctrine\EntityManagerFactory;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see EntryChange}. The rows reference the entry
 * by plain columns (no association), so one entry's log is a criteria read;
 * the change log SCREEN (Finanzen › Änderungsprotokoll, owner 2026-10-08) is a
 * search in SQL over the whole log, paged.
 */
class EntryChangeRepository extends DoctrineRepository
{
    /**
     * The change log of one entry, oldest first.
     *
     * @return list<EntryChange>
     */
    public function forEntry(int $entryId): array
    {
        $rows = $this->findBy(['entryId' => $entryId]);
        usort($rows, static fn(EntryChange $a, EntryChange $b) => $a->getId() <=> $b->getId());

        return array_values($rows);
    }

    /**
     * One page of the change log screen: the rows that match every criterion
     * of $search, in its order. Every value is bound; the ORDER BY comes from
     * a fixed map, never from input. Doctrine-only (SQL, then a criteria read
     * of the page's ids).
     *
     * @return list<EntryChange>
     */
    public function search(EntryChangeSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'number' => "y.start_date {$dir}, c.entry_number {$dir}, c.id {$dir}",
            'text'   => self::snapshotText('before_snapshot') . " {$dir}, c.id {$dir}",
            'who'    => "c.changed_by {$dir}, c.id {$dir}",
            default  => "c.changed_at {$dir}, c.id {$dir}",
        };
        $ids = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT c.id FROM journal_entry_change c LEFT JOIN fiscal_year y ON y.id = c.fiscal_year_id{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $change) {
            $byId[$change->getId()] = $change;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /** How many rows match $search — the pager and the badge. Doctrine-only (SQL). */
    public function countSearch(EntryChangeSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM journal_entry_change c{$where}", $params, $types);
    }

    /**
     * The WHERE clause of {@see search()} / {@see countSearch()}: fragments
     * AND-ed, one bound value each. The day range compares the timestamp
     * half-open (`>= from 00:00`, `< the day after to`); the text is the
     * entry text before OR after the change, read from the JSON snapshots.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function searchWhere(EntryChangeSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $add = static function (string $sql, array $values, ParameterType $type = ParameterType::STRING) use (&$where, &$params, &$types): void {
            $where[] = $sql;
            foreach ($values as $value) {
                $params[] = $value;
                $types[]  = $type;
            }
        };
        if ($search->fiscalYearId !== null) {
            $add('c.fiscal_year_id = ?', [$search->fiscalYearId], ParameterType::INTEGER);
        }
        if ($search->from !== null) {
            $add('c.changed_at >= ?', [$search->from . ' 00:00:00']);
        }
        if ($search->to !== null) {
            $add('c.changed_at < ?', [(new \DateTimeImmutable($search->to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
        }
        if ($search->number !== null) {
            $add('c.entry_number = ?', [$search->number], ParameterType::INTEGER);
        }
        if ($search->who !== null) {
            $add("c.changed_by LIKE ? ESCAPE '!'", [self::contains($search->who)]);
        }
        if ($search->text !== null) {
            $add('(' . self::snapshotText('before_snapshot') . " LIKE ? ESCAPE '!'"
                . ' OR ' . self::snapshotText('after_snapshot') . " LIKE ? ESCAPE '!')",
                [self::contains($search->text), self::contains($search->text)]);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /**
     * The entry text inside a JSON snapshot column, in the installation's
     * collation. `JSON_UNQUOTE()` returns `utf8mb4_bin` on MariaDB 11.4 (MySQL
     * too), so without the COLLATE the text search turned case-sensitive
     * («bankspesen» missed «Bankspesen») and the text sort binary — 10.6 kept
     * the column's collation (FIN-CHANGELOG-001). $column is a literal of this
     * class, never input.
     */
    private static function snapshotText(string $column): string
    {
        return "JSON_UNQUOTE(JSON_EXTRACT(c.{$column}, '$.text')) COLLATE " . EntityManagerFactory::COLLATION;
    }

    /** «50%» → `%50!%%`: the LIKE pattern «contains», its wildcards escaped with `!`. */
    private static function contains(string $text): string
    {
        return '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
