# receipt

2026-11-12

## entry

1. `packages/module-receipt/src/Services/ReceiptCaptureService.php` — THE capture path: values checked, template and payment method applied, photo sniffed and stored in the DMS, the journal row written; no session needed (the API target of wave 2 calls it)
2. `packages/module-receipt/src/Services/ReceiptJournalPostingService.php` — «Journal verbuchen» up to a cut-off date: `preview($cutOff)` and `post($cutOff, list<{id,version}>)`, all or nothing, one generated entry per receipt through `LedgerService::post()`
3. `tests/module-receipt.php` — the harness: module direction, entity rules (template, payment method, split, cut-off), master-data shape and photo sniff offline (O); the backend screens' wiring, templates and help offline (S); migration, master data with references, capture without session, correction, preview / post / rollback, split, open work, delete against a throwaway schema (M); the screens through host doubles — list, window, fill, split, delete, «Journal verbuchen», master data (SM)
4. `packages/module-receipt/src/Ui/ReceiptJournalControllerTrait.php` — the backend screen «Belegjournal» (mounted at `/backend/finance/receipt-journal`)

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
SOURCE=/packages/module-receipt/src/Api/DeviceOnlyTarget.php
SOURCE=/packages/module-receipt/src/Api/ReceiptTemplatesService.php
SOURCE=/packages/module-receipt/src/Api/PaymentMethodsService.php
SOURCE=/packages/module-receipt/src/Api/ReceiptsService.php
SOURCE=/packages/module-receipt/src/Services/ReceiptIcons.php
SOURCE=/packages/module-receipt/src/Repositories/ReceiptSearch.php
SOURCE=/packages/module-receipt/src/Repositories/ReceiptJournalPostingRepository.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptLayout.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptJournalControllerTrait.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptJournalListing.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptTemplateControllerTrait.php
SOURCE=/packages/module-receipt/src/Ui/PaymentMethodControllerTrait.php
SOURCE=/packages/module-receipt/src/Ui/MasterDataListing.php
SOURCE=/packages/module-receipt/src/Ui/MasterDataAnswers.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptChoices.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptPhoto.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/listAction.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/act.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/toolbar.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/list.help.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/edit.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/edit.help.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/split.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/confirmDelete.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptJournalController/confirmPost.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/listAction.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/_row.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/act.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/edit.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/edit.help.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/ReceiptTemplateController/list.help.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/listAction.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/_row.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/act.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/edit.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/edit.help.tpl.php
SOURCE=/packages/module-receipt/res/view/templates/Backend/PaymentMethodController/list.help.tpl.php
SOURCE=/packages/module-receipt/data/framework/routing/navigation.d/module-receipt.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/ReceiptJournalController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/ReceiptTemplateController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/PaymentMethodController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/receiptJournalControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/receiptTemplateControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/paymentMethodControllerConfig.inc.php
SOURCE=/packages/module-backend/res/scss/components/_docview.scss
SOURCE=/packages/module-dms/src/Ui/MediaField.php
SOURCE=/packages/module-receipt/res/view/templates/App/receipt.tpl.php
SOURCE=/packages/module-receipt/res/assets/js/app/receipt.js
SOURCE=/packages/module-receipt/res/assets/js/app/receipt.min.js
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
- **Module direction:** module-receipt requires financial (accounts, VAT, posting), dms (the photo), vat, mandator; module-financial gains neither dms nor api (T4). The API targets and the app screen are DECLARED in receiptConfig (`apiServices`, `apiDeviceScopes`, `appScreens`) and found by module-api — this package does not name it (harness O2).
- **Master data is file-based** — `ReceiptTemplate` (`data/framework/receipt/receipt_templates.json`: code, label, icon, debit account, tax code — '' = without VAT, position, active) and `PaymentMethod` (`payment_methods.json`: code, label, credit account, position, active). The pattern of the order status and the payment terms that E3 names: a handful of rows per installation, edited in the backend, referenced BY `code` (ADR-043 decision 19: `receipt.template_code`, `receipt.payment_method_code`), deactivated never deleted, code immutable (`ReceiptTemplateService`, `PaymentMethodService`). Not seeded: the accounts depend on the chart. Every save checks the accounts and the tax code against the chart and module-vat.
- **The receipt row** (`receipt`, Doctrine): date, text, GROSS amount (base currency), `template_code`, `payment_method_code`, `debit_account` / `credit_account` (numbers — the payment method's credit account RESOLVED at capture, correctable), `tax_code` OR `without_vat`, `document_id` (DMS, no FK, indexed — split parts share it), `device_id` / `device_name` / `captured_by` (snapshots, E8), `capture_key` (unique), `changed_by/at`, state `captured` → `posted`, `ledger_entry_ref` (`{year}/{number}`, debtor's shape), `journal_posting_id`, `posted_by/at`, `version` (`ORM\Version`).
- **Complete** = debit, credit, the VAT decision (a tax code or «ohne MWST») and a text (`Receipt::missing()` lists what lacks, in German). The text is part of it because the ledger refuses an entry without one.
- **Capture** (`ReceiptCaptureService::capture()`) needs no session — who captured it is data from the device principal. Date default today, never future (E6); amount required, positive (E5); text default = template label; an unknown template or payment method refused, a deactivated one still applied (an offline queue may send late). A known `capture_key` returns the existing receipt and stores nothing (E7).
- **The photo** goes through the DMS's trusted system path (T2): `DocumentService::forModule('receipt', 'Belege')` → `ModuleDrive::folder(<fiscal-year code>, <month>)` → `saveGenerated()` — `Belege/2026/11` (E4; the calendar year when no fiscal year covers the date). Because that path does not sniff, the capture does: `finfo_buffer` on the BYTES, only `image/jpeg`, `image/png`, `application/pdf`, at most 10 MiB (`ReceiptCaptureService::MAX_PHOTO_BYTES`); the stored file name gets the extension of the sniffed type.
- **Correction, split, deletion** (`ReceiptJournalService`): with the version the caller showed; row locked FOR UPDATE, compared, read fresh. Values checked by the same `ReceiptFields` as the capture. `split($id, $version, $amountOfNewPart)` (E12) reduces the receipt and creates a second captured receipt with the part — same photo, date, text, payment method and credit account, capture data; debit and VAT left open for the owner; no capture key; the two amounts sum to the original. Deleting keeps the photo in the DMS and returns its document id only when no other receipt names it (null while a split part still does).
- **The posting** (`ReceiptPostingBuilder`): a receipt IS a one-line entry, so financial's `OneLineEntryForm` builds the lines (VAT in gross mode at the rate of the receipt date, net on the P&L side, the tax line on the mandator's VAT account, zero / exempt without tax line) and the builder re-wraps them as `PostingRequest::generated(date, text, 'receipt', <id>, 'receipt:<id>', lines)`.
- **«Journal verbuchen»** (`ReceiptJournalPostingService`, E11): `preview($cutOff)` returns the run (`seen`: every captured receipt dated ≤ cut-off with its version), what blocks it, `postable`, the rows per account; `post($cutOff, $seen)` locks the rows EXCLUSIVE by id, refuses `changed` when a version differs or the run is no longer exactly the captured receipts ≤ cut-off, refuses `incomplete` when any receipt of the run lacks something, then writes a protocol row (`receipt_journal_posting`: who, when, count, gross) and posts per receipt in date order through `LedgerService::post()`. Lock order: receipt rows → `journal-entry.*`. Any ledger refusal (no fiscal year, closed or VAT-settled period, account refused) rolls the whole run back; the refusal comes out German, naming the receipt.
- **The phone (wave 2, part E, 2026-11-12):** three targets on module-api's gateway, all scope `receipt.capture`, all refusing a machine (bearer) caller with 403 (`Api\DeviceOnlyTarget` — the gateway lets a bearer key past the scope check by design). `GET receipt-templates` → `{"templates": [{code, label, icon, position}]}` (active, tile order); `GET payment-methods` → `{"payment_methods": [{code, label}]}` — no account, no VAT code on the wire (E2, E10), ETag = payload hash. `POST receipts` (multipart: `photo` + `amount`, `date`, `text`, `template`, `payment_method`) → `capture()` with the owner's name as `capturedBy`, the device id and LABEL (`ApiPrincipal::$deviceLabel`, E8), the request's `Idempotency-Key` as capture key (E7); answers `{"receipt": {id, date, amount, text, template, payment_method, state}, "message": "Beleg erfasst"}` (the journal position, never a ledger number, E1) or 422 `invalid_fields` with `InvalidReceiptException::$errors` in `error.fields`; an upload PHP dropped says «zu gross» / «nicht vollständig angekommen» instead of «Das Foto fehlt.». The scope is offered to the «Geräte» screen by `apiDeviceScopes` (label «Belege erfassen»).
- **The app screen** (`appScreens` → `res/view/templates/App/receipt.tpl.php` + `res/assets/js/app/receipt.js`, inside module-api's shell `/app`, [`api.md`](api.md) «app shell»): tiles (the templates + «Ohne Vorlage»; a tap opens the camera), capture (photo scaled on the phone to ≤ 1600 px JPEG on a canvas, Betrag, Datum = today, Text, the template chip, «Bezahlt mit» chips, the last choice preselected), confirmation «Beleg erfasst» / «Beleg gespeichert» (offline), «Heute gesendet». The tile symbols: keys `ReceiptTemplate::ICONS`, glyphs `ReceiptIcons::GLYPHS` (harness O21: every offered key has one; unknown → `receipt`).
- **The backend screens (wave 2, part D1, 2026-11-12; boards «Belegjournal» / «Belegvorlagen» of the developer canvas, owner-approved, plus E10's «Zahlungsarten»):** three fragments in `Ui\`, mounted by thin hosts in module-backend under `/backend/finance/…` (ADR-018; URL roots in `ReceiptLayout`), menu from the seed `navigation.d/module-receipt.json` — «Finanzen › Belegjournal» right after «Journal», «Stammdaten › Finanzen › Belegvorlagen / Zahlungsarten» after «MWST-Codes» (on a fresh install; an update appends, ADR-050).
  - **Belegjournal** (`ReceiptJournalControllerTrait`): action cell «Journal verbuchen …» (green; disabled only while the journal is empty — whether an incomplete receipt blocks depends on the cut-off); toolbar tabs «Im Journal» · «Verbuchte Journale»; the band (count, total, oldest — or red: every receipt `preview(today)` blocks, linked to its window); a standard list (`ReceiptJournalListing`, search date / text / amount / «Erfasst von» in SQL, `ReceiptRepository::search()`), the photo as the DMS `s` thumbnail, Soll · Haben · MWST · Vorlage · «Bezahlt mit» · «Erfasst von» · «vollständig» / «Konten fehlen»; under it the VAT per code from `preview()` (`vat`, `withoutVat`, `blockedGross` — read off the built lines, no VAT computed in the screen). The whole journal = `preview(today)`: a receipt is never dated in the future (E6).
  - **The receipt window** (`edit`): the photo large beside the fields (`.be-docview`, module-backend) through the DMS's gated preview — `ReceiptPhoto` takes the addresses from `MediaField::previewUrl()` / `thumbUrl()`, never builds one; Datum, Betrag, Text, Soll, Haben (the chart's `<datalist>`), MWST (a code, «ohne MWST» = the select value `-`, «— wählen —» = undecided); «Vorlage» and «Bezahlt mit» are FILL HELPERS (core.js «fill», `template-fill` / `payment-fill` answer Soll + MWST / Haben) — not saved, the receipt keeps what the phone chose (`ReceiptCorrection` has no template). The save is `update()` with the shown version; the answer closes the window and reloads the list region. «Aufteilen …» (`split`, E12) and «Löschen …» (`confirm-delete`) load into the window; a posted receipt shows read-only.
  - **«Löschen …» and the photo:** «Foto auch löschen» (off by default) is offered only when no split part shares the photo AND the session may `manage` it; on, the photo goes to the DMS trash through `DocumentService::delete()` (the session's own right — restorable in the Drive) after `delete()` answered its id. A failing DMS delete leaves the receipt deleted and says so.
  - **«Journal verbuchen …»** (`confirm-post`, a page): the cut-off (`?cutoff=`, default `defaultCutOff()`, «Anzeigen» recomputes), the run, the blockers, VAT per code, the entries per account with names; the POST form carries the cut-off and every `{id}:{version}`; `post` → «Verbuchte Journale» or back to the confirmation of that cut-off with the refusal.
  - **Belegvorlagen / Zahlungsarten** (`ReceiptTemplateControllerTrait`, `PaymentMethodControllerTrait`, the order-status model): standard list in position order (`MasterDataListing`) with «Belege» (how many receipts carry the code, `countByTemplate()` / `countByPaymentMethod()`) and the «Aktiv» switch; the window (name, code fixed, symbol from `ReceiptTemplate::ICONS` / Soll / MWST — resp. Haben —, Reihenfolge); action cell «+ Vorlage» / «+ Zahlungsart». No delete anywhere (ADR-043 decision 19).
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
- When a receipt screen shows the photo → MUST take its addresses from `ReceiptPhoto` (module-dms `MediaField::previewUrl()` / `thumbUrl()`, the read-gated preview); MUST NOT write a `/document/preview` address by hand (harness S7 / S8)
- When a screen shows VAT totals of receipts → MUST read them from `ReceiptJournalPostingService::preview()` (`vat`, `withoutVat`); MUST NOT compute a VAT share in a template or a controller
- When a receipt target answers → MUST refuse a caller without `$request->device()` (403) and take who captured from the device principal; MUST NOT put an account or a VAT code into a payload for the phone (E2, E10)
- When a tile symbol is added to `ReceiptTemplate::ICONS` → MUST add its glyph to `ReceiptIcons::GLYPHS` (24×24 stroke path) in the same change; MUST NOT reference an external icon file from the app screen (the app CSP and the inline sprite)
- When the phone screen shows server data → MUST build it with DOM nodes and `textContent` (`_Z77.app.shell.el()`); MUST NOT use `innerHTML` or inline script/style (the app CSP is enforced)

## known issues

- RECEIPT-ORPHAN-001: don't assume a stored photo always has a receipt — the photo is written to the DMS (file store) BEFORE the receipt row is committed; a failing insert (e.g. two parallel first sends with the same `capture_key`) leaves the photo in `Belege/<year>/<month>` without a row. Harmless (the Drive shows it), not cleaned up automatically
- RECEIPT-PHONE-001: don't assume an iPhone photo arrives — the phone converts it to JPEG with `createImageBitmap` + canvas; where a browser cannot decode the file, the screen says «Dieses Bild kann das Handy nicht lesen» and nothing is sent (HEIC is refused by the server anyway, T2). Not tested on a real iPhone yet (api.md API-008)
- RECEIPT-KEY-001: don't assume a repeated `capture_key` is compared — the existing receipt is returned even when the repeat carries other values (the same key means the same capture, the gateway's idempotency model)
- RECEIPT-BUILD-001: don't assume financial has a public one-line line builder — `ReceiptPostingBuilder` drives the UI class `OneLineEntryForm` (`fromPost()` / `toRequest()`) to get the lines; a change of that form's field names breaks the posting (the harness M23 / M29 catch it)
- RECEIPT-VAT-001: don't assume the mandator's VAT liability is applied — a receipt or template with a tax code posts input tax even when the mandator is NOT liable to VAT (`liable_to_vat`); such an installation sets «ohne MWST» explicitly
- RECEIPT-PAIR-001: don't assume a template and a payment method are judged together before a receipt exists — a template whose debit and a payment method whose credit are BOTH P&L accounts, with a VAT code, cannot post (the one-line entry refuses it); the preview shows it as blocked per receipt, master-data saving does not refuse it
- RECEIPT-DMS-001: don't assume the DMS rule «no `saveGenerated()` for a person's upload» ([`documents.md`](documents.md), media picker) is broken by accident — the phone photo is a person's upload on the trusted path by approved decision T2; the capture service carries the checks (sniff, allowlist, size) that `UploadService` would

## pending

- **Owner's look — backend screens** (committed `658ca7c` / `3a8b8f6`): the switch «Foto auch löschen» is OFF by default; the master data have NO «Löschen …» (the domain deletes nothing, ADR-043 decision 19).
- **Owner's look — the phone** (`3a8b8f6`): live test on an iPhone and an Android phone — camera, the JPEG scaling of an iPhone photo, the offline queue ([`api.md`](api.md) API-008).
- **Owner decisions** (each built one way, to confirm): delete a receipt's photo with the receipt or keep it (built: keep); HEIC (built: refused, the phone converts to JPEG); the mandator not liable to VAT (RECEIPT-VAT-001); «ohne MWST» counts as the VAT decision of a complete receipt (built: yes).
- **Module not registered in the local project DBs**: `Version20261112100000` is not among the migration namespaces of z77.ch or z77.ch-initial (`z77-db status` 2026-11-12) — enable the module there before the look.
- Not built (cheap, not on the first pass): «Journal drucken» (the journal list as PDF) and the print icon per protocol in «Verbuchte Journale»; zoom / rotate of the photo in the window. Deliberately left out: the fiscal-year selection (a filter would hide open receipts of another year, backend-screen.md §4).
- Not built: the dashboard entry («n Belege im Belegjournal») in module-backend's `dashboard` list.
- Not built: correction of a POSTED receipt through the ledger as its source (`LedgerService::amend()` / `retract()` / `reverse()` with source `receipt`).
- Not built: the receipts of one device from the «Geräte» window (needs api.md API-010 b); the link from a journal entry to its receipt photo (financial knows no module — needs a source-link registry, not designed).
- Deferred: a public line builder in module-financial for the one-line entry (RECEIPT-BUILD-001) — when a second caller needs it.

## see also

- [`financial.md`](financial.md) — `LedgerService::post()`, the generated-entry rules (unit of work, idempotency key, refusals), the one-line entry whose VAT split the posting reuses
- [`debtor.md`](debtor.md) — the Faktura-Journal (ADR-054) whose pattern «Journal verbuchen» follows: protocol row, lock order, the whole run, open-work checks
- [`documents.md`](documents.md) — `forModule()` / `ModuleDrive` / `saveGenerated()`, the trusted system path the photo takes; `MediaField::previewUrl()`, the gated address the receipt window shows the photo through
- [`css-backend.md`](css-backend.md) — the action cells of the three entries and the shared blocks the screens added (`.be-docview`, `.be-list__thumb`, `.be-form__missing`, `.be-form__field--full`)
- [`api.md`](api.md) — the gateway and the device channel that will call `capture()` (wave 2)
- [`persistence-doctrine.md`](persistence-doctrine.md) — the transaction port, the migrations, the open-work registry
