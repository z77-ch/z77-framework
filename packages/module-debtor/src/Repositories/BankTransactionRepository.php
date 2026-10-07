<?php

namespace Z77\Module\Debtor\Repositories;

use Z77\Module\Debtor\Entities\TransactionState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see \Z77\Module\Debtor\Entities\BankTransaction}
 * — the reading the close check needs. Doctrine-only (SQL, ADR-039
 * decision 8).
 */
class BankTransactionRepository extends DoctrineRepository
{
    /**
     * The transactions still `matched` or `unmatched` with a value date in
     * [$from, $to]: the first $limit rows (oldest first) and the total —
     * `Close/UnbookedTransactionsCheck`.
     *
     * @return array{rows: list<array<string, mixed>>, count: int}
     */
    public function unbookedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        $states = [TransactionState::Unmatched->value, TransactionState::Matched->value];
        $params = [$from->format('Y-m-d'), $to->format('Y-m-d'), ...$states];
        $rows   = $this->connection()->fetchAllAssociative(
            'SELECT t.id, t.value_date, t.amount, t.currency, m.message_id AS message_ref FROM bank_transaction t JOIN bank_message m ON m.id = t.message_id'
            . ' WHERE t.value_date BETWEEN ? AND ? AND t.state IN (?, ?) ORDER BY t.value_date, t.id LIMIT ' . max(1, $limit),
            $params
        );
        $count = (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM bank_transaction t WHERE t.value_date BETWEEN ? AND ? AND t.state IN (?, ?)',
            $params
        );

        return ['rows' => $rows, 'count' => $count];
    }
}
