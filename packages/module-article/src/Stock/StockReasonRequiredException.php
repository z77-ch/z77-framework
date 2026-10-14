<?php

namespace Z77\Module\Article\Stock;

/**
 * The request would bring CONSUMED stock back into the balance (a mistaken «geliefert», or
 * «erledigt ohne Lagerbuchung» after delivery) and carries no {@see StockConfirmation} (ADR-043
 * decision 14). Nothing was booked; the source's whole unit of work rolls back. The source's
 * screen re-opens its confirm with a mandatory reason field and resubmits with the confirmation
 * (plan §8.1) — never silently.
 *
 * Thrown by the journal ledger of P7b; the pending ledger of P7 slim never refuses.
 */
final class StockReasonRequiredException extends \RuntimeException
{
    /** @param list<string> $lineRefs the source lines whose consumed stock would come back */
    public function __construct(public readonly array $lineRefs)
    {
        parent::__construct('Consumed stock would come back for line(s) ' . implode(', ', $lineRefs) . ' — a reason and a confirmation are required');
    }
}
