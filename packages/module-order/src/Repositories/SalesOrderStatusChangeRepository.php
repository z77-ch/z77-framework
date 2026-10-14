<?php

namespace Z77\Module\Order\Repositories;

use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderStatusChange;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * The status log of an order (plan §9.4 «Verlauf»). Doctrine-only.
 */
class SalesOrderStatusChangeRepository extends DoctrineRepository
{
    /** @return list<SalesOrderStatusChange> oldest first */
    public function historyOf(SalesOrder $order): array
    {
        return $this->em()->createQueryBuilder()
            ->select('c')->from(SalesOrderStatusChange::class, 'c')
            ->where('c.order = :order')->setParameter('order', $order)
            ->orderBy('c.id', 'ASC')
            ->getQuery()->getResult();
    }
}
