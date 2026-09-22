<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
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
 *   - the journal REPORT (part 3) reads one page of a date range the same
 *     way, oldest first — ids by SQL (bound LIMIT/OFFSET), then the same
 *     fetch-join; the page is bounded, never a whole year.
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

    /** How many entries a year holds — for «n of m» on the journal screen. Doctrine-only (SQL). */
    public function countForYear(FiscalYear $year): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM journal_entry WHERE fiscal_year_id = ?', [(int) $year->getId()]);
    }

    /**
     * One page of the entries of a year dated from–to (both inclusive), in
     * the journal report's order — date, then number — with lines and
     * accounts loaded. Doctrine-only (SQL + DQL).
     *
     * @return list<JournalEntry>
     */
    public function chronological(FiscalYear $year, string $from, string $to, int $offset, int $limit): array
    {
        $ids = $this->connection()->fetchFirstColumn(
            'SELECT id FROM journal_entry WHERE fiscal_year_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, number LIMIT ? OFFSET ?',
            [(int) $year->getId(), $from, $to, max(1, $limit), max(0, $offset)],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
        );

        return $this->hydrate(array_map('intval', $ids));
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
