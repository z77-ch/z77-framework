<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMovement} — the dedup read of the import, the search of
 * the list «Bewegungen» and the FREE movements an owner may link (ADR-061, stage C1).
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
     * original amount. $unlinked = only movements WITHOUT a link (`bank_link`). Sorted by booking
     * date (then the order the bank delivered) or by amount. Doctrine-only (SQL for the ids, then
     * the entities).
     *
     * @return list<BankMovement>
     */
    public function search(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, string $sort, bool $descending, int $offset, int $limit, bool $unlinked = false): array
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount, $unlinked);
        $dir   = $descending ? 'DESC' : 'ASC';
        $order = $sort === 'amount' ? "amount {$dir}, id {$dir}" : "booking_date {$dir}, id {$dir}";
        $ids   = array_map('intval', $this->connection()->fetchFirstColumn(
            "SELECT id FROM bank_movement WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
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
    public function countSearch(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, bool $unlinked = false): int
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount, $unlinked);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM bank_movement WHERE {$where}", $params, $types);
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<ParameterType>} */
    private function searchWhere(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, bool $unlinked = false): array
    {
        $where  = ['bank_account_id = ?'];
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
            $add('booking_date >= ?', [$from]);
        }
        if ($to !== null) {
            $add('booking_date <= ?', [$to]);
        }
        if ($text !== null && $text !== '') {
            $like = '%' . strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $add("(text LIKE ? ESCAPE '!' OR counterparty_name LIKE ? ESCAPE '!' OR reference LIKE ? ESCAPE '!')", [$like, $like, $like]);
        }
        if ($amount !== null) {
            $plain = ltrim($amount, '-');
            $add('(amount = ? OR original_amount = ?)', [$plain, $plain]);
        }
        if ($unlinked) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM bank_link l WHERE l.movement_id = bank_movement.id)';
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
}
