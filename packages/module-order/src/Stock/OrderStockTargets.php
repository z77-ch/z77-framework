<?php

namespace Z77\Module\Order\Stock;

use Z77\Module\Article\Stock\StockLineTarget;
use Z77\Module\Debtor\Entities\LineType;
use Z77\Module\Order\Entities\OrderStatus;
use Z77\Module\Order\Entities\SalesOrderLine;

/**
 * The TARGET quantities of an order's lines under a status (plan §8.1, ADR-043 decision 11 with
 * the addendum 2026-10-14, owner O5) — pure, the harness checks it offline (section T):
 *
 *   - every ARTICLE line (a variant referenced) becomes one {@see StockLineTarget}: `reserved` =
 *     the quantity if the status `reservesStock`, `consumed` = the quantity if it `consumesStock`,
 *     otherwise `0.000`; a NEGATIVE quantity stays negative (an inflow — a return);
 *   - a LUMP-SUM article line has no quantity: it counts as ONE unit (decided at build 2026-10-14 —
 *     a lump-sum article is a valid article, and leaving it out would let a stock-relevant one
 *     drift silently);
 *   - free and text lines are NOT sent (they move no stock);
 *   - the line reference is the line ID — stable across saves (`OrderService::update()` rewrites
 *     lines in place), so the journal (keyed by line AND variant) books only real differences.
 *
 * The order never reads the article's stock flag — the ledger decides per variant (plan §8.1).
 */
final class OrderStockTargets
{
    /**
     * @param list<SalesOrderLine> $lines the order's COMPLETE current lines (ids assigned)
     * @return list<StockLineTarget>
     * @throws \LogicException an article line without an id (not flushed yet) or without a variant id
     */
    public static function of(array $lines, OrderStatus $status): array
    {
        $targets = [];
        foreach ($lines as $line) {
            if (!$line->isArticleLine()) {
                continue;
            }
            if ($line->getId() === null || $line->getVariant()?->getId() === null) {
                throw new \LogicException('An article line reaches the stock seam with its id and its variant id — flush first');
            }
            $quantity = self::quantityOf($line);
            $targets[] = new StockLineTarget(
                (string) $line->getId(),
                (int) $line->getVariant()->getId(),
                $status->isReservesStock() ? $quantity : StockLineTarget::ZERO,
                $status->isConsumesStock() ? $quantity : StockLineTarget::ZERO,
            );
        }

        return $targets;
    }

    /** The line's quantity in stock terms — a lump-sum article line counts as one unit. */
    public static function quantityOf(SalesOrderLine $line): string
    {
        if ($line->type() === LineType::LumpSum) {
            return '1.000';
        }

        return StockLineTarget::quantity((string) $line->getQuantity());
    }
}
