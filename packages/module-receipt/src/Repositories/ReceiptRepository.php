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

    /**
     * One page of the «Belegjournal» list — the captured receipts matching $search, sorted by the
     * fixed map (the id breaks every tie). SQL for the ids, then the entities in that order.
     *
     * @return list<Receipt>
     */
    public function search(ReceiptSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir   = $search->descending ? 'DESC' : 'ASC';
        $order = match ($search->sort) {
            'amount' => "amount {$dir}, receipt_date {$dir}, id {$dir}",
            default  => "receipt_date {$dir}, id {$dir}",
        };
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT id FROM receipt{$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('r')->from(Receipt::class, 'r')->where('r.id IN (:ids)')->setParameter('ids', array_map('intval', $ids))->getQuery()->getResult() as $receipt) {
            $byId[$receipt->getId()] = $receipt;
        }

        return array_values(array_filter(array_map(static fn($id) => $byId[(int) $id] ?? null, $ids)));
    }

    /** How many captured receipts match $search — the list's pager and badge. SQL. */
    public function countSearch(ReceiptSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM receipt{$where}", $params, $types);
    }

    /**
     * How many receipts (any state) carry each template code — the «Belege» column of the master
     * data: a used template is deactivated, never deleted. SQL.
     *
     * @return array<string, int> code → count, only codes in use
     */
    public function countByTemplate(): array
    {
        return array_map('intval', $this->connection()->fetchAllKeyValue('SELECT template_code, COUNT(*) FROM receipt WHERE template_code IS NOT NULL GROUP BY template_code'));
    }

    /**
     * How many receipts (any state) carry each payment method code («Bezahlt mit», E10). SQL.
     *
     * @return array<string, int> code → count, only codes in use
     */
    public function countByPaymentMethod(): array
    {
        return array_map('intval', $this->connection()->fetchAllKeyValue('SELECT payment_method_code, COUNT(*) FROM receipt WHERE payment_method_code IS NOT NULL GROUP BY payment_method_code'));
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

    /**
     * The WHERE of {@see search()} / {@see countSearch()} — always the captured receipts, every
     * value bound.
     *
     * @return array{0: string, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function searchWhere(ReceiptSearch $search): array
    {
        $where  = ['state = ?'];
        $params = [ReceiptState::Captured->value];
        $types  = [ParameterType::STRING];
        $add = static function (string $sql, mixed $value) use (&$where, &$params, &$types): void {
            $where[]  = $sql;
            $params[] = $value;
            $types[]  = ParameterType::STRING;
        };
        if ($search->dateFrom !== null) {
            $add('receipt_date >= ?', $search->dateFrom);
        }
        if ($search->dateTo !== null) {
            $add('receipt_date <= ?', $search->dateTo);
        }
        if ($search->text !== null && $search->text !== '') {
            $add('text LIKE ?', '%' . addcslashes($search->text, '\\%_') . '%');
        }
        if ($search->amount !== null) {
            $add('amount = ?', $search->amount);
        }
        if ($search->capturedBy !== null && $search->capturedBy !== '') {
            $like     = '%' . addcslashes($search->capturedBy, '\\%_') . '%';
            $where[]  = '(captured_by LIKE ? OR device_name LIKE ?)';
            array_push($params, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING);
        }

        return [' WHERE ' . implode(' AND ', $where), $params, $types];
    }
}
