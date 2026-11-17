# ADR-061 — Bank-driven bookkeeping: every movement is business, private or «zu prüfen»

**Status:** `[APPROVED]` — owner decisions of 2026-11-17 on the review «bank-driven bookkeeping»
(working paper in `docs/temp/`, its owner sections are the binding part). Supersedes ADR-060
decisions 16 and 17 and parts of 1, 4, 5 and 12 (see «What this supersedes»). Nothing built —
stage C0 is this record.
**Date:** 2026-11-17
**Build plan:** [`bank-account-service-plan.md`](../03-development/bank-account-service-plan.md)
(stage table C0–C5). **Topic:** [`bank.md`](../topics/bank.md). **Builds on:**
[ADR-060](adr-060-bank-account-service.md).

---

## Context

ADR-060 (2026-11-16) narrowed the camt.053 to an information source for the creditor journal: no
state, no reconciliation, no close check, no posting from a movement. One day later the owner
looked at it from the year-end catch-up: the books lag, the statements of the year are already
read, and the bank is the one complete, external list of what happened. He wants the bank to drive
the books — every movement is either business (and then in the ledger) or private, never silently
one or the other — on both of his accounts at LUKB: a MIXED one (business and private payments)
and the one customer payments arrive on.

The review weighed it: sound as a control for cash-principle books, but «business sum = ledger
change» proves only that every posting has a movement, not that every business movement was
booked. Its biggest risk was a silent default «private». The owner answered with a third state,
«zu prüfen», that blocks the close.

Real-file findings (LUKB, structure and counts only):

- Incoming QR payments are COLLECTIVE credits in the camt.053 (bank transaction code
  PMNT / RCDT / VCOM): no `TxDtls`, no QR reference, 1–4 transactions per entry. The details come
  only in the camt.054.
- `Ntry/AcctSvcrRef` is unique per entry and the SAME in both files: every camt.054 entry of a
  real pair found its camt.053 entry, with equal amount, booking date and `NbOfTxs`.
  `Ntry/NtryRef` is a constant (QR-IBAN + participant number) — no link.
- Debtor stores the TRANSACTION-level reference and keeps no copy of the camt.054 file.
- Other credits (transfers, salary / pension) come one per entry with `TxDtls`.
- Card payments carry no counterparty name; the merchant stands only in the entry text.
- `CcyXchg/XchgRate` is the effective rate (CHF ÷ original amount, card surcharge included); the
  entry text may name a different «Wechselkurs», the bank's reference rate.

Order of work the model is built for (year-end catch-up): 1) read the camt.053 → 2) read the
creditor invoices (QR-paid ones find their movement) → 3) read the camt.054 → 4) scroll the
movements, classify, learn → 5) scan hand receipts (card- or bank-paid ones find their movement).

## Decision

**Switching on**

1. **No modes.** The existing switch «Kontoauszug einlesen» plus a date «Abgleich ab» per bank
   account. Off: «Bezahlt am» by hand as today. On: bank-driven from «Abgleich ab»; movements
   before that date are information only (no state, not in the check).
2. **Switched on with a date in the past:** already posted invoices find their movement and are
   linked WITHOUT a new posting (same certainty as decision 11); what finds none shows in the check
   as «Buchung ohne Bewegung».
3. **Switching off:** any time, forward only; links and postings stay, the check stops from then.

**States and patterns**

4. **Three states per movement:** «geschäftlich» (linked to an invoice, open item or receipt, or
   booked via «Geschäftlich buchen …»), «privat» (matches a pattern the owner confirmed as private,
   or set by hand), «zu prüfen» (everything else — the alarm). Nothing is private by silence.
5. **Learned patterns** (like creditor's learned values): counterparty / IBAN, merchant text
   («Bezugsort: …»), direction, approximate amount, day of month. One confirmation as private →
   every movement of the pattern becomes «privat». Learning stays in the database, no AI
   (ADR-060 decision 14).
6. **A credit** is «geschäftlich» only by an open item, a prepayment or a double payment, «privat»
   only by a private pattern (e.g. a monthly pension); otherwise «zu prüfen».
7. **A document beats a pattern:** an invoice, receipt or payment that finds its movement makes it
   «geschäftlich», even if a pattern had made it «privat» (then decision 8 asks).
8. **A pattern flips only after asking:** booking a movement of a private pattern as business asks
   «Die restlichen N … Bewegungen auf ‹zu prüfen› setzen?». «Ja» → the pattern is «gemischt», its
   movements are always «zu prüfen» from then on; «Nein» → this movement is an exception, the
   pattern stays private.

**The link**

9. **A link ties a movement to its owner** (creditor invoice, debtor payment, receipt, a direct
   posting) **and stores an amount.** Only 1:1 for now (one link, full amount); n:1 and 1:n can
   follow without a rebuild.
10. **Corrections release the link in the same unit of work** as the owner's own correction.
11. **Certainty (creditor, receipt):**
    - Level 1 — QR reference identical → taken automatically, shown for control.
    - Level 2 — CHF without reference (amount + creditor name / IBAN in the entry + date near due)
      and level 3 — foreign currency (original amount + currency + merchant text + date; CHF from
      the movement) → proposed, ticked in advance, confirmed several at once in the list.
    - Nothing unique → candidates shown, never a guess.
12. **Debtor links by the entry reference:** the camt.054 entry's `Ntry/AcctSvcrRef` equals the
    camt.053 entry's — identity, linked automatically; one collective credit links every payment of
    its entry. `Ntry/NtryRef` is never a link. Debtor stores the entry `AcctSvcrRef` and archives
    the camt.054 like the camt.053. Debtor stays the only path that books a customer payment.

**Creditor «Bezahlt am»**

13. **An invoice read in stays unpaid.** On a bank account with the switch on, from «Abgleich
    ab», «Bezahlt am» and the CHF amount come only from the bank (the movement's booking date and
    amount); typing them exists only on an account with the switch off. Now: the owner pays himself
    and the camt.053 finds the movement. Later: «Zahlungsauftrag auslösen» (pain.001) — the invoice
    goes to «Zahlung ausgelöst», the movement confirms it.

**«Geschäftlich buchen …»**

14. **A posting from a movement, by an explicit act:** contra account, text from the movement,
    optional VAT tax code; dated the booking date, linked to the movement. A movement never posts by
    itself.
15. **With a tax code and gross ≥ CHF 100 a file must be attached**, else not saved; below that the
    attachment is voluntary (the owner's risk at a VAT audit). The threshold is a config value, not
    code.
16. **Without a tax code** (charges, interest, «Privatbezug» / «Privateinlage») the archived
    camt.053 is the voucher; the posting refers to movement and statement.
17. **Transfers between own accounts:** the mixed account's side is «privat», not booked. A purely
    business account's side is booked against «Privat»: business → mixed = «Privatbezug» («Privat
    an Bank»), mixed → business = «Privateinlage» («Bank an Privat»); learnable (the mixed account's
    own IBAN). Two purely business accounts: each side against the transfer clearing account 1090.

**Corrections**

18. **«privat» but business** → «Geschäftlich buchen …» or assign an invoice; the pattern asks
    (decision 8).
19. **Booked as business but private or wrong** → «Buchung zurücknehmen …»: reversing entry, link
    released, movement back to «zu prüfen».
20. **Wrong invoice assigned** → link released, the invoice loses «Bezahlt am» and is open again;
    if it was posted, first the reversing entry.
21. **A closed period or a settled VAT period refuses the take-back;** then only a manual
    correction in the open period, as for every posting today.

**The mixed account's ledger account**

22. **Private movements are never booked.** At the first import after switching on, the bank
    account shows «Bank X · Fibu Y · Differenz Z — bestätigen?»; confirmed, the difference is
    stored as the account's anchor.
23. **The check compares changes:** change of the ledger account = sum of «geschäftlich»
    movements. Breakdown: bank balance − anchor − private movements = ledger balance.
24. **Year change:** new anchor = old anchor + private net of the year, computed; re-confirmed
    only on a mismatch.

**Close** (every bank account reading camt.053; month close where one exists, VAT return, year end)

25. **Blocks:** camt.053 not read to the period end; movements of the period still «zu prüfen»;
    the check fails (difference and postings without a movement named); 1090 ≠ 0.
26. **Warns:** «n Buchungen mit Vorsteuer ohne Beleg · CHF x Vorsteuer».

**Access**

27. **Role `admin`** for now, as the B1 screens (ADR-060 decision 14).

## What this supersedes in ADR-060

| ADR-060 | Now |
|---|---|
| 1 — a movement never posts | Superseded in part: «Geschäftlich buchen …» posts from a movement by an explicit act (14). Nothing posts by itself; a document still posts through its module |
| 4 — taken once, nothing automatic | Superseded: a link with an amount (9); identity links automatically (11 level 1, 12) |
| 5 — a movement has no state | Superseded: three states (4) |
| 12 — «not deduplicated» | Superseded in part: linked by `Ntry/AcctSvcrRef` (12). Debtor stays the only path that books a customer payment |
| 16 — information source for creditors only | Superseded entirely |
| 17 — one list, no states, no close check | Superseded in part: the ONE list «Bewegungen» stays the screen; state, row actions and the close check come back (board first, Rule 8) |
| Consequences — «not solved: automatic posting, any reconciliation» | Reconciliation solved for switched-on accounts; automatic posting still not |

**Stays valid:** 2 (lives in financial), 3 and 9 (master data «Bankkonten», one source for the
ledger account; payment methods on a bank account), 6 (dedup key), 7 (archive), 8 (base currency
only), 10 (the switch; the second switch stays dropped), 11 (booking date), 13 (one parser), 14
(privacy), 15, 18 (the seamless chain), the movements list.

## Reasoning

- **A third state instead of a silent default.** A forgotten business credit is unrecorded income;
  «zu prüfen» makes every unexplained movement visible and blocks the close until it is decided.
- **Patterns instead of hundreds of clicks.** Three quarters of the mixed account are card and
  TWINT purchases; one confirmation classifies a merchant for good, and «gemischt» stops a pattern
  from hiding a business payment.
- **Identity links automatically, likeness proposes.** A QR reference or the bank's entry reference
  cannot be wrong; amount + date can.
- **Anchor, not private postings.** The books stay free of private detail; comparing changes keeps
  the check exact.
- **One switch plus a date** covers the catch-up year without three modes nobody can tell apart.

## Consequences

- Stages: C0 this record · C1 creditor finds its movement · C2 states, patterns, «Geschäftlich
  buchen …», «Buchung zurücknehmen …» · C3 debtor entry reference + camt.054 archive · C4 «Abgleich
  ab», anchor, check, close · C5 receipts. Later: pain.001, collective variant (2000), n:1 / 1:n.
  Details in the plan.
- C1 replaces ADR-060's stage B2; C3 takes the camt.054 archive from B5; C5 replaces B4.
- New tables (link with amount, patterns, anchor) and a creditor field «Banktext»; each stage brings
  its own migration.
- Financial becomes a posting source of its own for «Geschäftlich buchen …» (a generated entry
  belongs to its source, ADR-042 decision 7); the exact shape is decided with C2.
- z77 closes only whole years today; «month close» applies once a period close exists.
- Not decided yet, each needs a sentence before its stage: a returned payment (`RvslInd`); amount
  differences (skonto, a charge netted into a credit); a movement that is only partly business;
  where financial keeps the attachment of decision 15 (it has no DMS dependency, ADR-060 decision 7);
  the flag «nur durch Module bebuchbar» on the bank's ledger account.

**Open with the fiduciary**

- An optional year-end posting «Privat / Bank» (sum of the private movements), so the balance
  sheet shows the real bank balance — a button at the year-end close, addable without changing 22.
- The CHF 100 attachment threshold — a practical rule, not law (MWSTG's CHF 400 concerns only the
  content of a receipt).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Modes a / b / c per bank account | Whether an invoice goes through creditor is a property of the invoice, not the account; one switch + date suffices |
| «privat» as the silent default (review) | Completeness would rest on the owner's eye alone; a forgotten credit is unrecorded income |
| A «geprüft bis» date per bank account (review) | Replaced by the state «zu prüfen», which names each open movement |
| «Posted, match pending» for creditor | Puts postings in the ledger the bank has not confirmed |
| Direct posting without a tax code (review) | Input VAT on small bank-paid items would be lost; an attachment rule covers the voucher |
| Every private movement booked (ledger = bank) | Private detail in the books; the anchor keeps the check exact without it |
| One posting with two movements for a transfer | A special case in link, check and posting; 1090 shows a missing side by itself |
| `Ntry/NtryRef` as the debtor link | A constant per QR-IBAN, not per entry |
