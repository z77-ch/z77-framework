# ADR-042 — Ledger and money: integer money, generated vs. manual entries, close states

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum 2026-09-30 (owner decisions on the close, below); addenda 2026-10-06 (generated entries corrected by their source) and 2026-10-22 (the year opening); addendum 2026-11-24 (the VAT return: `vat-settled` dropped, the freeze restored, the correction path, lifting by reversal)
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

## Addendum 2026-09-30 — closing a fiscal year, the admin reopen, VAT-filed periods (owner decisions)

Three owner decisions of 2026-09-30 amend the close states (decisions 10–11):

1. **Only the whole year is closed**, in order. Closing a fiscal year sets every period of it
   `closed` (no month-by-month close); a year closes only after the year before it. Decision 12
   (the year-end) is unchanged — the carry-forward still follows.
2. **An admin can reopen a closed year**, with a mandatory reason, in reverse order (only the latest
   closed year). Every close and every reopen is written to a protocol (who, when, why — the
   warnings the closer confirmed). This amends «a period moves one way» of decision 10: `closed` →
   `open` exists, for the whole year, by an admin, traceable. Built in P5 part 1
   (`FiscalYearCloseService`, [`financial.md`](../topics/financial.md)).
3. *(Reversed by the owner on 2026-11-24 — see the addendum of that date, point 3; the text stays
   for the record.)* **VAT-filed periods stay open for postings** (for P5 part 2, not built): a posting into a period
   whose VAT return is filed is allowed — also a line with a tax code — and the LAST VAT return of the
   year computes the adjustment. This replaces the freeze of tax lines at `vat-settled` in decision
   10 and in the consequence «A manual entry changed after the VAT return that carries a tax code is
   refused». Until part 2 is built, the ledger keeps refusing tax lines in a `vat-settled` period —
   no period reaches that state before the return exists.

Decision 11 (the close check) is built as the open-work registry of ADR-039 decision 15 (scope
`period-close`); the «`PeriodCloseCheck`» of its wording is that registry's check interface.

## Addendum 2026-10-06 — a generated entry is corrected by its source, in place (owner decision)

Decision 7 said a generated entry is never edited or deleted, only reversed. The owner changed
that when the first settlements were recorded («manuelle Buchungen sind tippfehleranfällig —
der Sachbearbeiter muss korrigieren können, ohne komplizierte Stornobuchungen»):

1. **A generated entry is changed or removed ONLY by the module that posted it**, through
   `LedgerService::amend($idempotencyKey, $new)` and `LedgerService::retract($idempotencyKey,
   $sourceType)`, inside that module's unit of work. The source names what it posted by the
   idempotency key, never by a number; a request from another `sourceType` is refused.
2. **The journal screen never edits a generated entry** — it stays shown-only there, as before.
   The bookkeeper corrects a manual entry; a settlement is corrected in the debtor module, which
   changes the Fibu posting with it.
3. **Every amend and retract writes an `EntryChange`** (who, when, before / after), exactly like
   a manual edit or delete — the change log is the traceability the GeBüV asks for. A retracted
   entry leaves its number as a documented gap (decision 9 unchanged).
4. **The same period rules as a manual edit**: `closed` refuses, `vat-settled` refuses when a
   tax line is involved, a date in another fiscal year is refused (retract and post anew). An
   entry that was reversed, or is a reversal, is frozen — the reversal pair stays the correction
   of record for anything a source no longer owns the shape of.
5. **`reverse()` stays** for the cases a module chooses it (a correction after the period
   closed, a counterpart the books should show). Decision 8 (the correction principle) holds
   for documents and orders; for a SETTLEMENT the owner prefers the corrected posting over a
   storno pair.

First consumer: module-debtor's `PaymentService::update()` / `delete()` (P4 part 1).

## Addendum 2026-10-22 — the year opening (owner decisions)

Decision 12 said «balance-sheet balances carry forward as the opening entry; the result goes to
retained earnings». The owner decided how, when the first z77 opening (2026 of the migrated wdv
books) came due:

1. **One generated opening entry per fiscal year**, dated its first day: every balance-sheet
   account with a non-zero closing balance of the previous year, opened against the contra
   account **9100 «Eröffnungsbilanz»**, which nets to zero.
2. **The previous year's result goes to 2979 «Jahresgewinn/-verlust»** (Swiss KMU), not straight
   to retained earnings; its appropriation (2970 Gewinnvortrag, reserves) is booked by hand. Both
   accounts are the company's settings on the mandator record (`opening-balance`,
   `annual-result`), like the VAT and debtor accounts.
3. **Closing and opening are two measures** («Sperren und neu eröffnen sind 2 unterschiedliche
   Massnahmen»): the opening has its own button on the TARGET year and is not part of the close.
   It **can be repeated while the target year is not closed** — the tax administration may require
   a change in the old year, after which the opening is re-created. A re-run replaces the opening
   IN PLACE through the source-correction path of the addendum 2026-10-06 (`amend()`: same
   number, logged in the change log); a closed target refuses. An opening on a predecessor that is
   still open is allowed and marked provisional (the agent's reading, to be confirmed).
4. **The closing balance is the year's own entries, its opening included** — an imported opening
   (wdv's «EB:YYYY») counts like a generated one; earlier years are never added up. A year whose
   contra account already carries entries the generator did not make (an imported or manual
   opening) is refused, so a year is never opened twice.
5. Collective accounts flagged «nur durch Module bebuchbar» are opened like any other — the
   opening is a generated posting.

Built in `YearOpeningService` ([`financial.md`](../topics/financial.md) «P5 — the year opening»,
FIN-OPENING-001).

### Addendum 2026-10-22 b — one opening entry per account (owner decision, same day)

Point 1 above is refined: the opening is **one two-line entry per account** against 9100 — the
account with its closing balance, 9100 with the same amount on the other side — not one compound
entry. The owner, on the statement of 9100 showing «div.» on both lines of the compound entry: «wie
die EB:-Buchungen von wdv». The result on 2979 is one entry with 2979's own carried balance plus
the result. Each entry has the key `opening:{code}:{account}` and the origin `opening` / the code;
a re-run replaces the SET in one unit of work — amends the entries whose amount changed (same
number), retracts those of accounts without a balance, posts those of new accounts, leaves the
rest. Point 3's in-place rule holds per entry.

## Addendum 2026-11-24 — the VAT return in the ledger (owner decisions)

Decided before the VAT return (P5 part 2) is built, and corrected the same day after a review
against the VAT law, the daily handling and the build effort
([`vat-return-review-2026-11-24.md`](../03-development/vat-return-review-2026-11-24.md)); the
VAT-model side is the addendum of the same day in [ADR-041](adr-041-vat-model.md).

1. **One button posts the return.** The preview can be read and printed any number of times and
   writes nothing; it already shows the findings of the open-work registry (scope `vat-return`),
   each with the way to the screen that clears it. «MWST buchen» refuses on a blocking finding,
   has the warnings confirmed (the fingerprint and the protocol of the year close), posts the
   settlement entry through `LedgerService` — the VAT owed and the input tax of the return against
   the settlement account, the Rappen difference to the VAT-return rounding account, no tax code on
   its lines — and locks the return. Posted counts as filed: there is no second state; the screen
   says to file in the portal first, and the return may carry the date it was filed.
   **No figure of a return is editable by hand.**
2. **«Settled» is read from the posted returns, not from the period.** A period is settled when a
   posted return covers it. *This amends decision 10:* the period state `vat-settled` is dropped;
   a period is `open` or `closed`. One source instead of two, and reopening a year cannot lose the
   VAT state. The build owes two things the state gave for free: the lock that keeps a posting and
   a return from racing (today the period state under a share lock), and one public answer of
   financial «is this date settled?» for the modules that read the state today.
3. **A settled period refuses every posting with a tax line** — created, changed, deleted, or
   generated by a module; a line without a tax code stays possible until the year is closed.
   *This REVERSES point 3 of the addendum 2026-09-30* (postings into filed periods allowed, the
   last return of the year computes the adjustment): the freeze of decision 10 holds again, and
   with it point 4 of the addendum 2026-10-06 and ADR-061 decision 21 stand as written.
   What follows for the user is NOT one rule but three:
   - **A genuinely later event belongs to the running period** — an invoice issued later, a credit
     note, a discount, a loss, input tax claimed later. Debtor therefore refuses to ISSUE a
     document dated into a settled period (the draft warns), and creditor offers «Vorsteuer
     nachholen» (ADR-041 addendum, point 9). A payment correction or a bank take-back that would
     change a settled period is offered as «reverse and re-post in the running period».
   - **An error or an omission in a settled period goes the correction path** of point 4 — the law
     wants it filed apart from the ordinary returns (Art. 129 MWSTV).
   - **A return posted by mistake and not yet filed is lifted** (point 5).
4. **The correction path.** An admin posts a «Korrekturbuchung zu Periode X» — the only posting
   with a tax line a settled period accepts, marked as a correction and never part of an ordinary
   return. From the corrections of a period z77 builds a **correction return**: per form field the
   difference between the ledger now and the posted return, its own settlement entry, and the
   statement of what to file — a correction return for the period while the tax year runs, the
   yearly reconciliation (Art. 72 MWSTG) after its end. Year-end entries with a tax code for a
   settled period go this way too. The declared figures per field are stored on every posted
   return; the correction is «recomputed − declared», no entry is picked by hand.
5. **Lifting is for a return posted by mistake.** A visible reversal entry — the settlement entry
   and its reversal both stay in the journal — and the return is created anew (the new settlement
   entry carries a revision in its key). Only the LATEST return, only an admin, with a mandatory
   reason, written to a protocol; one action may lift several from the latest backwards. The year
   must be open. *An explicit exception to the addendum 2026-10-06* (a generated entry is
   corrected by its source in place, with a change log): a tax return is not a typing error, and
   an auditor reads a reversal pair, not a change log.
6. **The year close is blocked while a VAT period of the year is not settled,** for a liable
   mandator: financial registers this as its own check under `period-close`; the finding names the
   period. A settlement period may span two fiscal years, and with the yearly rhythm the close
   waits only for the periods that END within the year.
7. **The VAT accounts accept computed tax lines only.** The accounts for VAT owed and for input tax
   carry «Nur durch Module bebuchbar», refined for them: per entry, the lines on each VAT account
   must equal the tax of the coded lines that map to it — a freely typed amount is refused, an
   uncoded amount on a VAT account being exactly what makes the ledger differ from the return.
   - The Sammelbuchung therefore writes the tax line itself, as the one-line form does; the
     bookkeeper no longer types it.
   - A «tax only» line (ADR-041 addendum, point 9) is a coded line ON the VAT account and passes.
   - Before «Abrechnung ab» the accounts are free (opening balances, earlier years).
   - The account pickers of the bank, the receipts and the creditor invoices do not offer them —
     the flag stops manual entries only.
   - The settlement account stays open: the bank posts the payment to the tax authority and the
     instalments onto it.
8. **Roles:** posting a return needs the finance permission of the module (a later bookkeeper role
   included); a correction entry and lifting are pinned to the admin, like the reopen of a year.
