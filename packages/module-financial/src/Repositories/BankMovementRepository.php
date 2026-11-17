<?php

namespace Z77\Module\Financial\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankMovement} — the dedup read of the import and the search of
 * the list «Bewegungen». Doctrine-only (ADR-039 decision 8).
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
     * original amount. Sorted by booking date (then the order the bank delivered) or by amount.
     * Doctrine-only (SQL for the ids, then the entities).
     *
     * @return list<BankMovement>
     */
    public function search(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount, string $sort, bool $descending, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount);
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
    public function countSearch(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount): int
    {
        [$where, $params, $types] = $this->searchWhere($bankAccountId, $from, $to, $text, $amount);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM bank_movement WHERE {$where}", $params, $types);
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<ParameterType>} */
    private function searchWhere(int $bankAccountId, ?string $from, ?string $to, ?string $text, ?string $amount): array
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

        return [implode(' AND ', $where), $params, $types];
    }
}
