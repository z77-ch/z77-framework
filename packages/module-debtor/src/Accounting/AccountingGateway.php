<?php

namespace Z77\Module\Debtor\Accounting;

/**
 * The accounting port (plan §6.6, ADR-040 decision 5): the ONE way the
 * receivables side reaches a bookkeeping. `InvoicingService::finalize()`
 * hands a {@see PostingRequest} in and gets back where it went — a string
 * the document stores as its ledger reference — or null when the
 * installation keeps its books elsewhere.
 *
 * Two implementations ship: {@see LedgerAccountingGateway}, the default,
 * delegating to module-financial's `LedgerService::post()`; and
 * {@see NullAccountingGateway} for an installation without z77 bookkeeping.
 * Which one runs is configuration — `debtorConfig → accountingGateway`, a
 * class name (the `memberConfig` hook pattern, ADR-038), read by
 * {@see AccountingGateways::fromConfig()}.
 *
 * The contract every implementation keeps:
 *
 *   - it JOINS the caller's unit of work and never opens or commits one
 *     (ADR-040 decision 7): the state change of the document and the
 *     posting commit together or not at all;
 *   - the request carries an idempotency key and an opaque origin; the same
 *     key with the same content must not post twice;
 *   - a refusal is {@see AccountingRefusedException}, and it ENDS the unit of
 *     work — the caller lets it propagate and never retries inside
 *     (`financial.md`, the race contract: a refusal or a unique-index
 *     failure can arrive after a number was drawn; the rollback gives it
 *     back). A retry is a new unit of work.
 *
 * Every class implementing it takes `(UnifiedEntityManager $em, string
 * $actor)` — what the factory hands over; an implementation that needs
 * neither ignores them.
 */
interface AccountingGateway
{
    /**
     * @return string|null where the posting went, as the document stores it (`{fiscal-year}/{number}`
     *                     for the ledger); null = nothing was booked here on purpose (no bookkeeping)
     * @throws AccountingRefusedException the bookkeeping refused — the unit of work must end
     */
    public function post(PostingRequest $request): ?string;
}
