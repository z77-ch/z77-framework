# ADR-040 — Business module cut: order, debtor, financial, vat

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum 2026-10-14 (P7 slim: issue and book in one unit of work, «gebucht» set by order — approved by the owner)
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
   the books. *(Amended — a draft has no number and can be deleted: addendum 2026-10-13; a source that is its own draft issues and books in one unit of work: addendum 2026-10-14.)*
4. **order posts nothing** and knows no ledger. It builds an invoice draft and hands it to
   `InvoicingService`.
5. **debtor reaches financial only through a port**, `AccountingGateway`. The default adapter calls
   `LedgerService`; `NullAccountingGateway` serves installations with external bookkeeping. The adapter
   is the only debtor class that knows financial; the package only *suggests* `module-financial`.
6. **Payment state is asked, never pushed.** order asks debtor for an order's payment state through a
   read port, the mirror image of `AccountingGateway`. debtor never writes into order. `invoiced`,
   `part-paid` and `paid` are therefore **not** order statuses; the open item is the single truth.
   *(Sharpened — «gebucht», set by order itself at its booking print, is allowed: addendum 2026-10-14.)*
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

## Addendum 2026-10-13 — a draft has no number, and a draft can be deleted (owner decisions)

Two owner decisions amend decision 3; its original text above stays as written, and where it and
this addendum differ, the addendum applies.

1. **«Re-issued under the same number» is obsolete since 2026-10-10.** A document in `invoicing` is a
   **draft without a number**; the number (`YYMMnnnn`) is drawn at the **booking print**
   (`InvoicingService::finalize()`), which numbers, posts and prints in one unit of work
   ([`debtor.md`](../topics/debtor.md) → «The booking print»). What decision 3 protects stays: an
   invoice is posted on `finalize()`, never on `invoice()`, and while it is `invoicing` it can be
   edited (`reinvoice()`) without touching the books.
2. **A draft can be discarded** (2026-10-13, review 2026-10-13 H3): `InvoicingService::discard($id,
   $version)` deletes a document in `invoicing` with its lines and tax rows — a real `DELETE`, under
   a row lock with the version the caller saw. The correction principle (plan §1: «never an edit,
   never a deletion») binds what was **booked**: a draft has no number, nothing was posted, and
   nothing references it (a credit note, a payment, a dunning notice all need a final document), so
   there is nothing to correct and no gap. A booked document is still never deleted — its
   correction is a credit note. Why it is needed: a forgotten draft blocked the year close
   (`InvoicingInProgressCheck`) forever and kept the contact's «Debitor» role from being switched
   off.

Also decided the same day (review H5, owner): the booking print takes the payment part again from
the payment target and the mandator as they read then, and **refuses** a document whose payment
part would not be printable (`QrBill::problems()`) — the whole batch, before any number is drawn.
A draft's detail shows that re-taken part, so it lists exactly what the booking print refuses.

## Addendum 2026-10-14 — a source that is its own draft issues and books in one unit of work (approved by the owner)

Approved by the owner on 2026-10-14 («Alles ok los») with the P7 slim build plan
([`p7-order-plan.md`](../03-development/p7-order-plan.md) §2, §15). It amends decisions 3 and 6; their
original text above stays as written, and where it and this addendum differ, the addendum applies.

1. **Issue AND book in one unit of work — decision 3, second sanctioned form.** A source whose own
   record IS the draft (the order, owner decision O1: order and invoice are two records, the office
   edits only the order) may hand its drafts to `InvoicingService::invoiceAndFinalize()`: debtor
   issues and books them inside the CALLER's unit of work, without a stored draft in `invoicing`.
   The first form stays for every source with an editable invoice draft (the manual invoice, the
   credit note): `invoice()` → `finalize()`. What decision 3 protects holds in both forms: nothing
   is posted before the booking print, and a booked document is corrected only by a credit note.
2. **Decision 6 sharpened.** Forbidden as order statuses are the states the RECEIVABLES side would
   write or that track the payment — `part-paid`, `paid` (and an `invoiced` that debtor sets). The
   order status «gebucht» (flag `invoiceBooked`) is allowed: it is set by ORDER ITSELF, in the same
   unit of work as its booking print, after `invoiceAndFinalize()` returned. Debtor still never
   writes into order and never names it; the payment state is still asked through the read port
   (`PaymentStates`), never pushed.
