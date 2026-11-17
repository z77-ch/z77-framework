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

    // ── the bank's entries (ADR-061 stage C3, `Services\BankEntryLinkService`) ──

    /** Keys per `IN (…)` — far below the driver's placeholder limit. */
    private const CHUNK = 500;

    /**
     * Per ENTRY reference: how many transactions carry it, their sum, their currency (`mixed`
     * when they differ), the messages they came in and the debtors' names — what the camt.053
     * collective credit of that reference must agree with. Doctrine-only (SQL).
     *
     * @param list<string> $entryRefs
     * @return array<string, array{count: int, amount: string, currency: string, mixed: bool, messages: list<int>, names: string, day: string}>
     */
    public function entryTotals(array $entryRefs): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_filter($entryRefs, static fn($r) => is_string($r) && $r !== ''))), self::CHUNK) as $chunk) {
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT entry_ref, COUNT(*) AS n, SUM(amount) AS total, MIN(currency) AS c1, MAX(currency) AS c2, GROUP_CONCAT(DISTINCT message_id) AS messages,'
                . " GROUP_CONCAT(DISTINCT debtor_name ORDER BY position SEPARATOR ' · ') AS names, MIN(COALESCE(booking_date, value_date)) AS day"
                . ' FROM bank_transaction WHERE entry_ref IN (?) GROUP BY entry_ref',
                [$chunk],
                [\Doctrine\DBAL\ArrayParameterType::STRING]
            );
            foreach ($rows as $row) {
                $out[(string) $row['entry_ref']] = self::totals($row);
            }
        }

        return $out;
    }

    /**
     * The distinct ENTRY references of the transactions booked (else valued) on or after $since,
     * newest first — what a pass compares with the free camt.053 movements. Doctrine-only (SQL).
     *
     * @return list<string>
     */
    public function entryRefsSince(\DateTimeImmutable $since, int $limit): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            'SELECT entry_ref FROM bank_transaction WHERE entry_ref IS NOT NULL AND COALESCE(booking_date, value_date) >= ?'
            . ' GROUP BY entry_ref ORDER BY MAX(COALESCE(booking_date, value_date)) DESC LIMIT ' . max(1, $limit),
            [$since->format('Y-m-d')]
        ));
    }

    /** @return list<string> the distinct ENTRY references of message $messageId. Doctrine-only (SQL). */
    public function entryRefsOfMessage(int $messageId): array
    {
        return array_map('strval', $this->connection()->fetchFirstColumn(
            'SELECT DISTINCT entry_ref FROM bank_transaction WHERE message_id = ? AND entry_ref IS NOT NULL',
            [$messageId]
        ));
    }

    /**
     * The transactions WITHOUT an entry reference (imported before 2026-11-17, or an entry the
     * bank sent without one), grouped per message and booking day (else value day) — the
     * fallback of the link, a proposal at most. $since limits the day; newest first.
     * Doctrine-only (SQL).
     *
     * @return list<array{message: int, count: int, amount: string, currency: string, mixed: bool, names: string, day: string}>
     */
    public function dayGroups(?\DateTimeImmutable $since, int $limit, ?int $messageId = null, ?string $day = null): array
    {
        $where  = ['entry_ref IS NULL'];
        $params = [];
        if ($since !== null) {
            $where[]  = 'COALESCE(booking_date, value_date) >= ?';
            $params[] = $since->format('Y-m-d');
        }
        if ($messageId !== null) {
            $where[]  = 'message_id = ?';
            $params[] = $messageId;
        }
        if ($day !== null) {
            $where[]  = 'COALESCE(booking_date, value_date) = ?';
            $params[] = $day;
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT message_id, COALESCE(booking_date, value_date) AS day, COUNT(*) AS n, SUM(amount) AS total, MIN(currency) AS c1, MAX(currency) AS c2,'
            . " GROUP_CONCAT(DISTINCT debtor_name ORDER BY position SEPARATOR ' · ') AS names"
            . ' FROM bank_transaction WHERE ' . implode(' AND ', $where)
            . ' GROUP BY message_id, COALESCE(booking_date, value_date) ORDER BY day DESC, message_id DESC LIMIT ' . max(1, $limit),
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['message' => (int) $row['message_id']] + self::totals($row);
        }

        return $out;
    }

    /** @return array{count: int, amount: string, currency: string, mixed: bool, messages: list<int>, names: string, day: string} */
    private static function totals(array $row): array
    {
        return [
            'count'    => (int) $row['n'],
            'amount'   => (string) $row['total'],
            'currency' => (string) $row['c1'],
            'mixed'    => (string) $row['c1'] !== (string) $row['c2'],
            'messages' => array_values(array_map('intval', array_filter(explode(',', (string) ($row['messages'] ?? $row['message_id'] ?? ''))))),
            'names'    => mb_substr((string) $row['names'], 0, 120),
            'day'      => substr((string) $row['day'], 0, 10),
        ];
    }

    /**
     * How many transactions are still `matched` or `unmatched` — received, not
     * booked, whatever their date: the dashboard's «Zahlungseingänge nicht
     * verbucht» (`Dashboard\DebtorDashboard`, ADR-052). One COUNT over the index
     * `idx_bank_transaction_state_date`. Doctrine-only (SQL).
     */
    public function countUnbooked(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM bank_transaction WHERE state IN (?, ?)',
            [TransactionState::Unmatched->value, TransactionState::Matched->value]
        );
    }
}
