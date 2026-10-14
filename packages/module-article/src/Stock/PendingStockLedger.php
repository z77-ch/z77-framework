<?php

namespace Z77\Module\Article\Stock;

/**
 * The stock ledger of P7 SLIM (plan §8.1): the seam exists, the journal does not yet (P7b). It
 * accepts every valid {@see StockRequest} — the request validated itself on construction —,
 * books NOTHING and NEVER refuses: no reason is ever demanded, because no consumed stock exists
 * that could come back. So every order write already makes its one call in the right place, and
 * P7b swaps this class for the journal ledger in {@see StockLedgers::default()} without touching
 * order (ADR-043 addendum 2026-10-14).
 */
final class PendingStockLedger implements StockLedger
{
    public function bringTo(StockRequest $request): StockResult
    {
        return StockResult::none();
    }
}
