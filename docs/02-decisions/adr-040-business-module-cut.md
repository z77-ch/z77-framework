# ADR-040 — Business module cut: order, debtor, financial, vat

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md))
**Date:** 2026-09-21

---

## Context

wdv-6.2.2 has order processing, invoicing and bookkeeping in one knot: the order module posts to the
ledger, the receivables side writes order statuses, and re-invoicing deletes and re-posts journal
entries. The analysis is in
[`order-financial-review-2026-09-18.md`](../03-development/order-financial-review-2026-09-18.md); the
owner's decisions D1–D8 are in §1 of the plan.

z77 rebuilds this as framework modules — public, MIT, industry-neutral (D1). The cut has to hold for
four accounting clients and the developer's own books, each migrated with full history (D7), and it
has to stay open for applications not built yet (subscriptions, shipping, a shop — plan §13) without
being shaped by any one of them.

## Decision

1. **Four business modules plus two master-data modules**, each its own package (plan §2):
   `module-vat`, `module-contact`, `module-article`, `module-financial`, `module-debtor`,
   `module-order`. Dependencies point one way:

   ```text
   module-order ──→ module-debtor ··→ module-financial      ··→ = through a port, suggest only
         │                │                  │
         ├────────────────┴──→ module-vat ←──┘
         └──→ module-contact ←──┘  (debtor → contact as well)
   ```

2. **financial knows no module.** It accepts postings from **any** source through one entry,
   `LedgerService::post(PostingRequest)`. The source appears only as an **opaque origin**
   (`sourceType` + `sourceRef`, strings financial does not interpret) and an **idempotency key**; a
   repeated key returns the existing entry instead of posting twice.
3. **Invoicing lives in debtor.** `InvoicingService` is the single entry for every source — order,
   manual invoice, later contracts and fees. An invoice is posted on `finalize()`, not on
   `invoice()`: while it is `invoicing` it can be re-issued under the same number without touching
   the books.
4. **order posts nothing** and knows no ledger. It builds an invoice draft and hands it to
   `InvoicingService`.
5. **debtor reaches financial only through a port**, `AccountingGateway`. The default adapter calls
   `LedgerService`; `NullAccountingGateway` serves installations with external bookkeeping. The adapter
   is the only debtor class that knows financial; the package only *suggests* `module-financial`.
6. **Payment state is asked, never pushed.** order asks debtor for an order's payment state through a
   read port, the mirror image of `AccountingGateway`. debtor never writes into order. `invoiced`,
   `part-paid` and `paid` are therefore **not** order statuses; the open item is the single truth.
7. **One use case, one transaction.** Writes that belong together — invoice, open item and posting on
   `finalize()`; status change and stock movement — commit together or not at all. `LedgerService`
   never commits; the caller owns the transaction (mechanism: ADR-039).
8. **One write path per stock quantity** (plan §1). The ledger balance changes only through
   `LedgerService::post()`, a stock balance only through the stock service in `module-article`.
   Neither knows its callers.
9. **The one sanctioned second write path is the wdv import.** It writes `Invoice`, `OpenItem`,
   `Payment` and `Allocation` directly and posts nothing, because the journal entries are imported
   separately and must reconcile *as booked* — going through `finalize()` would post everything a
   second time. It is bounded to staging-based import (ADR-032) with `origin = wdv`. Stock opening
   balances do **not** need this exception; they go through the stock correction path with reason
   `opening balance`.
10. **An order must be creatable from outside** — by a service with an idempotency key, not only by a
    person at a screen. That seam is what subscriptions, a shop or an API build on (plan §13, Q7).

## Reasoning

- **A ledger that knows its sources cannot outlive them.** With an opaque origin, a manual invoice, an
  order, a fee run or a future subscription post the same way, and financial never changes for a new
  source.
- **Invoicing belongs where the receivable is born.** The invoice creates the open item; putting it in
  order would make every non-order invoice (manual, fees) depend on order.
- **Posting on `finalize()` removes wdv's delete-and-repost.** Clients notice errors after printing;
  re-issuing an unposted invoice produces no reversal noise and no deleted entries.
- **Asking instead of pushing keeps one truth.** A status written from the receivables side and an
  open item can drift apart; a question asked of the open item cannot.
- **Naming the import exception makes it bounded.** Unnamed, it would be found in P5b and look like a
  violation of point 3 — or worse, be copied by the next import as precedent.

## Consequences

- A new posting source needs no change in financial — only a `PostingRequest` with its own origin.
- Installations without z77 bookkeeping run debtor with `NullAccountingGateway`; financial is not
  installed.
- The payment-state port is designed in P7 (it has no consumer before order exists). The order screen
  and its filters show the state as before.
- An invoice sitting in `invoicing` is not in the books yet. The close check (plan §5.3) **blocks** the
  VAT return and the period close while one exists, and a payment arriving for it stays unmatched until
  `finalize()`.
- Every module depends on the transaction port of ADR-039; a File-only installation cannot run these
  modules.
- The import exception is documented at the import code and in `import.md` when it is built (P5b).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| order posts directly (wdv) | Every other revenue source would need its own posting logic; the ledger would depend on order |
| Invoicing in order | Manual invoices and fees would depend on order; receivables would be split from the invoice that creates them |
| One `module-commerce` for all four | No installation with external bookkeeping could use order without the ledger; the dependency direction would be unenforceable inside one package |
| Payment state as order statuses set by debtor | Two truths about one amount; debtor would write into order against the dependency direction |
| Post on `invoice()`, reverse on re-invoice | Reversal noise for every correction after printing; what wdv does today by deleting |
| Import through `InvoicingService::finalize()` | Posts every migrated invoice a second time next to the imported journal |
