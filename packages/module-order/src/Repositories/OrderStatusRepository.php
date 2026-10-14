<?php

namespace Z77\Module\Order\Repositories;

use Z77\Module\Order\Entities\OrderStatus;
use Z77\Persistence\File\Repository\FileRepository;

/**
 * Convention repository for {@see OrderStatus}. `allInOrder()` sorts by `level`, then by id — the
 * order the list shows and the transition rule reads (plan §6); the file order is not
 * authoritative. Which status a step SELECTS (the initial one, the delivery-note target, the
 * booked one) is never decided here but in {@see \Z77\Module\Order\Services\OrderStatuses} — by
 * flag, never by code (ADR-043 consequence, harness N1).
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

    /** @return list<OrderStatus> ascending by level, then id */
    public function allInOrder(): array
    {
        $rows = $this->findAll();
        usort($rows, static fn(OrderStatus $a, OrderStatus $b) => [$a->getLevel(), $a->getId() ?? PHP_INT_MAX] <=> [$b->getLevel(), $b->getId() ?? PHP_INT_MAX]);

        return array_values($rows);
    }
}
