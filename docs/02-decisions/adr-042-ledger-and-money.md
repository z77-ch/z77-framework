# ADR-042 — Ledger and money: integer money, generated vs. manual entries, close states

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md))
**Date:** 2026-09-21

---

## Context

wdv-6.2.2 computes money in floats and compares floats strictly. Its ledger stores a journal entry
twice (header and `FmBooking` lines), deletes and re-posts entries when an invoice is re-issued, and
blocks the year-end on the state of orders. The findings are in
[`order-financial-review-2026-09-18.md`](../03-development/order-financial-review-2026-09-18.md).

`module-financial` is the one place where an amount becomes a booking. Four accounting clients and the
developer's own books will be migrated with full history and reconciled to the Rappen (D7), so the
rules for money and for what may change after posting must hold from the first entry.

## Decision

### Money

1. **`Money` in the kernel** (`shared`, pure PHP, ADR-019 Rule 2): `int $minor` + `string $currency`
   (ISO 4217), immutable. `add`, `subtract`, `multiply(int|string)`, `allocate(ratios)` (split without
   losing a Rappen), `roundTo(int $step)` (1 = 0.01, 5 = 0.05), comparison. **No float in the API** — a
   float argument is a `TypeError`.
2. **Percentages as integers** in hundredths of a percent; rounding half away from zero in integer
   arithmetic (commercial rounding; a negative tie −0.005 → −0.01, so a credit note mirrors its
   invoice — clarified 2026-09-21, owner; the earlier wording said «half-up»).
3. **Storage.** In the database, amounts are `DECIMAL(15,2)` — readable, and `SUM()` in SQL is exact.
   A Doctrine custom type in `persistence-doctrine` maps the decimal **string** to minor units, never
   through float. File-based entities store minor units as integers.

### The ledger

4. **Base currency only.** A journal line carries no currency, no foreign amount and no rate. A
   document may be issued in a foreign currency — amount and rate belong to that document — but it is
   posted converted; an exchange difference on payment is an ordinary posting to an exchange-difference
   account. No parallel valuation, no revaluation run, no currency dimension in any report.
5. **One entry, stored once.** A `JournalEntry` header (`number`, `date`, `text`, `kind`, `origin`,
   `idempotencyKey`, `reversalOf?`, created/changed by/at) and n ≥ 2 `JournalLine`s (`account`,
   `debit`, `credit` — exactly one > 0 — and `taxCode?`, `taxBase?`, `taxAmount?`, `text?`).
   **Σ debit = Σ credit** is checked in the domain before persist.
6. **One write path.** `LedgerService::post()` is the only way into the journal (ADR-040); it never
   commits, the caller owns the transaction (ADR-039). A repeated idempotency key returns the existing
   entry.
7. **Generated and manual entries.**
   - *Generated* (posted by a module): never editable, never deletable. Correction by reversal
     (`reverse()`), posted by the module that owns the source.
   - *Manual* (entered in financial): editable and deletable until the accounting close of their
     period. Every edit and delete writes an `EntryChange` (who, when, before/after), so «editable
     until the close» stays traceable in the sense of the GeBüV.
8. **The correction principle** (plan §1) ties the modules together: nothing that has been posted or
   consumed is corrected by deletion or by a special state — always by a counterpart of the same shape.
   A reversal against an entry, a credit note against an invoice, an order with negative quantities
   against an order. The only deletion the ledger allows is the manual entry before the close, and it
   is logged.
9. **Numbering** per fiscal year from `NumberRange` (ADR-039). A deleted manual entry leaves a gap,
   documented by its change log entry.

### Close states

10. **A period moves one way:** `open` → `vat-settled` → `closed`.
    - `open`: generated entries post; manual entries can be created, edited, deleted.
    - `vat-settled`: the VAT return is posted and filed. The return and every line **with a tax code**
      in the period are frozen; manual entries without a tax code stay editable until the close.
    - `closed`: nothing changes. A correction is a reversal in an open period.
11. **The close check.** Before the VAT return and before the accounting close, financial asks every
    registered `PeriodCloseCheck` «anything open for this period?»; each finding is `blocking` or
    `warning`. financial knows none of the checks — modules register them (the open-work check registry
    of ADR-039). debtor blocks on invoices still `invoicing` and on unbooked payments. The ledger's own
    refusal to post into a `vat-settled` or `closed` period stays the last line of defence.
12. **The year-end** is blocked by an `open` period of the year — never by the state of an order.
    Balance-sheet balances carry forward as the opening entry; the result goes to retained earnings.

## Reasoning

- **Integer money** removes a whole class of wdv bugs by construction; a float that cannot enter the
  API cannot be compared strictly or rounded twice. `DECIMAL` in the database keeps SQL sums exact and
  the tables readable for a person with a query tool.
- **Base currency only** matches how every installation books today and keeps every report
  single-dimension. A foreign invoice is a property of the document, not of the ledger.
- **Generated entries are immutable** because their source owns them: a module that posted an invoice
  is the only one that knows how to correct it — by reversal. Manual entries are the bookkeeper's own
  work and stay editable until the close, which is how bookkeeping is done in practice.
- **Freezing only tax lines at `vat-settled`** protects what was filed with the ESTV and nothing more;
  the bookkeeper can still fix a transfer between two balance-sheet accounts until the close.
- **Asking the modules at the close** keeps financial free of any module while no invoice in
  `invoicing` can slip past a VAT return.
- **Blocking the year-end on periods, not orders,** removes a coupling wdv has: the ledger's year has
  nothing to do with whether an order is done.

## Consequences

- `Money` lands in the kernel in P1; every module uses it, and the Doctrine custom type ships with
  `persistence-doctrine`.
- `JournalEntry` header and lines are one entry; the wdv double storage is imported from the **lines**
  (plan §8).
- Migrated past years are set `closed` after acceptance.
- A manual entry changed after the VAT return that carries a tax code is refused; the bookkeeper posts a
  correction in an open period instead.
- The wdv float bugs become test cases for `Money` and `VatCalculator`.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Float amounts (wdv) | Strict float compares and rounding drift; the source of the wdv findings |
| Integer minor units in the database | Unreadable to a person with a query tool; `DECIMAL` is exact as well |
| Foreign currency in the ledger | Parallel valuation, revaluation runs and a currency dimension in every report — for invoices no installation posts that way |
| All entries immutable, manual ones included | Against practice; a bookkeeper corrects own entries until the close, and the change log keeps that traceable |
| Delete and re-post on correction (wdv) | Loses what happened; the correction principle demands a counterpart |
| Header and lines stored twice (wdv) | Two truths about one entry |
| Year-end blocked by order state (wdv) | Couples the ledger to a module it must not know |
