# ADR-060 — Bank account service: the statement as an information source, seamless

> **Superseded in part by [ADR-061](adr-061-bank-driven-bookkeeping.md) (2026-11-17)** — bank-driven
> bookkeeping: decisions 16 and 17 entirely, parts of 1, 4, 5 and 12. The seamless chain, the
> archive, the parser, the master data «Bankkonten» and the movements list stay valid.

**Status:** `[APPROVED]` — owner decisions 1–14 of 2026-11-15 and 15–21 of 2026-11-16 on the
reviewed plan (18–21 after the first build and the first boards: purpose narrowed, slimmer,
seamless chain — they supersede parts of 4, 5, 6, 10 and 12 below, marked there). Stage B1
(master data «Bankkonten», camt.053 import with the seamless chain, archive) built 2026-11-16 as
services; the screens follow after the owner's look at their boards. B2 and later not built.
**Date:** 2026-11-16
**Build plan:** [`bank-account-service-plan.md`](../03-development/bank-account-service-plan.md)
(the review, the variants, the stages). **Topic:** [`bank.md`](../topics/bank.md).

---

## Context

z77 saw a bank account only through debtor's camt.054 import: customer credits, matched to
invoices and booked. Outgoing money never reached the system — «Bezahlt am» of a creditor invoice
was typed, and the CHF amount of a foreign card payment (which exists only at the bank) was copied
by hand from the e-banking screen.

The bank's account statement (camt.053) carries every debit and credit with dates, text, amount,
the instructed foreign amount and the rate, plus the opening and closing balance. The owner's
proposal: keep the statement in z77 and let the creditor journal look things up in it.

Questions: where does such a store live and who may depend on it? What does a movement MEAN for
the books — may it post? Where is «which IBAN is which ledger account» written down, given that
debtor's payment targets and receipt's payment methods each hold an account number today? How
does an overlapping export behave, and what if a statement is missing? Where is the file kept, and
who may see the data?

The real file of the stage-1 account was read before building (structure only): camt.053.001.08,
ONE statement per export over a free period, opening / closing booked balance, an entry reference
(`AcctSvcrRef`) on every entry, one transaction per entry, QR-bill payments with their QRR
reference, card purchases naming the merchant only in the entry text, the instructed foreign
amount with the rate as fields, booking date ≠ value date on two thirds of the entries.

The first build followed a wider reading (an open / done work list, a reconciliation, a close
check). The owner narrowed it the same day: «einlesen einer camt.053 ist einzig eine
Auskunftsplattform für Kreditoren … mir scheint die Darstellung zu kompliziert» and «das Einlesen
muss immer nahtlos sein». Decisions 16–18 record that; the code was reworked before any deploy.

## Decision

1. **A movement is information — it never posts.** The statement is where the creditor journal
   looks up «Bezahlt am» and the CHF amount the bank debited (e.g. for an invoice of 4.91 USD).
   Posting stays with the module that owns the document through its own run; a generated entry
   keeps belonging to that module (ADR-042). Without camt.053 everything works as before — the
   fields stay editable by hand (creditor: stage A0).
2. **The service lives in module-financial**, namespace `Z77\Module\Financial\Bank\` — no own
   package. Financial owns the ledger account, and it is the only package every consumer may
   depend on. Financial names none of them.
3. **«Bankkonten» is the one source for IBAN → ledger account (Rule 2).** Master data in
   financial (`bank_account`: code, label, IBAN, currency, ledger account). The statement import
   finds the account by IBAN; consumers read the ledger account from it (decision 9). Debtor's
   payment target keeps its own account number for now and reads it from the bank account later
   (with stage B5); until then a contradiction (same IBAN, another ledger account) is shown as a
   hint in the BANK ACCOUNT WINDOW only (owner 2026-11-16).
4. **Which invoice took a movement** is recorded from stage B2; a movement is taken ONCE
   (owner 2026-11-16). *Superseded:* the earlier form — claims with an amount, several per
   movement, a table `bank_claim` shipped with B1 — is dropped; B2 decides the shape and brings
   its own migration. Nothing is taken automatically: a match is a proposal the office confirms.
5. *Dropped (decision 17):* the manual state «Erledigt» with a reason. A movement has no state.
6. **A movement exists once per bank account.** Its key is the bank's entry reference
   (`Ntry/AcctSvcrRef`); without one, a fingerprint (booking date, value date, side, amount, bank
   transaction code, text, rank among identical entries) — such a movement is flagged. The same
   FILE (SHA-256) and the same bank message for the same account (`MsgId` + `Stmt/Id`) are
   refused. *Superseded:* «a gap is named, never blocks» — see decision 18.
7. **The camt.053 file is kept in an own archive**, `data/archive/financial/bank/<YYYY>/`
   (debtor's invoice-archive pattern: SHA-256 on the statement row, verified on reading, no delete
   and no overwrite path) — not in the DMS: financial has no DMS dependency and a bank statement
   must not show up in a Drive. Ten years apply; it is part of the «Daten» backup.
8. **Base-currency accounts only.** A bank account in another currency and a statement in another
   currency are refused with a clear sentence. A foreign-currency account kept in a base-currency
   ledger needs valuation (ADR-042 decision 4 excludes it) — that is a new ADR, not a side
   effect.
9. **A payment method that pays through a bank hangs on the bank account.** Module-receipt's
   `PaymentMethod` («Bezahlt mit») names the bank account by code and takes its credit account
   from it; the method stores none of its own. A payment method without a bank account (cash,
   «privat ausgelegt») keeps its own ledger account. The account is resolved on every read —
   nothing is copied.
10. **One switch per bank account, OFF by default: «Kontoauszug einlesen».** Only then a camt.053
    of the account is accepted — off means master data only (no upload, no movements). The switch
    says plainly that EVERY movement of the account is stored, private ones too (server and
    ten-year archive). *Dropped (decision 17):* the second switch «Gemischtes Konto (auch
    privat)» — it only served the close check.
11. **The payment date is the bank's BOOKING date** (it is the date of the statement's balance),
    not the value date — for creditor «Bezahlt am» and, later, for debtor's payments (which use
    the value date today).
12. **Customer payments stay with debtor.** Debtor's `bank_transaction` remains the receivables
    work queue and the only path that books a customer payment. A credit on the statement is
    visible in the movements list and nothing more; the two stores are not deduplicated against
    each other.
13. **One camt parser.** The generic reading (entry, transaction, party, remittance, amounts,
    dates, balances) lives in kernel shared (`Z77\Shared\Payment\Camt\`); debtor's camt.054 reader
    and financial's camt.053 reader are views of it. The ZIP reading with its limits moved along.
14. **Privacy.** The movements carry names, IBANs and texts of third parties and private
    payments. Nothing of a movement goes into logs or error mails; the archive lies outside the
    web root; no movement data goes to an AI step without a separate decision. The bank screens
    use the role `admin` for now, like the journal (owner 2026-11-16: «ja c, vorerst admin»); a
    separate per-user right is a building block of its own, later.
15. **A foreign amount written only as text** is not parsed: the bank of stage 1 delivers the
    instructed amount and the rate as fields. A per-bank text parser would be an opt-in strategy,
    its result never «sure».
16. *Superseded by ADR-061 (2026-11-17).* **Purpose narrowed (owner 2026-11-16).** Reading a camt.053 is ONLY an information source for
    creditors: find the movement, take «Bezahlt am» and the CHF amount. No bank reconciliation, no
    open / done work list, no year-close check, no «manual entry from a movement».
17. *Superseded in part by ADR-061 (2026-11-17) — the one list stays, states and the close check come back.* **Slimmer (owner 2026-11-16).** ONE list «Bewegungen» per bank account — date, text, amount,
    original amount and currency where present, the balance — with search by text, amount and
    date; from stage B2 a column «Rechnung». No tabs «Offen / Zugeordnet / Erledigt», no
    «Erledigt», no switch «Gemischtes Konto», no close check.
18. **The chain is seamless, always (owner 2026-11-16: «das Einlesen muss immer nahtlos sein»).**
    The camt.053 is the source of truth, so what is stored for a bank account is one period
    without a hole:
    - a statement must be consistent in itself (opening + movements = closing) — refused otherwise;
    - the first statement of a bank account sets the start; from then on the account is
      «eingelesen bis D · Saldo C» — D the end date of the newest statement (`FrToDt/ToDtTm`,
      its date), C its closing balance;
    - *Amended 2026-11-18 (owner, after the first weeks on a real account):* a file must OVERLAP
      the last stored day — a statement that starts after D is refused (`no-overlap`), with both
      dates and D as the day to download from. Before, a start on D + 1 was accepted; but D may have
      been exported during the day, and two bookings made on D afterwards that net to zero pass
      every balance proof unseen. No weekend / holiday rule (an assumption), no sequence number
      (the bank's e-banking does not keep it). One exception: a statement FOLLOWING another
      statement of the same account in the same file joins that one (a bank sending one statement
      per booking day never overlaps itself; inside a file the order is the bank's). Accepted
      limit: a bank that only hands out fixed periodic statements cannot be continued;
    - a file that starts on or before D overlaps: its entries the account
      already holds are recognised by the bank's entry reference and not stored again; the proof
      that the overlap is identical is the balance — C + the new movements must be the file's
      closing balance (the file being consistent in itself, that is «the file's balance at the
      covered end equals C»). No match → refused, nothing stored;
    - the cut is by the bank's REFERENCE, not by a date comparison: an export made during day D
      ends on D, and the bank may book further entries on D afterwards — they carry new
      references, the next file brings them, the balance proves the join. A date cut would lose
      them or refuse the file for ever;
    - a file that ends on or before D and brings nothing new adds nothing and says so; nothing is
      inserted into the chain or in front of its start.
    The upload answers in one sentence: «45 neue Bewegungen, Saldo stimmt (eingelesen bis
    31.03.2026 …)». Because the chain is seamless every movement carries the account's balance
    after it.

## Reasoning

- **Information, not a posting source (1, 16).** A statement line cannot know the expense account,
  the VAT code or the document; a module can. Letting both post is how double postings arise.
- **Financial (2)** is the only place every consumer can reach without a wrong-way dependency; a
  service inside creditor would be rebuilt for the next user (Rule 9).
- **One source for the ledger account (3, 9).** Three places held «the account of the bank»
  (payment target, payment method, and now the statement import). Copies drift; a reference
  cannot.
- **Seamless and refusing (18)** instead of «named, not blocked»: a lookup source with a hole
  answers «not paid» for a payment that lies in the hole — silently wrong. A refusal with the two
  dates costs one more download and keeps every answer true. The statement proves itself (its own
  balances) and the join (the stored balance), so no judgement is needed.
- **Reference cut, balance proof (18).** The real export is one statement over a free period with
  a constant sequence number: message-level dedup does not see an overlap, the entry reference
  does; and only the reference survives an export made in the middle of a day.
- **Slim (16, 17).** A work list invites work: three quarters of the stage-1 account's movements
  are card and TWINT purchases nobody would ever assign. A plain searchable list does the job the
  owner asked for.
- **Booking date (11).** The statement's balance is by booking date.
- **Switch off by default (10).** Storing an account's history must be a decision, not a side
  effect of creating master data.

## Consequences

- New tables `bank_account`, `bank_statement`, `bank_movement` in ONE migration
  (`Version20261116140000`, reworked in place before any deploy). Stage B2 brings its own
  migration for «which invoice took the movement».
- `PaymentMethod` gains `bank_account_code`; its readers must go through `PaymentMethodService`
  (which resolves the account). Existing payment methods have no bank account and behave as
  before.
- Debtor's `CamtReader` / `BankImportArchive` are thin views of the shared parser / archive
  reader; their behaviour and their exception type are unchanged.
- An account must be read from its chosen start onwards without a hole; history from before the
  first statement cannot be added later (it would need a new start — not built).
- Not solved, and said so in the help: credit-card settlements (one debit a month), banks without
  camt.053, foreign-currency accounts, automatic posting, any reconciliation of the bank account
  with the ledger.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| CHF by hand only | Stays as the fallback (stage A0), but copies a value the bank already delivers and gives no «Bezahlt am» |
| camt.053 inside module-creditor | Puts IBAN → ledger account into creditor; a second user would rebuild it |
| Debtor's camt.054 import grows into the service | Financial cannot depend on debtor; touches the working import before any gain |
| Own package `module-bank` | A package skeleton and one more dependency, no gain today |
| IBAN field on the ledger account | One account cannot carry IBAN + QR-IBAN cleanly; no place for the switch |
| The file in the DMS | A DMS dependency for financial; the statement visible in a Drive |
| A movement posts by itself | No document, no VAT decision; two posting sources for one payment |
| A gap or a broken overlap is named but stored (first build) | A lookup source with a hole answers wrongly without saying so (owner: «immer nahtlos») |
| Open / done work list, «Erledigt», reconciliation, close check (first build) | Not the purpose (owner: «einzig eine Auskunftsplattform für Kreditoren»); too complicated |
| Cut the overlap by date | Loses or blocks movements booked on the covered end day after an earlier export |
