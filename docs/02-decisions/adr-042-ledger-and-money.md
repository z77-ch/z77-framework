# ADR-042 — Ledger and money: integer money, generated vs. manual entries, close states

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum 2026-09-30 (owner decisions on the close, below); addenda 2026-10-06 (generated entries corrected by their source) and 2026-10-22 (the year opening); addendum 2026-11-24 (the VAT return: `vat-settled` dropped, the freeze restored, the correction path, lifting by reversal); addendum 2026-11-25 (posting and lifting on the screen) and addendum 2 of 2026-11-25 (the correction entry instead of a correction return, the lock of the VAT accounts, the yearly overview, the deploy)
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

Decided before the VAT return (P5 part 2) is built, and corrected the same day after three
reviews — the VAT law at its primary sources, the daily handling, the build effort, and concrete
cases calculated
([`vat-return-review-2026-11-24.md`](../03-development/vat-return-review-2026-11-24.md)); the
VAT-model side is the addendum of the same day in [ADR-041](adr-041-vat-model.md).

1. **One button posts the return.** The preview can be read and printed any number of times and
   writes nothing; it already shows the findings of the open-work registry (scope `vat-return`),
   each with the way to the screen that clears it. «MWST buchen» refuses on a blocking finding and
   has the warnings confirmed (the fingerprint and the protocol of the year close) — among them
   every line on a revenue account without a tax code, every credit line without a tax code on a
   fixed-asset account (a possible sale of an asset), every supplier voucher in a foreign
   currency or from a foreign address posted without VAT (a possible reverse charge), and an
   input code on a voucher for a service rendered before «Abrechnung ab». *(The last warning
   falls away and the foreign-voucher warning is built for creditor invoices only — addendum 2 of
   2026-11-25, point 9.)* Then it
   posts the settlement entry through `LedgerService` and locks the return.
   - The settlement entry is dated the last day of the period, carries no tax code, clears each
     VAT account by the CODED tax of the return *(and of the block of correction entries the
     return carries — addendum 2 of 2026-11-25, point 2)* against the settlement account, and posts the
     payable amount as declared; the Rappen difference goes to the VAT-return rounding account.
   - A gap between the coded tax and the movement of a VAT account is a BLOCKING finding — never
     something the rounding account absorbs.
   - Posted counts as filed: there is no second state; the screen says to file in the portal
     first, and the return may carry the date it was filed. *(The filed date is dropped — addendum
     2026-11-25, point 1.)*
   - **No figure of a return is editable by hand.**
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
     note, a correcting document, a discount, a loss, input tax claimed later. Debtor therefore
     refuses to ISSUE a document dated into a settled period (the draft warns), and creditor
     offers «Vorsteuer nachholen» (ADR-041 addendum, point 9). A payment correction or a bank
     take-back that would change a settled period is REFUSED with a sentence; the correction
     is an entry by hand in the running period (owner 2026-11-25 — this replaces the earlier
     wording «offered as reverse and re-post in the running period» and agrees with ADR-061
     decision 21; an automatic re-post may follow later as its own slice, it is not planned).
   - **An error or an omission in a settled period goes the correction path** of point 4 — the law
     wants it filed apart from the ordinary returns (Art. 129 MWSTV). *(Superseded by addendum 2
     of 2026-11-25, point 5: within the calendar year both ways are offered, across it the
     correction entry is the way z77 names.)*
   - **A return posted by mistake and not yet filed is lifted** (point 5).
4. *(Superseded whole by addendum 2 of 2026-11-25, points 1, 3, 6 and 8 — z77 posts no
   correction return, knows no channel, and a correction entry is never dated into a settled
   period; the text stays for the record.)*
   **The correction path.** An admin posts a «Korrekturbuchung» — a posting with a tax line that
   carries TWO dates: its posting date, in an open period (the settled period itself only while
   its fiscal year is open), and the VAT period it corrects. It never appears in an ordinary
   return; the correction return selects by the corrected period. A correction is therefore
   possible years after the books of that year are closed, as the law obliges, without reopening
   them. The declared figures per field are stored on every posted return; the correction is
   «recomputed − last declared», no entry is picked by hand. From the corrections z77 builds a
   **correction return per filing channel** — the two channels differ in kind:
   - **Korrekturabrechnung for a period:** it REPLACES the return filed for that period, so its
     print shows the COMPLETE new figures, the difference beside them for information.
   - **Jahresabstimmung:** ONE per tax period — the calendar year — holding only the SUM of the
     differences of its settlement periods not already filed by the first channel; filed once,
     never corrected. A later finding goes by the first channel.
   - The channel follows from the date: until the tax period ends, the first; from then until the
     Jahresabstimmung is filed or the 240th day after the end of the business year, the second;
     afterwards the first again, until the right to assess lapses (five years). The screen shows
     the 180- and 240-day dates.
   - Each correction return stores its channel and posts the difference as its own settlement
     entry. Its print lists the fields z77 does not carry, to be taken over from the original.
5. **Lifting is for a return posted by mistake.** A visible reversal entry — the settlement entry
   and its reversal both stay in the journal — and the return is created anew (the new settlement
   entry carries a revision in its key); the figures of the lifted return stay stored. Only the
   LATEST return, only an admin, with a mandatory reason, written to a protocol; one action may
   lift several from the latest backwards. Refused while a filed date is set, and the year must be
   open. *(No cascade and no filed date — addendum 2026-11-25, points 1 and 2.)* *An explicit exception to the addendum 2026-10-06* (a generated entry is corrected by its
   source in place, with a change log): a tax return is not a typing error, and an auditor reads a
   reversal pair, not a change log.
6. **The year close is blocked while a VAT period of the year is not settled,** for a liable
   mandator: financial registers this as its own check under `period-close`; the finding names the
   period. A settlement period may span two fiscal years, and the close waits only for the periods
   that END within the year. The Jahresabstimmung comes after the accounts and never blocks the
   close. *(There is no Jahresabstimmung object — the yearly overview posts and blocks nothing,
   addendum 2 of 2026-11-25, point 8.)* **Without «Abrechnung ab» nothing blocks** (owner 2026-11-24, evening): a liable
   mandator that has not named the date settles its VAT outside z77 — the close shows a hint
   only; the block holds for the periods from «Abrechnung ab» on. (A block would have stopped
   the close of every existing liable installation on deploy.)
7. *(Superseded in its wording by addendum 2 of 2026-11-25, point 7: the accounts are read from
   the mandator, module postings are judged too, two more exemptions, off after «Abrechnung bis».
   The principle stands.)*
   **The VAT accounts accept computed tax lines only.** The accounts for VAT owed and for input tax
   carry «Nur durch Module bebuchbar», refined for them: per entry, the lines on each VAT account
   must equal the tax of the coded lines that map to it — a freely typed amount is refused, an
   uncoded amount on a VAT account being exactly what makes the ledger differ from the return.
   - The Sammelbuchung therefore writes the tax line itself, as the one-line form does; the
     bookkeeper no longer types it.
   - A «tax only» line and an input-tax correction (ADR-041 addendum, points 9 and 10) are coded
     lines and pass; a reverse-charge line maps to two tax lines (point 8 there).
   - EXEMPT are the entries of the return itself — the settlement entry, the settlement entry of
     a correction return, their reversals — and every entry dated before «Abrechnung ab»
     (opening balances, earlier years, the carry-over). These are the only uncoded lines a VAT
     account accepts. *(Superseded: the year opening and a reversal through the ledger are exempt
     too, the correction return is gone — addendum 2 of 2026-11-25, point 7.)*
   - The account pickers of the bank, the receipts and the creditor invoices do not offer the VAT
     accounts — the flag stops manual entries only. *(Superseded: module postings are refused
     too — addendum 2 of 2026-11-25, point 7.)*
   - The settlement account stays open: the bank posts the payment to the tax authority and the
     instalments onto it.
8. **Roles:** posting a return needs the finance permission of the module (a later bookkeeper role
   included); a correction entry and lifting are pinned to the admin, like the reopen of a year.

## Addendum 2026-11-25 — posting and lifting on the screen (owner decisions)

Decided on the boards of stage S2 (the owner's walk-through of 2026-11-25), before the screens of
«MWST buchen» and «Aufheben» were built. The rules as built: [`financial.md`](../topics/financial.md)
→ «MWST buchen» and «lifting a posted return».

1. **Posted means filed — there is no filed date.** The user files in the ePortal first, then
   posts in z77; the posting date is the filing. No field, no column, no fourth state, no log
   action for a filed date. *This replaces the last clause of point 1 of 2026-11-24* («the return
   may carry the date it was filed») *and the clause «refused while a filed date is set» of point
   5.* A later event or a correction belongs to the running period; an error in a filed return is
   reported to the tax office — z77 does not reopen a filed return for it.
2. **Lifting is the emergency brake** — for a return posted by MISTAKE: admin only, the LATEST
   posted return only, a mandatory reason, ONE return per action. *This replaces «one action may
   lift several from the latest backwards» of point 5* — there is no cascade; an earlier return is
   reached by lifting one after the other from the latest. Point 3's third rule now reads «a return
   posted by mistake is lifted». The voucher of a lifted return stays byte for byte. Entry points:
   the ⋮ of the newest posted row of the list and the tool «Aufheben …» of the posted return —
   never the action cell.
3. **The screen.** The action cell of the list is «Neue Abrechnung» (the next elapsed open period);
   the action cell of an open return is «MWST buchen …» while nothing blocks — otherwise EMPTY and
   the reason is the first open point (no disabled button). The window shows the payable amount,
   the rounding, the settlement entry line by line and the warnings, confirmed with ONE tick; a
   nil return goes through the same window («Buchung: keine»). A posted return shows its STORED
   figures. *(The tabs «Herkunft» / «Abstimmung»: addendum 2 of 2026-11-25, point 10.)*
4. **The journal** shows the settlement entry and its reversal with the voucher, and an entry with
   a tax line in a settled period with a lock; opening it gives the one refusal sentence, read only.
5. **No setup page.** Old balances of the VAT accounts from before «Abrechnung ab» are carried
   onto the settlement account, if at all, by a manual journal entry dated before that day — they
   do not disturb the first return (its reconciliation compares the movements of the period).

## Addendum 2 (2026-11-25) — the correction entry, the lock of the VAT accounts, the year, the deploy (owner decisions)

Decided in the owner's rounds of 2026-11-25 on the stages S3 (corrections), S4 (the lock and the
Sammelbuchung), S5 / S6 (tax-only line, catching up, reverse charge) and S7 (the year), after the
first addendum of the day and before any of it is built — the builders build against this text.
A point or clause marked *derived* is not an owner decision: it is a rule of the system that
follows from the decisions and closes a gap between them. *To be confirmed* marks a derivation the
owner has yet to confirm. The VAT-model side (codes, tax-only line, rate date, catching up,
reverse charge, the calendar year) is the addendum of the same day in
[ADR-041](adr-041-vat-model.md); the creditor's second entry is the addendum of the same day in
[ADR-059](adr-059-creditor-module.md). Where an article of law is named, it is the basis the
decision rested on as the decision notes give it — z77 gives no tax advice.

1. **z77 posts no correction return — the correction entry.** A «Korrekturbuchung zu Periode X» is
   a manual entry of an admin (point 8 of 2026-11-24 unchanged), dated in the running period, that
   names the settled VAT period it corrects. The ordinary form of the period it is dated in leaves
   it out; that period's return shows it as the block «Korrekturen früherer Perioden — separat im
   ePortal melden»: per corrected period and form field the difference AND the new total
   (original + corrections so far = new). *(Franken and Rappen with the exact value under each tax
   amount, and a column «frühere Korrekturen» for a period corrected again — addendum 3, points 5
   and 6.)* The user reports it in the ePortal by hand; z77 knows no filing channel and no filing
   state. *Replaces point 4 of 2026-11-24 whole* (a correction return
   per channel, its print, the channel by date, its own settlement entry). Reason: booked in the
   running period AND reported separately (Art. 129 MWSTV) without being declared twice — at a
   fraction of the build of a correction return.
2. **The ordinary return carries the block.** «MWST buchen» of the period the correction entry is
   dated in clears the block's tax from the VAT accounts onto the settlement account as a line of
   its own, stores the block with the return and prints it on the voucher. *Amends point 1 of
   2026-11-24* (the settlement entry clears the coded tax of the return — now also the block's).
   *Derived:* the reconciliation of the VAT accounts counts the block as an expected item of its
   own (otherwise the correction entry's tax line would be a blocking remainder); a return is not
   lifted while a correction entry corrects ITS period — the entry is changed first. Reason: the
   block is declared in the ePortal, so the VAT accounts must be cleared of it like of the form.
3. **A correction entry is never dated into a settled period** — the freeze of point 3 of
   2026-11-24 has no exception. *Replaces the clause «the settled period itself only while its
   fiscal year is open» of point 4.* At the year end the entry without a tax code is dated 31.12.
   (through a transit account) and the VAT goes as a correction entry dated later. *(The transit
   account is the mandator account 2209 «MWST-Korrektur, noch nicht abgerechnet» — addendum 3,
   point 4.)* Reason: one
   freeze without an exception; a settled period stays as it was filed.
4. **The rate of a correction entry is the rate of the corrected period** (at its last day).
   *Derived:* one notion «rate date» for every case where the rate is not the entry date's —
   the creditor invoice (ADR-041 addendum 2026-11-25, point 7) and the correction entry; the ledger
   never recomputes a rate. Reason: the entry corrects that period's tax.
5. **Which way — within and across the calendar year.** An error or omission in a settled period
   of the SAME calendar year: both ways are offered — an ordinary entry in the running period or a
   correction entry; the screen names both, the help explains the difference, the user chooses
   per case. An error of an EARLIER calendar year: z77 says clearly (help, yearly overview) that it
   is entered as a correction entry and reported in the ePortal; it cannot prevent the ordinary
   entry. *(«Same» and «earlier» are measured against the year of the RUNNING period — addendum 3,
   point 2.)* *Replaces the second rule of point 3 of 2026-11-24.* Two cases are not «an error» in
   this sense and follow ADR-041 addendum 2026-11-25: input tax claimed later (point 9 there —
   within the year caught up automatically, across it refused) and a reverse charge whose
   invoice date lies in a posted period (point 10 there — ALWAYS the correction entry, also within
   the year, owner). Legal basis as the decision notes give it: defects are corrected (Art. 72
   MWSTG), separately from the ordinary returns (Art. 129 MWSTV). The ordinary entry within the
   year departs formally from Art. 129; the ESTV interface norm eCH-0217 names it as one of three
   ways — no source was found that the ESTV tolerates or sanctions it (*uncertain*). Across the
   year the departure is real: the tax is levied per calendar year (Art. 34 MWSTG).
6. **Deadlines are shown only when the fiscal year is the calendar year** (day 180 / 240);
   otherwise the screen says «Frist bei der ESTV klären». *Replaces «The screen shows the 180- and
   240-day dates» of point 4.* Reason: z77 does not compute a deadline it cannot state reliably.
7. **The lock of the VAT accounts.** *Replaces the wording of point 7 of 2026-11-24* — its
   principle (the VAT accounts carry computed tax lines only; the Sammelbuchung writes the tax
   line itself; the settlement account stays open) stands.
   - **The locked accounts are read from the mandator** (VAT owed, input tax material, input tax
     other) — no flag on the account, no migration. *Replaces the first sentence of point 7.*
     Reason: one source (Key Rule 2); an account changed on the mandator is locked at once and the
     old one is free.
   - **The lock holds for module postings too** (debtor, creditor, receipt, bank): from «Abrechnung
     ab» to «Abrechnung bis», for a liable mandator, every entry — manual and generated — must have
     on each VAT account exactly the tax of the coded lines that map to it; a module's posting is
     refused at once with a sentence naming the document. *Replaces «the flag stops manual entries
     only».* Reason: the error shows where it happens; the server checks, not only the pickers.
   - *Derived:* a coded line may itself sit on a VAT account only when that account is the
     input-side account its code maps to — a tax-only line, an input-tax correction (VE / VK / VN),
     the reverse-charge pair line; an output, zero or exempt code on a VAT account stays refused.
     One predicate instead of three exceptions.
   - **Exempt:** the entries of the return (the settlement entry and its reversal), **the year
     opening**, **a reversal a module makes through the ledger** (`LedgerService::reverse()`), and
     every entry dated before «Abrechnung ab». **After «Abrechnung bis» the lock is off.**
     *Replaces the list of exemptions of point 7* («the settlement entry of a correction return»
     goes with point 1; «these are the only uncoded lines» no longer holds). Reason: an opening of
     a fiscal year inside a VAT period carries the balance uncoded; a reversal mirrors an entry
     already judged — a difference it leaves shows in the reconciliation and blocks «MWST buchen»,
     never silently; after «Abrechnung bis» there is no return left to protect.
   - **An entry posted before the lock** (an «old entry» with a typed tax line): a change of text
     or date with unchanged lines saves without the VAT check; changed lines must make the whole
     entry consistent. The period rules apply as always. Reason: an old entry stays correctable.
   - **Pickers** (bank, receipts, payment methods, templates, creditor profile and invoice lines,
     debtor's payment allocation) do not offer the VAT accounts; the server refuses them as well. A
     learned bank proposal naming a VAT account is dropped with one line pointing to the
     settlement account (ADR-061 addendum 2026-11-25); no migration.
   - **Not locked:** the settlement account (the payment to the tax authority, the instalments) —
     a Rappen difference against the figure the ESTV computes is booked by hand from the settlement
     account to the rounding account (6945; neither is locked) — and the transit account «Vorsteuer,
     noch nicht abgerechnet» (ADR-041 addendum 2026-11-25, point 9), which carries «Nur durch Module
     bebuchbar» like any collective account.
   - **An old balance** of the VAT accounts is carried, if at all, by a manual entry dated before
     «Abrechnung ab», before that year is closed; when it is already closed, an admin reopens it,
     books the carry-over, closes it and re-runs the opening. No exemption of its own — the help
     says so.
8. **The year: an overview that posts nothing.** z77 posts no Jahresabstimmung. A read-only
   yearly overview («Jahresübersicht») per tax period — the calendar year (Art. 34 MWSTG) — with a
   PDF: the corrections of the year per original period and field with the yearly sum, the
   turnover reconciliation and the reconciliation of input and owed tax (Art. 128 MWSTV), the
   deadlines. A button stores it as an unchangeable PDF in the archive with a protocol row
   (*derived:* each storing writes a new file with its «Stand» and its own protocol row; nothing is
   replaced or lifted). The overview blocks nothing; one dashboard line names the return that is
   due, and the window «Jahr abschliessen» links to the overview. `vat_return.kind` goes before
   the first deploy (this later decision replaces «`kind` stays until S7 is built»). *(Which year
   opens, when «Ablegen …» is offered, storing again and the protocol kind — addendum 3, points 7
   to 10. `kind` is gone since wave 1, migration `Version20261126100000`.)* *Replaces the
   Jahresabstimmung of point 4 and its mention in point 6.* Reason: z77 does not file; the
   reconciliation is the evidence the ESTV may ask for, the report of a difference is the user's.
   By calendar year only — ADR-041 addendum 2026-11-25, point 11.
9. **Warnings at «MWST buchen»** — *amends the list of point 1 of 2026-11-24.* *Derived:* the
   warning «input code on a voucher for a service rendered before «Abrechnung ab»» falls away — no
   coded posting can be dated before that day, a voucher before it is posted «ohne MWST» and
   founding costs are claimed by hand (ADR-041 addendum 2026-11-25, point 2). *Derived:* the
   foreign-voucher warning is built for creditor invoices only — a receipt has neither a currency
   nor an address. New: the import-VAT warning of ADR-041 addendum 2026-11-25, point 8.
   *(Both derivations confirmed by the owner, and a BLOCKING finding for a missing reverse-charge
   entry that looks only at the period being posted — addendum 3, points 1 and 3.)*
10. **A posted return, tabs «Herkunft» and «Abstimmung»:** they stay recomputed; when the
    recomputed view differs from what was posted, the tab itself says «Seit dem Buchen geändert —
    der Beleg zeigt den Stand vom {Datum}.». No frozen tables. *Makes point 3 of the first
    addendum of today precise* (the STORED figures are the form's). Reason: freezing the two views
    would store a second copy of the ledger; the voucher already holds what was posted.
11. **Deploy as a whole.** The VAT return goes onto a server only when it is complete up to and
    including reverse charge (stage S6) — no deploy of S2 alone, no tag before. Reason: an interim
    state (reverse charge refused, no lock, no correction entry) never meets real books.
12. **Unchanged:** point 8 of 2026-11-24 (roles); point 3 of 2026-11-24 as amended on 2026-11-25 (a
    payment correction or a bank take-back that would change a settled period is refused — refuse
    only); the first addendum of today.

## Addendum 3 (2026-11-25) — the owner's rulings on the derived rules and on the boards S3 / S7, and what wave 1 built (owner decisions)

Ruled by the owner later the same day, after addendum 2 was written: on the rules it marked
*derived*, on the board questions of the correction entry (S3) and of the yearly overview (S7), and
on the follow-ups of the first build wave of the VAT return (wave 1, 2026-11-25). The VAT-model
side is addendum 2026-11-25 b in [ADR-041](adr-041-vat-model.md); the creditor side is the
amendment of 2026-11-25 b in [ADR-059](adr-059-creditor-module.md). The short ids in brackets name
the ruling in the owner's decision record. What is built is said per point — everything else is
**decided, not built**.

1. **Two derivations confirmed** (O-6, O-7): there is no zero check of the VAT accounts — a balance
   on a VAT account alone triggers no warning (ADR-041 addendum 2026-11-25, point 2); and there is
   no warning «Vorsteuer vor Abrechnung ab» — input-tax postings before the start are not objected
   to automatically (point 9 of addendum 2). Nothing to build.
2. **The running period, and the year of an error** (O-9, C-B): «laufende Periode» is the FIRST VAT
   period not yet posted — not necessarily the current calendar period; that is its meaning in
   points 1, 3 and 5 of addendum 2 and in ADR-041 addendum 2026-11-25, points 9 and 10. Whether an
   error lies in the «same» or an «earlier» calendar year (point 5 of addendum 2) is measured
   against the year of the running period, not against today's date. Built: the first day of the
   running period (`VatSettlement::claimableFrom()`); the screens of point 5 are decided, not built.
3. **A missing reverse-charge entry blocks «MWST buchen» — and looks ONLY at the period being
   posted** (O-8 — *amends point 9 of addendum 2 and ADR-059 addendum 2026-11-25, point 3*). It
   blocks when an invoice carries a reverse-charge code, its INVOICE date lies in the period being
   posted and its tax entry is missing; the way out is always open: the creditor's «Journal
   verbuchen» posts the tax. It NEVER blocks because of an invoice whose period of origin is
   already posted: such an invoice cannot keep its reverse-charge code — «Journal verbuchen»
   refuses it with «Die Bezugsteuer gehört in die Periode {Periode}, sie ist abgerechnet — die
   Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu {Periode}
   erfassen.»; the user switches the line to «ohne MWST», posts, and enters the reverse charge by
   hand as a correction entry naming that period (shown in the block of the running return). z77
   does not check that the correction entry was made (as with input tax across the year). No
   deadlock. **Decided, not built.**
4. **The year end: a mandator account 2209 «MWST-Korrektur, noch nicht abgerechnet»** (B-1, B-1a,
   B-1b — *makes point 3 of addendum 2 precise*: the transit account named there). The tax
   belongs economically to the old fiscal year but is corrected only in a later VAT period; an own
   account makes the open amount visible and reconcilable. The entry without a tax code on 31.12.
   goes through 2209, the correction entry clears it. Its BALANCE appears in the yearly overview.
   At the year close a balance other than 0.00 is a WARNING to confirm with the existing tick
   «Warnungen gelesen» — «Konto 2209 MWST-Korrektur, noch nicht abgerechnet: Saldo {Betrag} — die
   Korrekturbuchung dazu steht noch aus.» —, recorded in the close protocol, never a block. 1179
   «Vorsteuer, noch nicht abgerechnet» stays EXCLUSIVELY for catching up input tax. Number 2209:
   free in the shipped KMU chart (`res/charts/kmu.json`, checked 2026-11-25). **Decided, not
   built.**
5. **Rappen in the correction block** (B-2 — *makes point 1 of addendum 2 precise*): Franken and
   Rappen, PLUS the exact value small under every tax amount, so every sum can be recomputed; «neu»
   is rounded ONCE from the exact values. The same in the yearly overview and on the PDFs.
   **Decided, not built.**
6. **A period corrected a second time** (C-C — *makes point 1 of addendum 2 precise*): a FOURTH
   column «frühere Korrekturen», shown only when an earlier correction of that period exists;
   «ursprünglich» stays the figure originally posted. **Decided, not built.**
7. **Which tax year the yearly overview opens** (B-3 — *point 8 of addendum 2*): «Jahresübersicht»
   and the link in «Jahr abschliessen» open the TAX YEAR whose 31.12. lies in the chosen fiscal
   year — one rule for both; the other year through the rail. **Decided, not built.**
8. **«Ablegen …» only for a final overview** (B-4 — *point 8 of addendum 2*): offered only when the
   overview is NOT «vorläufig» — every VAT period of the year posted AND the fiscal year closed.
   Before that only «PDF», marked «vorläufig»; the link in «Jahr abschliessen» is for checking,
   storing happens after the close. **Decided, not built.**
9. **Storing again** (B-5 — *makes the derived clause of point 8 of addendum 2 precise*): possible
   as soon as the overview has changed since the last storing; every stored PDF stays in the
   archive and in the protocol, the newest counts; until stored again the head says «Seit der
   Ablage geändert — das abgelegte PDF zeigt den Stand vom {Datum}.». **Decided, not built.**
10. **A fifth protocol kind** (C-D — *extends the protocol «Abschlüsse und MWST» of the first
    addendum of 2026-11-25*): «Jahresübersicht abgelegt», with the icon of the stored PDF; the
    year filter by what the row is about (the tax year), as for the other kinds (F-4, built with
    stage S2). **Decided, not built.**
11. **The follow-ups F-1 … F-5** stand where they belong: F-1 — the refusal of input tax across
    the year (ADR-041 addendum 2026-11-25, point 9; built as the rule `InputTaxDeferral`, not yet
    called by a document); F-2 — the action cell «MWST buchen …» leads with the icon `file-check`,
    the window button «MWST jetzt buchen» has none (point 3 of the first addendum of 2026-11-25;
    built); F-3 — «Rhythmus gilt ab» (ADR-041 addendum 2026-11-25, point 1; built in wave 1);
    F-4 — the protocol's year filter by what a row is about (built); F-5 — the hint «Seit dem
    Buchen geändert …» on a posted return (point 10 of addendum 2; built).
12. **The lock of the VAT accounts after wave 1** (point 7 of addendum 2): built — `VatAccountMap`
    is the ONE table of the signs and of the lock (`taxLines()`, `mayCarryCode()`,
    `lockedAccounts()`, `accounts()`), read by the reconciliation of the return
    (`VatAccountExpectation`) and by the rule `PostingRules::assertVatLinesComputed()` (the
    equation, the predicate, the exemptions, one German sentence `vatLineRefusalText()`); the
    pickers leave the VAT accounts out and the servers refuse them; the Sammelbuchung writes the
    tax lines itself; the chart shows «nur MWST» from the day «Abrechnung ab» is SET, also when it
    lies ahead (C-A). The rule is wired into the ledger's write paths and the modules' refusal arms
    in the same wave — [`financial.md`](../topics/financial.md) records the call sites.
