<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMovement} — the dedup read of the import, the search of
 * the list «Bewegungen», the FREE movements an owner may link (ADR-061, stage C1) and the STATE
 * of a movement — «geschäftlich» / «privat» / «zu prüfen» (stage C2), derived in SQL in ONE place
 * ({@see STATE}).
 * Doctrine-only (ADR-039 decision 8).
 */
class BankMovementRepository extends DoctrineRepository
{
    /** Keys per `IN (…)` — far below the driver's placeholder limit. */
    private const CHUNK = 500;

    /**
     * Which of $dedupKeys the bank account already holds — read on the connection of the open
     * unit of work, after the account's row lock, so it sees every committed import. Doctrine-only
     * (SQL).
     *
     * @param list<string> $dedupKeys
     * @return array<string, true> the known keys
     */
    public function knownKeys(int $bankAccountId, array $dedupKeys): array
    {
        $known = [];
        foreach (array_chunk($dedupKeys, self::CHUNK) as $chunk) {
            $rows = $this->connection()->fetchFirstColumn(
                'SELECT dedup_key FROM bank_movement WHERE bank_account_id = ? AND dedup_key IN (?)',
                [$bankAccountId, $chunk],
                [ParameterType::INTEGER, ArrayParameterType::STRING]
            );
            foreach ($rows as $key) {
                $known[(string) $key] = true;
            }
        }

        return $known;
    }

    /**
     * One page of the list «Bewegungen» of a bank account. $from / $to bound the BOOKING date
     * (`Y-m-d`, inclusive), $text is looked for in the bank's text, the counterparty and the
     * reference, $amount (a decimal string, the sign ignored) matches the booked amount OR the
     * original amount. $state = only movements of that state ({@see STATE}; null = all). Sorted by booking
     * date (then the order the bank delivered) or by amount. Doctrine-only (SQL for the ids, then
     * the entities).
     *
     * @return list<BankMovement>
     */
    public function search(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, string $sort, bool $descending, int $offset, int $limit, ?string $state = null): array
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount, $state);
        $dir   = $descending ? 'DESC' : 'ASC';
        $order = $sort === 'amount' ? "m.amount {$dir}, m.id {$dir}" : "m.booking_date {$dir}, m.id {$dir}";
        $ids   = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT m.id FROM bank_movement m WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        ));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $movement) {
            $byId[$movement->getId()] = $movement;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /** How many movements match — the pager and the badge. Doctrine-only (SQL). */
    public function countSearch(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, ?string $state = null): int
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount, $state);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM bank_movement m WHERE {$where}", $params, $types);
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<ParameterType>} */
    private function searchWhere(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, ?string $state = null): array
    {
        $where  = ['m.bank_account_id = ?'];
        $params = [$bankAccountId];
        $types  = [ParameterType::INTEGER];
        $add = static function (string $sql, array $values) use (&$where, &$params, &$types): void {
            $where[] = $sql;
            foreach ($values as $value) {
                $params[] = $value;
                $types[]  = ParameterType::STRING;
            }
        };
        if ($from !== null) {
            $add('m.booking_date >= ?', [$from]);
        }
        if ($to !== null) {
            $add('m.booking_date <= ?', [$to]);
        }
        if ($text !== null && $text !== '') {
            $like = '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $add("(m.text LIKE ? ESCAPE '!' OR m.counterparty_name LIKE ? ESCAPE '!' OR m.reference LIKE ? ESCAPE '!')", [$like, $like, $like]);
        }
        if ($amount !== null) {
            $plain = ltrim($amount, '-');
            $add('(m.amount = ? OR m.original_amount = ?)', [$plain, $plain]);
        }
        if ($state !== null) {
            $add(self::STATE . ' = ?', [$state]);
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /** What makes a movement FREE for an owner: no link, no reversal, an active bank account that takes statements. */
    private const FREE = 'm.reversal = 0 AND a.active = 1 AND a.statement_import = 1 AND NOT EXISTS (SELECT 1 FROM bank_link l WHERE l.movement_id = m.id)';

    /**
     * The FREE movements of $direction matching every given criterion, newest booking first —
     * `BankLinkService::freeMovements()`. $references are compared exactly, $amount with the
     * booked amount, $originalAmount + $originalCurrency with the instructed foreign amount,
     * $from / $to (`Y-m-d`, inclusive) with the BOOKING date. Doctrine-only (SQL for the ids,
     * then the entities).
     *
     * @param list<string>|null $references
     * @return list<BankMovement>
     */
    public function findFree(string $direction, ?array $references, ?string $amount, ?string $originalAmount, ?string $originalCurrency, ?string $from, ?string $to, int $limit): array
    {
        $where  = [self::FREE, 'm.direction = ?'];
        $params = [$direction];
        $types  = [ParameterType::STRING];
        $add = static function (string $sql, mixed $value, ParameterType|ArrayParameterType $type = ParameterType::STRING) use (&$where, &$params, &$types): void {
            $where[]  = $sql;
            $params[] = $value;
            $types[]  = $type;
        };
        if ($references !== null) {
            $add('m.reference IN (?)', array_values($references), ArrayParameterType::STRING);
        }
        if ($amount !== null) {
            $add('m.amount = ?', $amount);
        }
        if ($originalAmount !== null && $originalCurrency !== null) {
            $add('m.original_amount = ?', $originalAmount);
            $add('m.original_currency = ?', $originalCurrency);
        }
        if ($from !== null) {
            $add('m.booking_date >= ?', $from);
        }
        if ($to !== null) {
            $add('m.booking_date <= ?', $to);
        }
        $params[] = max(1, $limit);
        $types[]  = ParameterType::INTEGER;
        $ids = array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT m.id FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE ' . implode(' AND ', $where)
            . ' ORDER BY m.booking_date DESC, m.id DESC LIMIT ?',
            $params,
            $types
        ));
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $movement) {
            $byId[$movement->getId()] = $movement;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /**
     * The FREE movements of $direction whose ENTRY reference (`Ntry/AcctSvcrRef`) is one of
     * $entryRefs — `BankLinkService::freeMovementsByEntryRefs()` (ADR-061 decision 12, debtor's
     * identity). Compared exactly; oldest booking first. Doctrine-only (SQL for the ids, then the
     * entities).
     *
     * @param list<string> $entryRefs
     * @return list<BankMovement>
     */
    public function findFreeByEntryRefs(string $direction, array $entryRefs): array
    {
        $ids = [];
        foreach (array_chunk(array_values(array_unique(array_filter($entryRefs, static fn($r) => is_string($r) && $r !== ''))), 500) as $chunk) {
            array_push($ids, ...array_map('intval', $this->connection()->fetchFirstColumn(
                'SELECT m.id FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE m.direction = ? AND m.entry_ref IN (?) AND ' . self::FREE
                . ' ORDER BY m.booking_date, m.id',
                [$direction, $chunk],
                [ParameterType::STRING, ArrayParameterType::STRING]
            )));
        }
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $movement) {
            $byId[$movement->getId()] = $movement;
        }

        return array_values(array_filter(array_map(static fn(int $id) => $byId[$id] ?? null, $ids)));
    }

    /** The movement $id when it is FREE (see {@see findFree()}), else null. Doctrine-only (SQL). */
    public function findFreeById(int $id): ?BankMovement
    {
        $free = $this->connection()->fetchOne(
            'SELECT m.id FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE m.id = ? AND ' . self::FREE,
            [$id],
            [ParameterType::INTEGER]
        );

        return $free === false ? null : $this->find($id);
    }

    /**
     * The distinct non-empty references of the FREE movements of $direction. Doctrine-only (SQL).
     *
     * @return list<string>
     */
    public function freeReferences(string $direction, int $limit): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            "SELECT DISTINCT m.reference FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE m.direction = ? AND m.reference <> '' AND " . self::FREE . ' LIMIT ?',
            [$direction, $limit],
            [ParameterType::STRING, ParameterType::INTEGER]
        ));
    }

    /** The booking date of the oldest FREE movement of $direction, or null. Doctrine-only (SQL). */
    public function firstFreeBooking(string $direction): ?\DateTimeImmutable
    {
        $date = $this->connection()->fetchOne(
            'SELECT MIN(m.booking_date) FROM bank_movement m JOIN bank_account a ON a.id = m.bank_account_id WHERE m.direction = ? AND ' . self::FREE,
            [$direction]
        );

        return is_string($date) && $date !== '' ? new \DateTimeImmutable($date) : null;
    }

    /** Whether any ACTIVE bank account has «Kontoauszug einlesen» on. Doctrine-only (SQL). */
    public function anyAccountReads(): bool
    {
        return $this->connection()->fetchOne('SELECT 1 FROM bank_account WHERE active = 1 AND statement_import = 1 LIMIT 1') !== false;
    }

    // ── the state of a movement (ADR-061 decisions 4–8, stage C2) ─────────

    /** «geschäftlich»: the movement has a link — an invoice, a payment, a receipt or a direct posting. */
    public const STATE_BUSINESS = 'business';
    /** «privat»: by hand, or a pattern the office confirmed as private takes it. */
    public const STATE_PRIVATE = 'private';
    /** «zu prüfen»: everything else — nothing is private by silence. */
    public const STATE_REVIEW = 'review';
    public const STATES = [self::STATE_REVIEW, self::STATE_BUSINESS, self::STATE_PRIVATE];
    /** No state: the movement is INFORMATION ONLY — its bank account is not bank-driven on its day (ADR-061 decision 1). */
    public const STATE_NONE = '';

    /**
     * Movement `m` lies in the BANK-DRIVEN time of its bank account (ADR-061 decisions 1, 3, stage
     * C4): «Kontoauszug einlesen» on, «Abgleich ab» set, booked on or after it — the SQL twin of
     * `BankAccount::drives()`. Everything else is information only: no state, not in the check.
     */
    public const DRIVEN = 'EXISTS (SELECT 1 FROM bank_account da WHERE da.id = m.bank_account_id AND da.statement_import = 1 AND da.reconcile_from IS NOT NULL AND m.booking_date >= da.reconcile_from)';

    /**
     * Pattern `p` TAKES movement `m`: the same bank account, direction and key, and — where the
     * pattern is narrowed — the approximate amount and the day of month (the SQL twin of
     * `BankPattern::takes()`; the day distance counts across the month end).
     */
    private const TAKES = "p.bank_account_id = m.bank_account_id AND p.direction = m.direction AND p.match_key = m.pattern_key AND m.pattern_key <> ''"
        . ' AND (p.amount_min IS NULL OR m.amount BETWEEN p.amount_min AND p.amount_max)'
        . ' AND (p.day_of_month IS NULL OR LEAST(ABS(DAYOFMONTH(m.booking_date) - p.day_of_month), 31 - ABS(DAYOFMONTH(m.booking_date) - p.day_of_month)) <= ' . \Z77\Module\Financial\Entities\BankPattern::DAY_TOLERANCE . ')';

    /**
     * THE state of movement `m`, derived — the one place that says it (never stored):
     * not {@see DRIVEN} → none ('', information only — whatever is linked to it); else
     * a LINK → business (a document beats a pattern, decision 7); else the office's mark by hand
     * (`private` / `review`; an `exception` says nothing without a link); else a PRIVATE pattern
     * that takes it → private; else review.
     */
    public const STATE = '(CASE WHEN NOT ' . self::DRIVEN . " THEN '' WHEN EXISTS (SELECT 1 FROM bank_link l WHERE l.movement_id = m.id) THEN 'business' ELSE COALESCE("
        . "(SELECT CASE k.state WHEN 'private' THEN 'private' WHEN 'review' THEN 'review' END FROM bank_movement_mark k WHERE k.movement_id = m.id), "
        . "(SELECT 'private' FROM bank_pattern p WHERE p.kind = 'private' AND " . self::TAKES . ' LIMIT 1), '
        . "'review') END)";

    /**
     * The state of each of $ids. Doctrine-only (SQL).
     *
     * @param list<int> $ids
     * @return array<int, string> movement id → {@see STATES}, or {@see STATE_NONE} for a movement that is information only
     */
    public function statesOf(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), self::CHUNK) as $chunk) {
            $rows = $this->connection()->fetchAllKeyValue('SELECT m.id, ' . self::STATE . ' FROM bank_movement m WHERE m.id IN (?)', [$chunk], [ArrayParameterType::INTEGER]);
            foreach ($rows as $id => $state) {
                $out[(int) $id] = (string) $state;
            }
        }

        return $out;
    }

    /**
     * How many movements of the bank account stand in each state — the bank-driven ones only
     * (a movement that is information only has none). Doctrine-only (SQL).
     *
     * @return array<string, int> state → count, every state present
     */
    public function stateCounts(int $bankAccountId): array
    {
        $rows = $this->connection()->fetchAllKeyValue(
            'SELECT s.state, COUNT(*) FROM (SELECT ' . self::STATE . ' AS state FROM bank_movement m WHERE m.bank_account_id = ? AND ' . self::DRIVEN . ') s GROUP BY s.state',
            [$bankAccountId],
            [ParameterType::INTEGER]
        );
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $state => $count) {
            $counts[(string) $state] = (int) $count;
        }

        return $counts;
    }

    /**
     * How many movements are «privat» BECAUSE of pattern $patternId — it takes them, nothing is
     * linked to them and the office decided none of them by hand (a mark `private` / `review`; an
     * `exception` says nothing without a link). Doctrine-only (SQL).
     */
    public function countPrivateBy(int $patternId): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM bank_movement m JOIN bank_pattern p ON ' . self::TAKES . ' WHERE p.id = ? AND ' . self::DRIVEN
            . ' AND NOT EXISTS (SELECT 1 FROM bank_link l WHERE l.movement_id = m.id) AND NOT EXISTS (SELECT 1 FROM bank_movement_mark k WHERE k.movement_id = m.id AND k.state <> \'exception\')',
            [$patternId],
            [ParameterType::INTEGER]
        );
    }

    /**
     * The movements of the bank account that are LINKED although a private pattern takes them, and
     * for which the office has not answered yet (no `exception` mark) — a document beat the
     * pattern, decision 8 asks. Oldest first. Doctrine-only (SQL).
     *
     * @return list<array{movement: int, pattern: int}>
     */
    public function openPatternQuestions(int $bankAccountId, int $limit = 20): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT m.id AS movement, p.id AS pattern FROM bank_movement m JOIN bank_pattern p ON ' . self::TAKES
            . " WHERE m.bank_account_id = ? AND " . self::DRIVEN . " AND p.kind = 'private' AND EXISTS (SELECT 1 FROM bank_link l WHERE l.movement_id = m.id)"
            . " AND NOT EXISTS (SELECT 1 FROM bank_movement_mark k WHERE k.movement_id = m.id AND k.state = 'exception')"
            . ' ORDER BY m.booking_date, m.id LIMIT ?',
            [$bankAccountId, max(1, $limit)],
            [ParameterType::INTEGER, ParameterType::INTEGER]
        );

        return array_map(static fn(array $r): array => ['movement' => (int) $r['movement'], 'pattern' => (int) $r['pattern']], $rows);
    }

    // ── the check (ADR-061 decisions 22–25, stage C4) ─────────────────────

    /** The signed amount of movement `m`: a credit +, a debit −. */
    private const SIGNED = "(CASE m.direction WHEN 'credit' THEN m.amount ELSE -m.amount END)";

    /**
     * Σ of the signed amounts of the bank account's movements booked BEFORE $day (`Y-m-d`) — with
     * the opening balance of the first statement, the bank's balance at the opening of that day.
     * Doctrine-only (SQL).
     */
    public function signedBefore(int $bankAccountId, string $day): string
    {
        return (string) $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(' . self::SIGNED . '), 0) FROM bank_movement m WHERE m.bank_account_id = ? AND m.booking_date < ?',
            [$bankAccountId, $day],
            [ParameterType::INTEGER, ParameterType::STRING]
        );
    }

    /**
     * Count and signed sum of the bank account's BANK-DRIVEN movements booked from $from to $to
     * (`Y-m-d`, inclusive), per state. Doctrine-only (SQL).
     *
     * @return array<string, array{count: int, sum: string}> state → figures, every state present
     */
    public function stateSums(int $bankAccountId, string $from, string $to): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT s.state, COUNT(*) AS n, COALESCE(SUM(s.signed), 0) AS total FROM (SELECT ' . self::STATE . ' AS state, ' . self::SIGNED . ' AS signed'
            . ' FROM bank_movement m WHERE m.bank_account_id = ? AND m.booking_date BETWEEN ? AND ? AND ' . self::DRIVEN . ') s GROUP BY s.state',
            [$bankAccountId, $from, $to],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
        $out = array_fill_keys(self::STATES, ['count' => 0, 'sum' => '0.00']);
        foreach ($rows as $row) {
            $out[(string) $row['state']] = ['count' => (int) $row['n'], 'sum' => (string) $row['total']];
        }

        return $out;
    }

    /**
     * Σ of the signed amounts of the bank account's «privat» movements booked from $from
     * (inclusive) to BEFORE $before (`Y-m-d`) — what moves the anchor (decision 24). Doctrine-only (SQL).
     */
    public function privateNet(int $bankAccountId, string $from, string $before): string
    {
        return (string) $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(' . self::SIGNED . '), 0) FROM bank_movement m WHERE m.bank_account_id = ? AND m.booking_date >= ? AND m.booking_date < ? AND '
            . self::STATE . " = 'private'",
            [$bankAccountId, $from, $before],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]
        );
    }

    /**
     * Every LINKED bank-driven movement of the bank account: the owner key, the booking day and
     * the signed amount — what the check joins the postings with. Doctrine-only (SQL).
     *
     * @return array<string, array{movement: int, date: string, signed: string}> owner key → its movement
     */
    public function linkedOf(int $bankAccountId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT l.owner_key, m.id, m.booking_date, ' . self::SIGNED . ' AS signed FROM bank_link l JOIN bank_movement m ON m.id = l.movement_id'
            . ' WHERE m.bank_account_id = ? AND ' . self::DRIVEN . ' ORDER BY m.booking_date, m.id',
            [$bankAccountId],
            [ParameterType::INTEGER]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['owner_key']] = ['movement' => (int) $row['id'], 'date' => (string) $row['booking_date'], 'signed' => (string) $row['signed']];
        }

        return $out;
    }

    /**
     * Locks the movement row EXCLUSIVELY until the caller commits — what a decision about ONE
     * movement (a mark, a posting) serialises on. False when it is gone. Doctrine-only (SQL).
     *
     * @throws \LogicException outside an open unit of work
     */
    public function lockRow(int $id): bool
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockRow() needs an open unit of work — the row lock holds until commit');
        }

        return $this->connection()->fetchOne('SELECT id FROM bank_movement WHERE id = ? FOR UPDATE', [$id], [ParameterType::INTEGER]) !== false;
    }
}
