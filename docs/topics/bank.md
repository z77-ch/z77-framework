# bank

2026-11-17

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
SOURCE=/packages/module-financial/src/Bank/BankAccountHintInterface.php
SOURCE=/packages/module-financial/src/Bank/BankAccountHints.php
SOURCE=/packages/module-financial/src/Ui/BankLayout.php
SOURCE=/packages/module-financial/src/Ui/BankAccountControllerTrait.php
SOURCE=/packages/module-financial/src/Ui/BankAccountListing.php
SOURCE=/packages/module-financial/src/Ui/BankMovementControllerTrait.php
SOURCE=/packages/module-financial/src/Ui/BankMovementListing.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/listAction.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/_row.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/act.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/edit.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/edit.help.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankAccountController/list.help.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankMovementController/listAction.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankMovementController/_list.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankMovementController/act.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/BankMovementController/list.help.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/partials/bankAccountSelect.tpl.php
SOURCE=/packages/module-financial/data/framework/routing/navigation.d/module-financial.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/BankAccountController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/BankMovementController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/bankAccountControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/bankMovementControllerConfig.inc.php
SOURCE=/packages/module-debtor/src/Services/PaymentTargetBankAccountHint.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/edit.tpl.php
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
SOURCE=/docs/02-decisions/adr-061-bank-driven-bookkeeping.md
SOURCE=/docs/03-development/bank-account-service-plan.md
RUNTIME=/data/archive/financial/bank

## mental model

The bank account service (ADR-060) keeps the bank's account statement in z77; built today (B1) it is an INFORMATION SOURCE: when was an invoice paid, and how many CHF did the bank debit for it. Decided 2026-11-17, not built (ADR-061): BANK-DRIVEN BOOKKEEPING — on a switched-on account every movement is «geschäftlich», «privat» or «zu prüfen», and the ledger account's change must equal the business movements (stages C1–C5, `## pending`). A camt.053 file becomes a `BankStatement` with its `BankMovement`s and goes unaltered into an archive; a movement never posts and has no state. What is stored for a bank account is ONE SEAMLESS CHAIN — a file that leaves a gap or whose overlap does not agree with what is stored is refused. The service lives in module-financial (`Z77\Module\Financial\Bank\`) and names no consumer. Stage B1 (2026-11-16, reworked the same day after the owner narrowed the purpose) built the master data «Bankkonten», the import and the archive as services with their harness; the three screens followed on 2026-11-17 after the owner approved the slim boards.

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
- **Built today, B1** (ADR-060 decisions 16, 17 — superseded by ADR-061, not yet replaced in code): no state on a movement, no link to an invoice, no reconciliation, no close check.
- **Bank-driven bookkeeping** (ADR-061, owner 2026-11-17, NOT built) — the target model:
  - **Switch + date:** «Kontoauszug einlesen» plus «Abgleich ab» per bank account; no modes. Before the date movements are information only; switching off works forward only, links stay.
  - **Three states:** «geschäftlich» (linked to an invoice / open item / receipt, or booked via «Geschäftlich buchen …»), «privat» (a confirmed private pattern, or by hand), «zu prüfen» (everything else). Nothing is private by silence.
  - **Learned patterns** (counterparty / IBAN, merchant text, direction, approximate amount, day of month): one confirmation as private classifies the pattern; a document beats a pattern; booking a private-pattern movement as business asks, «Ja» makes the pattern «gemischt».
  - **The link** ties a movement to its owner and stores an amount (1:1 for now). Identity links automatically (creditor: QR reference; debtor: the entry `AcctSvcrRef`), likeness is proposed, without a unique candidate nothing is guessed.
  - **«Geschäftlich buchen …»** posts from a movement by an explicit act (contra account, optional VAT code; a file required with a VAT code from CHF 100, config). «Buchung zurücknehmen …» = reversing entry, link released, back to «zu prüfen».
  - **Mixed account:** private movements are never booked; the opening difference bank ↔ ledger is confirmed once as the ANCHOR, the check compares changes (ledger change = business movements). Transfers: mixed side «privat», business side against «Privat», two business accounts against 1090.
  - **Close** (VAT return, year end) BLOCKS on: not read to the period end, «zu prüfen» in the period, check difference, 1090 ≠ 0; WARNS on «Vorsteuer ohne Beleg».
- **The LUKB camt.054 ↔ camt.053 pair** (real files, counts only, 2026-11-17): incoming QR payments are COLLECTIVE credits in the camt.053 (PMNT / RCDT / VCOM, no `TxDtls`, no QR reference, 1–4 transactions); `Ntry/AcctSvcrRef` is the same entry reference in both files (every camt.054 entry found its camt.053 entry: amount, booking date, `NbOfTxs` equal); `Ntry/NtryRef` is a constant. Other credits come one per entry with `TxDtls`.
- **Payment methods hang on a bank account** (owner decision 15): module-receipt's `PaymentMethod` has `bank_account_code`; `PaymentMethodService::find()` / `all()` / `active()` resolve the bank account's ledger account on every read, `getCreditAccount()` answers it, and `save()` clears the method's own account. A payment method without a bank account (cash) keeps its own. Existing payment methods carry no bank account and behave as before.
- **The screens** (2026-11-17; traits in `src/Ui/`, thin hosts in module-backend `Finance/`, role `admin`): **Stammdaten › Finanzen › Bankkonten** (`BankAccountControllerTrait`, `/backend/finance/bank-account/…`) — a standard list (Bezeichnung, IBAN, Konto, Kontoauszug «nein» / «ja» / «bis <date>», «Aktiv»), action cell «+ Bankkonto», the window with Bezeichnung, Kürzel (read-only after creation), IBAN (read-only once a statement was read), Konto, Währung (shown, never posted) and the switch «Kontoauszug einlesen»; no delete. **Finanzen › Bankkonto** (`BankMovementControllerTrait`, `/backend/finance/bank-movement/…`) — the action cell IS the upload (the kernel `partials/upload` in its `cell` shape, XML or ZIP, the work area is the drop target), `upload` answers per file with `BankImportResult::message()` or the refusal sentence (fetch mode: the JSON envelope and the list replaced in place through `[data-bank-movements]`; page mode: flash + list; a ZIP: every XML on its own, one answer naming each), and the ONE list «Bewegungen» (`BankMovementListing`: Datum, Text, Betrag, Original, Saldo; searched in the database by date, text — bank text, counterparty, reference — and amount — booked OR original; header «eingelesen bis … · Saldo …»; no tabs, no row window). With more than one account that takes statements the choice stands at the top of the rail (`partials/bankAccountSelect`, `?account=<code>`); with none the page is one sentence and the action cell empty.
- **Screens carry labels only** (owner 2026-11-17, backend-screen.md §5): no explanatory sentence under a field, the switch or a list. What «Kontoauszug einlesen» means — and that every movement is stored, private ones too — stands in `edit.help` (`data-help-field="statement_import"`); the seamless rule and the search stand in the list help of «Bankkonto» (`f_date`, `f_text`, `f_amount`).
- **The contradiction hint** (decision 3): `Bank\BankAccountHints` collects classes the modules declare under the config key `bankAccountHints` (`BankAccountHintInterface`); financial names none. Debtor declares `PaymentTargetBankAccountHint` — an ACTIVE payment target with the same IBAN (plain or QR-IBAN) and another ledger account gives ONE short line in the bank account window, nowhere else.
- **What the real file of the stage-1 account showed** (read locally, structure only): camt.053.001.08; ONE statement per export over a free period; `OPBD` / `CLBD` / `CLAV`; `AcctSvcrRef` on every entry; one transaction per entry; QR-bill payments carry their QRR reference and the creditor's IBAN; card and TWINT purchases name the merchant only in `AddtlNtryInf`; a foreign purchase carries `InstdAmt` and `CcyXchg/XchgRate` as fields; booking date ≠ value date on two thirds of the entries.

## rules

- When a camt.053 file arrives (any screen, script or job) → MUST go through `BankStatementService::import()`; MUST NOT write `bank_statement` / `bank_movement` rows any other way — the import owns the chain, the dedup and the archive.
- When changing the import → MUST keep the chain seamless: a gap and an overlap that does not agree are REFUSED with a German sentence naming the dates and balances; MUST NOT store a statement «with a note», and MUST NOT insert a movement into the covered period or in front of its start.
- When deciding which entries of a file are new → MUST go by the bank's entry reference (the dedup key) and prove the join by the balance; MUST NOT cut by comparing booking dates with the covered end (movements booked on that day after an earlier export would be lost).
- When code needs the ledger account of a bank → MUST read it from `BankAccount` (through `BankAccountService` or, for a payment method, `PaymentMethodService`); MUST NOT store a copy of it next to an IBAN or on a payment method (Rule 2).
- When reading payment methods for their credit account → MUST go through `PaymentMethodService` (`find()`, `all()`, `active()`); MUST NOT read `PaymentMethod` straight from its repository — a bank-bound method answers an empty credit account there.
- When a movement would lead to a ledger entry → MUST post either through the owning module's document or through «Geschäftlich buchen …» by an explicit act of the office (ADR-061 decision 14); MUST NOT post from a movement automatically.
- When building states, links, patterns or the close check (stages C1–C5) → MUST follow ADR-061; MUST NOT reintroduce modes, a second switch or a silent default «privat» — a movement without link or pattern is «zu prüfen».
- When linking a debtor payment to a camt.053 movement → MUST link by the ENTRY reference (`Ntry/AcctSvcrRef` of the camt.054 entry = that of the camt.053 entry); MUST NOT use `Ntry/NtryRef` (a constant per QR-IBAN and participant number) or the transaction-level reference.
- When a link is set without the office confirming → MUST be an identity (creditor: QR reference identical; debtor: entry `AcctSvcrRef`); everything else MUST be a proposal, and MUST NOT be guessed when no candidate is unique.
- When storing a link → MUST store an amount (rule for now: one link, the full amount); MUST NOT model it as a yes / no flag on the movement.
- When a foreign amount is taken from a movement → MUST take the movement's CHF and the structured `CcyXchg/XchgRate` (the effective rate, surcharge included); MUST NOT match against or show the «Wechselkurs» written in the entry text.
- When matching a card payment to a creditor → MUST use the merchant text (creditor field «Banktext») or original amount + currency + date; MUST NOT expect a counterparty name (cards carry none).
- When a learned pattern classifies movements → MUST let a document match win over a private pattern, and MUST ask before a private pattern turns «gemischt» (ADR-061 decisions 7, 8).
- When creditor «Bezahlt am» is set on a bank account with «Kontoauszug einlesen» on, dated from «Abgleich ab» → MUST take it and the CHF amount from a movement; MUST NOT accept them typed (ADR-061 decision 13).
- When a period with a switched-on bank account is closed → MUST block on «zu prüfen» movements, a statement not read to the period end, a check difference and 1090 ≠ 0; MUST NOT downgrade these to warnings.
- When parsing any camt file → MUST use `Z77\Shared\Payment\Camt\CamtParser`; MUST NOT open the XML with an own SimpleXML / DOM call in a module (one parser, hardened once).
- When writing a test, a fixture, a doc or a log line → MUST use synthetic bank data; MUST NOT copy names, IBANs, references, texts or amounts from a real statement into the repository, a log or an error mail.
- When a bank account or a statement is in another currency than the base currency → MUST refuse with the German sentence; MUST NOT convert or store it (ADR-060 decision 8).
- When changing `BankStatementArchive` → MUST NOT add a delete or overwrite path; an archived statement file is a booking record kept ten years.
- When changing a bank screen → MUST escape every movement value on output (bank text is foreign input) and keep the screen to labels, answers and refusals; MUST NOT add explanatory text to the screen (it goes into the help, backend-screen.md §5) and MUST NOT put matching or refusal logic into JavaScript (Rule 7).
- When another module holds a ledger account for an IBAN of its own → MUST report a contradiction through `bankAccountHints` (`BankAccountHintInterface`); MUST NOT let financial read that module, and MUST NOT show the hint anywhere but in the bank account window.

## known issues

- **BANK-SCREEN-001** — resolved 2026-11-17: the three screens are built (boards approved by the owner the same day). Don't assume the owner has seen them in the browser yet — see `## pending`.
- **BANK-ACL-001** — don't assume a separate access right «Bankkonten» exists: the backend knows only the role ladder (`editor` < `admin` < `superUser`). Owner 2026-11-16 («ja c, vorerst admin»): the bank screens use the role `admin` for now, like the journal; a separate per-user right is a building block of its own, built later when needed.
- **BANK-START-001** — don't assume history can be added in front: the first statement of an account sets the start of its chain, and a file bringing earlier movements is refused (`before-start`). Choosing an earlier start means a new beginning for that account — not built. Pick the first file with that in mind (e.g. from 1 January of the first year wanted).
- **BANK-BATCH-001** — don't assume a collective entry is split: a statement entry with several transactions is ONE movement with the entry's amount, without counterparty and reference. The mixed account's real file has none; the account customer payments arrive on books incoming QR payments as collective credits (1–4 transactions, often without `TxDtls`) — only the camt.054 names the payments (ADR-061 decision 12).
- **BANK-FP-001** — don't assume a fingerprinted movement is told apart as safely as one with an entry reference: two exports that cut the same day differently can rank identical entries differently — the balance proof then refuses the file rather than storing something wrong. The stage-1 bank sends a reference on every entry, so this path is a fallback.
- **BANK-UPLOAD-001** — don't assume several files dropped at once are read oldest first: the upload component sends one request per file in the order the browser hands them over. A later file read before an earlier one is refused as a gap and names the date — upload the oldest first (the help says so).
- **BANK-SEQ-001** — don't assume the statement sequence number is checked: it is stored only (an e-banking export carries 1 every time). The chain is guarded by dates and balances.
- **BANK-PAGE-001** — don't assume a paginated statement (`GrpHdr/MsgPgntn` with several pages, intermediate balances) was ever read: each page is judged as its own statement if it carries `OPBD` and `CLBD`; not verified against a real paginated file.
- **BANK-CONTRA-001** — resolved 2026-11-17: the hint is built (`bankAccountHints`, debtor's `PaymentTargetBankAccountHint`), in the bank account window only. Don't assume debtor reads its payment target's account from «Bankkonten» — that comes later, by a new owner decision.

## pending

- **Owner's look in the browser** — deployed to z77.ch 2026-11-17; the owner read the first statements of two accounts (a mixed one and the one debtor payments arrive on), each with its own ledger account and chain. Still open: the full screen check (list, search, field «Bankkonto» in the payment-method window).
- **Bank-driven bookkeeping** ([ADR-061](../02-decisions/adr-061-bank-driven-bookkeeping.md), owner 2026-11-17; each stage a board first where a screen changes, Rule 8; its own migration):
  - **C1** (replaces B2) — the creditor invoice finds its movement: QR reference identical → automatic, else proposal (ticked, confirmed in bulk) or candidates; «Bezahlt am» + CHF from the bank; already posted invoices linked afterwards WITHOUT a new posting; the link stores an amount; the creditor field «Banktext»; a link column in the movements list. The rules on `XchgRate` and card text above come from the first real statement.
  - **C2** — the three states and learned patterns incl. «gemischt» and the ask; «Geschäftlich buchen …» with optional VAT code and the attachment rule (≥ CHF 100, config); «Privat»; transfers (1090); «Buchung zurücknehmen …».
  - **C3** — debtor: store the camt.054 ENTRY `AcctSvcrRef`, archive the camt.054 file, link the collective credit automatically.
  - **C4** — the date «Abgleich ab», the anchor (opening difference confirmed), the check, the close blocks, the VAT control line «Vorsteuer ohne Beleg».
  - **C5** — receipts paid by card / bank find their movement (replaces B4).
  - **Later** — pain.001 («Zahlungsauftrag auslösen»), the collective variant (2000), n:1 / 1:n; debtor's payment target reads its ledger account from «Bankkonten».
  - **To decide before the stage that needs it** (ADR-061 consequences): returned payments (`RvslInd`), amount differences, a partly business movement, where financial keeps the attachment, «nur durch Module bebuchbar» on the bank's ledger account; debtor's payment date = booking date (ADR-060 decision 11) is not in the C3 cut.
  - **With the fiduciary**: an optional year-end posting «Privat / Bank»; the CHF 100 attachment threshold.
- **Purge after the retention period** — not built; the model does not prevent it.

## see also

- [`financial.md`](financial.md) — the module the service lives in: accounts (`Account`, postable / active — what `ledger_account` is checked against), `LedgerService::baseCurrency()`
- [`debtor.md`](debtor.md) — the camt.054 import that reads through the same parser and stays the only path that books a customer payment
- [`receipt.md`](receipt.md) — `PaymentMethod` / `PaymentMethodService`, where a payment method takes its credit account from a bank account
- [`creditor.md`](creditor.md) — the consumer (stage C1): «Bezahlt am», the CHF amount of a foreign-currency invoice (A0 by hand today)
- [`persistence-doctrine.md`](persistence-doctrine.md) — the transaction port (`getTransaction()->run()`, rollback replaces the EntityManager), `MoneyType` (base currency only), the migrations CLI
- [`money.md`](money.md) — `Money`, string decimals, why no float comes near an amount
- [`../02-decisions/adr-060-bank-account-service.md`](../02-decisions/adr-060-bank-account-service.md) — the binding decisions of B1 (parts superseded by ADR-061)
- [`../02-decisions/adr-061-bank-driven-bookkeeping.md`](../02-decisions/adr-061-bank-driven-bookkeeping.md) — bank-driven bookkeeping: states, patterns, link, «Geschäftlich buchen …», anchor, close (stages C1–C5)
- [`../03-development/bank-account-service-plan.md`](../03-development/bank-account-service-plan.md) — the review, the variants and the stage table
