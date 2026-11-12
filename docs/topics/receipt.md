# receipt

2026-11-12

## entry

1. `packages/module-receipt/src/Services/ReceiptCaptureService.php` — THE capture path: values checked, template and payment method applied, photo sniffed and stored in the DMS, the journal row written; no session needed (the API target of wave 2 calls it)
2. `packages/module-receipt/src/Services/ReceiptJournalPostingService.php` — «Journal verbuchen» up to a cut-off date: `preview($cutOff)` and `post($cutOff, list<{id,version}>)`, all or nothing, one generated entry per receipt through `LedgerService::post()`
3. `tests/module-receipt.php` — the harness: module direction, entity rules (template, payment method, split, cut-off), master-data shape and photo sniff offline (O); migration, master data with references, capture without session, correction, preview / post / rollback, split, open work, delete against a throwaway schema (M)

## file map

SOURCE=/packages/module-receipt/composer.json
SOURCE=/packages/module-receipt/README.md
SOURCE=/packages/module-receipt/src/App/Config/receiptConfig.inc.php
SOURCE=/packages/module-receipt/res/migrations/Version20261112100000.php
SOURCE=/packages/module-receipt/src/Entities/Receipt.php
SOURCE=/packages/module-receipt/src/Entities/ReceiptState.php
SOURCE=/packages/module-receipt/src/Entities/ReceiptJournalPosting.php
SOURCE=/packages/module-receipt/src/Entities/ReceiptTemplate.php
SOURCE=/packages/module-receipt/src/Entities/PaymentMethod.php
SOURCE=/packages/module-receipt/src/Repositories/ReceiptRepository.php
SOURCE=/packages/module-receipt/src/Repositories/ReceiptTemplateRepository.php
SOURCE=/packages/module-receipt/src/Repositories/PaymentMethodRepository.php
SOURCE=/packages/module-receipt/src/Services/ReceiptCaptureService.php
SOURCE=/packages/module-receipt/src/Services/ReceiptJournalService.php
SOURCE=/packages/module-receipt/src/Services/ReceiptCorrection.php
SOURCE=/packages/module-receipt/src/Services/ReceiptJournalPostingService.php
SOURCE=/packages/module-receipt/src/Services/ReceiptPostingBuilder.php
SOURCE=/packages/module-receipt/src/Services/ReceiptFields.php
SOURCE=/packages/module-receipt/src/Services/ReceiptTemplateService.php
SOURCE=/packages/module-receipt/src/Services/PaymentMethodService.php
SOURCE=/packages/module-receipt/src/Services/ReceiptException.php
SOURCE=/packages/module-receipt/src/Services/InvalidReceiptException.php
SOURCE=/packages/module-receipt/src/Services/ReceiptRefusedException.php
SOURCE=/packages/module-receipt/src/Services/InvalidMasterDataException.php
SOURCE=/packages/module-receipt/src/Services/MasterDataCodeChangedException.php
SOURCE=/packages/module-receipt/src/Validators/ValidatesMasterDataRow.php
SOURCE=/packages/module-receipt/src/Validators/ReceiptTemplateValidator.php
SOURCE=/packages/module-receipt/src/Validators/PaymentMethodValidator.php
SOURCE=/packages/module-receipt/src/Close/UnpostedReceiptsCheck.php
SOURCE=/packages/module-financial/src/Ui/OneLineEntryForm.php
SOURCE=/packages/module-financial/src/Ui/ManualEntryForm.php
SOURCE=/packages/module-financial/src/Services/LedgerService.php
SOURCE=/packages/module-financial/src/Ledger/PostingRequest.php
SOURCE=/packages/module-dms/src/Services/ModuleDrive.php
SOURCE=/packages/module-dms/src/Services/DocumentService.php
SOURCE=/packages/module-debtor/src/Services/JournalPostingService.php
SOURCE=/tests/module-receipt.php
SOURCE=/docs/03-development/gateway-app-kanal-bauplan.md
SOURCE=/docs/02-decisions/adr-054-faktura-journal.md
RUNTIME=/skeleton/data/framework/receipt/receipt_templates.json
RUNTIME=/skeleton/data/framework/receipt/payment_methods.json

## mental model

A phone captures a receipt — photo, gross amount, optionally a text, a TEMPLATE (what was bought: debit account and VAT code) and a PAYMENT METHOD («Bezahlt mit»: the credit account) — into the RECEIPT JOURNAL; the ledger sees nothing. The owner reviews, corrects and splits receipts in the backend and posts every receipt up to a CUT-OFF date in one go, «Journal verbuchen»: one generated journal entry per receipt, one unit of work, all or nothing — the flow of debtor's Faktura-Journal (ADR-054), built on its pattern, not its classes. Owner decisions E1–E12 and the approved proposals T2 / T4 of 2026-11-12 (`gateway-app-kanal-bauplan.md`) are the source.

- **Owner decisions after the plan (2026-11-12):** **E10** — the credit account is NOT part of the template; a second master-data entity, the payment method («Bezahlt mit»: Kasse, Firmenkarte, privat ausgelegt), carries it. **E11** — «Journal verbuchen» takes a cut-off date (default: the last day of the previous month); the run is every captured receipt dated on or before it, an incomplete one blocks the whole run, later receipts stay untouched. **E12** — the phone captures ONE VAT code per receipt; a rare mixed-VAT receipt is SPLIT in the journal into two receipts sharing the photo.
- **Module direction:** module-receipt requires financial (accounts, VAT, posting), dms (the photo), vat, mandator; module-financial gains neither dms nor api (T4). module-api will register the targets in wave 2 — this package does not name it.
- **Master data is file-based** — `ReceiptTemplate` (`data/framework/receipt/receipt_templates.json`: code, label, icon, debit account, tax code — '' = without VAT, position, active) and `PaymentMethod` (`payment_methods.json`: code, label, credit account, position, active). The pattern of the order status and the payment terms that E3 names: a handful of rows per installation, edited in the backend, referenced BY `code` (ADR-043 decision 19: `receipt.template_code`, `receipt.payment_method_code`), deactivated never deleted, code immutable (`ReceiptTemplateService`, `PaymentMethodService`). Not seeded: the accounts depend on the chart. Every save checks the accounts and the tax code against the chart and module-vat.
- **The receipt row** (`receipt`, Doctrine): date, text, GROSS amount (base currency), `template_code`, `payment_method_code`, `debit_account` / `credit_account` (numbers — the payment method's credit account RESOLVED at capture, correctable), `tax_code` OR `without_vat`, `document_id` (DMS, no FK, indexed — split parts share it), `device_id` / `device_name` / `captured_by` (snapshots, E8), `capture_key` (unique), `changed_by/at`, state `captured` → `posted`, `ledger_entry_ref` (`{year}/{number}`, debtor's shape), `journal_posting_id`, `posted_by/at`, `version` (`ORM\Version`).
- **Complete** = debit, credit, the VAT decision (a tax code or «ohne MWST») and a text (`Receipt::missing()` lists what lacks, in German). The text is part of it because the ledger refuses an entry without one.
- **Capture** (`ReceiptCaptureService::capture()`) needs no session — who captured it is data from the device principal. Date default today, never future (E6); amount required, positive (E5); text default = template label; an unknown template or payment method refused, a deactivated one still applied (an offline queue may send late). A known `capture_key` returns the existing receipt and stores nothing (E7).
- **The photo** goes through the DMS's trusted system path (T2): `DocumentService::forModule('receipt', 'Belege')` → `ModuleDrive::folder(<fiscal-year code>, <month>)` → `saveGenerated()` — `Belege/2026/11` (E4; the calendar year when no fiscal year covers the date). Because that path does not sniff, the capture does: `finfo_buffer` on the BYTES, only `image/jpeg`, `image/png`, `application/pdf`, at most 10 MiB (`ReceiptCaptureService::MAX_PHOTO_BYTES`); the stored file name gets the extension of the sniffed type.
- **Correction, split, deletion** (`ReceiptJournalService`): with the version the caller showed; row locked FOR UPDATE, compared, read fresh. Values checked by the same `ReceiptFields` as the capture. `split($id, $version, $amountOfNewPart)` (E12) reduces the receipt and creates a second captured receipt with the part — same photo, date, text, payment method and credit account, capture data; debit and VAT left open for the owner; no capture key; the two amounts sum to the original. Deleting keeps the photo in the DMS and returns its document id only when no other receipt names it (null while a split part still does).
- **The posting** (`ReceiptPostingBuilder`): a receipt IS a one-line entry, so financial's `OneLineEntryForm` builds the lines (VAT in gross mode at the rate of the receipt date, net on the P&L side, the tax line on the mandator's VAT account, zero / exempt without tax line) and the builder re-wraps them as `PostingRequest::generated(date, text, 'receipt', <id>, 'receipt:<id>', lines)`.
- **«Journal verbuchen»** (`ReceiptJournalPostingService`, E11): `preview($cutOff)` returns the run (`seen`: every captured receipt dated ≤ cut-off with its version), what blocks it, `postable`, the rows per account; `post($cutOff, $seen)` locks the rows EXCLUSIVE by id, refuses `changed` when a version differs or the run is no longer exactly the captured receipts ≤ cut-off, refuses `incomplete` when any receipt of the run lacks something, then writes a protocol row (`receipt_journal_posting`: who, when, count, gross) and posts per receipt in date order through `LedgerService::post()`. Lock order: receipt rows → `journal-entry.*`. Any ledger refusal (no fiscal year, closed or VAT-settled period, account refused) rolls the whole run back; the refusal comes out German, naming the receipt.
- **Year close and periods:** the ledger's rules decide at posting; `preview()` pre-checks year and period state lock-free. `Close\UnpostedReceiptsCheck` answers financial's open-work scopes: `period-close` and `vat-return` BLOCK while a receipt dated in the range is captured, `ledger-report` warns with the total.

## rules

- When capturing a receipt from any channel (API target, backend upload, import) → MUST call `ReceiptCaptureService::capture()` with who captured it as data; MUST NOT write a `Receipt` directly, MUST NOT read the actor from an `AuthService` on the device path (stateless, T1)
- When accepting a receipt photo → MUST let `ReceiptCaptureService` sniff the BYTES and name the file from the sniffed type; MUST NOT trust the client's file name or MIME, and MUST NOT widen `PHOTO_TYPES` (e.g. HEIC) without an owner decision — the trusted `saveGenerated()` path does not check it again
- When storing a receipt photo → MUST go through `DocumentService::forModule(ReceiptCaptureService::MODULE_KEY, …)` → `ModuleDrive`; MUST NOT use a folder id outside the module partition
- When a credit account must be offered on the phone → MUST come from a `PaymentMethod` (E10); MUST NOT add a credit account to `ReceiptTemplate` or let the phone choose a free account (E2)
- When posting receipts → MUST call `ReceiptJournalPostingService::post($cutOff, $seen)` with the cut-off and the `seen` list `preview($cutOff)` returned; MUST NOT post a subset of the run, MUST NOT post a receipt through `LedgerService` elsewhere and MUST NOT use another source type or idempotency key than `receipt` / `receipt:{id}`
- When building a receipt's posting lines → MUST go through `ReceiptPostingBuilder` (financial's one-line entry); MUST NOT compute VAT, net side or tax account in this module
- When a receipt carries two VAT rates → MUST split it with `ReceiptJournalService::split()` (E12); MUST NOT post it as a manual Sammelbuchung beside the journal or add a second VAT code to a receipt
- When changing, splitting or deleting a captured receipt → MUST pass the version the screen showed and handle `ReceiptRefusedException::CHANGED` by reloading; MUST NOT write a posted receipt (the entity refuses)
- When deleting a receipt's photo after the receipt → MUST only offer it when `delete()` returned a document id; MUST NOT delete a document another receipt (a split part) still names
- When a template or payment method is no longer wanted → MUST deactivate it with `setActive(…, false)`; MUST NOT delete the row or change its code (receipts carry it)
- When a value of a receipt or of master data is checked → MUST use `ReceiptFields` (date, amount, text, account, tax code); MUST NOT add a second rule set in a screen or the API target

## known issues

- RECEIPT-ORPHAN-001: don't assume a stored photo always has a receipt — the photo is written to the DMS (file store) BEFORE the receipt row is committed; a failing insert (e.g. two parallel first sends with the same `capture_key`) leaves the photo in `Belege/<year>/<month>` without a row. Harmless (the Drive shows it), not cleaned up automatically
- RECEIPT-KEY-001: don't assume a repeated `capture_key` is compared — the existing receipt is returned even when the repeat carries other values (the same key means the same capture, the gateway's idempotency model)
- RECEIPT-BUILD-001: don't assume financial has a public one-line line builder — `ReceiptPostingBuilder` drives the UI class `OneLineEntryForm` (`fromPost()` / `toRequest()`) to get the lines; a change of that form's field names breaks the posting (the harness M23 / M29 catch it)
- RECEIPT-VAT-001: don't assume the mandator's VAT liability is applied — a receipt or template with a tax code posts input tax even when the mandator is NOT liable to VAT (`liable_to_vat`); such an installation sets «ohne MWST» explicitly
- RECEIPT-PAIR-001: don't assume a template and a payment method are judged together before a receipt exists — a template whose debit and a payment method whose credit are BOTH P&L accounts, with a VAT code, cannot post (the one-line entry refuses it); the preview shows it as blocked per receipt, master-data saving does not refuse it
- RECEIPT-DMS-001: don't assume the DMS rule «no `saveGenerated()` for a person's upload» ([`documents.md`](documents.md), media picker) is broken by accident — the phone photo is a person's upload on the trusted path by approved decision T2; the capture service carries the checks (sniff, allowlist, size) that `UploadService` would

## pending

- Backend screens after the owner-approved board (Rule 8): Stammdaten «Belegvorlagen» and «Bezahlt mit» (list, edit, deactivate); mask «Belegjournal» (list with «Handy <Benutzer> · <Gerät>», photo preview, correction, split, delete with optional «Foto auch löschen» through `DocumentService::delete()`, «Journal verbuchen» with the cut-off and the preview, the posting protocols)
- API targets (wave 2, module-api): `receipt-templates` (GET, the active templates and payment methods), `receipts` (POST → `capture()`, `InvalidReceiptException::$errors` as the envelope's field errors, the request's `Idempotency-Key` as `captureKey`, the device principal as `capturedBy` / `deviceId` / `deviceName`); no free debit / credit on the phone (E2, E10)
- App shell (T6): `/app`, manifest, service worker, tiles from `receipt-templates`, «Bezahlt mit», offline queue (E7)
- Owner decisions: delete a receipt's photo with the receipt or keep it (built: keep); HEIC (built: refused, the phone converts to JPEG); the mandator not liable to VAT (RECEIPT-VAT-001); whether «ohne MWST» counts as the VAT decision of a complete receipt (built: yes)
- Correction of a POSTED receipt: through the ledger as its source (`LedgerService::amend()` / `retract()` / `reverse()` with source `receipt`), not built
- A public line builder in module-financial for the one-line entry (RECEIPT-BUILD-001) — the smallest additive seam, when a second caller needs it
- Dashboard entry («n Belege im Belegjournal») — module-backend's `dashboard` list, with the screens
- The link from a journal entry to its receipt photo in financial's journal (financial knows no module — needs a source-link registry, not designed)

## see also

- [`financial.md`](financial.md) — `LedgerService::post()`, the generated-entry rules (unit of work, idempotency key, refusals), the one-line entry whose VAT split the posting reuses
- [`debtor.md`](debtor.md) — the Faktura-Journal (ADR-054) whose pattern «Journal verbuchen» follows: protocol row, lock order, the whole run, open-work checks
- [`documents.md`](documents.md) — `forModule()` / `ModuleDrive` / `saveGenerated()`, the trusted system path the photo takes
- [`api.md`](api.md) — the gateway and the device channel that will call `capture()` (wave 2)
- [`persistence-doctrine.md`](persistence-doctrine.md) — the transaction port, the migrations, the open-work registry
