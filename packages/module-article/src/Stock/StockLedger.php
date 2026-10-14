<?php

namespace Z77\Module\Article\Stock;

/**
 * THE stock seam (plan §8.1, ADR-043 decisions 9–14 with the addendum 2026-10-14, owner O5:
 * «Lagerbuchung nur an einem Ort, nur ein Code, immer statusabhängig»). The one write path of
 * every stock quantity (ADR-040 decision 8): no controller, repository, import, form or run
 * touches a balance or a reservation any other way.
 *
 * The contract a SOURCE (module-order) relies on:
 *
 *   - it sends the COMPLETE target state of ONE source document every time — every article line
 *     with the quantities it should hold now (`reserved`, `consumed`), on every status change AND
 *     every position change (add / edit / delete). One method, one shape;
 *   - the ledger books only the DIFFERENCE against its journal (decision 12): a repeated call
 *     books nothing (idempotent by construction); what the journal holds for the source and the
 *     request no longer names (a deleted line, a line whose variant changed — keyed by line AND
 *     variant) is brought to zero;
 *   - QUANTITIES cross the boundary, never a status: article never knows `OrderStatus`, the
 *     source never reads the stock flag — the ledger decides per variant whether stock is kept
 *     (`stock_relevant`), a non-stock variant counts as target zero;
 *   - a change that would bring CONSUMED stock back is refused with
 *     {@see StockReasonRequiredException} unless the request carries a {@see StockConfirmation}
 *     (reason + confirmed — decision 14);
 *   - it JOINS the caller's open unit of work (decision 13) and never commits; it never posts
 *     (decision 18).
 *
 * Selection: {@see StockLedgers::default()}. P7 slim: {@see PendingStockLedger} (validates,
 * books nothing). P7b: the journal implementation behind the same interface — P7b changes
 * article only.
 */
interface StockLedger
{
    /**
     * Bring ONE source document to the given target state; books only the difference.
     *
     * @throws StockReasonRequiredException consumed stock would come back and the request carries no confirmation
     */
    public function bringTo(StockRequest $request): StockResult;
}
