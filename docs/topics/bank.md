# bank

2026-11-16

## entry

1. `packages/module-financial/src/Bank/BankStatementService.php` — the camt.053 import: the seamless chain (gap and mismatch refused), the reference cut, the archive — one unit of work
2. `packages/kernel/shared/src/Payment/Camt/CamtParser.php` — the one camt parser (camt.053 / .054 / .052, versions .04 and .08) debtor and financial both read through
3. `tests/financial-bank.php` — the harness: parser, reader, archive offline; master data, import and every case of the chain against MariaDB

## file map

SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtParser.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtMessage.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtReport.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtBalance.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtEntry.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtTransaction.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtParty.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtArchive.php
SOURCE=/packages/kernel/shared/src/Payment/Camt/CamtFormatException.php
SOURCE=/packages/module-financial/src/Bank/Camt053Reader.php
SOURCE=/packages/module-financial/src/Bank/StatementData.php
SOURCE=/packages/module-financial/src/Bank/MovementData.php
SOURCE=/packages/module-financial/src/Bank/BankAccountService.php
SOURCE=/packages/module-financial/src/Bank/InvalidBankAccountException.php
SOURCE=/packages/module-financial/src/Bank/BankStatementService.php
SOURCE=/packages/module-financial/src/Bank/BankImportResult.php
SOURCE=/packages/module-financial/src/Bank/BankStatementArchive.php
SOURCE=/packages/module-financial/src/Bank/BankRefusedException.php
SOURCE=/packages/module-financial/src/Entities/BankAccount.php
SOURCE=/packages/module-financial/src/Entities/BankStatement.php
SOURCE=/packages/module-financial/src/Entities/BankMovement.php
SOURCE=/packages/module-financial/src/Repositories/BankAccountRepository.php
SOURCE=/packages/module-financial/src/Repositories/BankStatementRepository.php
SOURCE=/packages/module-financial/src/Repositories/BankMovementRepository.php
SOURCE=/packages/module-financial/src/Validators/BankAccountValidator.php
SOURCE=/packages/module-financial/src/App/Config/financialConfig.inc.php
SOURCE=/packages/module-financial/res/migrations/Version20261116140000.php
SOURCE=/packages/module-debtor/src/Payments/CamtReader.php
SOURCE=/packages/module-debtor/src/Services/BankImportArchive.php
SOURCE=/packages/module-receipt/src/Entities/PaymentMethod.php
SOURCE=/packages/module-receipt/src/Services/PaymentMethodService.php
SOURCE=/packages/module-receipt/src/Validators/PaymentMethodValidator.php
SOURCE=/tests/financial-bank.php
SOURCE=/tests/module-receipt.php
SOURCE=/docs/02-decisions/adr-060-bank-account-service.md
SOURCE=/docs/03-development/bank-account-service-plan.md
RUNTIME=/data/archive/financial/bank

## mental model

The bank account service (ADR-060) keeps the bank's account statement in z77 as an INFORMATION SOURCE for the creditor journal: when was an invoice paid, and how many CHF did the bank debit for it. A camt.053 file becomes a `BankStatement` with its `BankMovement`s and goes unaltered into an archive; a movement never posts and has no state. What is stored for a bank account is ONE SEAMLESS CHAIN — a file that leaves a gap or whose overlap does not agree with what is stored is refused. The service lives in module-financial (`Z77\Module\Financial\Bank\`) and names no consumer. Stage B1 (2026-11-16, reworked the same day after the owner narrowed the purpose) built the master data «Bankkonten», the import and the archive as services with their harness; the screens wait for the owner's look at their boards.

- **«Bankkonten»** (`BankAccount`, table `bank_account`, `BankAccountService`): `code` (unique, immutable — what a payment method names), `label`, `iban` (normalised, unique, immutable once a statement was imported), `currency` (the base currency; a new account gets it), `ledger_account` (a postable, active account), `active`, and ONE switch, OFF by default: `statement_import` («Kontoauszug einlesen»). Never deleted. It is the ONE place that ties an IBAN to a ledger account.
- **The parser is shared** (`Z77\Shared\Payment\Camt\CamtParser`, owner decision 12): pure data — `CamtMessage` (kind, message id) → `CamtReport` (`Stmt` / `Ntfctn` / `Rpt`: id, sequence numbers, period, IBAN, currency, `CamtBalance`s signed) → `CamtEntry` (`Ntry`) → `CamtTransaction` (`TxDtls`: references, amount, instructed amount with `CcyXchg`, `CamtParty` debtor / ultimate debtor / creditor / ultimate creditor with account IBAN, structured reference, message). Hardened XML (`LIBXML_NONET`, no entity loading). Debtor's `Payments\CamtReader` (camt.054) and financial's `Bank\Camt053Reader` are views of it; `CamtArchive` reads the XML files of a bank's ZIP with fixed limits (debtor's `BankImportArchive` delegates to it).
- **`Camt053Reader::read($xml)`** → one `StatementData` per `Stmt`, one `MovementData` per BOOKED entry (`Sts` = `BOOK`; others are counted in `skipped`). The ENTRY is the movement — it moves the balance. With exactly one transaction its counterparty (creditor of a debit, debtor of a credit, the ultimate party when it carries a name), the account IBAN, the QRR / SCOR reference and the instructed foreign amount with the bank's rate fill the movement; a collective entry keeps the entry's amount and text and counts its transactions (`tx_count`). The text is the remittance message plus `AddtlNtryInf` — on a card purchase the only place that names the merchant. Refused: not XML, not a camt.053, no `OPBD` / `CLBD`, an entry without a booking date or in another currency, and a statement that is not consistent in itself (opening + booked entries ≠ closing).
- **`BankStatementService::import($bytes, $fileName)`** → one `BankImportResult` per statement; all statements of a file in ONE unit of work, or none. Refusals with a German sentence (`BankRefusedException`, stable reason codes): the same file (SHA-256), the same message for the account (`MsgId` + `Stmt/Id`), an IBAN that is no ACTIVE bank account, «Kontoauszug einlesen» off, another currency than the base currency, and the three chain refusals below. Lock order: the bank account rows (by id) first, then the reads, then the writes.
- **The seamless chain** (ADR-060 decision 18). The first statement of an account sets the start. From then on the account is «eingelesen bis D · Saldo C»: D = `to_date` of the NEWEST statement (the date of `FrToDt/ToDtTm`), C = its closing balance (`BankStatementService::coveredTo()`).
  - A file starting after D + 1 day → `gap`, refused, both dates named — even when the balances would join (movements netting to zero could hide in the hole).
  - Otherwise the file's entries are split by the bank's entry reference: known (the account holds them) and new. The join is proven by the balance: C + new must equal the file's closing balance — the file being consistent in itself, that is «the file's balance at the covered end equals C». No match, or a file ending before D that brings anything new → `chain-mismatch`, refused, the three sums named.
  - A new movement dated before the account's start → `before-start`, refused.
  - New = nothing and the file ends on or before D → nothing is stored (no statement row, no archived file); the result says «Nichts Neues».
  - A file with no movement that ends after D and whose balance is C is stored: it moves the covered end.
- **Why the cut is by reference, not by date** (the rule for intraday `ToDtTm`): an export made during day D ends its period on D; the bank may book further entries on D afterwards. They carry new references, the next file brings them as new, and the balance proves the join. A cut «booking date > D» would lose them or refuse every later file.
- **Dedup key**: `bank_movement.dedup_key` is unique per bank account — the bank's `Ntry/AcctSvcrRef`, else `fp:` + a fingerprint (booking date, value date, side, amount, bank transaction code, text, rank among identical entries of the statement; `BankMovement::isFingerprinted()`). A known movement is never touched by a later file.
- **Balance per movement**: `bank_movement.balance` is the account's booked balance after the movement — C plus the new movements in file order; the chain being seamless, it is exact (`tests/financial-bank.php` I20 checks the running sum over all rows).
- **The answer of an upload** is `BankImportResult::message()`: «45 neue Bewegungen, Saldo stimmt (eingelesen bis 31.03.2026, Saldo …).» or «Nichts Neues — das Bankkonto … ist bereits eingelesen bis …».
- **The archive** (`BankStatementArchive`): `data/archive/financial/bank/<YYYY>/<YYYYMMDD>-<bank account code>-<sha256, 16 hex>.xml`, year and date of the (last) closing date in the file. Written inside the unit of work under a temporary name, read back and verified, then moved; an existing path with other bytes is refused; `bytes()` verifies the SHA-256 against the statement row. No delete path except `discardWritten()` after a rollback. Not the DMS. Only a file that stored at least one statement is archived.
- **Not in the model** (owner 2026-11-16, ADR-060 decisions 16, 17): no «Erledigt», no open / assigned state, no switch «Gemischtes Konto», no claim table, no reconciliation, no close check. Which invoice took a movement comes with stage B2 (its own migration; a movement is taken once).
- **Payment methods hang on a bank account** (owner decision 15): module-receipt's `PaymentMethod` has `bank_account_code`; `PaymentMethodService::find()` / `all()` / `active()` resolve the bank account's ledger account on every read, `getCreditAccount()` answers it, and `save()` clears the method's own account. A payment method without a bank account (cash) keeps its own. Existing payment methods carry no bank account and behave as before.
- **What the real file of the stage-1 account showed** (read locally, structure only): camt.053.001.08; ONE statement per export over a free period; `OPBD` / `CLBD` / `CLAV`; `AcctSvcrRef` on every entry; one transaction per entry; QR-bill payments carry their QRR reference and the creditor's IBAN; card and TWINT purchases name the merchant only in `AddtlNtryInf`; a foreign purchase carries `InstdAmt` and `CcyXchg/XchgRate` as fields; booking date ≠ value date on two thirds of the entries.

## rules

- When a camt.053 file arrives (any screen, script or job) → MUST go through `BankStatementService::import()`; MUST NOT write `bank_statement` / `bank_movement` rows any other way — the import owns the chain, the dedup and the archive.
- When changing the import → MUST keep the chain seamless: a gap and an overlap that does not agree are REFUSED with a German sentence naming the dates and balances; MUST NOT store a statement «with a note», and MUST NOT insert a movement into the covered period or in front of its start.
- When deciding which entries of a file are new → MUST go by the bank's entry reference (the dedup key) and prove the join by the balance; MUST NOT cut by comparing booking dates with the covered end (movements booked on that day after an earlier export would be lost).
- When code needs the ledger account of a bank → MUST read it from `BankAccount` (through `BankAccountService` or, for a payment method, `PaymentMethodService`); MUST NOT store a copy of it next to an IBAN or on a payment method (Rule 2).
- When reading payment methods for their credit account → MUST go through `PaymentMethodService` (`find()`, `all()`, `active()`); MUST NOT read `PaymentMethod` straight from its repository — a bank-bound method answers an empty credit account there.
- When a movement would lead to a ledger entry → MUST NOT post from the movement; the owning module posts its document (ADR-060 decision 1).
- When tempted to add a state, a work list, a reconciliation or a close check to the movements → MUST NOT without a new owner decision; the statement is an information source for creditors only (ADR-060 decisions 16, 17).
- When parsing any camt file → MUST use `Z77\Shared\Payment\Camt\CamtParser`; MUST NOT open the XML with an own SimpleXML / DOM call in a module (one parser, hardened once).
- When writing a test, a fixture, a doc or a log line → MUST use synthetic bank data; MUST NOT copy names, IBANs, references, texts or amounts from a real statement into the repository, a log or an error mail.
- When a bank account or a statement is in another currency than the base currency → MUST refuse with the German sentence; MUST NOT convert or store it (ADR-060 decision 8).
- When changing `BankStatementArchive` → MUST NOT add a delete or overwrite path; an archived statement file is a booking record kept ten years.
- When building a bank screen → MUST draw its board first and escape every movement value on output (bank text is foreign input); MUST NOT put matching or refusal logic into JavaScript (Rule 7).

## known issues

- **BANK-SCREEN-001** — don't assume a backend screen exists: stage B1 built the services only. «Stammdaten › Finanzen › Bankkonten», the camt.053 upload, the list «Bewegungen» and the field «Bankkonto» in the payment-method window are drawn as boards (slim form, 2026-11-16) and wait for the owner's look. Until then a bank account can only be created by a script through `BankAccountService`.
- **BANK-ACL-001** — don't assume a separate access right «Bankkonten» exists: the backend knows only the role ladder (`editor` < `admin` < `superUser`). Owner 2026-11-16 («ja c, vorerst admin»): the bank screens use the role `admin` for now, like the journal; a separate per-user right is a building block of its own, built later when needed.
- **BANK-START-001** — don't assume history can be added in front: the first statement of an account sets the start of its chain, and a file bringing earlier movements is refused (`before-start`). Choosing an earlier start means a new beginning for that account — not built. Pick the first file with that in mind (e.g. from 1 January of the first year wanted).
- **BANK-BATCH-001** — don't assume a collective entry is split: a statement entry with several transactions is ONE movement with the entry's amount, without counterparty and reference. The real file of the stage-1 account has none.
- **BANK-FP-001** — don't assume a fingerprinted movement is told apart as safely as one with an entry reference: two exports that cut the same day differently can rank identical entries differently — the balance proof then refuses the file rather than storing something wrong. The stage-1 bank sends a reference on every entry, so this path is a fallback.
- **BANK-SEQ-001** — don't assume the statement sequence number is checked: it is stored only (an e-banking export carries 1 every time). The chain is guarded by dates and balances.
- **BANK-PAGE-001** — don't assume a paginated statement (`GrpHdr/MsgPgntn` with several pages, intermediate balances) was ever read: each page is judged as its own statement if it carries `OPBD` and `CLBD`; not verified against a real paginated file.
- **BANK-CONTRA-001** — don't assume a contradiction between a bank account and a debtor payment target (same IBAN, different ledger account) is reported yet: owner 2026-11-16 — a hint in the BANK ACCOUNT WINDOW only, built with the screens (financial cannot read debtor's payment targets, so the screen's host collects it); debtor reading its payment targets from «Bankkonten» comes later.

## pending

- **Stage B1, screens** — after the owner's look at the slim boards: «Bankkonten» (list + window with the one switch and the contradiction hint), the camt.053 upload (XML / ZIP through `CamtArchive` and the shared upload component) answering with `BankImportResult::message()` or the refusal sentence, the ONE list «Bewegungen» per bank account (date, text, amount, original amount + currency, balance; search by text, amount, date; header «eingelesen bis … · Saldo …»), help texts, navigation seed, the field «Bankkonto» in the payment-method window (BANK-SCREEN-001). Role `admin` (BANK-ACL-001).
- **Stage B2** — the creditor journal looks a movement up and takes it (once): «Bezahlt am», the CHF amount; column «Rechnung» in the movements list; the creditor field «Banktext»; its own migration.
- **Later, only by a new owner decision** — receipt as a second reader (card receipts in foreign currency); debtor: payment date = booking date, payment target reads its ledger account from «Bankkonten», camt.054 file archived. The former stages B3 (reconciliation, close check) and B6 («Buchung aus Bewegung») are dropped (ADR-060 decision 16).
- **Purge after the retention period** — not built; the model does not prevent it.

## see also

- [`financial.md`](financial.md) — the module the service lives in: accounts (`Account`, postable / active — what `ledger_account` is checked against), `LedgerService::baseCurrency()`
- [`debtor.md`](debtor.md) — the camt.054 import that reads through the same parser and stays the only path that books a customer payment
- [`receipt.md`](receipt.md) — `PaymentMethod` / `PaymentMethodService`, where a payment method takes its credit account from a bank account
- [`creditor.md`](creditor.md) — the consumer (stage B2): «Bezahlt am», the CHF amount of a foreign-currency invoice (A0 by hand today)
- [`persistence-doctrine.md`](persistence-doctrine.md) — the transaction port (`getTransaction()->run()`, rollback replaces the EntityManager), `MoneyType` (base currency only), the migrations CLI
- [`money.md`](money.md) — `Money`, string decimals, why no float comes near an amount
- [`../02-decisions/adr-060-bank-account-service.md`](../02-decisions/adr-060-bank-account-service.md) — the binding decisions
- [`../03-development/bank-account-service-plan.md`](../03-development/bank-account-service-plan.md) — the review, the variants and the stage table
