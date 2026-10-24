<?php

namespace Z77\Module\Order\Repositories;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\Query;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Reads and row locks of the order head (plan §5 «Lock order of module-order»). Doctrine-only.
 *
 * Every order unit of work takes its row locks FIRST with {@see lockIds()} — a locking read that
 * takes no read view (MariaDB REPEATABLE READ: the first PLAIN read of a transaction fixes its
 * read view, and one taken before a lock wait would not see what the holder committed, debtor.md
 * «Lock order») — and compares the version it returns; only then it reads the order with
 * {@see withLines()} (refreshing whatever the identity map held from a read before the unit of work).
 */
class SalesOrderRepository extends DoctrineRepository
{
    /**
     * Locks the rows of $ids EXCLUSIVELY (`SELECT … FOR UPDATE`), one by one in ascending id order,
     * reading nothing but the version — the version the caller compares against what its screen
     * saw. Inside an open unit of work only.
     *
     * @param list<int> $ids
     * @return array<int, int> id → current version, ascending by id, only the rows that exist
     * @throws \LogicException outside an open unit of work
     */
    public function lockIds(array $ids): array
    {
        if (!$this->connection()->isTransactionActive()) {
            throw new \LogicException('lockIds() needs an open unit of work — the row locks hold until commit');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        $versions = [];
        foreach ($ids as $id) {
            $version = $this->connection()->fetchOne('SELECT version FROM sales_order WHERE id = ? FOR UPDATE', [$id]);
            if ($version !== false) {
                $versions[$id] = (int) $version;
            }
        }

        return $versions;
    }

    /**
     * The order with its lines in one query, REFRESHED from the database (`HINT_REFRESH`): an
     * entity the identity map already holds from a read before the unit of work is overwritten
     * with the committed state. Call it after {@see lockIds()} inside a unit of work.
     */
    public function withLines(int $id): ?SalesOrder
    {
        return $this->em()->createQueryBuilder()
            ->select('o', 'l')
            ->from(SalesOrder::class, 'o')
            ->leftJoin('o.lines', 'l')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * The ids of the orders (not deleted) linked to any of $invoiceIds, ascending — «Journal
     * verbuchen»'s listener (`OrderInvoicePostedListener`).
     *
     * @param list<int> $invoiceIds
     * @return list<int>
     */
    public function idsByInvoices(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        return array_map('intval', $this->connection()->fetchFirstColumn(
            'SELECT id FROM sales_order WHERE invoice_id IN (?) AND deleted_at IS NULL ORDER BY id',
            [array_values(array_map('intval', $invoiceIds))],
            [ArrayParameterType::INTEGER],
        ));
    }

    public function findByNumber(int $number): ?SalesOrder
    {
        return $this->findOneBy(['number' => $number]);
    }

    // ── the list (plan §9.3, listing.md) ────────────────────────────────

    /**
     * One page of orders matching $search, sorted by the fixed map, the id breaking every tie.
     * The ids are chosen in SQL, the entities loaded in one DQL query (the debtor list's model).
     *
     * @return list<SalesOrder>
     */
    public function search(OrderSearch $search, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->searchWhere($search);
        $dir  = $search->descending ? 'DESC' : 'ASC';
        $name = "CASE WHEN c.kind = 'organisation' THEN c.company ELSE CONCAT(c.last_name, ' ', c.first_name) END";
        $order = match ($search->sort) {
            'date'   => "o.order_date {$dir}, o.number {$dir}",
            'service' => "o.service_from IS NULL, o.service_from {$dir}, o.number {$dir}",   // no date last, either way
            'name'   => "{$name} {$dir}, o.number {$dir}",
            'amount' => "o.total_gross {$dir}, o.number {$dir}",
            'invoice' => "i.number IS NULL, i.number {$dir}, o.number {$dir}",   // orders without an invoice last, either way
            default  => "o.number {$dir}",
        };
        $ids = $this->connection()->fetchFirstColumn(
            "SELECT o.id FROM sales_order o JOIN contact c ON c.id = o.contact_id" . self::INVOICE_JOIN . "{$where} ORDER BY {$order}, o.id {$dir} LIMIT ? OFFSET ?",
            array_merge($params, [max(1, $limit), max(0, $offset)]),
            array_merge($types, [ParameterType::INTEGER, ParameterType::INTEGER])
        );
        if ($ids === []) {
            return [];
        }
        $byId = [];
        foreach ($this->em()->createQueryBuilder()->select('o', 'c')->from(SalesOrder::class, 'o')->join('o.contact', 'c')
            ->where('o.id IN (:ids)')->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER)
            ->getQuery()->getResult() as $row) {
            $byId[$row->getId()] = $row;
        }

        return array_values(array_filter(array_map(static fn($id) => $byId[(int) $id] ?? null, $ids)));
    }

    /** How many orders match $search — the pager and the badge. */
    public function countSearch(OrderSearch $search): int
    {
        [$where, $params, $types] = $this->searchWhere($search);

        return (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM sales_order o JOIN contact c ON c.id = o.contact_id" . self::INVOICE_JOIN . "{$where}", $params, $types);
    }

    /** The booked invoice of a row — its number is a column of the list (search and sort, owner 2026-10-22). */
    private const INVOICE_JOIN = ' LEFT JOIN invoice i ON i.id = o.invoice_id';

    /**
     * The orders in one of $statusCodes dated $from–$to (both inclusive): the first $limit as rows
     * (id, number, order date, status code, the customer's name) and the total count — the year
     * close's warning (`Close\InvoiceableOrdersCheck`). Plain SQL, no lock.
     *
     * @param list<string> $statusCodes
     * @return array{rows: list<array<string, mixed>>, count: int}
     */
    public function inStatusBetween(array $statusCodes, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit): array
    {
        if ($statusCodes === []) {
            return ['rows' => [], 'count' => 0];
        }
        $params = [$statusCodes, $from->format('Y-m-d'), $to->format('Y-m-d')];
        $types  = [ArrayParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
        $where  = ' WHERE o.status_code IN (?) AND o.order_date >= ? AND o.order_date <= ? AND o.deleted_at IS NULL';
        $rows   = $this->connection()->fetchAllAssociative(
            "SELECT o.id, o.number, o.order_date, o.status_code, c.kind, c.company, c.first_name, c.last_name FROM sales_order o JOIN contact c ON c.id = o.contact_id{$where} ORDER BY o.order_date, o.number LIMIT " . max(1, $limit),
            $params,
            $types
        );
        $count = (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM sales_order o JOIN contact c ON c.id = o.contact_id{$where}", $params, $types);

        return ['rows' => $rows, 'count' => $count];
    }

    /** @return array{0: string, 1: list<mixed>, 2: list<mixed>} WHERE clause (with its leading space, or ''), parameters, types */
    private function searchWhere(OrderSearch $search): array
    {
        $where  = [];
        $params = [];
        $types  = [];
        $add = static function (string $sql, mixed $value, ParameterType|ArrayParameterType $type = ParameterType::STRING) use (&$where, &$params, &$types): void {
            $where[]  = $sql;
            $params[] = $value;
            $types[]  = $type;
        };
        // A deleted order (ADR-054 decision 14) is kept but hidden — only the tab «Gelöscht» shows it.
        $where[] = $search->deleted ? 'o.deleted_at IS NOT NULL' : 'o.deleted_at IS NULL';
        if ($search->statusCodes !== null) {
            if ($search->statusCodes === []) {
                $where[] = '1 = 0';   // a view no status falls into
            } else {
                $add('o.status_code IN (?)', array_values($search->statusCodes), ArrayParameterType::STRING);
            }
        }
        if ($search->number !== null) {
            $add('o.number = ?', $search->number, ParameterType::INTEGER);
        }
        if ($search->dateFrom !== null) {
            $add('o.order_date >= ?', $search->dateFrom);
        }
        if ($search->dateTo !== null) {
            $add('o.order_date <= ?', $search->dateTo);
        }
        // «Leistung»: the order's day or period overlaps the searched day / month / year.
        if ($search->serviceFrom !== null && $search->serviceTo !== null) {
            $add('o.service_from <= ?', $search->serviceTo);
            $add('COALESCE(o.service_to, o.service_from) >= ?', $search->serviceFrom);
        }
        if ($search->name !== null) {
            $like = '%' . addcslashes($search->name, '\\%_') . '%';
            $where[] = "(c.company LIKE ? OR c.last_name LIKE ? OR c.first_name LIKE ? OR CONCAT(c.last_name, ' ', c.first_name) LIKE ? OR CONCAT(c.first_name, ' ', c.last_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
            array_push($types, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING);
        }
        if ($search->subject !== null) {
            $add('o.subject LIKE ?', '%' . addcslashes($search->subject, '\\%_') . '%');
        }
        if ($search->amount !== null) {
            $add('o.total_gross = ?', $search->amount);
        }
        if ($search->invoiceNumber !== null) {
            $add('i.number = ?', $search->invoiceNumber, ParameterType::INTEGER);
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $types];
    }

    /**
     * The distinct «Betreffname» labels the orders carry (owner 2026-10-21) — suggestions of the
     * order window's `<datalist>`, at most $limit, alphabetical.
     *
     * @return list<string>
     */
    public function subjectLabelsInUse(int $limit): array
    {
        return array_map('strval', $this->em()->createQueryBuilder()
            ->select('DISTINCT o.subjectLabel')
            ->from(SalesOrder::class, 'o')
            ->where('o.subjectLabel IS NOT NULL')
            ->orderBy('o.subjectLabel', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
