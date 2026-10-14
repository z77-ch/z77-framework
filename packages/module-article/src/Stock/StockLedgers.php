<?php

namespace Z77\Module\Article\Stock;

use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Which {@see StockLedger} a source uses when it is not handed one (plan §8.1). A source's service
 * takes an OPTIONAL ledger in its constructor — the harness injects a spy or a refusing double
 * (the `AccountingGateway` model) — and asks here otherwise.
 *
 * P7 slim: {@see PendingStockLedger}. P7b returns the journal ledger, built on $em (it joins the
 * caller's unit of work, ADR-043 decision 13) and stamping $actor on its movements. The signature
 * is the P7b one already, so no caller changes then.
 */
final class StockLedgers
{
    public static function default(UnifiedEntityManager $em, string $actor): StockLedger
    {
        return new PendingStockLedger();
    }
}
