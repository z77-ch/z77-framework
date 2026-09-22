<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\PeriodState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see JournalLine} — the report SQL of P2 part 3
 * (plan §5.5, ADR-039 decision 8). Every method is an AGGREGATE or a bounded
 * read over `journal_line` on `connection()`, never a hydrated entity: the
 * largest installation's volume only works that way (plan §12/§13).
 * Doctrine-only, each method marked as such.
 *
 * What comes back are plain rows with amounts as the DECIMAL STRINGS MariaDB
 * returns (`SUM()` over `DECIMAL(15,2)` keeps two decimals and is exact); the
 * caller turns them into `Money` with `Money::fromDecimal()` (money.md), never
 * through float. Dates are bound as `Y-m-d` strings, both ends INCLUSIVE.
 * Every value is a bound parameter — no interpolation, LIMIT/OFFSET included.
 *
 * A report is a READ: a failing statement propagates, and nothing here
 * writes (DOCTRINE-TX-006 does not apply).
 *
 * Two existence checks serve the chart instead of a report (FIN-TYPE-001,
 * owner 2026-09-22): {@see accountHasLines()} and
 * {@see accountHasLinesInClosedPeriod()} — `AccountService` refuses a
 * type or postable change by them, the edit form disables the fields.
 */
class JournalLineRepository extends DoctrineRepository
{
    /**
     * Σ debit and Σ credit per account over the entries of one fiscal year
     * dated from–to — the one aggregate behind the trial balance, the balance
     * sheet and the income statement. Only accounts WITH lines in the range
     * come back (only postable accounts carry lines), in chart order (the
     * number as a string). Uses `idx_journal_entry_date`
     * (`fiscal_year_id, entry_date`) and the line's FK index on `entry_id`.
     * Doctrine-only (SQL).
     *
     * @return list<array{account_id: int, number: string, name: string, type: string, debit: string, credit: string}>
     */
    public function balancesByAccount(int $fiscalYearId, string $from, string $to): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT a.id AS account_id, a.number, a.name, a.type, SUM(l.debit) AS debit, SUM(l.credit) AS credit
               FROM journal_entry e
               JOIN journal_line l ON l.entry_id = e.id
               JOIN account a ON a.id = l.account_id
              WHERE e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?
              GROUP BY a.id, a.number, a.name, a.type
              ORDER BY a.number',
            [$fiscalYearId, $from, $to]
        );

        return array_map(static fn(array $r) => ['account_id' => (int) $r['account_id']] + $r, $rows);
    }

    /**
     * One account within one fiscal year: the OPENING (Σ debit − credit of
     * the lines dated before $from — inside the year only, plan §5.7: the
     * carry-forward is an entry of its own, P5), and Σ debit, Σ credit and
     * the line count dated from–to. One row, zeros when nothing matches.
     * Doctrine-only (SQL).
     *
     * @return array{opening: string, debit: string, credit: string, lines: int}
     */
    public function accountTotals(int $accountId, int $fiscalYearId, string $from, string $to): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT COALESCE(SUM(CASE WHEN e.entry_date < ? THEN l.debit - l.credit END), 0) AS opening,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.debit END), 0) AS debit,
                    COALESCE(SUM(CASE WHEN e.entry_date >= ? THEN l.credit END), 0) AS credit,
                    COUNT(CASE WHEN e.entry_date >= ? THEN 1 END) AS line_count
               FROM journal_line l
               JOIN journal_entry e ON e.id = l.entry_id
              WHERE l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date <= ?',
            [$from, $from, $from, $from, $accountId, $fiscalYearId, $to]
        );

        return [
            'opening' => (string) $row['opening'],
            'debit'   => (string) $row['debit'],
            'credit'  => (string) $row['credit'],
            'lines'   => (int) $row['line_count'],
        ];
    }

    /**
     * One PAGE of an account's lines dated from–to, in the account
     * statement's order (date, entry number, position), each with its
     * RUNNING Σ (debit − credit) from the first line of the range. The
     * window function runs over the whole range BEFORE LIMIT/OFFSET cut the
     * page, so the running figure of a line on page 7 counts pages 1–6 as
     * well — correct under pagination without reading them (MariaDB ≥ 10.2;
     * the installations run 10.6). Doctrine-only (SQL, window function).
     *
     * @return list<array{line_id: int, entry_id: int, number: int, entry_date: string, entry_text: string, line_text: ?string, debit: string, credit: string, running: string}>
     */
    public function accountLines(int $accountId, int $fiscalYearId, string $from, string $to, int $offset, int $limit): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.id AS line_id, e.id AS entry_id, e.number, e.entry_date, e.text AS entry_text, l.text AS line_text,
                    l.debit, l.credit,
                    SUM(l.debit - l.credit) OVER (
                        ORDER BY e.entry_date, e.number, l.position, l.id
                        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                    ) AS running
               FROM journal_line l
               JOIN journal_entry e ON e.id = l.entry_id
              WHERE l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?
              ORDER BY e.entry_date, e.number, l.position, l.id
              LIMIT ? OFFSET ?',
            [$accountId, $fiscalYearId, $from, $to, max(1, $limit), max(0, $offset)],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
        );

        return array_map(static fn(array $r) => [
            'line_id'    => (int) $r['line_id'],
            'entry_id'   => (int) $r['entry_id'],
            'number'     => (int) $r['number'],
        ] + $r, $rows);
    }

    /**
     * The OTHER accounts of the given entries — the account statement's
     * counter-account column: per entry the number of distinct other
     * accounts and, when there is exactly one, its number and name.
     * Bounded by the page's entries. Doctrine-only (SQL).
     *
     * @param list<int> $entryIds
     * @return array<int, array{count: int, number: string, name: string}> entry id → counter accounts
     */
    public function counterAccounts(array $entryIds, int $accountId): array
    {
        if ($entryIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.entry_id, COUNT(DISTINCT l.account_id) AS n, MIN(a.number) AS number, MIN(a.name) AS name
               FROM journal_line l
               JOIN account a ON a.id = l.account_id
              WHERE l.entry_id IN (?) AND l.account_id <> ?
              GROUP BY l.entry_id',
            [array_values(array_unique($entryIds)), $accountId],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER]
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['entry_id']] = ['count' => (int) $r['n'], 'number' => (string) $r['number'], 'name' => (string) $r['name']];
        }

        return $result;
    }

    /**
     * Whether ANY journal line references the account, in any period state —
     * such an account must not become a group (FIN-TYPE-001). One index
     * probe on the FK index of `account_id`, `LIMIT 1`, cheap at any volume.
     * Doctrine-only (SQL).
     */
    public function accountHasLines(int $accountId): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM journal_line WHERE account_id = ? LIMIT 1', [$accountId]) !== false;
    }

    /**
     * Whether a journal line of the account lies in a CLOSED period — then
     * its type is locked (FIN-TYPE-001: the reports read the type at
     * runtime, and a change would move closed years between the balance
     * sheet and the income statement). The period is not stored on the
     * entry; it is the period of the entry's year whose dates contain the
     * entry date (`FiscalYear::periodOn()` in SQL). `vat-settled` does not
     * count.
     *
     * Two steps, for the cost at volume (measured ad hoc at 200'000 lines):
     * first the closed periods themselves (a few dozen rows at most), merged
     * into one date range per run of adjacent closed periods of a year —
     * none closed (every installation before its first close) answers
     * without touching the journal. Then per range, NEWEST first, the
     * account-statement shape (`l.account_id = ? AND e.fiscal_year_id = ?
     * AND e.entry_date BETWEEN`) with `LIMIT 1` — the latest closed year is
     * where an account in use has lines, so the common case stops at the
     * first range. A range that holds none of a busy account's lines costs
     * ≈ 0.5 s per closed 100'000-entry year (the optimizer walks the entry
     * side), so a miss grows with the number of closed years; one join over
     * all closed periods at once was worse (it walked every line of the
     * account). The structural fix is the `type_locked` marker of P5
     * (financial.md, pending). Doctrine-only (SQL).
     */
    public function accountHasLinesInClosedPeriod(int $accountId): bool
    {
        $periods = $this->connection()->fetchAllAssociative(
            'SELECT fiscal_year_id, start_date, end_date FROM fiscal_period WHERE state = ? ORDER BY start_date',
            [PeriodState::Closed->value]
        );
        $ranges = [];
        foreach ($periods as $p) {
            $last = array_key_last($ranges);
            $next = $last === null ? null : (new \DateTimeImmutable($ranges[$last][2]))->modify('+1 day')->format('Y-m-d');
            if ($last !== null && $ranges[$last][0] === (int) $p['fiscal_year_id'] && $next === $p['start_date']) {
                $ranges[$last][2] = $p['end_date'];
                continue;
            }
            $ranges[] = [(int) $p['fiscal_year_id'], $p['start_date'], $p['end_date']];
        }
        foreach (array_reverse($ranges) as [$yearId, $from, $to]) {   // newest first
            $hit = $this->connection()->fetchOne(
                'SELECT 1
                   FROM journal_line l
                   JOIN journal_entry e ON e.id = l.entry_id
                  WHERE l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?
                  LIMIT 1',
                [$accountId, $yearId, $from, $to]
            );
            if ($hit !== false) {
                return true;
            }
        }

        return false;
    }
}
