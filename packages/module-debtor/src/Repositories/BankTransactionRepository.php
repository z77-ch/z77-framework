<?php

namespace Z77\Module\Debtor\Repositories;

use Doctrine\ORM\Query;
use Z77\Module\Debtor\Entities\BankTransaction;
use Z77\Module\Debtor\Entities\TransactionState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see \Z77\Module\Debtor\Entities\BankTransaction}
 * — the reading the close check needs, and the fresh re-reads of the
 * writes after their message lock. Doctrine-only (SQL and DQL, ADR-039
 * decision 8).
 */
class BankTransactionRepository extends DoctrineRepository
{
    /**
     * The transactions of message $messageId in position order, RE-READ
     * from the database even when the EntityManager already holds them
     * (`HINT_REFRESH`): what the booking judges after it locked the message
     * — a copy loaded before the lock (the screen's, the pre-read of the
     * booking) would show the state before a concurrent booking or
     * assignment. Doctrine-only (DQL).
     *
     * @return list<BankTransaction>
     */
    public function freshOfMessage(int $messageId): array
    {
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(BankTransaction::class, 't')
            ->where('t.message = :message')
            ->setParameter('message', $messageId)
            ->orderBy('t.position', 'ASC')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /** One transaction RE-READ (`HINT_REFRESH`), or null — {@see freshOfMessage()} for one row. Doctrine-only (DQL). */
    public function fresh(int $id): ?BankTransaction
    {
        return $this->em()->createQueryBuilder()
            ->select('t')
            ->from(BankTransaction::class, 't')
            ->where('t.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

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
