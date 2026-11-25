# ADR-061 — Bank-driven bookkeeping: every movement is business, private or «zu prüfen»

**Status:** `[APPROVED]` — owner decisions of 2026-11-17 on the review «bank-driven bookkeeping»
(working paper in `docs/temp/`, its owner sections are the binding part). Supersedes ADR-060
decisions 16 and 17 and parts of 1, 4, 5 and 12 (see «What this supersedes»). Stage C0 is this
record; stages C1 to C5 were built the same day (state in `bank.md` / `creditor.md` /
`debtor.md` / `receipt.md`; the shapes decided with C2 and with C4 stand in the two addenda at the
end).
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

## Addendum 2026-11-17 — the shapes decided with stage C2

Built by the owner's word «pull it through»; each point is how the build read the decisions
above. The points marked «owner» wait for his confirmation (`bank.md` → pending).

- **The state is derived** (4): link → «geschäftlich»; else the mark by hand; else a private
  pattern → «privat»; else «zu prüfen». Nothing of it is stored on the movement.
- **A pattern** (5) is ONE row per bank account, direction and key. The key is the counterparty's
  IBAN, else the merchant after «Bezugsort:», else the counterparty's name, else the text without
  its numbers. «Approximate amount» and «day of month» are an optional NARROWING chosen when
  «Privat» is confirmed (± 10 %, ± 3 days — owner), not part of the key: one «Privat» classifies a
  merchant whatever the amount.
- **«Set by hand»** (4) is a mark on the one movement — «nur diese Bewegung», a movement without a
  key, or a movement of a «gemischt» pattern.
- **The question of decision 8** is asked in two places with one meaning: IN the booking form when
  a movement of a private pattern is booked (no answer, no posting), and as an OPEN QUESTION above
  the list when a movement is linked although a private pattern takes it — a document found it
  (decision 7), or it was booked before the pattern was confirmed. «Nein» is stored on the
  movement and not asked again.
- **A pattern that only carried a booking proposal** becomes private on «Privat»; what was booked
  of it stays business and the question is open for it (owner).
- **The posting source** (consequences): source type `bank`, one `bank_posting` row per posting as
  the owner of the link and of the idempotency key; the entry's origin names bank account,
  movement and the archived statement file (16).
- **Where the attachment lies** (15; the open point of the consequences): in a write-once archive
  of financial's own (`data/archive/financial/bank-voucher/`, the shared `WriteOnceStore`) — not
  the DMS; financial keeps no dependency on a document module (ADR-060 decision 7).
- **The threshold and the two named accounts** (15, 17) are config values of financial
  (`bankPosting`): CHF 100.00, «Privat» 2850, transfer 1090 — whether the accounts belong on the
  mandator record is open (owner).
- **«Buchung zurücknehmen»** (19) dates the reversing entry on the entry's own date, so the
  period's change of the bank's ledger account nets to zero (owner).
- **Not bound to the switch yet** (1): until «Abgleich ab» exists (C4) every stored movement has a
  state.
- **Still undecided**, unchanged: a returned payment (`RvslInd` — it can be posted directly or set
  private like any movement, owner), amount differences, a partly business movement.

## Addendum 2026-11-17 — the shapes decided with stage C4

Built by the owner's word «pull it through»; each point is how the build read the decisions
above. The points marked «owner» wait for his confirmation (`bank.md` → pending).

- **Bank-driven is derived, not a mode** (1, 3): «Kontoauszug einlesen» on AND «Abgleich ab» set,
  for a movement booked on or after the date. Nothing is stored on a movement — moving the date or
  switching off changes what is shown at once; links, postings, marks, patterns and the anchor
  stay. A movement that is information only has no state and NOTHING can be decided about it,
  «Geschäftlich buchen …» included (owner); a link can still be set on it by an owning module.
- **The anchor** (22) is a row per bank account and DAY: the bank's balance, the ledger balance
  and their difference at the opening of «Abgleich ab». The bank's balance comes from the first
  statement's opening and the movements before the day — so «Abgleich ab» cannot lie before the
  first statement.
- **The year change** (24): the anchor of a later day is COMPUTED (the last confirmed + the
  private net since) and compared with the real difference at the opening of the fiscal year the
  window shows; equal → nothing to do, a mismatch → «Differenz bestätigen» for that day. An
  unconfirmed or mismatching anchor WARNS at the year close and does not block — decision 25 lists
  four blocks, the anchor is not one (owner).
- **The check** (23, 25) leaves the year opening out (an entry of origin `opening`, or with a line
  on the mandator's opening contra account) and names three things beside the difference: postings
  without a movement IN the period, business movements without a posting in it, movements «zu
  prüfen». It fails on a difference OR on a posting without a movement, also when the sums agree.
- **Which posting belongs to which movement** is answered by the module that owns the link —
  the registry `bankEntryOwners` (creditor, debtor, receipt); financial resolves only its
  own direct postings. A module that declares nothing has its postings named, never hidden.
- **The transfer and the «Privat» account** (17) stand on the MANDATOR record (`bank-transfer`,
  `private`), not in `financialConfig` — the answer to the open point of the C2 addendum. Where
  this ADR writes «1090» it means that account; no number is assumed anywhere, and without one the
  transfer check is skipped.
- **«Vorsteuer ohne Beleg»** (26) counts the direct postings with a tax code and no archived
  voucher, not taken back, by their entry's date; the tax is the entry's.
- **Creditor** (13): «bank-driven» follows the invoice's PAYMENT METHOD (its bank account), judged
  for the typed day; a date typed before the account was switched on stays (2).
- **Not solved, shown honestly:** a module that posts on another date than the bank books (debtor:
  the payment's date) fails the check of BOTH periods when the two dates fall into different ones
  (owner — debtor's switch to the booking date, ADR-060 decision 11, was not part of C4).
- **Not built:** the year-end posting «Privat / Bank» (open with the fiduciary); the flag «nur
  durch Module bebuchbar» on a bank's ledger account (the check names a manual entry instead).

## Addendum 2026-11-19 — the switch «gemischt geführt» (owner)

The owner asked for a second switch per bank account; it is decided as follows.

- **What it is:** `bank_account.mixed_use` — «gemischt geführt (privat und geschäftlich)», beside
  «Kontoauszug einlesen», OFF by default. Only a MIXED account knows «privat»: the state «privat»,
  «Privat …», the views «Geschäftlich» / «Privat», the column «Stand», private and «gemischt»
  patterns. OFF = a purely business account: every movement is linked or booked; what is open is
  «zu prüfen» (the view stays) and shows by its empty «Zuordnung». `markPrivate()` refuses
  (`not-mixed`).
- **Why this is no «mode» in the sense of decision 1:** the modes a / b / c were rejected because
  whether an invoice goes through creditor is a property of the invoice. Whether an account carries
  private payments IS a property of the account, and the owner knows it before the first statement.
  The states stay derived and the same; the switch only takes «privat» away as a possibility.
- **Why now, after ADR-060 dropped «Gemischtes Konto»:** there it served only the close check, and
  the close check had been dropped. The new reason is the screen and safety: on a business account
  the private tools are noise, and one «Privat» click on a collective credit taught a pattern that
  made every coming credit private (found live 2026-11-18). Deriving «mixed» from the data would keep
  «Privat …» on every account — the very click to avoid.
- **Switching off** only while the account holds no private decision (marks by hand, private or
  «gemischt» patterns) — refused with one sentence that counts them. Booking proposals of business
  patterns stay (they are no private decision).
- **Start value:** BY DATA (owner) — `Version20261119100000` sets it ON where a private mark or a
  private / mixed pattern exists, OFF elsewhere; nothing decided before is hidden.
- **The anchor of a purely business account (owner 2026-11-19):** «Differenz bestätigen» is
  REFUSED on an account that is not «gemischt geführt» (`BankReconciliation::confirm()` →
  `not-mixed`, one sentence: correct the difference by a booking, or switch «gemischt geführt» on
  if private payments really run over the account); nothing is written. Why: such an account is
  mirrored in full by the bookkeeping, so bank and ledger must agree exactly — a difference is an
  error to correct, not a private history to confirm. Its expected difference is 0.00 without a
  row: `anchor()` is never `open` there — 0.00 agrees (`computed`), anything else is a `mismatch`
  the window shows with the sentence instead of the button. Rows confirmed before stay and count
  (no migration). The year change computes as before; a mismatch WARNS at the year close with the
  same advice and does not block (decision 25 unchanged). A mixed account is unchanged.

## Addendum 2026-11-25 — no VAT account as contra account (owner)

«Geschäftlich buchen …» (decision 14) neither offers nor accepts a VAT account of the mandator as
contra account; a learned pattern naming one is dropped when it is read, with one line pointing to
the VAT settlement account (the payment to the tax authority goes there). No migration. Reason: the
lock of the VAT accounts, [ADR-042](adr-042-ledger-and-money.md) addendum 2 of 2026-11-25, point 7.

*(Built 2026-11-25, wave 1 of the VAT return: the list of «Geschäftlich buchen …» leaves out the
VAT accounts locked on the movement's day, a typed one is refused («Nicht gebucht: 2200 ist ein
MWST-Konto — die Zahlung an die ESTV geht auf das Abrechnungskonto 2201.»), and a learned proposal
naming one is dropped with «MWST-Konto 2200 nicht mehr wählbar — für die Zahlung an die ESTV das
Abrechnungskonto 2201.» under the field. «Locked» follows the lock of ADR-042 addendum 2,
point 7: from «Abrechnung ab» to «Abrechnung bis» of a liable mandator.)*
