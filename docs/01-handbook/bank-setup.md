# Bank-driven bookkeeping — setting it up and extending it

**Status:** `[CURRENT]`
**Last updated:** 2026-11-19

For the developer who sets up a client project (with an AI coding assistant) and for the one who
joins another module to the bank. Compact on purpose: the names are verified against the code;
every detail lives in [`bank.md`](../topics/bank.md) (the source of truth). Screen labels in
«…» stand exactly as on screen.

## 1. What it is

- `module-financial` reads **camt.053** statements per bank account (`BankStatementService::import()`)
  into one seamless chain, archives the file write-once, and stores every booked entry as a `BankMovement`.
- Every movement on a *bank-driven* account has a **derived** state, never stored: «geschäftlich»
  (linked to a record of a module, or booked via «Geschäftlich buchen …»), «privat» (a confirmed
  learned pattern, or marked by hand), «zu prüfen» (everything else — nothing is private by silence).
- Debtor, receipt and creditor **find their payments** among the movements (a `BankLink` carries the
  opaque owner key); financial names none of them.
- «Abgleich ab» (per account) is the day from which the bank drives the bookkeeping; the **anchor**
  confirms once the difference bank ↔ ledger; the **check** compares *changes*.
- The period close (and, once built, the VAT return) **blocks** on: statement not read to the end,
  movements «zu prüfen», a check difference, the transfer account ≠ 0.

## 2. Setup order

1. **Mandator accounts** — Stammdaten › Firma › Mandant, tab «Konten»: «Privat» and «Übertrag eigene
   Bankkonten» (keys `private`, `bank-transfer`). They start **empty**: no KMU default, the shipped chart
   holds neither; «Privat» / transfer postings are refused naming the mandator until both exist.
   Read only through `LedgerService::bankPostingAccount()`.
2. **Master data** — Stammdaten › Finanzen › «Bankkonten», one row per IBAN: «Bezeichnung», «Kürzel»
   (immutable), «IBAN» (immutable once a statement was read), «Konto» (the ledger account — the one place that ties
   IBAN and ledger account), then the switches (all off by default):
   - «Kontoauszug einlesen» — without it the account is information only.
   - «gemischt geführt (privat und geschäftlich)» — only a mixed account knows «privat» (selection
     «Privat …», views «Geschäftlich» / «Privat», column «Stand»). Switching it off is refused while marks
     by hand or private / mixed patterns exist (`not-mixed` for «Privat» on a non-mixed account).
   - «Abgleich ab» — without the date nothing is bank-driven. Set it to the **first day the first statement
     covers**: the chain cannot be extended in front (BANK-START-001, BANK-CHECK-001).
   Never deleted; a migration never sets the date.
3. **Payment methods** — Stammdaten › Finanzen › Zahlungsarten (module-receipt), field «Bankkonto» on the payment
   method: it takes its credit account from the bank account; a method without one (cash) keeps its own.
4. **First camt.053 upload** — Finanzen › «Bankkonto». A click on the action cell «camt.053 Upload» opens the
   window with one row per account: «Eingelesen bis» and «Laden ab» (= the last stored day); «Dateien wählen …»
   then reads XML or ZIP. Files dropped on the page are read at once. Oldest file first.
   **The overlap rule:** every later statement must *overlap the last stored day* — download from
   «Laden ab», not from the day after. A file starting after it is refused (`no-overlap`); an overlap whose
   balance does not match is refused (`chain-mismatch`). Consequences: quarter files 01.01.–31.03. and
   01.04.–30.06. no longer join (BANK-OVERLAP-002); a bank that offers only fixed periods cannot be continued
   (BANK-OVERLAP-001).
5. **camt.054 (debtor side)** — customer payments are *collective credits* in the camt.053; only the
   camt.054 names them. Upload in Auftragswesen › Zahlungseingänge («camt.054 Upload»); «Bankabgleich …»
   there confirms proposals. Order does not matter: a camt.054 read after the statement links at its own
   import, a statement read after it is linked by the job `debtor-bank-link`. → [`debtor.md`](../topics/debtor.md)
   «ADR-061 stage C3b».

A fresh project then sees only information until step 2's date is set; the anchor line above the list
«Bewegungen» asks for «Bestätigen …» once. Full cookbook position: [business-suite-setup.md](business-suite-setup.md) step 9.

## 3. Configuration a project may change

A module config override **replaces the whole config** (BOOT-CONFIG-001): copy every key you keep.

| Key | Where | Meaning |
|---|---|---|
| `bankPosting.voucherFromGross` (`'100.00'`) | financialConfig | from this gross, «Geschäftlich buchen …» with a tax code needs a file |
| `bankMatch` `daysBefore` 2 / `daysAfter` 10 | receiptConfig | days around the receipt date a movement is searched |
| `bankMatch` `daysBefore` 3 / `daysAfter` 45 / `daysAroundPaid` 5 | creditorConfig | window around invoice / due date / «Bezahlt am» |
| `foreignCurrencies` (`['EUR','USD']`) | receiptConfig | currencies a foreign-currency amount may carry |
| `openWorkChecks` (`period-close`, `vat-return`) | financialConfig | registers `BankReconciliationCheck` — keep it |

Jobs (shipped `every:1m`, delete nothing): `debtor-bank-link` («Debitoren-Bankabgleich», debtorConfig) and
`creditor-intake` («Kreditoren-Eingang», creditorConfig) — the creditor side links reference identities on its
next pass; with the job off only «Zahlungen suchen …» in the Kreditorenjournal does. Financial's import knows
no owner and never calls a module (BANK-LINK-002).

## 4. Extending — another module joins the bank

Declare classes by name in your module config; they are instantiated without arguments and financial names
no consumer (Rule 9). Pick the link API first: `BankLinkService::freeMovements()` → `link()` inside your **own**
unit of work → `release()` in the same unit of work as your own correction.

- **`bankEntryOwners`** — `Z77\Module\Financial\Bank\BankEntryOwnerInterface::ownerKeys(array $entries): array`
  (journal entry id → owner key). Needed when your module posts on a bank's ledger account; without it its
  postings show as «Buchung ohne Bewegung» and block the close. Example:
  `Z77\Module\Creditor\Services\CreditorBankEntryOwner` (origin `creditor` → `creditor:{id}`); also
  `DebtorBankEntryOwner`, `ReceiptBankEntryOwner`.
- **`bankMovementClaims`** — `BankMovementClaimInterface::claimOn(BankMovement, UnifiedEntityManager): ?string`
  returns the German refusal sentence when the movement is yours to link; «Privat» is then refused
  (`claimed`). Example: debtor's `CollectiveCreditBankClaim` (credit coded `PMNT/RCDT/VCOM` on an IBAN of an
  active payment target).
- `bankAccountHints` (`BankAccountHintInterface`) — optional contradiction line in the bank account window
  (debtor's `PaymentTargetBankAccountHint`).

Never write `bank_link` or `bank_statement` / `bank_movement` rows yourself; never parse camt outside
`Z77\Shared\Payment\Camt\CamtParser`.

## 5. Operations

- **Archives** (write-once, never deleted, booking records kept ten years):
  `data/archive/financial/bank/<YYYY>/` (camt.053 as read), `data/archive/financial/bank-voucher/<YYYY>/`
  (files attached to a direct posting; with no tax code the archived camt.053 is the voucher),
  `data/archive/debtor/bank/<YYYY>/` (camt.054). Back them up with `data/`; not the DMS.
- **Refusals** — `BankRefusedException` reason codes (the message is a German sentence):

| Code | Meaning |
|---|---|
| `not-xml`, `not-camt053`, `incomplete` | not a readable statement (no balances, no booking date, sums disagree) |
| `duplicate-file`, `duplicate-message` | same SHA-256 / same message id already stored |
| `account-unknown` | IBAN is an inactive account, or one among known accounts that is none |
| `not-bank-account` | no statement of the file belongs to a bank account here — **skipped**, named in one neutral line |
| `import-off` | «Kontoauszug einlesen» is off |
| `foreign-currency` | currency differs from the base currency |
| `no-overlap` | file starts after the last stored day — download from «Laden ab» |
| `chain-mismatch` | overlap does not reproduce the stored balance |
| `before-start` | movements older than the account's first statement |
| `not-driven` | decision on a movement before «Abgleich ab» / without the switch |
| `not-mixed` | «Privat» on an account not «gemischt geführt» |
| `claimed` | another module claims the movement |
| `state-business`, `pattern-question`, `no-pattern` | state decisions (linked movement; unanswered pattern question) |
| `link-gone`, `link-taken`, `link-owner`, `link-amount` | `BankLinkService::link()` refusals (one link per movement, full amount) |
| `anchor`, `archive` | anchor cannot be confirmed; archive write failed |

  A direct posting has its own `BankPostingRefusedException`; a bank account its `InvalidBankAccountException`.
- **Closed year / settled VAT period:** «Buchung zurücknehmen» is refused by the ledger's period rules.
- Tests: `php tests/run.php finance` ([`testing.md`](../topics/testing.md)), harness `tests/financial-bank.php`.

## 6. Links

- [`bank.md`](../topics/bank.md) — source of truth (rules, known issues, pending)
- [ADR-060](../02-decisions/adr-060-bank-account-service.md) — the bank account service (parts superseded by ADR-061)
- [ADR-061](../02-decisions/adr-061-bank-driven-bookkeeping.md) — states, patterns, link, anchor, close; addenda 2026-11-17 and 2026-11-19
- [business-suite-setup.md](business-suite-setup.md) step 9 — where this sits in the project setup
- [`debtor.md`](../topics/debtor.md), [`receipt.md`](../topics/receipt.md), [`creditor.md`](../topics/creditor.md) — the three owners
