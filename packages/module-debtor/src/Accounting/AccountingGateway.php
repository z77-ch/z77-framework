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

    /**
     * CHANGE a posting of this source in place — the entry under the
     * request's idempotency key becomes what $request says now (ADR-042
     * addendum 2026-10-06: a generated entry is changed only by its
     * source, logged by the bookkeeping, frozen in the journal screen).
     * Same contract as {@see post()}: joins the unit of work, a refusal ends
     * it. The bookkeeping decides whether the period still allows it.
     *
     * @return string|null where the entry is (unchanged by an amend); null without bookkeeping
     * @throws AccountingRefusedException the bookkeeping refused — period closed, entry frozen, account unknown
     */
    public function amend(PostingRequest $request): ?string;

    /**
     * REMOVE a posting of this source — the entry under $idempotencyKey of
     * source $sourceType goes, its number stays a documented gap. Nothing
     * under the key (no bookkeeping, already gone) is not an error.
     *
     * @throws AccountingRefusedException the bookkeeping refused — period closed, entry frozen
     */
    public function retract(string $idempotencyKey, string $sourceType): void;
}
