<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Reports\JournalSearch;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see JournalEntry}. Two Doctrine-only seams
 * (ADR-039 decision 8), each the documented deviation it is:
 *
 *   - the journal screen loads the latest n entries of a year WITH their
 *     lines and accounts in one query — SQL for the ids (ORDER BY + LIMIT,
 *     which `findBy()` cannot express), then a DQL fetch-join for those ids
 *     (`ContactRepository::search()` model: a LIMIT on a fetch-joined
 *     collection would cut lines, not entries);
 *   - the detail screen loads one entry the same way.
 *
 *   - the journal LIST searches (FIN-JOURNAL-CAPTURE-001): ids by SQL with
 *     every criterion bound and the order from a fixed map, then the same
 *     fetch-join — one page, over one year or all of them;
 *   - the journal REPORT (part 3) reads one page of a date range the same
 *     way, oldest first — ids by SQL (bound LIMIT/OFFSET), then the same
 *     fetch-join; the page is bounded, never a whole year — and its PDF reads the
 *     whole range — searched and sorted like the screen — in detached chunks of the
 *     same fetch-join (`searchInChunks()`).
 *
 * The other reports of part 3 (trial balance, balance sheet, income
 * statement, account statement) are SQL aggregates over `journal_line` in
 * {@see JournalLineRepository}.
 */
class JournalEntryRepository extends DoctrineRepository
{
    /** The entry an {@see EntryRef} names, or null — one query by year code and number. Doctrine-only (DQL). */
    public function findByRef(EntryRef $ref): ?JournalEntry
    {
        return $this->em()->createQueryBuilder()
            ->select('e')
            ->from(JournalEntry::class, 'e')
            ->join('e.fiscalYear', 'y')
            ->where('y.code = :code AND e.number = :number')
            ->setParameter('code', $ref->fiscalYear)
            ->setParameter('number', $ref->number)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** The reversal of $entry, or null when it was not reversed (at most one — unique in the schema). */
    public function findReversalOf(JournalEntry $entry): ?JournalEntry
    {
        return $this->findOneBy(['reversalOf' => $entry]);
    }

    /**
     * One entry with its lines and their accounts fetch-joined — the detail
     * screen. Doctrine-only (DQL).
     */
    public function withLines(int $id): ?JournalEntry
    {
        $result = $this->hydrate([$id]);

        return $result[0] ?? null;
    }

    /**
     * The entry for a WRITE inside an open unit of work: the header row is
     * locked (`SELECT … FOR UPDATE`) so a second editor or deleter of the
     * same entry waits and then reads the committed state — version bumped
     * or row gone — instead of racing the flush; the lines and accounts are
     * then fetch-joined onto the same managed object. Refused outside a unit
     * of work (a row lock holds until commit and would otherwise be
     * released before the write). Doctrine-only (ORM lock mode + DQL).
     *
     * @throws \LogicException outside an open transaction
     */
    public function lockForUpdate(int $id): ?JournalEntry
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockForUpdate() needs an open unit of work — the row lock holds until commit');
        }
        if ($this->em()->find(JournalEntry::class, $id, LockMode::PESSIMISTIC_WRITE) === null) {
            return null;
        }

        return $this->withLines($id);
    }

    /**
     * The latest $limit entries of a year (highest number first) with lines
     * and accounts loaded — the journal screen. Doctrine-only (SQL + DQL).
     *
     * @return list<JournalEntry>
     */
    public function latestForYear(FiscalYear $year, int $limit): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM journal_entry WHERE fiscal_year_id = ? ORDER BY number DESC LIMIT ' . max(1, $limit),
            [(int) $year->getId()]
        );

        return $this->hydrate(array_map('intval', $ids));
    }

    /**
     * The GENERATED entries of one origin in a fiscal year, with lines and accounts loaded, in
     * number order — the year opening's set of entries (one per account, origin `opening` /
     * the year's code, owner 2026-10-22). Ids by SQL, then the fetch-join: no lazy load per
     * entry when their snapshots are compared. Doctrine-only (SQL + DQL).
     *
     * @return list<JournalEntry>
     */
    public function generatedFrom(FiscalYear $year, string $sourceType, string $sourceRef): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT id FROM journal_entry WHERE fiscal_year_id = ? AND kind = 'generated' AND source_type = ? AND source_ref = ? ORDER BY number",
            [(int) $year->getId(), $sourceType, $sourceRef],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );

        return $this->hydrate(array_map('intval', $ids));
    }

    /**
     * The NUMBERS of the generated entries of one origin in a fiscal year, in order — the cheap
     * form of {@see generatedFrom()} for a status line (no entity loaded). Doctrine-only (SQL).
     *
     * @return list<int>
     */
    public function generatedNumbersFrom(FiscalYear $year, string $sourceType, string $sourceRef): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT number FROM journal_entry WHERE fiscal_year_id = ? AND kind = 'generated' AND source_type = ? AND source_ref = ? ORDER BY number",
            [(int) $year->getId(), $sourceType, $sourceRef],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        ));
    }

    /** How many entries a year holds — for «n of m» on the journal screen. Doctrine-only (SQL). */
    public function countForYear(FiscalYear $year): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE fiscal_year_id = ?', [(int) $year->getId()]);
    }

    /**
     * One page of the journal LIST (FIN-JOURNAL-CAPTURE-001) or the journal
     * REPORT (its search held to the range, `date` ascending): the entries that
     * match every criterion of $search, in its order, with lines and accounts
     * loaded. The search runs in the database over the whole journal (one
     * year, or all of them) — never a filter of the rows on screen. Every
     * value is bound; the ORDER BY comes from a fixed map, never from input.
     * Doctrine-only (SQL + DQL).
     *
     * @return list<JournalEntry>
     */
    public function search(JournalSearch $search, int $offset, int $limit): array
    {
        return $this->hydrate($this->searchIds($search, $limit, $offset));
    }

    /**
     * EVERY entry that matches $search, in its order — the journal report's PDF, which prints
     * what the screen shows (owner 2026-10-16: the search and the sort of the list). The ids come
     * in ONE statement (one sort; a few bytes per entry), the entities in chunks of $chunk through
     * the same fetch-join as a page; each chunk is DETACHED from the EntityManager once the
     * caller has moved past it, so the identity map holds one chunk of entries and lines, not
     * the year (review 2026-10-13; the accounts and fiscal years they name stay — a bounded
     * set). Read-only: a caller must not write through an entry after it moved on.
     * Doctrine-only (SQL + DQL).
     *
     * @return \Generator<int, JournalEntry>
     */
    public function searchInChunks(JournalSearch $search, int $chunk): \Generator
    {
        foreach (array_chunk($this->searchIds($search, null, 0), max(1, $chunk)) as $part) {
            $entries = $this->hydrate($part);
            yield from $entries;
            foreach ($entries as $entry) {
                foreach ($entry->getLines() as $line) {
                    $this->em()->detach($line);
                }
                if ($entry->getReversalOf() !== null) {
                    $this->em()->detach($entry->getReversalOf());
                }
                $this->em()->detach($entry);
            }
        }
    }

    /**
     * The ids of the entries that match $search, in its order — a page ($limit) or all of them
     * (null). The ORDER BY comes from a fixed map, never from input.
     *
     * @return list<int>
     */
    private function searchIds(JournalSearch $search, ?int $limit, int $offset): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'date'   => "e.entry_date {$dir}, y.start_date {$dir}, e.number {$dir}",
            'text'   => "e.text {$dir}, y.start_date {$dir}, e.number {$dir}",
            'amount' => "(SELECT SUM(ls.debit) FROM journal_line ls WHERE ls.entry_id = e.id) {$dir}, y.start_date {$dir}, e.number {$dir}",
            default  => "y.start_date {$dir}, e.number {$dir}",
        };
        $sql = "SELECT e.id FROM journal_entry e JOIN fiscal_year y ON y.id = e.fiscal_year_id{$where} ORDER BY {$order}";
        if ($limit !== null) {
            $sql    .= ' LIMIT ? OFFSET ?';
            $params  = array_merge($params, [max(1, $limit), max(0, $offset)]);
            $types   = array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER]);
        }

        return array_map('intval', $this->connection()->fetchFirstColumn($sql, $params, $types));
    }

    /** How many entries match $search — the list's pager and badge. Doctrine-only (SQL). */
    public function countSearch(JournalSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM journal_entry e{$where}", $params, $types);
    }

    /**
     * The WHERE clause of {@see search()} / {@see countSearch()}: fragments
     * AND-ed, one bound value each. The account criteria ask for a line of
     * THAT side (an entry with 6500 in credit is not found by «Soll 6500»);
     * the amount is the entry total, Σ debit — what the list shows.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function searchWhere(JournalSearch $search): array
    {
        $where = [];
        $params = [];
        $types = [];
        $add = static function (string $sql, mixed $value, ParameterType $type = ParameterType::STRING) use (&$where, &$params, &$types): void {
            $where[] = $sql;
            $params[] = $value;
            $types[] = $type;
        };
        if ($search->fiscalYearId !== null) {
            $add('e.fiscal_year_id = ?', $search->fiscalYearId, ParameterType::INTEGER);
        }
        if ($search->number !== null) {
            $add('e.number = ?', $search->number, ParameterType::INTEGER);
        }
        if ($search->dateFrom !== null) {
            $add('e.entry_date >= ?', $search->dateFrom);
        }
        if ($search->dateTo !== null) {
            $add('e.entry_date <= ?', $search->dateTo);
        }
        if ($search->text !== null) {
            $add("e.text LIKE ? ESCAPE '!'", '%' . strtr($search->text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        }
        foreach (['debit' => $search->debitAccount, 'credit' => $search->creditAccount] as $side => $number) {
            if ($number !== null) {
                $add("EXISTS (SELECT 1 FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = e.id AND a.number = ? AND l.{$side} > 0)", $number);
            }
        }
        if ($search->account !== null) {
            $add('EXISTS (SELECT 1 FROM journal_line l JOIN account a ON a.id = l.account_id WHERE l.entry_id = e.id AND a.number = ?)', $search->account);
        }
        if ($search->amount !== null) {
            $add('(SELECT SUM(lt.debit) FROM journal_line lt WHERE lt.entry_id = e.id) = ?', $search->amount);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /**
     * How many entries match $search and their Σ debit and Σ credit (each summed on its own) —
     * the journal report's pager and totals since it searches (owner 2026-10-15). Every entry has
     * at least two lines, so the join loses none. Doctrine-only (SQL).
     *
     * @return array{entries: int, debit: string, credit: string}
     */
    public function searchSummary(JournalSearch $search): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $row = $this->connection()->fetchAssociative(
            "SELECT COUNT(DISTINCT e.id) AS entries, COALESCE(SUM(jl.debit), 0) AS debit, COALESCE(SUM(jl.credit), 0) AS credit
               FROM journal_entry e JOIN journal_line jl ON jl.entry_id = e.id{$where}",
            $params,
            $types
        );

        return ['entries' => (int) $row['entries'], 'debit' => (string) $row['debit'], 'credit' => (string) $row['credit']];
    }

    /**
     * How many entries a year holds dated from–to, and their Σ debit and
     * Σ credit (each summed on its own — the report shows both, not one
     * twice) — the journal report's totals and pager. Every entry has at least two lines,
     * so the join loses none. Doctrine-only (SQL).
     *
     * @return array{entries: int, debit: string, credit: string}
     */
    public function rangeSummary(FiscalYear $year, string $from, string $to): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT COUNT(DISTINCT e.id) AS entries, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit
               FROM journal_entry e JOIN journal_line l ON l.entry_id = e.id
              WHERE e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?',
            [(int) $year->getId(), $from, $to]
        );

        return ['entries' => (int) $row['entries'], 'debit' => (string) $row['debit'], 'credit' => (string) $row['credit']];
    }

    /**
     * @param list<int> $ids
     * @return list<JournalEntry> in the order of $ids, lines in position order
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // The fiscal year rides along: the screens name it on every page, and
        // after a refused write the entity is read for display only.
        $entries = $this->em()->createQueryBuilder()
            ->select('e', 'y', 'l', 'a', 'r', 'ry')
            ->from(JournalEntry::class, 'e')
            ->join('e.fiscalYear', 'y')
            ->leftJoin('e.lines', 'l')
            ->leftJoin('l.account', 'a')
            ->leftJoin('e.reversalOf', 'r')
            ->leftJoin('r.fiscalYear', 'ry')   // «Storno von {year}/{number}» names the reversed entry's own year — no lazy load per reversal
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('e.number', 'DESC')
            ->addOrderBy('l.position', 'ASC')
            ->getQuery()
            ->getResult();

        // The caller's SQL decided the order (newest number first for the
        // list, date + number for the report) — keep it.
        $position = array_flip($ids);
        usort($entries, static fn(JournalEntry $a, JournalEntry $b) => $position[$a->getId()] <=> $position[$b->getId()]);

        return $entries;
    }
}
