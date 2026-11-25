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
 * read over `journal_line` on `connection()` — or, for the account statement
 * PDF, a SQL stream of rows ({@see streamAccountLines()}) — never a hydrated
 * entity: the largest installation's volume only works that way (plan §12/§13).
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
     * The account statement's lines of one account dated from–to, in its
     * order (date, entry number, position), each with its RUNNING Σ
     * (debit − credit) from the first line of the range. The window function
     * runs over the whole range BEFORE LIMIT/OFFSET cut a page, so the running
     * figure of a line on page 7 counts pages 1–6 as well — correct under
     * pagination without reading them (MariaDB ≥ 10.2; the installations run
     * 10.6).
     */
    private const ACCOUNT_LINES_SQL =
        'SELECT l.id AS line_id, e.id AS entry_id, e.number, e.entry_date, e.text AS entry_text, l.text AS line_text,
                l.debit, l.credit,
                SUM(l.debit - l.credit) OVER (
                    ORDER BY e.entry_date, e.number, l.position, l.id
                    ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                ) AS running
           FROM journal_line l
           JOIN journal_entry e ON e.id = l.entry_id
          WHERE l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ?
          ORDER BY e.entry_date, e.number, l.position, l.id';

    /**
     * One PAGE of an account's lines ({@see ACCOUNT_LINES_SQL}) — the screen.
     * Doctrine-only (SQL, window function).
     *
     * @return list<array{line_id: int, entry_id: int, number: int, entry_date: string, entry_text: string, line_text: ?string, debit: string, credit: string, running: string}>
     */
    public function accountLines(int $accountId, int $fiscalYearId, string $from, string $to, int $offset, int $limit): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            self::ACCOUNT_LINES_SQL . ' LIMIT ? OFFSET ?',
            [$accountId, $fiscalYearId, $from, $to, max(1, $limit), max(0, $offset)],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER]
        );

        return array_map(self::accountLineRow(...), $rows);
    }

    /**
     * EVERY line of the range ({@see ACCOUNT_LINES_SQL}), one row at a time — the PDF
     * (review 2026-10-13 §2: the PDF hydrated everything). ONE statement, so the window
     * function sorts the range once (a page-wise read would sort it again per page —
     * ≈ 1.6 s per page at 90'000 lines, FIN-REPORT-005); the rows are a SQL stream —
     * the driver holds the raw result (PDO buffers it, a few dozen bytes per line), PHP
     * builds one row array at a time. Buffered, so other statements may run while the
     * caller iterates (the counter accounts per chunk). Doctrine-only (SQL, window function).
     *
     * @return \Generator<int, array{line_id: int, entry_id: int, number: int, entry_date: string, entry_text: string, line_text: ?string, debit: string, credit: string, running: string}>
     */
    public function streamAccountLines(int $accountId, int $fiscalYearId, string $from, string $to): \Generator
    {
        $result = $this->connection()->executeQuery(
            self::ACCOUNT_LINES_SQL,
            [$accountId, $fiscalYearId, $from, $to],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
        foreach ($result->iterateAssociative() as $row) {
            yield self::accountLineRow($row);
        }
    }

    private static function accountLineRow(array $r): array
    {
        return [
            'line_id'  => (int) $r['line_id'],
            'entry_id' => (int) $r['entry_id'],
            'number'   => (int) $r['number'],
        ] + $r;
    }

    /** The fixed condition of {@see manualLinesOnAccount()} — every value a placeholder, nothing from input. */
    private const MANUAL_LINES_WHERE = "l.account_id = ? AND e.fiscal_year_id = ? AND e.entry_date BETWEEN ? AND ? AND e.kind = 'manual'";

    /**
     * The MANUAL entries with a line on one account in a fiscal year dated from–to — what the
     * year-close reconciliation names when a collective account and its subledger disagree
     * (`SubledgerReconciliationCheck`, owner 2026-10-13): how many, the first $limit entry
     * numbers, and their net Σ (debit − credit) on the account. Doctrine-only (SQL).
     *
     * @return array{count: int, numbers: list<int>, net: string}
     */
    public function manualLinesOnAccount(int $accountId, int $fiscalYearId, string $from, string $to, int $limit): array
    {
        $total = $this->connection()->fetchAssociative(
            "SELECT COUNT(DISTINCT e.id) AS entries, COALESCE(SUM(l.debit - l.credit), 0) AS net
               FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE " . self::MANUAL_LINES_WHERE,
            [$accountId, $fiscalYearId, $from, $to],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
        $numbers = $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT e.number FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE " . self::MANUAL_LINES_WHERE . ' ORDER BY e.number LIMIT ?',
            [$accountId, $fiscalYearId, $from, $to, max(1, $limit)],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );

        return ['count' => (int) $total['entries'], 'numbers' => array_map('intval', $numbers), 'net' => (string) $total['net']];
    }

    /** The fixed condition of {@see entriesOnAccountNotFrom()} — every value a placeholder. */
    private const ACCOUNT_NOT_FROM_WHERE = 'l.account_id = ? AND e.fiscal_year_id = ? AND (e.source_type IS NULL OR e.source_type <> ? OR e.source_ref IS NULL OR e.source_ref <> ?)';

    /**
     * The entries of a fiscal year with a line on one account that do NOT come from the origin
     * $sourceType / $sourceRef — what the year opening asks of its contra account (P5, owner
     * 2026-10-22): an opening the generator did not make (an imported «EB:» entry, a manual
     * one) refuses a generated one; the generated one is a SET of entries of origin
     * `opening` / the year's code (one per account). How many, and the first $limit entry
     * numbers in order. Doctrine-only (SQL).
     *
     * @return array{count: int, numbers: list<int>}
     */
    public function entriesOnAccountNotFrom(int $accountId, int $fiscalYearId, string $sourceType, string $sourceRef, int $limit): array
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(DISTINCT e.id) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE ' . self::ACCOUNT_NOT_FROM_WHERE,
            [$accountId, $fiscalYearId, $sourceType, $sourceRef],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
        $numbers = $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT e.number FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id WHERE ' . self::ACCOUNT_NOT_FROM_WHERE . ' ORDER BY e.number LIMIT ?',
            [$accountId, $fiscalYearId, $sourceType, $sourceRef, max(1, $limit)],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );

        return ['count' => (int) $count, 'numbers' => array_map('intval', $numbers)];
    }

    /**
     * EVERY line of the given entries with its account — what the account statement needs to
     * name the counter account of each of its lines, and to split a line into one row per
     * counter line when the pairing is unambiguous (`LedgerReports::statementLines()`, owner
     * 2026-10-22). ONE query for a page (or a PDF chunk) of statement rows, bounded by their
     * entries; in entry, position order. Doctrine-only (SQL).
     *
     * @param list<int> $entryIds
     * @return array<int, list<array{line_id: int, account_id: int, number: string, name: string, debit: string, credit: string}>> entry id → its lines
     */
    public function linesOfEntries(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.entry_id, l.id AS line_id, l.account_id, a.number, a.name, l.debit, l.credit
               FROM journal_line l
               JOIN account a ON a.id = l.account_id
              WHERE l.entry_id IN (?)
              ORDER BY l.entry_id, l.position, l.id',
            [array_values(array_unique($entryIds))],
            [ArrayParameterType::INTEGER]
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['entry_id']][] = [
                'line_id'    => (int) $r['line_id'],
                'account_id' => (int) $r['account_id'],
                'number'     => (string) $r['number'],
                'name'       => (string) $r['name'],
                'debit'      => (string) $r['debit'],
                'credit'     => (string) $r['credit'],
            ];
        }

        return $result;
    }

    // ── the check of a bank account (ADR-061 decisions 22–25, stage C4) ───

    /**
     * Entry `e` is NO year opening: not generated by the year opening (`source_type` `opening`)
     * and without a line on the opening contra account (an imported or hand-made opening posts
     * against it too). The check of a bank account compares CHANGES — the carried balance is no
     * change. One `?`: the id of the opening contra account, 0 when none is set.
     */
    private const NOT_OPENING = "(e.source_type IS NULL OR e.source_type <> 'opening') AND NOT EXISTS (SELECT 1 FROM journal_line ol WHERE ol.entry_id = e.id AND ol.account_id = ?)";

    /**
     * Σ (debit − credit) of the account in fiscal year $fiscalYearId at the OPENING of $day
     * (`Y-m-d`): its lines dated before that day plus the year opening, wherever it is dated.
     * Doctrine-only (SQL).
     */
    public function balanceAtOpeningOf(int $accountId, int $fiscalYearId, string $day, int $openingAccountId): string
    {
        return (string) $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM journal_line l JOIN journal_entry e ON e.id = l.entry_id
              WHERE l.account_id = ? AND e.fiscal_year_id = ? AND (e.entry_date < ? OR NOT (' . self::NOT_OPENING . '))',
            [$accountId, $fiscalYearId, $day, $openingAccountId],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER]
        );
    }

    /**
     * The entries with a line on the account dated from $from to $to (`Y-m-d`, inclusive), the year
     * opening left out — each with its NET on the account (debit − credit), in date / number order.
     * Their sum is the account's change in the range. Doctrine-only (SQL).
     *
     * @return list<array{id: int, year: string, number: int, date: string, text: string, sourceType: string, sourceRef: string, key: string, reversalOf: int, amount: string}>
     */
    public function changesOnAccount(int $accountId, string $from, string $to, int $openingAccountId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.id, y.code, e.number, e.entry_date, e.text, e.source_type, e.source_ref, e.idempotency_key, e.reversal_of_id, SUM(l.debit - l.credit) AS amount
               FROM journal_line l
               JOIN journal_entry e ON e.id = l.entry_id
               JOIN fiscal_year y ON y.id = e.fiscal_year_id
              WHERE l.account_id = ? AND e.entry_date BETWEEN ? AND ? AND ' . self::NOT_OPENING . '
              GROUP BY e.id, y.code, e.number, e.entry_date, e.text, e.source_type, e.source_ref, e.idempotency_key, e.reversal_of_id
              ORDER BY e.entry_date, e.number, e.id',
            [$accountId, $from, $to, $openingAccountId],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]
        );

        return array_map(static fn(array $r): array => [
            'id'         => (int) $r['id'],
            'year'       => (string) $r['code'],
            'number'     => (int) $r['number'],
            'date'       => (string) $r['entry_date'],
            'text'       => (string) $r['text'],
            'sourceType' => (string) $r['source_type'],
            'sourceRef'  => (string) $r['source_ref'],
            'key'        => (string) $r['idempotency_key'],
            'reversalOf' => (int) $r['reversal_of_id'],
            'amount'     => (string) $r['amount'],
        ], $rows);
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
     * entry date (`FiscalYear::periodOn()` in SQL). A settled VAT period does
     * not count — it is no period state.
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
        // Inside a unit of work (the guarded type change of `AccountService::update()`, under the
        // account's row lock) the periods are read SHARE-LOCKED: `state` has no index, so InnoDB locks
        // every period row it scans (a few dozen) — a year close (`lockPeriodsOf()`, exclusive) and
        // this re-check serialise, and a close committing in between cannot slip past the type lock
        // (P5 part 1). Lock order: account → years → periods here; a close takes no account lock. Lock-free
        // outside a unit of work (the screen's `postingLocks()`).
        if ($this->connection()->isTransactionActive()) {
            // The years first (review 2026-09-30): a close holds them X before it locks the
            // periods X — the same order here, so a type change and a close cannot deadlock.
            $this->connection()->fetchFirstColumn('SELECT id FROM fiscal_year LOCK IN SHARE MODE');
        }
        $periods = $this->connection()->fetchAllAssociative(
            $this->connection()->isTransactionActive()
                ? 'SELECT fiscal_year_id, start_date, end_date FROM fiscal_period WHERE state = ? ORDER BY start_date LOCK IN SHARE MODE'
                : 'SELECT fiscal_year_id, start_date, end_date FROM fiscal_period WHERE state = ? ORDER BY start_date',
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

    // ── the VAT return (P5 part 2, stage S1 — read-only) ──────────────────

    /**
     * The entries of a settlement period: dated from–to in the fiscal years the period touches. The
     * year ids make the index `idx_journal_entry_date` (`fiscal_year_id, entry_date`) a range per
     * year — a period may span two fiscal years (a yearly settlement with a business year from
     * 1 July). Three `?`: the year ids (array), from, to.
     */
    private const VAT_PERIOD_WHERE = 'e.fiscal_year_id IN (?) AND e.entry_date BETWEEN ? AND ?';

    /**
     * 1 when entry `e` belongs to a REVERSAL PAIR as of the period's end: it IS a reversal, or a
     * reversal of it is dated on or before the period's end (the unique index on `reversal_of_id`
     * makes the probe one index lookup). What the line is classified by stays the service's
     * (`VatReturnSources::rowOrigin()`); SQL only reads the marker. One `?`: the period's end.
     */
    /**
     * An entry of the ORDINARY return: not a correction entry (ADR-042 addendum 2 of 2026-11-25,
     * point 1 — a «Korrekturbuchung zu Periode X» is left out of the form of the period it is dated
     * in and carried as its block). No `?`.
     */
    private const VAT_ORDINARY_ENTRY = 'e.vat_period_from IS NULL';

    private const VAT_REVERSAL_FLAG = 'CASE WHEN e.reversal_of_id IS NOT NULL OR EXISTS (SELECT 1 FROM journal_entry r WHERE r.reversal_of_id = e.id AND r.entry_date <= ?) THEN 1 ELSE 0 END';

    /**
     * The coded lines of a settlement period summed per tax code, rate, source type, reversal flag
     * and account — the ONE aggregate the VAT return is computed from (`VatReturn\VatReturnService`):
     * the calculator's rows and the tree of «Herkunft» are both sums of these groups. Only lines
     * WITH a tax code (on the NET line, `financial.md` part 2: exactly one line per taxed amount
     * carries them), and only of ORDINARY entries — a correction entry is the block's
     * ({@see vatReturnCorrectionAggregate()}); never hydrated. Doctrine-only (SQL).
     *
     * @param list<int> $yearIds
     * @return list<array{tax_code: string, tax_rate: int, source_type: ?string, reversal: bool, account_id: int, number: string, name: string, type: string, lines: int, base: string, tax: string}>
     */
    public function vatReturnAggregate(array $yearIds, string $from, string $to): array
    {
        if ($yearIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.tax_code, l.tax_rate, e.source_type, ' . self::VAT_REVERSAL_FLAG . ' AS reversal,
                    l.account_id, a.number, a.name, a.type,
                    COUNT(*) AS line_count, SUM(l.tax_base) AS base, SUM(l.tax_amount) AS tax
               FROM journal_entry e
               JOIN journal_line l ON l.entry_id = e.id
               JOIN account a ON a.id = l.account_id
              WHERE ' . self::VAT_PERIOD_WHERE . ' AND l.tax_code IS NOT NULL AND ' . self::VAT_ORDINARY_ENTRY . '
              GROUP BY l.tax_code, l.tax_rate, e.source_type, reversal, l.account_id, a.number, a.name, a.type
              ORDER BY l.tax_code, l.tax_rate, a.number',
            [$to, array_values($yearIds), $from, $to],
            [ParameterType::STRING, ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );

        return array_map(static fn(array $r): array => [
            'tax_code'    => (string) $r['tax_code'],
            'tax_rate'    => (int) $r['tax_rate'],
            'source_type' => $r['source_type'] === null ? null : (string) $r['source_type'],
            'reversal'    => (int) $r['reversal'] === 1,
            'account_id'  => (int) $r['account_id'],
            'number'      => (string) $r['number'],
            'name'        => (string) $r['name'],
            'type'        => (string) $r['type'],
            'lines'       => (int) $r['line_count'],
            'base'        => (string) $r['base'],
            'tax'         => (string) $r['tax'],
        ], $rows);
    }

    /**
     * How many ORDINARY ENTRIES of the period carry a coded line — the measure of the rounding finding
     * (ADR-041 addendum 2026-11-24, point 13: half a Rappen per coded document); correction entries
     * are not in the form it measures. Doctrine-only (SQL).
     *
     * @param list<int> $yearIds
     */
    public function vatReturnCodedEntries(array $yearIds, string $from, string $to): int
    {
        if ($yearIds === []) {
            return 0;
        }

        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(DISTINCT e.id) FROM journal_entry e JOIN journal_line l ON l.entry_id = e.id
              WHERE ' . self::VAT_PERIOD_WHERE . ' AND l.tax_code IS NOT NULL AND ' . self::VAT_ORDINARY_ENTRY,
            [array_values($yearIds), $from, $to],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
    }

    /**
     * The MOVEMENTS of the period per account and tax code (null = the uncoded lines) — what the
     * reconciliation of the VAT return compares and its warnings name (ADR-041 addendum 2026-11-24,
     * point 6: movements, never balances). Left out: the entries of the source types in
     * $excludedSourceTypes (the return's own settlement entries, the year opening) and every entry
     * with a line on the opening contra account ($openingAccountId, 0 = none — an imported or
     * hand-made opening). Per group: lines, Σ debit, Σ credit, Σ tax base, Σ posted tax, and the
     * count of its CREDIT lines (a fixed-asset account's uncoded credits are a possible sale). The
     * lines of a CORRECTION ENTRY form groups of their own, one set per entry (`correction_entry` =
     * the entry's id, null for an ordinary entry): the turnover reconciliation names them as one
     * item per entry, the VAT accounts count them with the block. Doctrine-only (SQL).
     *
     * @param list<int>    $yearIds
     * @param list<string> $excludedSourceTypes
     * @return list<array{account_id: int, number: string, name: string, type: string, tax_code: ?string, correction_entry: ?int, lines: int, credit_lines: int, debit: string, credit: string, base: string, tax: string}>
     */
    public function vatReturnMovements(array $yearIds, string $from, string $to, array $excludedSourceTypes, int $openingAccountId): array
    {
        if ($yearIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.account_id, a.number, a.name, a.type, l.tax_code,
                    CASE WHEN ' . self::VAT_ORDINARY_ENTRY . ' THEN 0 ELSE e.id END AS correction_entry,
                    COUNT(*) AS line_count, COUNT(CASE WHEN l.credit > 0 THEN 1 END) AS credit_lines,
                    SUM(l.debit) AS debit, SUM(l.credit) AS credit,
                    COALESCE(SUM(l.tax_base), 0) AS base, COALESCE(SUM(l.tax_amount), 0) AS tax
               FROM journal_entry e
               JOIN journal_line l ON l.entry_id = e.id
               JOIN account a ON a.id = l.account_id
              WHERE ' . self::VAT_PERIOD_WHERE . '
                AND (e.source_type IS NULL OR e.source_type NOT IN (?))
                AND NOT EXISTS (SELECT 1 FROM journal_line ol WHERE ol.entry_id = e.id AND ol.account_id = ?)
              GROUP BY l.account_id, a.number, a.name, a.type, l.tax_code, correction_entry
              ORDER BY a.number, l.tax_code, correction_entry',
            [array_values($yearIds), $from, $to, $excludedSourceTypes === [] ? [''] : array_values($excludedSourceTypes), $openingAccountId],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING, ParameterType::INTEGER]
        );

        return array_map(static fn(array $r): array => [
            'account_id'   => (int) $r['account_id'],
            'number'       => (string) $r['number'],
            'name'         => (string) $r['name'],
            'type'         => (string) $r['type'],
            'tax_code'     => $r['tax_code'] === null ? null : (string) $r['tax_code'],
            'correction_entry' => (int) $r['correction_entry'] === 0 ? null : (int) $r['correction_entry'],
            'lines'        => (int) $r['line_count'],
            'credit_lines' => (int) $r['credit_lines'],
            'debit'        => (string) $r['debit'],
            'credit'       => (string) $r['credit'],
            'base'         => (string) $r['base'],
            'tax'          => (string) $r['tax'],
        ], $rows);
    }

    /**
     * The coded lines of the CORRECTION ENTRIES dated in a settlement period (ADR-042 addendum 2 of
     * 2026-11-25, points 1 and 2) — the block of that period's return: summed per corrected period
     * (`corrected_from` / `corrected_to`), tax code, rate and account, in the shape of
     * {@see vatReturnAggregate()} (a correction entry is manual: no source type, never part of a
     * reversal pair). Doctrine-only (SQL).
     *
     * @param list<int> $yearIds
     * @return list<array{corrected_from: string, corrected_to: string, tax_code: string, tax_rate: int, account_id: int, number: string, name: string, type: string, lines: int, base: string, tax: string}>
     */
    public function vatReturnCorrectionAggregate(array $yearIds, string $from, string $to): array
    {
        if ($yearIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.vat_period_from, e.vat_period_to, l.tax_code, l.tax_rate, l.account_id, a.number, a.name, a.type,
                    COUNT(*) AS line_count, SUM(l.tax_base) AS base, SUM(l.tax_amount) AS tax
               FROM journal_entry e
               JOIN journal_line l ON l.entry_id = e.id
               JOIN account a ON a.id = l.account_id
              WHERE ' . self::VAT_PERIOD_WHERE . ' AND l.tax_code IS NOT NULL AND e.vat_period_from IS NOT NULL
              GROUP BY e.vat_period_from, e.vat_period_to, l.tax_code, l.tax_rate, l.account_id, a.number, a.name, a.type
              ORDER BY e.vat_period_from, l.tax_code, l.tax_rate, a.number',
            [array_values($yearIds), $from, $to],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );

        return array_map(static fn(array $r): array => [
            'corrected_from' => (string) $r['vat_period_from'],
            'corrected_to'   => (string) $r['vat_period_to'],
            'tax_code'       => (string) $r['tax_code'],
            'tax_rate'       => (int) $r['tax_rate'],
            'account_id'     => (int) $r['account_id'],
            'number'         => (string) $r['number'],
            'name'           => (string) $r['name'],
            'type'           => (string) $r['type'],
            'lines'          => (int) $r['line_count'],
            'base'           => (string) $r['base'],
            'tax'            => (string) $r['tax'],
        ], $rows);
    }

    /**
     * The CORRECTION ENTRIES dated between two days, in journal order (date, year, number), with the
     * period each corrects: the entries behind the block of a return, the entries of a year not yet
     * carried by a posted return. Doctrine-only (SQL).
     *
     * @return list<array{entry_id: int, year: string, number: int, entry_date: string, text: string, corrected_from: string, corrected_to: string, corrected_rhythm: string}>
     */
    public function vatCorrectionEntries(string $from, string $to): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.id, y.code, e.number, e.entry_date, e.text, e.vat_period_from, e.vat_period_to, e.vat_period_rhythm
               FROM journal_entry e
               JOIN fiscal_year y ON y.id = e.fiscal_year_id
              WHERE e.vat_period_from IS NOT NULL AND e.entry_date BETWEEN ? AND ?
              ORDER BY e.entry_date, y.code, e.number',
            [$from, $to]
        );

        return array_map(self::correctionEntryRow(...), $rows);
    }

    /**
     * The CORRECTION ENTRIES that correct the VAT period starting on $periodFrom — what keeps its
     * return from being lifted (ADR-042 addendum 2 of 2026-11-25, point 2). $locked: read under a
     * SHARED lock on the committed rows (`LOCK IN SHARE MODE`, until the caller's commit — the lift's
     * check after its row locks sees a correction entry committed meanwhile). Index
     * `idx_journal_entry_vat_period`. Doctrine-only (SQL).
     *
     * @return list<array{entry_id: int, year: string, number: int, entry_date: string, text: string, corrected_from: string, corrected_to: string, corrected_rhythm: string}>
     * @throws \LogicException $locked outside an open transaction
     */
    public function entriesCorrecting(string $periodFrom, bool $locked = false): array
    {
        if ($locked && !$this->connection()->isTransactionActive()) {
            throw new \LogicException('entriesCorrecting(locked) needs an open unit of work — the row lock holds until commit');
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.id, y.code, e.number, e.entry_date, e.text, e.vat_period_from, e.vat_period_to, e.vat_period_rhythm
               FROM journal_entry e
               JOIN fiscal_year y ON y.id = e.fiscal_year_id
              WHERE e.vat_period_from = ?
              ORDER BY e.entry_date, y.code, e.number' . ($locked ? ' LOCK IN SHARE MODE' : ''),
            [$periodFrom]
        );

        return array_map(self::correctionEntryRow(...), $rows);
    }

    /** @return array{entry_id: int, year: string, number: int, entry_date: string, text: string, corrected_from: string, corrected_to: string, corrected_rhythm: string} */
    private static function correctionEntryRow(array $r): array
    {
        return [
            'entry_id'         => (int) $r['id'],
            'year'             => (string) $r['code'],
            'number'           => (int) $r['number'],
            'entry_date'       => (string) $r['entry_date'],
            'text'             => (string) $r['text'],
            'corrected_from'   => (string) $r['vat_period_from'],
            'corrected_to'     => (string) $r['vat_period_to'],
            'corrected_rhythm' => (string) $r['vat_period_rhythm'],
        ];
    }

    /**
     * The statement of the line list: the period's coded lines with their reversal flag as a derived
     * table `x`, then the selectors on it — the flag is computed once per line. The placeholders are
     * filled with the fixed fragments below and the WHERE literals of {@see vatReturnLineSql()} only;
     * every value is a bound `?`.
     */
    private const VAT_LINES_SQL = 'SELECT %columns% FROM (
            SELECT l.id AS line_id, e.id AS entry_id, y.code AS year_code, e.number, e.entry_date, e.text AS entry_text,
                   l.text AS line_text, l.position, a.id AS account_id, a.number AS account_number, a.name AS account_name,
                   l.tax_code, l.tax_rate, l.tax_base, l.tax_amount, e.source_type, e.source_ref, ' . self::VAT_REVERSAL_FLAG . ' AS reversal
              FROM journal_entry e
              JOIN journal_line l ON l.entry_id = e.id
              JOIN account a ON a.id = l.account_id
              JOIN fiscal_year y ON y.id = e.fiscal_year_id
             WHERE %where%
        ) x
        WHERE %selectors%';

    /** The columns of a page of the line list. */
    private const VAT_LINE_COLUMNS = 'x.*';

    /** The columns of the list's total line. */
    private const VAT_LINE_TOTALS = 'COUNT(*) AS line_count, COALESCE(SUM(x.tax_base), 0) AS base, COALESCE(SUM(x.tax_amount), 0) AS tax';

    /** One selector: code, rate, the reversal flags taken (array), the source type. */
    private const VAT_SELECTOR_SOURCE = '(x.tax_code = ? AND x.tax_rate = ? AND x.reversal IN (?) AND x.source_type = ?)';

    /** One selector of the manual entries (no source type): code, rate, the reversal flags taken. */
    private const VAT_SELECTOR_MANUAL = '(x.tax_code = ? AND x.tax_rate = ? AND x.reversal IN (?) AND x.source_type IS NULL)';

    /**
     * One PAGE of the coded lines a {@see VatReturnLineSearch} selects — the standard list of a
     * form field's entries. In the search's order, every value bound. Doctrine-only (SQL).
     *
     * @return list<array{line_id: int, entry_id: int, year: string, number: int, entry_date: string, entry_text: string, line_text: ?string, account_id: int, account_number: string, account_name: string, tax_code: string, tax_rate: int, base: string, tax: string, source_type: ?string, source_ref: ?string, reversal: bool}>
     */
    public function vatReturnLines(VatReturnLineSearch $search, int $offset, int $limit): array
    {
        if ($search->selectors === [] || $search->yearIds === []) {
            return [];
        }
        [$sql, $params, $types] = $this->vatReturnLineSql($search, self::VAT_LINE_COLUMNS);
        // A fixed map of literal ORDER BY clauses (R47: nothing is interpolated into a statement).
        $order = match ([$search->sort, $search->descending]) {
            ['date', false]    => 'x.entry_date, x.year_code, x.number, x.position, x.line_id',
            ['date', true]     => 'x.entry_date DESC, x.year_code DESC, x.number DESC, x.position, x.line_id',
            ['number', false]  => 'x.year_code, x.number, x.position, x.line_id',
            ['number', true]   => 'x.year_code DESC, x.number DESC, x.position, x.line_id',
            ['account', false] => 'x.account_number, x.entry_date, x.number, x.line_id',
            ['account', true]  => 'x.account_number DESC, x.entry_date, x.number, x.line_id',
            ['text', false]    => 'x.entry_text, x.entry_date, x.number, x.line_id',
            ['text', true]     => 'x.entry_text DESC, x.entry_date, x.number, x.line_id',
            ['base', false]    => 'x.tax_base, x.entry_date, x.number, x.line_id',
            ['base', true]     => 'x.tax_base DESC, x.entry_date, x.number, x.line_id',
            ['tax', false]     => 'x.tax_amount, x.entry_date, x.number, x.line_id',
            ['tax', true]      => 'x.tax_amount DESC, x.entry_date, x.number, x.line_id',
        };
        $rows = $this->connection()->fetchAllAssociative(
            implode(' ', [$sql, 'ORDER BY', $order, 'LIMIT ? OFFSET ?']),
            [...$params, max(1, $limit), max(0, $offset)],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER]
        );

        return array_map(static fn(array $r): array => [
            'line_id'        => (int) $r['line_id'],
            'entry_id'       => (int) $r['entry_id'],
            'year'           => (string) $r['year_code'],
            'number'         => (int) $r['number'],
            'entry_date'     => (string) $r['entry_date'],
            'entry_text'     => (string) $r['entry_text'],
            'line_text'      => $r['line_text'] === null ? null : (string) $r['line_text'],
            'account_id'     => (int) $r['account_id'],
            'account_number' => (string) $r['account_number'],
            'account_name'   => (string) $r['account_name'],
            'tax_code'       => (string) $r['tax_code'],
            'tax_rate'       => (int) $r['tax_rate'],
            'base'           => (string) $r['tax_base'],
            'tax'            => (string) $r['tax_amount'],
            'source_type'    => $r['source_type'] === null ? null : (string) $r['source_type'],
            'source_ref'     => $r['source_ref'] === null ? null : (string) $r['source_ref'],
            'reversal'       => (int) $r['reversal'] === 1,
        ], $rows);
    }

    /** How many lines a {@see VatReturnLineSearch} selects — the pager. Doctrine-only (SQL). */
    public function countVatReturnLines(VatReturnLineSearch $search): int
    {
        return $this->vatReturnLineTotals($search)['lines'];
    }

    /**
     * Count, Σ base and Σ posted tax of every line a {@see VatReturnLineSearch} selects — the
     * list's total line (it equals its node of «Herkunft» without a column search). Doctrine-only (SQL).
     *
     * @return array{lines: int, base: string, tax: string}
     */
    public function vatReturnLineTotals(VatReturnLineSearch $search): array
    {
        if ($search->selectors === [] || $search->yearIds === []) {
            return ['lines' => 0, 'base' => '0.00', 'tax' => '0.00'];
        }
        [$sql, $params, $types] = $this->vatReturnLineSql($search, self::VAT_LINE_TOTALS);
        $row = $this->connection()->fetchAssociative($sql, $params, $types);

        return ['lines' => (int) $row['line_count'], 'base' => (string) $row['base'], 'tax' => (string) $row['tax']];
    }

    /**
     * The statement behind the three list methods ({@see VAT_LINES_SQL}): the narrowing of the search
     * as WHERE literals, the selectors, and their bound values in placeholder order.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType|ArrayParameterType>}
     */
    private function vatReturnLineSql(VatReturnLineSearch $search, string $columns): array
    {
        $params = [$search->to, array_values($search->yearIds), $search->from, $search->to];
        $types  = [ParameterType::STRING, ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING];
        $where  = [self::VAT_PERIOD_WHERE, 'l.tax_code IS NOT NULL', self::VAT_ORDINARY_ENTRY];
        if ($search->accountId !== null) {
            $where[]  = 'l.account_id = ?';
            $params[] = $search->accountId;
            $types[]  = ParameterType::INTEGER;
        }
        if ($search->dayFrom !== null) {
            $where[]  = 'e.entry_date >= ?';
            $params[] = $search->dayFrom;
            $types[]  = ParameterType::STRING;
        }
        if ($search->dayTo !== null) {
            $where[]  = 'e.entry_date <= ?';
            $params[] = $search->dayTo;
            $types[]  = ParameterType::STRING;
        }
        if ($search->number !== null) {
            $where[]  = 'e.number = ?';
            $params[] = $search->number;
            $types[]  = ParameterType::INTEGER;
        }
        if ($search->text !== null) {
            $where[] = "(e.text LIKE ? ESCAPE '!' OR l.text LIKE ? ESCAPE '!')";
            $like    = '%' . strtr($search->text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            array_push($params, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }

        $selectors = [];
        foreach ($search->selectors as $selector) {
            $selectors[] = $selector['source'] === null ? self::VAT_SELECTOR_MANUAL : self::VAT_SELECTOR_SOURCE;
            array_push($params, $selector['code'], $selector['rate'], $selector['reversal'] === [] ? [-1] : array_values($selector['reversal']));
            array_push($types, ParameterType::STRING, ParameterType::INTEGER, ArrayParameterType::INTEGER);
            if ($selector['source'] !== null) {
                $params[] = $selector['source'];
                $types[]  = ParameterType::STRING;
            }
        }

        $sql = strtr(self::VAT_LINES_SQL, [
            '%columns%'   => $columns,
            '%where%'     => implode(' AND ', $where),
            '%selectors%' => implode(' OR ', $selectors),
        ]);

        return [$sql, $params, $types];
    }

    /**
     * The lines the per-entry check of the VAT accounts reads (`VatReturn\VatAccountViolations` —
     * the lock's equation, entry by entry, for the tab «Abstimmung»): per entry of the period, its
     * lines on the VAT accounts of $search (coded or not) and its coded lines with tax on any
     * account, summed per account and tax code. Never hydrated; in date / number order.
     * Doctrine-only (SQL).
     *
     * @return list<array{entry_id: int, year: string, number: int, date: string, text: string, reversal_of: int, account: string, tax_code: ?string, debit: string, credit: string, tax: string, lines: int}>
     */
    public function vatAccountMovementLines(VatAccountMovementSearch $search): array
    {
        if ($search->yearIds === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT e.id AS entry_id, y.code, e.number, e.entry_date, e.text, e.reversal_of_id, a.number AS account, l.tax_code,
                    SUM(l.debit) AS debit, SUM(l.credit) AS credit, COALESCE(SUM(l.tax_amount), 0) AS tax, COUNT(*) AS line_count
               FROM journal_entry e
               JOIN journal_line l ON l.entry_id = e.id
               JOIN account a ON a.id = l.account_id
               JOIN fiscal_year y ON y.id = e.fiscal_year_id
              WHERE e.fiscal_year_id IN (?) AND e.entry_date BETWEEN ? AND ?
                AND (e.source_type IS NULL OR e.source_type NOT IN (?))
                AND NOT EXISTS (SELECT 1 FROM journal_line ol WHERE ol.entry_id = e.id AND ol.account_id = ?)
                AND (a.number IN (?) OR (l.tax_code IS NOT NULL AND l.tax_amount <> 0))
              GROUP BY e.id, y.code, e.number, e.entry_date, e.text, e.reversal_of_id, a.number, l.tax_code
              ORDER BY e.entry_date, e.number, e.id, a.number, l.tax_code',
            [
                array_values($search->yearIds), $search->from, $search->to,
                $search->excludedSourceTypes === [] ? [''] : array_values($search->excludedSourceTypes),
                $search->openingAccountId,
                $search->accounts === [] ? [''] : array_values($search->accounts),
            ],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING, ParameterType::INTEGER, ArrayParameterType::STRING]
        );

        return array_map(static fn(array $r): array => [
            'entry_id'    => (int) $r['entry_id'],
            'year'        => (string) $r['code'],
            'number'      => (int) $r['number'],
            'date'        => (string) $r['entry_date'],
            'text'        => (string) $r['text'],
            'reversal_of' => (int) $r['reversal_of_id'],
            'account'     => (string) $r['account'],
            'tax_code'    => $r['tax_code'] === null ? null : (string) $r['tax_code'],
            'debit'       => (string) $r['debit'],
            'credit'      => (string) $r['credit'],
            'tax'         => (string) $r['tax'],
            'lines'       => (int) $r['line_count'],
        ], $rows);
    }
}
