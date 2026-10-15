<?php

namespace Z77\Module\Order\Repositories;

use Z77\Module\Order\Entities\OrderStatus;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see OrderStatus}. `allInOrder()` sorts along the ladder — phase,
 * then the position within it, then id — the order the list shows and the choices offer; the file
 * order is not authoritative. Which status a step SELECTS (a new order's, the one an action sets)
 * is never decided here but in {@see \Z77\Module\Order\Services\OrderStatuses} — by PHASE and its
 * default, never by code (ADR-043, harness N1).
 */
class OrderStatusRepository extends FileRepository
{
    /** The row carrying $code (an order's own status, a target the user picked), or null. */
    public function findByCode(string $code): ?OrderStatus
    {
        $code = OrderStatus::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        return $this->findOneBy(['code' => $code]);
    }

    /** @return list<OrderStatus> along the ladder: phase, position, id */
    public function allInOrder(): array
    {
        $rows = $this->findAll();
        usort($rows, [OrderStatus::class, 'compare']);

        return array_values($rows);
    }
}
