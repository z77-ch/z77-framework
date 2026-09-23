# debtor

2026-09-23

## entry

1. `packages/module-debtor/src/Services/DebtorProfileService.php` — the write path of the debtor profile: one per contact, validate-before-mutate, deactivate never delete
2. `packages/module-debtor/src/Services/DebtorMasterData.php` — the one write path of the three file-based master-data types: validates, refuses a changed code, has no delete
3. `tests/module-debtor.php` — the harness against MariaDB: the migration through `z77-db` with a clean `diff`, the seeds, the discount tiers, the IBAN / QR-IBAN check digits, the reference rule per type, the config accessor's refusals and the four fragments (173 checks)

## file map

SOURCE=/packages/module-debtor/composer.json
SOURCE=/packages/module-debtor/README.md
SOURCE=/packages/module-debtor/src/App/Config/debtorConfig.inc.php
SOURCE=/packages/module-debtor/src/Entities/DebtorProfile.php
SOURCE=/packages/module-debtor/src/Entities/PaymentTerms.php
SOURCE=/packages/module-debtor/src/Entities/PaymentTarget.php
SOURCE=/packages/module-debtor/src/Entities/DunningLevel.php
SOURCE=/packages/module-debtor/src/Entities/HasDocumentText.php
SOURCE=/packages/module-debtor/src/Repositories/DebtorProfileRepository.php
SOURCE=/packages/module-debtor/src/Repositories/PaymentTermsRepository.php
SOURCE=/packages/module-debtor/src/Repositories/PaymentTargetRepository.php
SOURCE=/packages/module-debtor/src/Repositories/DunningLevelRepository.php
SOURCE=/packages/module-debtor/src/Services/DebtorProfileService.php
SOURCE=/packages/module-debtor/src/Services/DebtorMasterData.php
SOURCE=/packages/module-debtor/src/Services/DebtorAccounts.php
SOURCE=/packages/module-debtor/src/Services/DebtorCurrency.php
SOURCE=/packages/module-debtor/src/Services/LedgerAccountCheck.php
SOURCE=/packages/module-debtor/src/Services/Iban.php
SOURCE=/packages/module-debtor/src/Services/DebtorException.php
SOURCE=/packages/module-debtor/src/Services/AccountNotConfiguredException.php
SOURCE=/packages/module-debtor/src/Services/InvalidDebtorProfileException.php
SOURCE=/packages/module-debtor/src/Services/InvalidMasterDataException.php
SOURCE=/packages/module-debtor/src/Services/MasterDataCodeChangedException.php
SOURCE=/packages/module-debtor/src/Validators/DebtorProfileValidator.php
SOURCE=/packages/module-debtor/src/Validators/PaymentTermsValidator.php
SOURCE=/packages/module-debtor/src/Validators/PaymentTargetValidator.php
SOURCE=/packages/module-debtor/src/Validators/DunningLevelValidator.php
SOURCE=/packages/module-debtor/src/Validators/DocumentTextRule.php
SOURCE=/packages/module-debtor/src/Validators/ValidatesMasterDataRow.php
SOURCE=/packages/module-debtor/src/Ui/DebtorControllerTrait.php
SOURCE=/packages/module-debtor/src/Ui/DebtorLayout.php
SOURCE=/packages/module-debtor/src/Ui/PaymentTermsControllerTrait.php
SOURCE=/packages/module-debtor/src/Ui/PaymentTermsLayout.php
SOURCE=/packages/module-debtor/src/Ui/PaymentTargetControllerTrait.php
SOURCE=/packages/module-debtor/src/Ui/PaymentTargetLayout.php
SOURCE=/packages/module-debtor/src/Ui/DunningLevelControllerTrait.php
SOURCE=/packages/module-debtor/src/Ui/DunningLevelLayout.php
SOURCE=/packages/module-debtor/src/Ui/DocumentTextForm.php
SOURCE=/packages/module-debtor/res/migrations/Version20260922173918.php
SOURCE=/packages/module-financial/res/charts/kmu.json
SOURCE=/packages/module-debtor/data/framework/debtor/payment_terms.default.json
SOURCE=/packages/module-debtor/data/framework/debtor/dunning_levels.default.json
SOURCE=/packages/module-debtor/res/view/templates/Backend/DebtorController/listAction.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DebtorController/edit.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DebtorController/search.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTermsController/listAction.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTermsController/edit.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTermsController/addButton.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTargetController/listAction.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTargetController/edit.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/PaymentTargetController/addButton.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DunningLevelController/listAction.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DunningLevelController/edit.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DunningLevelController/addButton.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/DebtorController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/PaymentTermsController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/PaymentTargetController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/DunningLevelController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/debtorControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/paymentTermsControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/paymentTargetControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/dunningLevelControllerConfig.inc.php
SOURCE=/tests/module-debtor.php
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md
SOURCE=/docs/02-decisions/adr-040-business-module-cut.md
SOURCE=/docs/02-decisions/adr-041-vat-model.md
SOURCE=/docs/02-decisions/adr-042-ledger-and-money.md
SOURCE=/docs/02-decisions/adr-043-order-status-and-stock-movements.md
SOURCE=/docs/03-development/order-debtor-financial-bauplan.md

## mental model

`z77/module-debtor` (plan §6, ADR-040) is the receivables side: it owns invoicing and the open items, and it reaches the bookkeeping only through a port — the package *suggests* `z77/module-financial` and requires kernel, `persistence-doctrine`, `module-vat` and `module-contact` (plan §2). **P3 part 1, this state, builds MASTER DATA only**: the debtor profile per contact, and the three file-based types an invoice, a payment and a dunning notice are built from. `InvoicingService`, invoices, credit notes, PDF with QR-bill and the accounting gateway are parts 2 and 3; payments, discount and loss, CAMT.054 and the dunning runs are P4. Nothing here exists without a caller in this state (guiding rule: nothing in stock). The package is required in the monorepo ROOT `composer.json` only — NOT in `skeleton/composer.json`, like `module-contact` and `module-financial`; a project requires it when it needs it. There are therefore no `RUNTIME=` paths: the seeded `data/framework/debtor/*.json` come into being in a PROJECT that installs the package, not in the skeleton.

### `DebtorProfile` (Doctrine)

- Table `debtor_profile`: `contact_id` (FK to `contact`, **unique** — `uniq_debtor_profile_contact`), `payment_terms_code` (VARCHAR 16, indexed `idx_debtor_profile_terms`), `dunning_block`, `active`. Keyed by the contact's id in DEBTOR's table — not one column of it lives on `contact` (plan §2, §4a: contact knows no module).
- **At most one profile per contact**: `DebtorProfileValidator` asks first so the screen gets a field error; the unique index decides under a race, and `DebtorProfileService::flushOrRefuse()` turns its violation into the same field error (the `AccountService` model).
- **A NEW profile needs an ACTIVE contact** (decided 2026-09-22 on review): the reference rule of ADR-043 decision 19 applied to the party — a new reference needs an active row, and a debtor is a reference to a contact. An EXISTING profile on a contact deactivated SINCE keeps working and stays editable, because open items and documents are already written against it. The list therefore offers «Debitor anlegen» only on an active contact, and `addAction()` refuses a hand-built URL with the same sentence.
- **No `language`** (decided 2026-09-22): `Contact::$language` already says which language a document to this party is written in (plan §4a). A second field would be a second truth about one fact (Rule 2).
- **No `currency`** (decided 2026-09-22): the Bauplan §6.1 lists it, but Q6 decided against foreign-currency invoicing and §6.2 puts currency and rate on the DOCUMENT, not on the party. On the profile it would be an unread column with no caller; part 2 adds it to the invoice, where it is read. Adding it later is a new column on a new table, not a change to issued documents.
- **Payment terms are REQUIRED**, not «deviation from a default» (decided 2026-09-22): there is no global default payment terms setting, and inventing one before `InvoicingService` reads it would be a setting nobody uses. A profile without terms would leave part 2 with nothing to fall back on.
- Write path `DebtorProfileService`: `save($new)`, `update($profile, $values)` (values onto a detached clone, validated, only then applied to the managed entity — ADR-039 decision 9), `setActive()`, `forContact()`. **No delete anywhere** — invoices and open items are written against the party.

### File-based master data (three types, one rule set)

All three follow `module-vat`'s `TaxCode` pattern exactly (including `setActive()`, below): `#[Entity('file', 'framework/debtor/…json')]`, seeded once on first install from `data/**/*.default.json` (ADR-024 seed-once, no installer change needed — the installer walks every framework package's data root), referenced BY `code`, code immutable, **deactivated and never deleted** (ADR-043 decision 19), UTF-8 without BOM (DATA-JSON-001). Their one write path is `DebtorMasterData`, which has no remove method at all.

**`set*Active()` is a PURE STATE CHANGE** (decided 2026-09-22 on review, the `VatMasterData::setActive()` model): it checks the code and writes the row — the field validator does NOT run. A row must be switchable off exactly when it has become invalid, and that is the normal reason to switch it off: a payment target whose ledger account was deactivated in the bookkeeping, or a row whose document text stands in a language the installation has since dropped. Running the full validator there would let the rule meant for NEW input forbid the cleanup. The validator runs where new input arrives — on a form save (`saveTerms()` / `saveTarget()` / `saveLevel()`). The three `toggleActiveAction`s still catch `DebtorException` and answer with a `fetchError`, so the one rule `setActive()` keeps (an unchanged code) never becomes a 500.

- **`PaymentTerms`** (`payment_terms.json`): `code`, `label` (German, for the backend list), `dueDays`, `discounts` (a list of `{days, percent}`, at most `MAX_TIERS` = 2, percent as an INTEGER IN HUNDREDTHS like the VAT rates — 2 % = `200`), `documentText` (per language), `active`. Seed: `net-30` «30 Tage netto» and `disc-2-10` «10 Tage 2 %, 30 Tage netto» — **without a `document_text`** (owner, 2026-09-22): an empty text stays allowed, so an installation in any language starts with valid rows and writes its own wording. Two tiers are supported because three-step terms («3 % / 10 Tage, 2 % / 20 Tage, 30 Tage netto») exist in Swiss practice; the seeded example needs one tier plus `dueDays`.
- **`PaymentTarget`** (`payment_targets.json`): `code`, `label`, `iban` (normalized upper-case without spaces), `accountNumber` (the ledger account that bank account is booked on), `active`. **Not seeded** — an IBAN cannot be guessed and a placeholder would end up printed on a QR-bill; the file comes into being with the first row the backend writes.
- **`DunningLevel`** (`dunning_levels.json`): `code`, `label`, `level` (unique, the order a dunning run walks), `daysAfterDue`, `fee` (INTEGER MINOR UNITS — plan §3, `Money` is not a JSON row), `documentText`, `active`. Seed: `reminder` (level 1, 10 days, 0.00), `dunning-1` (level 2, 30 days, 20.00), `dunning-2` (level 3, 50 days, 40.00) — **without a `document_text`**, for the same reason as the payment terms. **No tax code and never one**: a dunning fee compensates the effort of chasing a debt, it is not a supply (plan §6.5).

### Shared shapes (Rule 8)

Three pieces exist once instead of two or three times: `Entities/HasDocumentText` (the property, its normalising setter and its getter — `PaymentTerms` and `DunningLevel` both carry it), `Validators/ValidatesMasterDataRow` (the identical `code` / `label` rules of all three file entities, with the repository supplied by the using validator through `masterDataRows()`), and `Validators/DocumentTextRule` (the per-language rule itself).

### Text per language, label in German

Two texts, two audiences (decided 2026-09-22): `label` is the German name the BACKEND list shows, exactly as `TaxCode::$label` and `AddressType::$label` are; `documentText` is what prints on the customer's invoice or notice and is therefore kept per language. The languages are the installation's (`I18n::getLanguages()`, `i18n.md`), the default language first, and `DocumentTextRule` enforces both halves: a key must be a language this installation serves, and as soon as ANY language is filled the DEFAULT language must be too — it is what every other language falls back to (the `ContentService::find()` fallback part 2 will use). Leaving the text empty everywhere stays allowed. The **fallback RESOLVER is deliberately not built yet** — it arrives with the document in part 2, like `AddressTypes::resolve()` did for the address snapshot.

### `Iban` — what is checked, and why separately

`Services/Iban` is pure: `normalize()`, `format()` (groups of four), `isWellFormed()` (shape plus the exact registry length for CH / LI: 21), `hasValidCheckDigits()` (ISO 7064 MOD-97-10 on the DIGIT STRING in seven-character chunks — a 34-character IBAN expands to ~70 digits and would overflow `int`), `isSwissArea()`, `iid()` (positions 5–9) and `isQrIban()` (IID **30000–31999**, the range SIX reserved for QR-IBANs). The three checks are separate so the German message says what is actually wrong. A QR-IBAN carries a QR reference (QRR), a normal IBAN a creditor reference (SCOR) or none — part 2 needs the difference to print the right reference; part 1 records and shows it.

### The soft boundary to financial

`LedgerAccountCheck` is the only class in debtor that names module-financial, and it names it as a STRING (`LEDGER_SERVICE`), so its absence is a lookup miss and not a fatal (ADR-040 decision 5: the package only `suggest`s financial). Every answer is three-valued: `true` postable and active, `false` financial refuses, **`null` financial is not usable here and the caller may not treat that as a failure**.

**«Installed» means TWO things** (decided 2026-09-22 on review), and the second one decides: the class must autoload AND `financial` must be a REGISTERED module (`ModuleManager::getModuleConfig('financial')`). A package sitting in `vendor/` but missing from the project's `moduleManager` config has no config, no announced entities and no booted Doctrine metadata for `Account` — asking it would not answer «no», it would fatal, and with it every debtor list. Registration is also what a project actually switches when it decides to keep its books elsewhere, which is why the harness proves the branch by unregistering the module rather than through a constructor seam. It wraps `LedgerService::accountExists()` (plan §5.4, «for configuration validation»), whose second production caller this is. It is the READ half of what becomes `AccountingGateway` in part 2 (plan §6.6).

### `debtorAccounts` — the account settings

`debtorConfig → debtorAccounts` is a map key → account NUMBER, read only through `Services/DebtorAccounts` — the `LedgerService::vatAccountFor()` model, key for key (Rule 2). Defaults verified against `packages/module-financial/res/charts/kmu.json`:

| key | default | account |
|---|---|---|
| `receivable` | `1100` | Forderungen aus Lieferungen und Leistungen (Debitoren) |
| `discount` | `3800` | Erlösminderungen (Skonto is one) |
| `loss` | `3805` | Verluste aus Forderungen, Veränderung Delkredere |
| `rounding` | `3809` | Rundungsdifferenzen — the invoice's 0.05 line (plan §6.2), its own account in group 38 |
| `dunningFee` | `6950` | Finanzertrag — the fee, without VAT (plan §6.5) and not turnover |

- `number($key)` reads the configured number and fails loudly on a malformed value (`UnexpectedValueException`) — a typo in a config file is reported, never skipped. `postableNumber($key)` additionally asks `LedgerAccountCheck` and refuses with a German message NAMING THE KEY; `status()` is what the debtor screen shows so a wrong account is found before an invoice is posted. Everything is asked AT THE POINT OF USE, never at boot.
- **`rounding` has its OWN account** (owner, 2026-09-22): `3809 «Rundungsdifferenzen»` was added to the KMU chart in group 38 next to `3800` and `3805`, because rounding and discount must stay separable in the reports. This is NOT financial's VAT-return rounding account — that is its own setting (plan §5.1, the two are named apart on purpose).
- **`dunningFee` stays on `6950 Finanzertrag`** (owner, 2026-09-22): a dunning fee is **damages for the delay, not a service** — so it carries no VAT (plan §6.5) and it is not turnover, which is why it belongs outside the revenue classes 3 the VAT return reads by code.
- ⚠️ **BOOT-CONFIG-001** (`bootstrap.md`): a project override of `debtorConfig.inc.php` REPLACES the package config rather than merging, so an override must carry the FULL `debtorAccounts` map. A dropped key is then an `AccountNotConfiguredException` at the point of use — the loud failure this caveat needs until the merge is built (harness H7).

### Number ranges

**None in part 1.** The `invoice` and `credit-note` ranges belong to part 2, where the first number is drawn (`NumberRangeRepository::next()`); creating them now would be a row nothing reads. Nothing in this state draws a number.

### The backend

Four fragments in the `finance` group (ADR-018 pattern, next to the tax codes, the chart, the fiscal years, the journal and the reports): `Ui/{X}ControllerTrait` + `Ui/{X}Layout::config()`, templates in `res/view/templates/Backend/{X}Controller/`, thin hosts in `module-backend` under `Ui/Controllers/Finance/`. German labels, English code, no JavaScript of its own (Rule 7) — the shared `core.js` wiring does the modals, the forms and the switches. Each fragment adds its own header slot (`financial.md`, «fragment slots»): an add button in `hc1`, the debtor screen's search in `hc2`.

- `/backend/finance/payment-terms/list` — add, edit, toggle-active; the list shows the tiers, which languages have a document text, and how many debtors reference the row.
- `/backend/finance/payment-target/list` — add, edit, toggle-active; the IBAN grouped in fours, a «QR-IBAN» badge, and the ledger account flagged «Konto prüfen» when financial refuses it or «ungeprüft» when financial is absent.
- `/backend/finance/dunning-level/list` — add, edit, toggle-active, in `level` order; the fee account is named once above the list with its refusal.
- `/backend/finance/debtor/list` — **a screen of its own, not a fragment on the contact screen** (decided 2026-09-22): mounting into `/backend/contact/contact` would mean module-contact's template renders a debtor partial, which is a dependency from contact to a module it must not know (plan §2). So the list is CONTACT-oriented instead — the same search and the same limit as the contact list (`?q=`, contactConfig `contactListLimit` through `ContactService::listLimit()`) — and only its columns and actions are debtor's. Nothing in module-contact is touched. A contact without a profile shows «Debitor anlegen»; the account settings stand above the list with their refusals.

### Migration

`res/migrations/Version20260922173918.php` — one table, `debtor_profile`. Generated with `z77-db diff --namespace="Z77\Module\Debtor\Migrations"` against a database at module-contact's migration and reviewed: `utf8mb4_unicode_ci`, `ENGINE = InnoDB` spelled out, the Doctrine-named foreign key kept so `diff` stays clean, expand only, no DROP outside `down()`. The harness applies it through the `z77-db` application and proves `diff` reports «No changes» afterwards.

## rules

- When storing a percentage in this module (a discount tier) → MUST store an INTEGER IN HUNDREDTHS OF A PERCENT (2 % = `200`), as `TaxRate::$rate` does; MUST NOT store a float or a decimal string.
- When storing an amount on a FILE-based entity (the dunning fee) → MUST store integer minor units and build `Money` with the currency the caller passes (`DunningLevel::fee($currency)`); MUST NOT let the entity read `systemConfig` itself and MUST NOT store a decimal string.
- When a module-debtor class needs the base currency → MUST read it through `Services/DebtorCurrency::base()`; MUST NOT call `LedgerService::baseCurrency()` (module-financial is only `suggest`ed and may be absent) and MUST NOT hardcode `CHF`.
- When referencing payment terms, a payment target or a dunning level from a database row → MUST reference it BY `code` with no foreign key and MUST snapshot what it shows at the moment of use; MUST NOT add a cross-driver foreign key (ADR-043 decision 19).
- When a NEW reference to a file master-data row is created (a new profile, or an update that CHANGES the code) → MUST require the row to be ACTIVE; an UNCHANGED reference MUST be allowed to keep a deactivated row (`DebtorProfileValidator::$requireActiveTerms`).
- When a master-data row is no longer wanted → MUST deactivate it through `DebtorMasterData::set*Active()`, which checks the code and writes — MUST NOT run the field validator there, because a row that has BECOME invalid is exactly the one that has to be switchable off; MUST NOT add a delete method to `DebtorMasterData`, a repository or a screen.
- When a screen switches a master-data row active or inactive → MUST catch `DebtorException` and answer with a `fetchError`; MUST NOT let the refusal reach the user as a 500.
- When creating a NEW `DebtorProfile` → MUST require the contact to be ACTIVE (the reference rule on the party) and MUST NOT offer «Debitor anlegen» for an inactive one; MUST NOT block an EXISTING profile whose contact was deactivated since — it stays editable.
- When the `code` of an existing master-data row would change → MUST refuse it (`MasterDataCodeChangedException`) and create a new row instead; the edit form MUST render the code field read-only.
- When changing an EXISTING `DebtorProfile` → MUST hand `DebtorProfileService::update()` the cleaned VALUES so a detached clone is validated first (ADR-039 decision 9); MUST NOT mutate the managed entity in a controller before validation.
- When a screen or a service needs an account this module posts to → MUST ask `DebtorAccounts::number()` / `postableNumber()`; MUST NOT read `debtorConfig → debtorAccounts` anywhere else and MUST NOT name an account number inline (Rule 2).
- When asking whether an account may be posted to → MUST treat `LedgerAccountCheck::isPostable()` returning `null` as «cannot tell» and raise NO error; MUST NOT `use` a `Z77\Module\Financial\…` class anywhere outside `LedgerAccountCheck` (ADR-040 decision 5).
- When deciding whether module-financial can be asked → MUST require BOTH that the class autoloads and that `financial` is a registered module (`LedgerAccountCheck::available()`); MUST NOT rely on `class_exists()` alone — an unregistered package in `vendor/` has no config and no booted metadata, and asking it fatals.
- When validating an IBAN → MUST check shape, MOD-97-10 check digits and CH / LI origin separately through `Services/Iban` so the message says what is wrong; MUST NOT accept a non-Swiss IBAN as a payment target (a QR-bill names a CH or LI creditor account).
- When deciding whether an IBAN is a QR-IBAN → MUST read the IID (positions 5–9) and test 30000–31999 through `Iban::isQrIban()`; MUST NOT infer it from the bank name or the reference type.
- When adding a per-language master-data text → MUST run it through `Validators/DocumentTextRule` (a language of this installation, and the default language filled as soon as any other is); MUST NOT store a text under a language the installation does not serve.
- When seeding a master-data row → MUST leave `document_text` out (an empty text is valid), so an installation in any language starts with valid rows; MUST NOT ship German wording an installation would have to correct before it can save the row.
- When a second entity in this module needs a per-language text or the same `code` / `label` rules → MUST use `Entities/HasDocumentText` and `Validators/ValidatesMasterDataRow`; MUST NOT copy the setter or the two validate methods (Rule 8).
- When a dunning fee is modelled or posted → MUST keep it free of VAT (plan §6.5); MUST NOT add a tax code to `DunningLevel` or a tax line to its posting.
- When adding a backend screen to this module → MUST build it as a fragment trait + layout in `module-debtor` with a thin host in `module-backend` under the `finance` group (ADR-018), German labels and no JavaScript (Rule 7); MUST NOT change anything in `module-contact` to reach a contact-related screen (plan §2).
- When a number range is needed → MUST create it where the first number is actually drawn (part 2: `invoice`, `credit-note`); MUST NOT create a range in part 1, where nothing draws.

## known issues

- **DEBTOR-ROUNDING-001** — resolved 2026-09-22 (owner): the invoice rounding gets its OWN account. `3809 «Rundungsdifferenzen»` was added to `packages/module-financial/res/charts/kmu.json` in group 38 and `debtorAccounts.rounding` points at it, so rounding and discount stay separable in the reports. Don't assume an installation that adopted the chart BEFORE this row exists has it — the chart is adopted by button into an empty chart and is never re-applied; such an installation adds 3809 by hand or repoints the key.
- **DEBTOR-FEE-ACCOUNT-001** — resolved 2026-09-22 (owner): the dunning fee stays on `6950 Finanzertrag`. Reason on record: the fee is damages for the delay, not a service — no VAT and no turnover, so it belongs outside the revenue classes 3.
- **DEBTOR-TEXT-DROP-001** — don't assume a document text survives a language being dropped from the installation. `DocumentTextForm::posted()` builds the texts from `I18n::getLanguages()`, so the next EDIT of a row silently drops what stands in a language the installation no longer serves; the row is saved without it and nothing says so. The row can still be deactivated (`set*Active()` is a pure state change), and re-adding the language does NOT bring the text back — it is gone from the file at the first save.
- Don't assume a document text resolves by itself — `PaymentTerms::getDocumentText()` / `DunningLevel::getDocumentText()` return the RAW map. The fallback to `defaultLanguage` arrives in part 2 with the document that prints it; validation already guarantees the default language is filled whenever anything is.
- Don't assume an account number in `debtorAccounts` or on a `PaymentTarget` was verified. Without a REGISTERED module-financial `LedgerAccountCheck` answers `null` and nothing is checked — the number is an unverified string until part 2 posts with it. Nor does a check that passed stay true: the account can be deactivated or turned into a group afterwards, which is one reason `set*Active()` does not validate.
- Don't assume `DebtorProfile` is a complete debtor record. Part 1 carries what §6.1 names minus language and currency; everything else (invoices, open items, payments, dunning history) arrives in parts 2 and 3 and in P4.
- BOOT-CONFIG-001 applies to `debtorConfig.inc.php` like every module config: a project override REPLACES it, so it must carry the full `debtorAccounts` map.

## pending

- **DEBTOR-TEXT-DROP-001**: decide with the owner whether an edit should KEEP a text in a dropped language (invisible on the form, written back untouched) or keep dropping it loudly with a warning on the form. Today it drops silently. Not urgent — nothing prints a document before part 2.
- **P3 part 2** — `InvoicingService`: the invoice draft with typed lines and parent lines, the `invoicing` / `final` states, the 0.05 rounding line, the number ranges `invoice` and `credit-note`, the document snapshot incl. currency and rate (§6.2), and the per-language document-text resolver this part deliberately left out.
- **P3 part 3** — PDF with QR-bill (QRR from a QR-IBAN, SCOR otherwise — `Iban::isQrIban()` is what decides) and the `AccountingGateway` port (§6.6), whose read half `LedgerAccountCheck` already is.
- The package is not a split target yet (`.github/workflows/split.yml`, Packagist) — like `persistence-doctrine`, `module-vat`, `module-contact` and `module-financial`.
- The navigation entries are NOT in the kernel seed (a host entry that fatals without the module would be wrong on a fresh install): a project adds «Debitoren» → `/backend/finance/debtor/list`, «Zahlungskonditionen» → `/backend/finance/payment-terms/list`, «Zahlungsziele» → `/backend/finance/payment-target/list` and «Mahnstufen» → `/backend/finance/dunning-level/list` in the backend, like the tax codes and contacts.

## see also

- [`contact.md`](contact.md) — the party this module is keyed by; `Contact::$language` is the document language, and `AddressType` is the reference-rule precedent this module follows
- [`financial.md`](financial.md) — the sibling P3 part 2 posts into: `LedgerService::accountExists()` is what `LedgerAccountCheck` asks, and `vatAccounts` is the config-accessor pattern `DebtorAccounts` copies
- [`vat.md`](vat.md) — the `TaxCode` master-data pattern these three file entities follow, and the rates a part-2 invoice will resolve
- [`money.md`](money.md) — integer minor units, the rounding to 0.05 part 2 needs, and why no float comes near an amount
- [`persistence-file.md`](persistence-file.md) — the file driver behind the three master-data types, seed-once and DATA-JSON-001 (UTF-8 without BOM)
- [`persistence-doctrine.md`](persistence-doctrine.md) — the driver behind `DebtorProfile`, the migrations CLI and the `NumberRange` part 2 will draw from
- [`backend.md`](backend.md) — the shell, the list/tree classes and the fetch wiring the four fragments render into
- [`i18n.md`](i18n.md) — the language whitelist and the default-language fallback the per-language document texts follow
- [`bootstrap.md`](bootstrap.md) — BOOT-CONFIG-001: a module config override replaces instead of merging, which is why `debtorAccounts` must be carried in full
- [`../03-development/order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md) — §6 is what this module builds; §9 places part 1 in P3
