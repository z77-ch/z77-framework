# creditor

2026-11-15

## entry

1. `packages/module-creditor/src/Services/CreditorProfileService.php` — THE write path of a creditor: `open()` (the role «Kreditor»), `update()` with the version (UID, defaults + stamp, IBAN sync), `setActive()`, `delete()`
2. `packages/module-creditor/src/Ui/CreditorProfileControllerTrait.php` — the master-data screen «Kreditoren» (mounted at `/backend/finance/creditor-profile`): standard list, window, «Aktiv» switch
3. `tests/module-creditor.php` — the harness: wiring, module direction, the shared payment helpers, entity and validator offline (O); seed, mount, fragment, helps (S); migration up / diff / down, role, update, refusals, shared IBAN, IBAN sync, reference rule, VAT liability, search (M); the screen through a host double (SM)

## file map

SOURCE=/packages/module-creditor/composer.json
SOURCE=/packages/module-creditor/README.md
SOURCE=/packages/module-creditor/src/App/Config/creditorConfig.inc.php
SOURCE=/packages/module-creditor/res/migrations/Version20261115100000.php
SOURCE=/packages/module-creditor/src/Entities/CreditorProfile.php
SOURCE=/packages/module-creditor/src/Entities/CreditorIban.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorProfileRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorIbanRepository.php
SOURCE=/packages/module-creditor/src/Repositories/CreditorSearch.php
SOURCE=/packages/module-creditor/src/Services/CreditorProfileService.php
SOURCE=/packages/module-creditor/src/Services/CreditorException.php
SOURCE=/packages/module-creditor/src/Services/CreditorRefusedException.php
SOURCE=/packages/module-creditor/src/Services/InvalidCreditorProfileException.php
SOURCE=/packages/module-creditor/src/Validators/CreditorProfileValidator.php
SOURCE=/packages/module-creditor/src/Roles/CreditorContactRole.php
SOURCE=/packages/module-creditor/src/Ui/CreditorProfileControllerTrait.php
SOURCE=/packages/module-creditor/src/Ui/CreditorListing.php
SOURCE=/packages/module-creditor/src/Ui/CreditorLayout.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/listAction.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/_row.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/edit.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/list.help.tpl.php
SOURCE=/packages/module-creditor/res/view/templates/Backend/CreditorProfileController/edit.help.tpl.php
SOURCE=/packages/module-creditor/data/framework/routing/navigation.d/module-creditor.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/CreditorProfileController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/creditorProfileControllerConfig.inc.php
SOURCE=/packages/kernel/shared/src/Payment/Iban.php
SOURCE=/packages/kernel/shared/src/Payment/QrReference.php
SOURCE=/packages/kernel/shared/src/Payment/ScorReference.php
SOURCE=/packages/kernel/shared/src/Payment/Mod97.php
SOURCE=/packages/module-debtor/src/Invoicing/QrReferenceLayout.php
SOURCE=/tests/module-creditor.php
SOURCE=/docs/03-development/creditor-module-bauplan.md

## mental model

A creditor is a CONTACT with a creditor profile — the debtor model (creditor plan §2.2): name, address, language and e-mail stay on the contact, a supplier who is also a customer is one party. The contact editor's role «Kreditor» (module-contact's `contactRoles` registry) opens an empty profile; the master-data screen «Kreditoren» (Stammdaten › Finanzen) edits UID, IBANs and the defaults a new invoice line starts with. Stage 1a (2026-11-15) built only this; the creditor invoice, the Kreditorenjournal, the upload and the posting follow in 1b / 1c (plan §9, owner answers §11).

- **Module direction:** creditor REQUIRES contact, vat, mandator, financial, receipt (owner answer 1: payment methods, field rules, later the rate proposal) and dms (the PDF, from 1b). None of them names creditor (harness O2); creditor names no debtor class.
- **`creditor_profile`:** `contact_id` (unique), `uid` (canonical `CHE-…`, unique, NULL = none — recognition in stage 2 takes an exact UID over the IBAN, so one UID names one creditor), the defaults `default_account` (number), `default_tax_code` OR `default_without_vat`, `default_payment_method_code` (module-receipt's `PaymentMethod` code) — all by code, no foreign key (ADR-043 decision 19) —, `defaults_set_at` / `defaults_set_by`, `active`, `version`. No creditor number (plan §2.2).
- **`creditor_iban`:** several per creditor, stored normalised, `is_qr_iban` derived, `hint`, `active`. The index on `iban` is NOT unique — a shared IBAN (a billing or collection company) is allowed; `CreditorIbanRepository::findProfilesByIban()` answers 0, 1 or n creditors (active links and active creditors only by default). One creditor lists an IBAN once (`uniq_creditor_iban_profile`). Any country — a supplier abroad has a foreign IBAN; the MOD-97 check digits are what is insisted on. Nothing references a row (an invoice will SNAPSHOT the IBAN), so the window removes a row by emptying it.
- **The defaults stamp (plan §5):** NULL = no defaults decided; stage 1c lets the first complete invoice set them while NULL. A change of the defaults through the window stamps actor + time (they are decided now); emptying all of them clears the stamp. An IBAN-only change leaves the stamp alone.
- **Optimistic lock:** `version` is a plain column the SERVICE bumps on every write (an IBAN-only change does not touch the profile row, so Doctrine's `ORM\Version` would not count it). `update()` validates a detached draft FIRST, then locks the row `FOR UPDATE`, compares the version, reads it fresh, applies, syncs the IBANs (same IBAN kept with its id, new ones added, missing ones removed), bumps.
- **Validation** (`CreditorProfileValidator`): UID shape + modulo-11 check digit (module-mandator's `Uid`), unique; account and tax code through module-receipt's `ReceiptFields` (exists, postable / active, splits a gross amount); payment method exists and is active — each only when CHANGED (an unchanged reference deactivated since is kept); a tax code and «ohne MWST» never together; a mandator NOT liable to VAT refuses a tax code (owner answer 5 applied to the defaults; 1c forces «ohne MWST» on the invoice lines); IBAN rows: shape, check digits, once per creditor, hint ≤ 80.
- **The role «Kreditor»** (`CreditorContactRole`): on = `open()` (refused for an inactive contact or a contact that already is a creditor); off = `delete()` (profile + IBANs) after the dialog's `offConfirm`. In 1a nothing can reference a profile; the «only while no invoice references it, else deactivate» guard arrives with the invoice (pending).
- **The screen «Kreditoren»** (`CreditorProfileControllerTrait`, thin host `Finance/CreditorProfileController`, seed `navigation.d/module-creditor.json` → «Stammdaten › Finanzen › Kreditoren» after «Zahlungsarten»): action cell EMPTY (a creditor is made by the role); standard list `CreditorListing` (Kreditor · UID · IBAN · Konto · MWST · Bezahlt mit · Aktiv; name, UID and IBAN searched in SQL, an IBAN part typed with spaces); the window — contact fixed, UID, the defaults (MWST select: code, `-` = «ohne MWST», '' = open; a non-liable mandator offers only «ohne MWST»), the stamp line, the IBAN rows plus an empty «Neue IBAN», «Auch bei: …» for a shared IBAN; posts the version and the entity token; the save closes the window and reloads the list region. Helps: `list.help` (toolbar), `edit.help` (a section per field).
- **Shared payment helpers (plan §2.5, moved in 1a):** `Z77\Shared\Payment\Iban` (was debtor's `Services\Iban`), `QrReference` (the QRR standard: check digit, validity, format, `withCheckDigit()`), `ScorReference` (ISO 11649 «RF», new), `Mod97` (the ISO 7064 check both IBAN and RF use). Debtor imports them; its own payload layout (zeros · customer number · document number) is `Invoicing\QrReferenceLayout` — the references it builds are unchanged.

## rules

- When a contact must become a creditor → MUST go through `CreditorProfileService::open()` (the role «Kreditor», later «Kreditor übernehmen …»); MUST NOT persist a `CreditorProfile` directly
- When changing a creditor → MUST call `CreditorProfileService::update($profile, $version, $values, $ibanRows)` with the version the screen showed and the COMPLETE list of IBAN rows (`CreditorProfileService::ibanRows()` cleans what a form posts); MUST handle `CreditorRefusedException::CHANGED` by reloading
- When the defaults of a creditor are set by anything but the creditor window (the first complete invoice, «als Standard übernehmen» — stage 1c) → MUST stamp `defaults_set_at` / `defaults_set_by` through the entity's `stampDefaults()` and MUST NOT overwrite defaults while the stamp is set unless the owner asked for it (plan §5)
- When matching an invoice to a creditor by IBAN → MUST use `CreditorIbanRepository::findProfilesByIban()` and treat n > 1 as «choose» (the hint decides only on exactly one hit); MUST NOT make `creditor_iban.iban` unique or pick the first of several (plan §2.2, never a guess)
- When checking an IBAN, a QR reference or an RF reference anywhere in the framework → MUST use `Z77\Shared\Payment\Iban` / `QrReference` / `ScorReference`; MUST NOT copy the check into a module
- When a creditor default or line names an account or a tax code → MUST judge it through module-receipt's `ReceiptFields`; MUST NOT add a second rule set
- When the mandator is not liable to VAT → MUST NOT accept a tax code on a creditor default (and from 1c MUST force «ohne MWST» on every creditor line, owner answer 5)
- When building the creditor invoice (1b) → MUST add the «Kreditor off / delete only while no invoice references the profile, else deactivate» guard to `CreditorProfileService::delete()` and the role's state in the same change

## known issues

- CREDITOR-ROLE-LINK-001: don't assume «Kreditor bearbeiten …» in the contact dialog opens a window — the role link is a `data-fetch-get` that REPLACES the dialog (contact.md, review M4), and the creditor edit is an ADR-047 window template; its form then posts as a page and the save lands on the list with a flash. To be looked at in the owner's look (the debtor role link has the same limit)
- CREDITOR-ANSWERS-001: don't assume the window answers are shared — `creditorSaved()` / `creditorRefuse()` repeat module-receipt's `MasterDataAnswers` (bound to receipt's namespace); a third copy should move them to a shared building block (Rule 9)

## pending

- **Stage 1b** (next agent): the creditor invoice + lines + intake + protocol entities (own migration), `CreditorIntakeService::receive()` (backend upload, SHA-256 idempotency), the Kreditorenjournal screen (list, window with the PDF, «Aufteilen …» = lines inside one invoice, owner answer 2), the invoice count in «Kreditoren», the delete guard of the role (rules above).
- **Stage 1c**: «Bezahlt am …» bulk, «Journal verbuchen …» as a run up to a cut-off on the payment date (owner answer 3), `CreditorPostingStrategy` + `DirectPosting` + `creditorConfig['postingVariant']`, financial's `GrossEntryBuilder` extraction (RECEIPT-BUILD-001), the defaults-once rule from the first complete invoice and «als Standard übernehmen», «IBAN beim Kreditor speichern», forced «ohne MWST» for a non-liable mandator, suspected duplicates created + blocked (owner answer 4), retention on posting (owner answer 8), the open-work check, the guide `Backend/Guide/creditorInvoices.help`, ADR-059 (record model, strategy, switch, module graph — not written in 1a: no 1a decision needed it beyond the plan).
- **Stage 2**: text-layer recognition (`SwissQrBillText`), matching by IBAN / UID / shared IBAN + hint, proposals and «Kreditor übernehmen …», duplicate checks, the folder job `creditor-intake` (no `processed/`, delete after the verified DMS store, `failed/` stays — owner answer 7), tab «Eingang».
- **Stage 3** (was 4; QR image decoding dropped, owner answer 6): the AI step through kibot's client, key file and its own on/off + model in `config/client/kibot.inc.php` (owner answer 9); off or no key → «nicht erkannt — von Hand erfassen».
- **Owner's look**: the board «Kreditoren» on the developer canvas was NOT drawn before building (backend-screen.md checklist 1) — the screen follows the approved «Zahlungsarten» / «Belegvorlagen» look; check list columns, the IBAN rows in the window, CREDITOR-ROLE-LINK-001.
- **Enable in the projects**: z77.ch and z77.ch-initial must register `creditor` in `moduleManager` and the PSR-4 path (README) before `z77-db migrate` runs `Version20261115100000` (the receipt «not registered» lesson).

## see also

- [`contact.md`](contact.md) — the contact-role registry the role «Kreditor» plugs into; the dialog that renders it
- [`receipt.md`](receipt.md) — `PaymentMethod` («Bezahlt mit») and `ReceiptFields`, reused for the defaults (owner answer 1)
- [`debtor.md`](debtor.md) — the model (profile on a contact, the role, «deactivate, never delete»); the QR-bill side of `Iban` / `QrReference` and `QrReferenceLayout`
- [`mandator.md`](mandator.md) — `Uid`, and the VAT liability that forbids a tax code on a creditor default
- [`listing.md`](listing.md) — the standard list «Kreditoren» is built on
