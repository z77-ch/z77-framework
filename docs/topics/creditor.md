# creditor

2026-11-15

## entry

1. `packages/module-creditor/src/Services/CreditorInvoiceService.php` — THE write path of a creditor invoice (1b): `update()` head + lines with the version (defaults fill, VAT forcing, duplicate check, defaults-once), `release()`, `delete()`, `adoptCreditor()`; `CreditorIntakeService::receive()` is the one intake path
2. `packages/module-creditor/src/Ui/CreditorJournalControllerTrait.php` — Finanzen › «Kreditorenjournal» (mounted at `/backend/finance/creditor-journal`): upload, list with tabs, invoice window with the PDF; `CreditorProfileControllerTrait` is the master data «Kreditoren»
3. `tests/module-creditor.php` — the harness: offline O (wiring, payment helpers, entities, sniff), S (seed, mounts, fragments, helps), M on MariaDB (migrations, role, profile, intake, invoice, duplicates, VAT, role guard, adopt, search), SM (both screens through host doubles)

## file map

SOURCE=/packages/module-creditor/composer.json
SOURCE=/packages/module-creditor/README.md
SOURCE=/packages/module-creditor/src/App/Config/creditorConfig.inc.php
SOURCE=/packages/module-creditor/res/migrations/Version20261115100000.php
SOURCE=/packages/module-creditor/res/migrations/Version20261115120000.php
SOURCE=/packages/module-creditor/src/Entities/CreditorProfile.php
SOURCE=/packages/module-creditor/src/Entities/CreditorIban.php
SOURCE=/packages/module-creditor/src/Entities/CreditorIntake.php
SOURCE=/packages/module-creditor/src/Entities/CreditorInvoice.php
SOURCE=/packages/module-creditor/src/Entities/CreditorInvoiceLine.php
SOURCE=/packages/module-creditor/src/Entities/CreditorJournalPosting.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorProfileRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorIbanRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorSearch.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorIntakeRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorInvoiceRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorInvoiceSearch.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorInvoiceLineRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorJournalPostingRepository.php
SOURCE=/packages/module-creditor/src/Services/CreditorProfileService.php
SOURCE=/packages/module-creditor/src/Services/CreditorIntakeService.php
SOURCE=/packages/module-creditor/src/Services/CreditorInvoiceService.php
SOURCE=/packages/module-creditor/src/Services/InvalidCreditorInvoiceException.php
SOURCE=/packages/module-creditor/src/Services/CreditorException.php
SOURCE=/packages/module-creditor/src/Services/CreditorRefusedException.php
SOURCE=/packages/module-creditor/src/Services/InvalidCreditorProfileException.php
SOURCE=/packages/module-creditor/src/Validators/CreditorProfileValidator.php
SOURCE=/packages/module-creditor/src/Roles/CreditorContactRole.php
SOURCE=/packages/module-creditor/src/Ui/CreditorProfileControllerTrait.php
SOURCE=/packages/module-creditor/src/Ui/CreditorListing.php
SOURCE=/packages/module-creditor/src/Ui/CreditorLayout.php
SOURCE=/packages/module-creditor/src/Ui/CreditorJournalControllerTrait.php
SOURCE=/packages/module-creditor/src/Ui/CreditorJournalListing.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/act.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/toolbar.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/listAction.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/_journal.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/edit.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/confirmDelete.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/adopt.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/list.help.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorJournalController/edit.help.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/Guide/creditorWorkflow.help.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/listAction.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/_row.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/edit.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/list.help.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/edit.help.tpl.php
SOURCE=/packages/module-creditor/data/framework/routing/navigation.d/module-creditor.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/CreditorProfileController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/creditorProfileControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/CreditorJournalController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/creditorJournalControllerConfig.inc.php
SOURCE=/packages/module-receipt/src/Ui/ReceiptPhoto.php
SOURCE=/packages/kernel/shared/src/Upload/UploadPolicy.php
SOURCE=/packages/kernel/shared/src/Payment/Iban.php
SOURCE=/packages/kernel/shared/src/Payment/QrReference.php
SOURCE=/packages/kernel/shared/src/Payment/ScorReference.php
SOURCE=/packages/kernel/shared/src/Payment/Mod97.php
SOURCE=/packages/module-debtor/src/Invoicing/QrReferenceLayout.php
SOURCE=/tests/module-creditor.php
SOURCE=/docs/03-development/creditor-module-bauplan.md

## mental model

A creditor is a CONTACT with a creditor profile — the debtor model (creditor plan §2.2): name, address, language and e-mail stay on the contact, a supplier who is also a customer is one party. The contact editor's role «Kreditor» (module-contact's `contactRoles` registry) opens an empty profile; the master-data screen «Kreditoren» (Stammdaten › Finanzen) edits UID, IBANs and the defaults a new invoice line starts with. Stage 1a (2026-11-15) built this. Stage 1b (2026-11-15) built the creditor INVOICE: the PDF upload and the «Kreditorenjournal» (Finanzen), where an invoice is checked, split into lines, and a suspected duplicate released or discarded. Nothing is posted yet — «Bezahlt am …» and «Journal verbuchen …» are 1c (plan §9, owner answers §11).

- **Module direction:** creditor REQUIRES contact, vat, mandator, financial, receipt (owner answer 1: payment methods, field rules, later the rate proposal) and dms (the PDF, from 1b). None of them names creditor (harness O2); creditor names no debtor class.
- **`creditor_profile`:** `contact_id` (unique), `uid` (canonical `CHE-…`, unique, NULL = none — recognition in stage 2 takes an exact UID over the IBAN, so one UID names one creditor), the defaults `default_account` (number), `default_tax_code` OR `default_without_vat`, `default_payment_method_code` (module-receipt's `PaymentMethod` code) — all by code, no foreign key (ADR-043 decision 19) —, `defaults_set_at` / `defaults_set_by`, `active`, `version`. No creditor number (plan §2.2).
- **`creditor_iban`:** several per creditor, stored normalised, `is_qr_iban` derived, `hint`, `active`. The index on `iban` is NOT unique — a shared IBAN (a billing or collection company) is allowed; `CreditorIbanRepository::findProfilesByIban()` answers 0, 1 or n creditors (active links and active creditors only by default). One creditor lists an IBAN once (`uniq_creditor_iban_profile`). Any country — a supplier abroad has a foreign IBAN; the MOD-97 check digits are what is insisted on. Nothing references a row (an invoice will SNAPSHOT the IBAN), so the window removes a row by emptying it.
- **The defaults stamp (plan §5):** NULL = no defaults decided; stage 1c lets the first complete invoice set them while NULL. A change of the defaults through the window stamps actor + time (they are decided now); emptying all of them clears the stamp. An IBAN-only change leaves the stamp alone.
- **Optimistic lock:** `version` is a plain column the SERVICE bumps on every write (an IBAN-only change does not touch the profile row, so Doctrine's `ORM\Version` would not count it). `update()` validates a detached draft FIRST, then locks the row `FOR UPDATE`, compares the version, reads it fresh, applies, syncs the IBANs (same IBAN kept with its id, new ones added, missing ones removed), bumps.
- **Validation** (`CreditorProfileValidator`): UID shape + modulo-11 check digit (module-mandator's `Uid`), unique; account and tax code through module-receipt's `ReceiptFields` (exists, postable / active, splits a gross amount); payment method exists and is active — each only when CHANGED (an unchanged reference deactivated since is kept); a tax code and «ohne MWST» never together; a mandator NOT liable to VAT refuses a tax code (owner answer 5 applied to the defaults; 1c forces «ohne MWST» on the invoice lines); IBAN rows: shape, check digits, once per creditor, hint ≤ 80.
- **The role «Kreditor»** (`CreditorContactRole`): on = `open()` (refused for an inactive contact or a contact that already is a creditor); off = `delete()` (profile + IBANs) after the dialog's `offConfirm` — only while no invoice names the creditor: with invoices the state is not switchable (reason «… nur deaktiviert werden», the summary counts them) and `delete()` refuses `CreditorRefusedException::IN_USE` (asked before and under the lock).
- **The invoice (1b, `Version20261115120000`, plan §2.3):** `creditor_invoice` carries from the start what 1c, stage 2 and the collective variant need — creditor or the recognised `creditor_proposal` (JSON), intake, PDF (`document_id`), number, invoice / due date, IBAN snapshot, `reference_type` (QRR / SCOR / NON, DERIVED from the reference) + reference, message, `amount` CHF + the receipt FX columns (unused: CHF only in 1b), payment state / `paid_on` / payment method / credit account, `posting_variant` snapshot `direct`, state `open` / `blocked` / `posted`, the duplicate mark, `recognition`, who / when, `version` (`ORM\Version`). Lines (`creditor_invoice_line`, owner answer 2): position, text, gross amount, account, tax code OR `without_vat`; replaced as a set on save. `creditor_journal_posting` exists (empty until 1c). `missing()` (German): Kreditor, Betrag, Bezahlt mit, Konto, MWST, «Zeilen = Betrag».
- **Intake (`CreditorIntakeService::receive()`, plan §3):** sniffs the BYTES (not empty, ≤ `creditorConfig['intake']['maxBytes']` 20 MiB, `%PDF-` in the first KiB AND `finfo` = application/pdf), SHA-256 → a hash read before is refused `DUPLICATE_FILE` «bereits eingelesen am … (Rechnung …)» and stores nothing (owner answer 4; `creditor_intake.sha256` UNIQUE decides a race); stores the PDF unaltered through `DocumentService::forModule('creditor', 'Kreditorenrechnungen')` → `<fiscal year>/<month of receipt>` (`saveGenerated`, checksum compared); then ONE unit of work: intake row + invoice (open, unpaid, direct) + ONE line — on the creditor's defaults when a creditor is handed in. No session needed (actor as data), ready for the stage-2 folder job.
- **Saving an invoice (`CreditorInvoiceService::update()`):** values through module-receipt's `ReceiptFields` plus IBAN / QR / RF checks (QRR only with a QR-IBAN, SCOR never with one); the creditor's defaults fill what is EMPTY (line account, line VAT, «Bezahlt mit» + Haben); one line without amount takes the invoice amount; a mandator NOT liable to VAT forces «ohne MWST» on every line and refuses a typed tax code (owner answer 5); then under the row lock: head + lines, the duplicate check, the DEFAULTS-ONCE rule (the first complete invoice of a creditor without defaults sets them via `CreditorProfileService::takeDefaults()`; «als Standard übernehmen» overwrites on purpose).
- **Suspected duplicate (owner answer 4):** on every save (and adopt) `CreditorInvoiceRepository::findDuplicate()` — same creditor + invoice number, or same IBAN + reference, on any other invoice (any state) — marks THIS invoice `blocked` with «mögliches Doppel von Rechnung … — …»; a match gone (typo fixed) lifts the mark. Settled pairs are skipped both ways: the invoice the owner released this one against (`duplicate_cleared_of`), and any invoice already marked or released as a duplicate of this one — so saving the original never blocks it. «Kein Doppel — übernehmen» = `release()`; «Verwerfen …» = `delete()`.
- **Delete:** invoice + lines go; the PDF stays in the DMS and the intake row stays — the same file is refused as «bereits eingelesen … die Rechnung dazu wurde gelöscht».
- **«Kreditor übernehmen …» (plan §5):** for a PROPOSED creditor (stage 2 fills `creditor_proposal`; 1b builds the path): an existing contact (searched by name) or a new one via module-contact's `ContactForm` (prefilled); `adoptCreditor()` opens the profile when needed, adds the proposed IBAN / UID (`CreditorProfileService::update()`, its own unit of work), then names the creditor on the invoice, fills empty lines with its defaults or the server-accepted proposed account / tax code, and sets the new creditor's defaults from the first line.
- **The screen «Kreditorenjournal»** (`CreditorJournalControllerTrait`, host `Finance/CreditorJournalController`, seed → «Finanzen › Kreditorenjournal» after «Belegjournal»): action cell = the kernel upload in its `cell` shape («PDF hochladen», several PDFs, the work area is the drop target, the queue in `[data-upload-progress]` OUTSIDE the list region; per file `ok` + `replace-html` of `[data-creditor-journal]`, `duplicate`, `error`); toolbar tabs «Im Journal» · «Gesperrt (n)» · «Verbuchte Journale»; band with count + total, red while invoices are blocked; standard list `CreditorJournalListing` (Status · PDF · Kreditor · Rechnungs-Nr. · Datum · fällig · Betrag · Konto · MWST · bezahlt am · Zustand). The WINDOW: the PDF beside the fields (`.be-docview`, module-receipt's `ReceiptPhoto` → `MediaField::previewUrl()`), head, «Bezahlung», the lines with core.js «rows» (`op=add` / `op=remove:<i>` without the script — re-render, nothing saved), «als Standard übernehmen» when the invoice deviates, «Kein Doppel — übernehmen» (a submit of the window form to `release`), «Kreditor übernehmen …», «Löschen …» / «Verwerfen …». Fill helpers `creditor-fill` / `payment-fill` answer values only (Rule 7). Helps `list.help` (toolbar), `edit.help` (a section per field), guide `Backend/Guide/creditorWorkflow.help`. «Kreditoren» gained the column «Rechnungen».
- **The screen «Kreditoren»** (`CreditorProfileControllerTrait`, thin host `Finance/CreditorProfileController`, seed `navigation.d/module-creditor.json` → «Stammdaten › Finanzen › Kreditoren» after «Zahlungsarten»): action cell EMPTY (a creditor is made by the role); standard list `CreditorListing` (Kreditor · UID · IBAN · Konto · MWST · Bezahlt mit · Aktiv; name, UID and IBAN searched in SQL, an IBAN part typed with spaces); the window — contact fixed, UID, the defaults (MWST select: code, `-` = «ohne MWST», '' = open; a non-liable mandator offers only «ohne MWST»), the stamp line, the IBAN rows plus an empty «Neue IBAN», «Auch bei: …» for a shared IBAN; posts the version and the entity token; the save closes the window and reloads the list region. Helps: `list.help` (toolbar), `edit.help` (a section per field).
- **Shared payment helpers (plan §2.5, moved in 1a):** `Z77\Shared\Payment\Iban` (was debtor's `Services\Iban`), `QrReference` (the QRR standard: check digit, validity, format, `withCheckDigit()`), `ScorReference` (ISO 11649 «RF», new), `Mod97` (the ISO 7064 check both IBAN and RF use). Debtor imports them; its own payload layout (zeros · customer number · document number) is `Invoicing\QrReferenceLayout` — the references it builds are unchanged.

## rules

- When a contact must become a creditor → MUST go through `CreditorProfileService::open()` (the role «Kreditor», later «Kreditor übernehmen …»); MUST NOT persist a `CreditorProfile` directly
- When changing a creditor → MUST call `CreditorProfileService::update($profile, $version, $values, $ibanRows)` with the version the screen showed and the COMPLETE list of IBAN rows (`CreditorProfileService::ibanRows()` cleans what a form posts); MUST handle `CreditorRefusedException::CHANGED` by reloading
- When the defaults of a creditor are set by anything but the creditor window (the first complete invoice, «als Standard übernehmen» — stage 1c) → MUST stamp `defaults_set_at` / `defaults_set_by` through the entity's `stampDefaults()` and MUST NOT overwrite defaults while the stamp is set unless the owner asked for it (plan §5)
- When matching an invoice to a creditor by IBAN → MUST use `CreditorIbanRepository::findProfilesByIban()` and treat n > 1 as «choose» (the hint decides only on exactly one hit); MUST NOT make `creditor_iban.iban` unique or pick the first of several (plan §2.2, never a guess)
- When checking an IBAN, a QR reference or an RF reference anywhere in the framework → MUST use `Z77\Shared\Payment\Iban` / `QrReference` / `ScorReference`; MUST NOT copy the check into a module
- When a creditor default or line names an account or a tax code → MUST judge it through module-receipt's `ReceiptFields`; MUST NOT add a second rule set
- When the mandator is not liable to VAT → MUST NOT accept a tax code on a creditor default and MUST force «ohne MWST» on every creditor invoice line (`CreditorInvoiceService`, owner answer 5) — a typed code is refused, an undecided line is set
- When a supplier file enters the installation (upload, later the folder job) → MUST go through `CreditorIntakeService::receive()`; MUST NOT store a creditor PDF or create an invoice another way (the sniff, the hash idempotency and the DMS place live there)
- When changing a creditor invoice → MUST call `CreditorInvoiceService::update()` / `release()` / `delete()` / `adoptCreditor()` with the version the screen showed; MUST NOT write `CreditorInvoice` or its lines directly — the duplicate check, the VAT forcing and the defaults-once rule run there
- When deleting a creditor invoice → MUST keep its PDF and its intake row (the same file is never read twice, owner answer 4)
- When a creditor has invoices → MUST NOT delete its profile (`CreditorProfileService::delete()` refuses IN_USE); deactivate it

## known issues

- CREDITOR-ROLE-LINK-001: don't assume «Kreditor bearbeiten …» in the contact dialog opens a window — the role link is a `data-fetch-get` that REPLACES the dialog (contact.md, review M4), and the creditor edit is an ADR-047 window template; its form then posts as a page and the save lands on the list with a flash. To be looked at in the owner's look (the debtor role link has the same limit)
- CREDITOR-DOCVIEW-001: don't assume the «document beside its record» figure is shared — the PDF figure of the invoice window repeats the markup of receipt's window (`.be-docview` + `ReceiptPhoto`); a third user should move it to a shared partial (Rule 9)
- CREDITOR-ANSWERS-001: don't assume the window answers are shared — `creditorSaved()` / `creditorRefuse()` repeat module-receipt's `MasterDataAnswers` (bound to receipt's namespace); a third copy should move them to a shared building block (Rule 9)

## pending

- **Stage 1c**: «Bezahlt am …» bulk (selection bar, `id:version`) and per invoice in the window, «Journal verbuchen …» as a run up to a cut-off on the payment date (owner answer 3) — it then takes the ACTION CELL and «PDF hochladen» moves to the toolbar (plan §8); a blocked or incomplete invoice in the run blocks it; `CreditorPostingStrategy` + `DirectPosting` + `creditorConfig['postingVariant']` (the invoice already snapshots `direct`), financial's `GrossEntryBuilder` extraction (RECEIPT-BUILD-001), the protocol rows (`creditor_journal_posting` exists), «IBAN beim Kreditor speichern», retention on posting (owner answer 8), the open-work check `UnpostedCreditorInvoicesCheck`, ADR-059 (record model, strategy, switch, module graph). The guide `Backend/Guide/creditorWorkflow.help` (1b) then loses its «Buchen: folgt».
- **Owner question (1b)**: a DELETED invoice keeps its intake row, so the identical PDF can never be uploaded again («bereits eingelesen … gelöscht») — the strict reading of owner answer 4. If a wrongly deleted invoice must come back, a «wieder einlesen» from the DMS (or releasing the hash on delete) is the owner's decision.
- **Owner's look (1b)**: no board «Kreditorenjournal» on the developer canvas before building (backend-screen.md checklist 1) — the screen follows the approved «Belegjournal» and «Zahlungseingänge» looks; check the window (PDF thumbnail + «PDF öffnen» instead of an inline PDF view, as receipt's PDF branch), the tab «Gesperrt», the upload in the action cell until 1c.
- **Stage 2**: text-layer recognition (`SwissQrBillText`) after `receive()` (fill only what is empty, note per value in `recognition`), matching by IBAN / UID / shared IBAN + hint, filling `creditor_proposal` (the «Kreditor übernehmen …» path exists since 1b), the folder job `creditor-intake` (no `processed/`, delete after the verified DMS store, `failed/` stays — owner answer 7), tab «Eingang».
- **Stage 3** (was 4; QR image decoding dropped, owner answer 6): the AI step through kibot's client, key file and its own on/off + model in `config/client/kibot.inc.php` (owner answer 9); off or no key → «nicht erkannt — von Hand erfassen».
- **Owner's look**: the board «Kreditoren» on the developer canvas was NOT drawn before building (backend-screen.md checklist 1) — the screen follows the approved «Zahlungsarten» / «Belegvorlagen» look; check list columns, the IBAN rows in the window, CREDITOR-ROLE-LINK-001.
- **Enable in the projects**: z77.ch and z77.ch-initial must register `creditor` in `moduleManager` and the PSR-4 path (README) before `z77-db migrate` runs `Version20261115100000` and `Version20261115120000` (the receipt «not registered» lesson); module-dms must be enabled (the PDFs).

## see also

- [`contact.md`](contact.md) — the contact-role registry the role «Kreditor» plugs into; the dialog that renders it
- [`receipt.md`](receipt.md) — `PaymentMethod` («Bezahlt mit») and `ReceiptFields`, reused for the defaults and the invoice fields (owner answer 1); `ReceiptPhoto` and `.be-docview` for the PDF beside the invoice window
- [`debtor.md`](debtor.md) — the model (profile on a contact, the role, «deactivate, never delete»); the QR-bill side of `Iban` / `QrReference` and `QrReferenceLayout`
- [`mandator.md`](mandator.md) — `Uid`, and the VAT liability that forbids a tax code on a creditor default
- [`listing.md`](listing.md) — the standard lists «Kreditoren» and «Kreditorenjournal» are built on
- [`documents.md`](documents.md) — the DMS the PDFs are stored in (`DocumentService::forModule()`, partition «Kreditorenrechnungen»)
- [`fetch.md`](fetch.md) — the upload component (UPLOAD-001) the action cell uses, core.js «rows» and «fill» of the invoice window
