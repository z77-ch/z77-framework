# Bank account service (camt.053) — critical review and plan

**Status:** `[PLANNED]` — reviewed, the owner decided all 14 questions on 2026-11-15 (see «Owner decisions» at the end). Nothing is built.
**Input:** the owner's handoff «bank account service», 2026-11-15 (kept in the z77.ch project).
**Scope of this file:** the review and build plan. When B1 starts, the binding parts move to an
ADR and the topic docs (financial.md, creditor.md, debtor.md).
All amounts, names and references in this file are example values.

---

## 1. What the proposal is

1. The bank statement (camt.053) is uploaded monthly; z77 then holds every debit and credit of the
   bank account with text, dates, amount, plus opening and closing balance.
2. A **bank account service in module-financial** stores these movements. It knows nothing about
   creditors, receipts or debtors.
3. Consumers use two steps: **query** (free movements that fit) and **claim** («movement X belongs
   to creditor invoice 17»); deleting the invoice **releases** it. One movement is never used twice.
4. The creditor journal gets «Bezahlt am» and the CHF amount (and the rate) **proposed** from the
   matching movement — for every invoice, not only foreign ones.
5. Movements nobody claimed are «open» in financial — the bank reconciliation as a by-product.

---

## 2. Critical review

### 2.1 What exists today (looked up, not assumed)

| Area | Fact | Where |
|---|---|---|
| camt.054 | Reader for `.001.04` / `.08`, pure data, hardened XML (`LIBXML_NONET`) | `packages/module-debtor/src/Payments/CamtReader.php` |
| camt.054 | Import, classify, assign, ignore, book — credits only; debits and reversals stored as `ignored` | `packages/module-debtor/src/Services/BankImportService.php` |
| Dedup | `MsgId` unique per message; transaction reference (`AcctSvcrRef`, else `TxId`, else `EndToEndId`, else position) unique **per message only** | `Entities/BankMessage.php`, `Entities/BankTransaction.php` (`uniq_bank_transaction_ref` on `message_id, tx_ref`) |
| IBAN → account | Debtor's **payment target** (IBAN / QR-IBAN + ledger account number); an IBAN that is no active target is refused | `module-debtor/src/Entities/PaymentTarget.php`, `BankImportService::targetFor()` |
| File | The camt.054 file itself is **not stored**, only its entries and the file name | `Entities/BankMessage.php` |
| Close | Open-work registry, scope `period-close`; debtor's `UnbookedTransactionsCheck` blocks; subledger reconciliation as a port | `docs/topics/financial.md` (close check, `SubledgerReconciliationInterface`) |
| Money | Base currency only in the ledger; the foreign amount and rate belong to the document | `docs/02-decisions/adr-042-ledger-and-money.md` decision 4 |
| Creditor | `amount` CHF + `original_amount`, `original_currency`, `exchange_rate` exist as columns; a foreign amount is **not accepted yet** («in CHF von Hand erfassen») | `module-creditor/src/Entities/CreditorInvoice.php`, `Services/CreditorInvoiceService.php` |
| Creditor | «Bezahlt am …» by hand (single / ticked rows); posting date = payment date; the camt bridge is foreseen | `docs/03-development/creditor-module-bauplan.md` §6, §11 |
| Receipt FX | Plan F1–F14 is `[CONCEPT]`, **not built** (no `foreignCurrencies` in code). F1: «card: the CHF amount from the statement»; F12: CHF is free | `docs/03-development/receipt-foreign-currency-bauplan.md` |
| Retention | `retentionUntil` in the DMS, set by `CreditorRetention` / `ReceiptRetention`; debtor PDFs in their own archive `data/archive/debtor/<YYYY>/` without a delete path | `module-creditor/src/Services/CreditorRetention.php`, bauplan §11.8 |
| Dependencies | financial requires kernel, persistence-doctrine, vat, mandator — **not** debtor, **not** dms. debtor only *suggests* financial | `packages/module-*/composer.json` |
| camt.053 | Named once: «camt.053 later, when needed» | `docs/03-development/order-debtor-financial-bauplan.md` line 1037 |

### 2.2 Value — honestly weighed

**Pro**

- The CHF amount of a foreign card payment exists only at the bank. The statement is the one true
  source; typing it is copying.
- «Bezahlt am» from the bank is more correct than a typed date, and it is the bridge the creditor
  plan already names (§6, last bullet).
- **Receipt is a second consumer from day one:** F1 of the receipt FX plan says «card: the CHF
  amount from the statement». A service inside creditor would have to be built twice.
- A stored statement makes a real control possible that z77 lacks today: ledger balance of the bank
  account = bank's closing balance. This catches forgotten and double postings of every module.
  For an accounting client this is worth more than the FX convenience.
- Debits in camt.054 are thrown away today; camt.053 is the first place z77 sees outgoing money.

**Contra**

- **The trigger alone does not justify it.** A handful of USD invoices a month is solved by variant
  (a) in about a day. The service costs about as much as the whole creditor module (§5.5).
- **«Open = bank reconciliation» is only true when EVERY posting on the bank account claims a
  movement:** debtor payments, card receipts, creditor invoices, and all manual entries (salary,
  rent, bank fees, interest, private drawings, transfers between 1021 and 1022). Until then the
  «open» list fills with movements that are booked correctly but not linked. The by-product is not
  free — see 2.7.
- A second bank store beside debtor's (`bank_message` / `bank_transaction`) until debtor moves.
- The full account history (counterparty names, texts, private payments) lies on a shared host.
- The matching depends on what the bank writes — unknown until the real file is read. Debtor's
  reader was built before a real file existed and returned every payer name empty on the first one
  (DEBTOR-CAMT-001, fixed 2026-10-13). Same risk here.

### 2.3 The four variants

| | (a) CHF by hand on the invoice | (b) camt.053 inside creditor | (c) shared service in financial (proposal) | (d) debtor's camt.054 import grows into the service |
|---|---|---|---|---|
| Solves the USD trigger | yes | yes | yes | no (credits only) |
| Effort | 8–12 h | 30–40 h | 55–70 h up to creditor use; 80–105 h complete | 40–50 h restructuring before any gain |
| «Bezahlt am» proposed | no | yes | yes | no |
| Receipt can use it (F1) | — | no — second parser or a dependency receipt → creditor (wrong direction) | yes | no |
| Reconciliation | no | no (creditor may not judge the ledger account) | yes | no |
| Rule 9 (module-agnostic) | — | violated on the second user | fits | — |
| Risk | none | low, but a dead end | medium: bank file, matching, privacy | high: touches the working debtor import |
| Needed anyway | **yes — as the fallback of (c)** (a movement is missing, a bank without camt, cash) | no | — | later, as a stage of (c) |

- **(a)** is not an alternative to (c) but its first stage: the fields the service fills must be
  editable by hand anyway.
- **(b)** is cheaper only until receipt wants the same. It also puts «which IBAN is which ledger
  account» into creditor, where it does not belong.
- **(d)** in the form «debtor's import becomes the service» is the wrong direction: financial cannot
  depend on debtor (debtor merely suggests financial). The right form of (d) is «debtor becomes a
  claimer of the service» — a late stage of (c).

### 2.4 Points where I disagree with the proposal as written (open, for discussion)

1. **A claim is not a yes/no on the movement — it needs an amount.** Cases the boolean cannot hold:
   one collective debit for several creditor invoices (a payment order booked as one entry), one
   invoice paid in two instalments, a debit that includes a fee. Proposal: a claim carries an
   amount; a movement is «open» while movement amount − sum of claims ≠ 0. Stage 1 may restrict the
   screen to the simple case, but the table must allow n claims per movement.
2. **«The movement is then taken for every other module» needs a database guarantee, not a
   convention:** claim inside the consumer's own unit of work, movement row locked first, remainder
   checked after the lock (the lock discipline debtor already follows for `book()`).
3. **«Open in financial = the reconciliation» is too optimistic** (2.2). The reliable reconciliation
   is the **balance comparison**; the open list is a work aid and needs a manual «erledigt» with a
   reason (the equivalent of debtor's `ignored`).
4. **IBAN → ledger account has an owner problem.** Today it lives in debtor's payment targets.
   Financial cannot read them. A second mapping in financial duplicates a setting (Rule 2). See
   decision 3.
5. **The DMS as the place for the camt files** (handoff: «in the DMS, 10 years») — financial has no
   DMS dependency, and a bank statement in a Drive is visible to whoever sees that folder. Debtor's
   archive pattern (own directory, checksum, no delete path) fits better. See decision 6.
6. **Monthly upload vs. «Bezahlt am for all invoices»:** with one statement a month an invoice paid
   on the 3rd is proposed as paid on the 1st of the next month at the earliest. In the direct
   variant that delays the posting, not its date — fine for the books, but the journal shows «unpaid»
   for weeks. Uploading more often must be harmless (overlapping statements, 2.5).

### 2.5 Overlap with debtor's camt.054

- A customer payment appears in both files, with **different `MsgId`** — message dedup does not see it.
- **`AcctSvcrRef` across both is not reliable as designed today:** debtor stores the reference of the
  transaction level (`TxDtls/Refs/AcctSvcrRef`) and it is unique per message only. A camt.053 often
  shows QR credits as ONE collective entry (the details only in camt.054); then the references sit
  on different levels and there is no 1:1 pair. Whether LUKB does this is an open fact (§3).
- **Recommendation: do not dedup across the two stores — separate their roles.**
  - debtor's `bank_transaction` stays the receivables work queue and the only path that books a
    customer payment (rule in `debtor.md`: a bank credit goes through `BankImportService`).
  - financial's movement is the copy of the account. A movement never posts by itself (2.6), so
    storing the same credit twice cannot produce a double posting.
  - The double posting risk appears only when a second consumer may claim **credits**. Until debtor
    is a claimer (stage B5), credits are shown but not claimable by a posting consumer; they can be
    marked «erledigt» by hand.
  - In B5 debtor claims the movement in `book()`; matching by entry reference where the bank gives
    one, else by date + amount (a collective entry is claimed by several payments — again the claim
    with an amount).
- **camt.053 against itself:** a statement downloaded twice for overlapping ranges can carry a new
  `MsgId`. So dedup on the movement: unique `(bank account, entry reference)`; without a reference a
  fingerprint (booking date, value date, amount, sign, text, position within the day) — flagged, not
  silently merged. Continuity check: opening balance of a statement = closing balance of the previous
  one, and the statement sequence number without a gap; a gap is named, not blocked.

### 2.6 What «claimed» means for posting

- **The movement is evidence and a proposal, never a posting source.** ADR-042: a generated entry
  belongs to the module that posted it. The creditor invoice stays the source (`creditor:{id}`), the
  run «Journal verbuchen …» stays the only way to post.
- A claim **sets** on the invoice: `paid_on`, CHF `amount` (foreign invoice), `exchange_rate` when
  the bank states one. While claimed, these fields are locked — «release» first. Otherwise the
  invoice and the bank drift apart silently.
- A claim **checks**: the invoice's credit account («Bezahlt mit» → Haben) equals the ledger account
  of the movement's bank account. Different → refused with a German sentence.
- Release: on delete, on «Verwerfen», on «unpaid again». A **posted** invoice keeps its claim; it is
  released only through the module's own correction path.
- **Which date becomes `paid_on` — booking date or value date?** The bank's closing balance is by
  booking date. Debtor books payments on the value date. Around a month or year end the two differ
  and the balance comparison shows a difference that is no error. See decision 5.
- **Rate:** receipt plan T1 stores `exchange_rate` only when CHF = original × rate (F12). The bank's
  CHF may include a surcharge. Rule: take the bank's CHF always; store the rate only when the bank
  states it and it multiplies out to the CHF amount within rounding.

### 2.7 Reconciliation and the close check

- **Balance comparison (cheap, robust):** per bank account and statement date — ledger balance of
  the account (by posting date) against the statement's closing balance. Needs no claims.
- **Close check** through the existing registry (`financialConfig → openWorkChecks → period-close`):
  - a **WARNING** when the last statement in the year does not reach the year end, or the balances
    differ (with the amount);
  - not BLOCKING at first: timing differences (booking vs. value date) and accounts without camt
    would block a correct close. Raise to blocking later, per account, once it has proven itself.
- **Open list:** tabs «Offen» / «Zugeordnet» / «Erledigt». It only becomes a true reconciliation when
  manual entries can be linked too (stage B6: «Buchung aus Bewegung» — a prefilled manual entry for
  fees, interest, salary). Until then «erledigt» with a reason.

### 2.8 Matching rules and their risks

| Case | Sure (proposed) | Possible (listed, the owner picks) |
|---|---|---|
| CHF, QR / SCOR reference | reference equal + amount equal | — |
| CHF, bank transfer | creditor IBAN equal + amount equal, date in the window | amount equal + name in the text |
| Foreign, FX structured | original amount + currency equal, date in the window | name in the text |
| Foreign, FX only as text | — | name in the text + date window; text parser as a hint |

- Window: invoice date − 3 days … + 60 days (config), debits only, same ledger account as «Bezahlt mit».
- **Nothing is claimed automatically.** One «sure» candidate → proposed in the window; several or
  only «possible» → a list. A later «alle sicheren übernehmen» for ticked rows is a convenience, not
  stage 1. Same principle as debtor: `matched` is a proposal until the office confirms.
- **Merchant text ≠ creditor name** (the card text says e.g. «EXAMPLE MERCHANT», the contact is
  «Example Inc.»). A per-creditor field «Banktext» (alias), proposed from the first manual
  assignment. This needs one small creditor migration (the plan promised none for stages 2–4).
- **FX only as free text:** a bank-specific text parser is fragile (the bank may change the text any
  time). If needed: an opt-in strategy class per bank, registered in config (Rule 9), its result
  marked «aus Text gelesen», never «sure». The feature does not depend on it — the candidate list by
  name + date still delivers the CHF amount.
- **Same amount twice** (subscriptions: same merchant, same amount every month): the date window
  decides; two candidates → never «sure».
- **Credit cards:** a debit card shows each purchase as its own entry. A credit card shows ONE
  monthly settlement — single purchases cannot be matched. The service does not solve those.
- **Collective debit** of a payment order: several invoices, one movement (2.4 point 1).

### 2.9 Several bank accounts, multi-currency

- Each statement names its account (`Acct/Id/IBAN`, `Acct/Ccy`). Mapping IBAN → ledger account is
  master data (decision 3). Unknown IBAN → refused with a German sentence naming where to create it
  (debtor's behaviour).
- **Stage 1: base-currency accounts only.** A statement with `Acct/Ccy` ≠ CHF is refused. A foreign
  currency account kept in a CHF ledger needs valuation — excluded by ADR-042 decision 4; that
  would be a new ADR, not a side effect.
- The second account in the handoff (1021) is at a non-Swiss bank: whether it delivers camt.053 at
  all, in which version, and in which currency is open (§3).

### 2.10 Retention and privacy

- The camt file is a booking record; the owner's rule (10 years after the fiscal year end, no
  delete, no overwrite) applies. Debtor's camt.054 file is not stored today — a gap by the same
  rule; closed in stage B5 with the same archive function.
- A statement can span two fiscal years → retention from the later one.
- Privacy: the movements carry names, IBANs and texts of third parties and possibly private
  payments. Measures: own access right for the bank screens (not every bookkeeping user), nothing of
  it in logs or error mails, the archive outside the web root, no movement data to the AI step of
  creditor (stage 3) without a separate decision, a sentence for the installation's privacy notes.
  Purge after the retention period is a later building block, but the model must not prevent it.
- Security: upload through the shared upload component and an `UploadPolicy` (XML / ZIP, size caps,
  the ZIP limits of `BankImportArchive`); XML without network and entities; every value escaped on
  output (bank text is foreign input).

---

## 3. Open facts — what to check in the real LUKB camt.053

The z77.ch session reads the file locally and reports **structure only** (element names, presence,
counts) — no amounts, names, IBANs or references in any committed file or handoff.

1. **Version and root:** `camt.053.001.04` or `.08`; `Document/BkToCstmrStmt`; one or several `Stmt`.
2. **Statement header:** `Stmt/Id`, `ElctrncSeqNb`, `LglSeqNb`, `FrToDt`, `Acct/Id/IBAN`, `Acct/Ccy`.
3. **Balances:** `Bal` with `Tp/CdOrPrtry/Cd` = `OPBD` and `CLBD` (also `CLAV`, `ITBD`?), each with a date.
4. **The card debit (the trigger):**
   - `Ntry/AmtDtls/InstdAmt/Amt @Ccy` (original amount) — present? Also on `NtryDtls/TxDtls/AmtDtls`?
   - `…/AmtDtls/TxAmt/CcyXchg` with `SrcCcy`, `TrgtCcy`, `XchgRate` (also `CntrValAmt/CcyXchg`) — present?
   - if not: in which element is the text — `AddtlNtryInf`, `TxDtls/AddtlTxInf`, `RmtInf/Ustrd`? Is
     it one line or several? Fixed labels («Betrag», «Wechselkurs», «Kursdatum») or free?
   - the merchant: `RltdPties/Cdtr/Nm` (or `…/Pty/Nm` in `.08`), or only in the text?
   - is a foreign surcharge part of the CHF amount, or a separate entry / `Chrgs`?
   - `BkTxCd` (`Domn/Cd`, `Fmly/Cd`, `SubFmlyCd`, `Prtry`) of a card entry — usable to recognise cards.
5. **References:** is `AcctSvcrRef` on the entry (`Ntry/AcctSvcrRef`), on the transaction
   (`TxDtls/Refs/AcctSvcrRef`), or both? Stable across two downloads of the same period?
6. **Collective entries:** are QR credits one entry per payment or one per day (`NtryDtls/Btch`,
   `NbOfTxs`)? With `TxDtls` or without? Same question for an outgoing payment order with several
   payments (setting «Einzel- / Sammelbuchung» in e-banking).
7. **Against a camt.054 of the same day:** does a credit carry the same reference in both, and on
   which level?
8. **A plain CHF creditor payment:** `RltdPties/Cdtr/Nm`, `CdtrAcct/Id/IBAN`, `RmtInf/Strd/CdtrRefInf`
   (QRR / SCOR) — present on debits? (Matching by IBAN + reference depends on it.)
9. **Dates:** `BookgDt` and `ValDt` both present; do they differ for card entries (transaction date
   vs. booking date — the handoff example shows two dates)?
10. **Reversals:** `RvslInd`, and how a refunded card payment looks.
11. **Download behaviour:** does a second download of the same month give the same `MsgId`? Can a
    free date range be chosen (overlaps)? Daily or monthly statements, and is there a ZIP?
12. **The second bank (account 1021):** camt.053 available at all? Version, currency of the account.

Not open any more: the e-banking **display** shows original amount, currency, rate, merchant and
date (handoff). That proves the bank has them, not that the file carries them as fields.

---

## 4. Recommendation

**Variant (c), the shared service in module-financial — but cut differently and gated:**

1. **First (a):** foreign currency by hand on the creditor invoice (stage A0). It solves the trigger
   in days, is the fallback forever, and defines the fields the service later fills.
2. **Then (c) in stages**, and **no line of it before the real file is read** (§3). The file decides
   whether FX matching is «sure» or only a candidate list — the design must not be guessed twice.
3. **The movement is evidence, the module posts.** Claims with an amount. No automatic claim.
4. **Reconciliation = balance comparison first**, the open list second; the close check warns.
5. **(d) as stage B5:** debtor becomes a claimer; its import is not rebuilt.

Reasons: receipt needs the same data (F1), so (b) breaks Rule 9 on the second user; financial is the
only package all three consumers may depend on and the owner of the ledger account and the close;
and (a) first keeps the owner independent of the bank file, the matching quality and the schedule.

**What it does not do — say so in the help:** credit-card purchases, banks without camt.053,
foreign-currency accounts, automatic posting.

### Owner decisions

1. **Build order: (a) first, then the service?** — Recommendation: yes. A0 right after creditor
   stage 2.
2. **Where the service lives:** module-financial, namespace `Z77\Module\Financial\Bank\` — or an own
   package `module-bank`. — Recommendation: financial (it owns account, balance and close; an own
   package brings a package skeleton and one more dependency for three consumers, no gain today).
3. **IBAN → ledger account, one source (Rule 2):** new master data «Bankkonten» in financial; debtor's
   payment target keeps its own account number for now, and a check names a contradiction (same
   IBAN, different account). In B5 the payment target reads the account from the bank account. —
   Recommendation: as described. Alternative: an IBAN field on the ledger account itself (simpler,
   but one account then cannot carry IBAN + QR-IBAN cleanly).
4. **A claim carries an amount** (n claims per movement, partial) instead of «movement taken: yes /
   no». — Recommendation: yes in the model; the first screen handles 1:1 and «several invoices on one
   debit».
5. **`paid_on` = booking date of the bank** (matches the bank's balance) — or value date (as debtor
   books payments). — Recommendation: booking date for creditor; the difference to debtor is named in
   the reconciliation, not forced equal now.
6. **Where the camt file is kept:** own archive `data/archive/financial/bank/<YYYY>/` (debtor's
   pattern: checksum, no delete path) — or the DMS with `retentionUntil` (your first idea). —
   Recommendation: own archive. No DMS dependency for financial, and the statement is not visible in
   a Drive. Your 10-year rule holds either way.
7. **Close check: warning or blocking?** — Recommendation: warning (balance differs / statement does
   not reach the year end); blocking per account later.
8. **Credits before debtor is a claimer:** visible, «erledigt» by hand, not claimable by a posting
   module. — Recommendation: yes (no double posting possible).
9. **FX only as text in the file:** build a LUKB text parser — or only the candidate list (name +
   date) and you confirm? — Recommendation: candidate list first; a parser only if the file shows a
   stable, labelled text, and then as an opt-in strategy per bank.
10. **Field «Banktext» on the creditor** (alias for the merchant text, one small creditor
    migration). — Recommendation: yes, in B2.
11. **Foreign-currency bank accounts:** refused in stage 1 (ADR-042). — Recommendation: yes; a new
    ADR if the account at the second bank needs it.
12. **Shared camt reading:** move the generic part of debtor's `CamtReader` (entry, party,
    remittance, dates, decimals) to kernel shared (`Z77\Shared\Payment\Camt\`, like `Iban` /
    `QrReference` in creditor stage 1) — or a second reader in financial with a noted duplication. —
    Recommendation: extract in B1 (+4 h, debtor's harness must stay green); two readers of the same
    standard drift apart, as the `.08` party fix showed.
13. **Service before or after creditor stage 3 (AI)?** — Recommendation: A0 → B1 → B2 → stage 3, if
    the real file carries usable data (creditor IBAN / reference on debits, card merchant). If it
    does not, stage 3 first. Note: a foreign PDF without a QR part is read by the AI only — until
    stage 3 its original amount is typed.
14. **Access right** for the bank screens separate from the journal. — Recommendation: yes.

---

## 5. Plan for the recommended variant

### 5.1 Where it lives

| Part | Place |
|---|---|
| Generic camt parsing (entry, party, remittance, amount details) | `packages/kernel` shared, `Z77\Shared\Payment\Camt\` (decision 12) |
| camt.053 reader, entities, repositories, services, close check | `packages/module-financial/src/Bank/…`, `Entities/`, `Repositories/`, `Close/` |
| Screens (traits + layout) | `packages/module-financial/src/Ui/`, thin hosts in `module-backend` `Finance/…` (the pattern of `BankImportControllerTrait`) |
| Creditor use | `packages/module-creditor` (calls financial's service — the dependency exists) |
| Receipt use | `packages/module-receipt` (dependency exists) |
| Debtor use | `packages/module-debtor`, only when financial is installed (debtor suggests financial — optional, like its posting) |
| Docs | new topic `docs/topics/bank.md` (trigger map entry), ADR «bank account service», build plan in `docs/03-development/`, help pages, `CHANGELOG.md` |

### 5.2 Entities (financial; table names as proposals)

- **`BankAccount`** (`bank_account`): code, label, IBAN (normalised, unique), currency (stage 1 = base
  currency), ledger account number (must be a postable active account), active.
- **`BankStatement`** (`bank_statement`): bank account, `MsgId` (unique), statement id, sequence
  numbers, from / to, opening and closing balance with dates, created at the bank, file name, file
  checksum (SHA-256, unique), archive path, importer, imported at.
- **`BankMovement`** (`bank_movement`): bank account, statement, position, entry reference, a
  fingerprint, direction (debit / credit), reversal flag, booking date, value date, amount (CHF,
  `Money`), original amount + currency + rate + rate source (`structured` / `text` / none), bank
  transaction code, counterparty name, counterparty IBAN, reference type + reference, text
  (unstructured + additional info), manual state `done` with reason and actor. Unique
  `(bank_account_id, entry_ref)` where a reference exists. `open` is **derived** (amount − claims),
  never stored — the way debtor derives the open amount.
- **`BankClaim`** (`bank_claim`): movement, **owner key** (opaque string like the ledger's
  idempotency key: `creditor:17`, `receipt:204`, `debtor-payment:88`), owner label (for the list),
  amount, claimed by / at. Unique `(movement_id, owner_key)`. Financial never resolves the key.

One migration in B1 with all four tables (the creditor stage-1 approach: later stages need none).

### 5.3 Services (financial)

- **`Camt053Reader::read($xml)`** → pure data (statement, balances, entries); refuses anything that
  is not `BkToCstmrStmt`. Built against the real file's structure and synthetic fixtures.
- **`BankStatementService::import($bytes, $fileName)`**: checksum and `MsgId` dedup, IBAN → bank
  account (unknown → refused), currency check, movements stored (movement dedup, 2.5), continuity
  check (balance chain, sequence gap → note), file written to the archive after a verified checksum,
  one unit of work. ZIP through the limits of debtor's `BankImportArchive` (moved to shared with the
  reader, or reused by pattern).
- **`BankMovements`** — the port for consumers:
  - `candidates(BankQuery)`: ledger account, direction, date window, CHF amount OR original amount +
    currency, reference, counterparty IBAN, text needles → candidates with a grade `sure` / `possible`
    and the reason in German. Only movements with an open remainder.
  - `claim(movementId, ownerKey, ownerLabel, ?Money amount)`: locks the movement row, re-reads the
    remainder, refuses an over-claim, a foreign ledger account, a credit before B5. Runs in the
    caller's unit of work.
  - `release(ownerKey)`, `claimsOf(ownerKey)`.
  - Refusals as one exception with reason codes (the `BankImportRefusedException` model); lock
    conflicts answered like debtor's (`LockConflict`).
- **`BankReconciliation`**: per bank account and date — statement balance, ledger balance
  (`LedgerReports`), difference, open movements.
- **`Close/BankReconciliationCheck`**: registered under `financialConfig → openWorkChecks →
  period-close`; warnings as in 2.7.

No debug or convenience helpers on stock; the reader's structure probe for the real file is ad-hoc
and removed after the owner has seen its output.

### 5.4 How the consumers use it

- **Creditor (B2):** in the invoice window a field section «Bankbewegung»: the proposal (date, CHF,
  text, grade) with «Übernehmen», or «Bewegung wählen …» (candidate list). `CreditorInvoiceService`
  calls `claim()` in its own unit of work and sets `paid_on` / `amount` / `exchange_rate`; the three
  fields are locked while claimed; «Zuordnung lösen» releases. For ticked rows: «Aus Bank übernehmen
  …» next to «Bezahlt am …» — takes only `sure` proposals, names the rest. «Bezahlt am …» by hand
  stays. Release in delete / «Verwerfen» / unpaid. Harness sections in `tests/module-creditor.php`.
- **Receipt (B4):** a card receipt in foreign currency takes its CHF from the movement (F1); built
  with or after receipt's FX plan, not before.
- **Debtor (B5):** `book()` claims the credit movement when financial is present; the payment target
  takes its ledger account from the bank account; the camt.054 file goes to the archive.
- **Manual entries (B6):** «Buchung aus Bewegung» — a prefilled manual entry, claimed by its entry.

### 5.5 Screens (backend-screen recipe; board on the canvas before building each)

| Screen | Action cell | Toolbar | Content |
|---|---|---|---|
| «Finanzen › Bankkonto» (movements) | upload «camt.053 Upload» (the most frequent action; shared upload component in its `cell` shape, drop on the work area) | tabs «Offen» · «Zugeordnet» · «Erledigt» · «Auszüge»; account select; search | standard list: date, text, counterparty, amount, original amount, state, claimed by; row window with all fields and «Erledigt …» |
| Tab «Auszüge» | same | same | standard list: period, opening / closing balance, ledger balance, difference, movements open |
| «Stammdaten › Finanzen › Bankkonten» | empty or «Neu» per the recipe | — | standard list + window (IBAN, ledger account, active) |
| Creditor invoice window | unchanged | unchanged | field section «Bankbewegung» |

Help with field sections for each; one look per control kind; no new JS logic — the server decides
grade, lock and refusal; bank text is always escaped (Rule 7).

### 5.6 Stages — each deployable

| # | Scope | Effort | Risks |
|---|---|---|---|
| **A0** | Creditor: foreign currency by hand — original amount + currency, CHF free and may stay empty (incomplete until filled), posting text «… · 5.41 USD», currency list at ONE place (receipt's `foreignCurrencies`, created now if receipt's FX is not built first). No migration (columns exist). | 8–12 h | defines a piece of receipt's FX plan early (F2–F5, F7, F12) — must follow it, not re-invent it |
| **Gate** | Real LUKB file read by the z77.ch session, structure reported (§3); decisions 1–14 | owner + 1–2 h | — |
| **B1** | Shared camt parsing (decision 12); `BankAccount` master data; `Camt053Reader`; statements + movements + archive; upload; the movements screen with «Erledigt»; continuity check; topic doc, ADR, help; harness. ONE migration (all four tables). | 30–36 h | real-file surprises; extraction touches debtor; access right; ZIP / upload limits |
| **B2** | `BankMovements` port (candidates / claim / release); creditor use (window, ticked rows, locks, release paths); «Banktext» on the creditor (one creditor migration) | 20–26 h | matching quality; lock order between creditor's run and claims; the creditor window is being changed by stages 2 / 3 |
| **B3** | `BankReconciliation`, tab «Auszüge» with balances, close check (warning) | 8–10 h | booking vs. value date differences read as errors |
| **B4** | Receipt as consumer (card receipts, F1) | 8–12 h | depends on receipt's FX build |
| **B5** | Debtor: claims in `book()`, payment target reads the bank account, camt.054 file archived | 12–16 h | touches the working import; collective entries; debtor without financial must keep working |
| **B6** | «Buchung aus Bewegung» (manual entry from a movement) — makes the open list a full reconciliation | 8–10 h | invites booking without a document — help text and reason required |

Total: A0 8–12 h; B1–B3 (the owner's idea, usable for creditor) 58–72 h; B4–B6 28–38 h.
Everything ≈ 95–120 h. Deploy each to z77.ch through the z77.ch session, DB backup before a migration.

### 5.7 Order relative to creditor stages 2 and 3

1. **Creditor stage 2** (text layer + folder job) is finished and deployed first — it is being built
   now in `packages/module-creditor/**`; nothing of this plan touches that package before.
2. **A0** directly after stage 2 (same window, small).
3. **Gate** in parallel: the file can be read any time.
4. **B1** touches financial, kernel shared and debtor's reader only — it can run **in parallel to
   creditor stage 3 (AI)** if two sessions work, otherwise before it (decision 13).
5. **B2** touches the creditor window — never at the same time as stage 3. Order by decision 13.
6. **B3** after B2; **B4** with receipt's FX build; **B5**, **B6** when the open list is used daily.

### 5.8 What must be written down before building

- ADR «bank account service»: movement = evidence, module posts; claim with amount; no automatic
  claim; base-currency accounts only; archive and retention; debtor's role until B5.
- `docs/topics/bank.md` (new, trigger map), `financial.md`, `creditor.md`, `debtor.md` (`## see also`
  and the DEBTOR-CAMT pendenzen that change), `docs:check` green.
- Board(s) on the developer canvas for the three screens.


## Owner decisions (2026-11-15)

1. A0 first (CHF by hand on the creditor invoice, stays as fallback), then the service in stages. — owner: «ja A0 zuerst, dann der Dienst»
2. Place: module-financial (`Z77\Module\Financial\Bank\`), no own package. — owner: «ja financial»
3. IBAN → ledger account: new master data «Bankkonten» in financial (IBAN, ledger account, label); debtor payment targets read from it later (Rule 2), until then a check reports contradictions. — owner: «ja so machen»
4. A claim carries an amount; several claims per movement; settled when the claims sum to its amount. — owner: «ja mit Betrag»
5. «Bezahlt am» = the bank's booking date (matches the statement balance), not the value date; debtor switches to the booking date as well (today value date). — owner: «ja Buchungsdatum, Debitoren auch umstellen»
6. camt.053 file: own archive `data/archive/financial/bank/<YYYY>/` (like debtor camt.054), not the DMS; 10 years apply, part of the «Daten» backup. — owner: «ja eigenes Archiv»
7. Close check for unclaimed movements: a setting per bank account in the master data «Bankkonten» («beim Abschluss prüfen» ja/nein, default nein) — an account with private movements cannot be checked. When on: warning. — owner: «es müsste im bankenstamm gesetzt werden können prüfen ja/nein»
8. Credits on the statement: until debtor uses the service they are visible, can be set «erledigt» by hand, but cannot be claimed (no double posting with camt.054). — owner: «ja einverstanden»
9. FX only as text in the file: candidate list (merchant name + date + CHF amount) the owner confirms with one click; a per-bank text parser only if the text proves stable, switchable per bank. — owner: «ja Kandidatenliste»
10. Creditor field «Banktext» (search term for the merchant text on the statement), small migration, built in B2. — owner: «ja einverstanden»
11. Foreign-currency bank accounts refused in stage 1 with a clear message. 1021 is a EUR account — later, with its own ADR (EUR balance, FX differences at close); first 1020 (CHF). — owner: «später, zuerst 1020»
    Correction (owner): the stage-1 account is 1022 = LUKB (CHF; the real camt.053 comes from it — confirm with the file). 1020 = former UBS account, not in use. 1021 = EUR, later.
12. Shared camt reading part moves from debtor to kernel shared (+4 h, in B1); debtor and financial both use it. — owner: «ja verschieben»
13. Order: A0 → B1 → B2 → creditor stage 3 (AI); AI first if the real LUKB file carries little usable data. The 20-invoice AI trial can happen any time. — owner: «ja einverstanden»
14. Own access right «Bankkonten» for the bank screens, separate from the journal rights. — owner: «ja einverstanden»
