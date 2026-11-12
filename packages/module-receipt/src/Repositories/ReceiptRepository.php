<?php

namespace Z77\Module\Receipt\Repositories;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptState;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads and row locks of the receipt journal. Doctrine-only.
 *
 * Every receipt unit of work takes its row locks FIRST with {@see lockIds()} — a locking read that
 * takes no read view (MariaDB REPEATABLE READ: the first PLAIN read of a transaction fixes its read
 * view, debtor.md «Lock order») — compares the versions it returns, and only then reads the rows
 * with {@see refreshed()} (overwriting whatever the identity map held from a read before).
 */
class ReceiptRepository extends DoctrineRepository
{
    /**
     * The open receipt journal — every `captured` receipt, by receipt date then id; $until = only
     * receipts dated on or before that day (the month-end posting).
     *
     * @return list<Receipt>
     */
    public function pending(?\DateTimeImmutable $until = null): array
    {
        $qb = $this->em()->createQueryBuilder()
            ->select('r')
            ->from(Receipt::class, 'r')
            ->where('r.state = :state')
            ->setParameter('state', ReceiptState::Captured->value)
            ->orderBy('r.receiptDate', 'ASC')
            ->addOrderBy('r.id', 'ASC');
        if ($until !== null) {
            $qb->andWhere('r.receiptDate <= :until')->setParameter('until', $until->format('Y-m-d'));
        }

        return array_values($qb->getQuery()->getResult());
    }

    /** How many OTHER receipts (any state) name the photo $documentId — split parts share one (E12). SQL. */
    public function countSharingDocument(int $documentId, int $exceptId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM receipt WHERE document_id = ? AND id <> ?', [$documentId, $exceptId], [ParameterType::INTEGER, ParameterType::INTEGER]);
    }

    /** The ids of the captured receipts dated on or before $cutOff, ascending — what «Journal verbuchen» must cover (E11). SQL. */
    public function capturedIdsUntil(\DateTimeImmutable $cutOff): array
    {
        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT id FROM receipt WHERE state = ? AND receipt_date <= ? ORDER BY id',
            [ReceiptState::Captured->value, $cutOff->format('Y-m-d')]
        ));
    }

    public function findByCaptureKey(string $key): ?Receipt
    {
        return $key === '' ? null : $this->findOneBy(['captureKey' => $key]);
    }

    /**
     * Locks the rows of $ids EXCLUSIVELY (`SELECT … FOR UPDATE`), one by one in ascending id order,
     * reading nothing but state and version. Inside an open unit of work only.
     *
     * @param list<int> $ids
     * @return array<int, array{state: string, version: int}> id → current state and version, ascending, only the rows that exist
     * @throws \LogicException outside an open unit of work
     */
    public function lockIds(array $ids): array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockIds() needs an open unit of work — the row locks hold until commit');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->connection()->fetchAssociative('SELECT state, version FROM receipt WHERE id = ? FOR UPDATE', [$id], [ParameterType::INTEGER]);
            if ($row !== false) {
                $rows[$id] = ['state' => (string) $row['state'], 'version' => (int) $row['version']];
            }
        }

        return $rows;
    }

    /** The receipt REFRESHED from the database (`HINT_REFRESH`) — call it after {@see lockIds()}. */
    public function refreshed(int $id): ?Receipt
    {
        return $this->em()->createQueryBuilder()
            ->select('r')
            ->from(Receipt::class, 'r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * The captured receipts dated $from … $to — count and gross total (decimal string), the first
     * $limit rows for a message. The open-work checks of the year close, the reports and the VAT
     * return ask it. SQL.
     *
     * @return array{count: int, gross: string, rows: list<array{id: int, receipt_date: string, text: string, amount: string}>}
     */
    public function unpostedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 10): array
    {
        $params = [ReceiptState::Captured->value, $from->format('Y-m-d'), $to->format('Y-m-d')];
        $sum    = $this->connection()->fetchAssociative(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0.00) AS gross FROM receipt WHERE state = ? AND receipt_date BETWEEN ? AND ?',
            $params
        );
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, receipt_date, text, amount FROM receipt WHERE state = ? AND receipt_date BETWEEN ? AND ? ORDER BY receipt_date, id LIMIT ' . max(1, $limit),
            $params
        );

        return [
            'count' => (int) ($sum['n'] ?? 0),
            'gross' => (string) ($sum['gross'] ?? '0.00'),
            'rows'  => array_map(static fn(array $r) => ['id' => (int) $r['id'], 'receipt_date' => (string) $r['receipt_date'], 'text' => (string) $r['text'], 'amount' => (string) $r['amount']], $rows),
        ];
    }
}
