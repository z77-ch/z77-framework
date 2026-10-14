<?php

namespace Z77\Module\Order\Repositories;

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

    public function findByNumber(int $number): ?SalesOrder
    {
        return $this->findOneBy(['number' => $number]);
    }
}
