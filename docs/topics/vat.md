# vat

2026-09-22

## entry

1. `packages/module-vat/src/Calculation/VatCalculator.php` — the one place VAT is computed: lines + service date + price mode → resolved rate per line and a tax summary per code
2. `packages/module-vat/src/Services/VatRates.php` — «rate of tax code X on date D»; the loud errors (`UnknownTaxCodeException`, `NoRateException`) and the `ResolvedRate` snapshot
3. `tests/module-vat.php` — the harness: seed, boundaries, rounding, negatives, no float, validators, backdating, deactivate-never-delete

## file map

SOURCE=/packages/module-vat/composer.json
SOURCE=/packages/module-vat/README.md
SOURCE=/packages/module-vat/src/App/Config/vatConfig.inc.php
SOURCE=/packages/module-vat/src/Entities/TaxCode.php
SOURCE=/packages/module-vat/src/Entities/TaxRate.php
SOURCE=/packages/module-vat/src/Entities/TaxCategory.php
SOURCE=/packages/module-vat/src/Repositories/TaxCodeRepository.php
SOURCE=/packages/module-vat/src/Repositories/TaxRateRepository.php
SOURCE=/packages/module-vat/src/Validators/TaxCodeValidator.php
SOURCE=/packages/module-vat/src/Validators/TaxRateValidator.php
SOURCE=/packages/module-vat/src/Services/VatRates.php
SOURCE=/packages/module-vat/src/Services/VatMasterData.php
SOURCE=/packages/module-vat/src/Services/VatException.php
SOURCE=/packages/module-vat/src/Services/UnknownTaxCodeException.php
SOURCE=/packages/module-vat/src/Services/NoRateException.php
SOURCE=/packages/module-vat/src/Services/RateInEffectException.php
SOURCE=/packages/module-vat/src/Services/TaxCodeChangedException.php
SOURCE=/packages/module-vat/src/Services/InvalidRateException.php
SOURCE=/packages/module-vat/src/Calculation/VatCalculator.php
SOURCE=/packages/module-vat/src/Calculation/PriceMode.php
SOURCE=/packages/module-vat/src/Calculation/VatLine.php
SOURCE=/packages/module-vat/src/Calculation/ResolvedRate.php
SOURCE=/packages/module-vat/src/Calculation/ResolvedLine.php
SOURCE=/packages/module-vat/src/Calculation/TaxSummaryEntry.php
SOURCE=/packages/module-vat/src/Calculation/TaxSummary.php
SOURCE=/packages/module-vat/src/Calculation/VatResult.php
SOURCE=/packages/module-vat/src/Ui/TaxCodeControllerTrait.php
SOURCE=/packages/module-vat/src/Ui/TaxCodeLayout.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/listAction.tpl.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/edit.tpl.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/addRate.tpl.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/actions.tpl.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/confirmRemoveRate.tpl.php
SOURCE=/packages/module-vat/res/view/templates/Backend/TaxCodeController/_rate.tpl.php
SOURCE=/packages/module-vat/data/framework/vat/tax_codes.default.json
SOURCE=/packages/module-vat/data/framework/vat/tax_rates.default.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/TaxCodeController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/taxCodeControllerConfig.inc.php
SOURCE=/packages/module-backend/res/view/templates/Finance/TaxCodeController/list.hc1.tpl.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/kernel/shared/src/Money/Money.php
SOURCE=/tests/module-vat.php
SOURCE=/docs/02-decisions/adr-041-vat-model.md
SOURCE=/docs/02-decisions/adr-042-ledger-and-money.md
SOURCE=/docs/02-decisions/adr-043-order-status-and-stock-movements.md
SOURCE=/docs/03-development/order-debtor-financial-bauplan.md

## mental model

`z77/module-vat` (ADR-041, plan §4) holds the VAT model for every business module and depends on the kernel only. A **tax code** (`TaxCode`: `code`, `country`, `category`, `label`, `active`) says what kind of tax; a **tax rate** (`TaxRate`: `code`, `validFrom`, `rate`, `createdOn`) says how much from when — one row per validity, a rate change is a NEW row and old rows stay, so a 2023 service date still resolves 7.7 %. Both are file-based installation master data (`data/framework/vat/`), seeded once on first install with the Swiss set since 2018 and maintained in the backend. `VatRates::resolve(code, serviceDate)` answers with a `ResolvedRate` snapshot; `VatCalculator::calculate()` computes a document's VAT ONCE — the resolved rate per line and a `TaxSummary` per code — and the caller carries the result; nothing downstream recomputes from the tables.

- **Rates are integers in hundredths of a percent** (`810` = 8.1 %), amounts are `Money` (ADR-042). There is no float in the module: `Money` refuses one, the rate parameters check `is_int`, `TaxRate::setRate()` throws a `TypeError`, and the form's percent string is converted by `TaxRate::percentToHundredths()` in integer arithmetic (trailing `%` and surrounding whitespace tolerated, inner whitespace refused — `8 1` must not become 81 %).
- **Resolution is by the service date** (ADR-041 decision 4): the row with the latest `validFrom` not after the date. A code without a row in effect is `NoRateException`, an unknown code `UnknownTaxCodeException` — never a silent 0 % (wdv's «Kein MwSt-Pflichtiger Betrag» fallback turned data gaps into tax-free invoices); there is deliberately no «try» variant. A deactivated code still resolves (history).
- **Tax on the sum per code, rounded once** (decision 5): `Σ line amounts per code → tax = round(sum × rate / 10000)` half away from zero to 0.01 (commercial rounding, clarified in ADR-041/042 on 2026-09-21); gross mode `tax = gross × rate / (10000 + rate)`, base = gross − tax. `ResolvedLine` carries the rate only, deliberately no per-line tax — per line and summed is the wdv Rappen smear. A negative amount (credit note) yields a negative tax, rounded half away from zero the same way (−0.005 → −0.01), so a credit note mirrors its invoice. The 0.05 rounding of a document total is NOT done here (debtor adds a rounding line).
- **Snapshot shapes**: `ResolvedRate` (`code`, `country`, `category`, `label`, `rate`, `validFrom`) per line and `TaxSummary` (`TaxSummaryEntry {code, rate, base, tax}` per code, one currency, totals `base()/tax()/gross()`). How they are STORED (JSON column, file fields) is the consumer's shape and arrives with the invoice in P3 — the ledger posts `taxCode`, `taxBase`, `taxAmount` per line from the summary (net method, decision 7); a discount corrects proportionally over the summary's bases with `Money::allocate()` (decision 8). The module carries no serialisation of its own until then (see pending).
- **Categories** are the model's, not an installation's: `TaxCategory` enum — standard, reduced, special, zero, exempt, reverse-charge, input-material, input-other — domain only, no labels. `zero` (Art. 23 MWSTG, taxable at 0 % with input tax deduction) and `exempt` (Art. 21, no deduction) are two categories because the ESTV form reports them apart. A country pack maps code + rate to the authority's form field by these categories. German labels live in the UI (`TaxCodeControllerTrait::CATEGORY_LABELS`, the `ROLE_LABELS` pattern of the backend user screen).
- **CH seed** (`tax_codes.default.json` / `tax_rates.default.json`, deployed by the installer's package walk, seed-once, ADR-024): `UN` standard 7.7 % → 8.1 %, `UR` reduced 2.5 % → 2.6 %, `US` special (lodging) 3.7 % → 3.8 % (2018-01-01 / 2024-01-01), `UE` zero 0 %, `UA` exempt 0 %, `VM` input-material and `VI` input-other at the standard rate. Labels are German (installation data). **Owner decisions 2026-09-21:** NO reverse-charge code in the seed (an installation that owes Bezugsteuer adds one, category `reverse-charge`), and NO reduced-rate input-tax codes (`VM`/`VI` at the standard rate only; an installation buying at 2.6 % / 3.8 % creates its own, e.g. `VM2`). The seed is the country pack `CH` for P1; the ESTV form mapping is P5 (see pending).
- **The reference rule** (ADR-043 decision 19): documents and journal lines reference a tax code by `code`, snapshot what it shows at use, and a code is DEACTIVATED, never deleted — `VatMasterData` has no delete, the backend trait has no remove action for a code, and `VatMasterData::saveCode()` refuses a changed `code` on an existing row (`TaxCodeChangedException`), so any writer is bound, not only the form (whose code field is read-only on edit).
- **Rates: add, never edit; backdating refused; removal narrow** (`VatMasterData::addRate()` / `removeRate()`, owner 2026-09-21). `addRate()` stamps `createdOn` with today, runs `TaxRateValidator` with today and persists. The validator refuses a `validFrom` before today — EXCEPT when it lies before the code's earliest existing row (pure backfill, e.g. pre-2018 rates for a migration) — or when the code has no row at all: its FIRST rate may start at any valid date (owner, 2026-09-21; VAT-RATE-002). A row may be removed while its validity has not started, or when it was entered TODAY for today (the same-day typo — `validFrom === createdOn === today`); every other row in effect stays (`RateInEffectException`). Seed rows carry no `createdOn` and are therefore never the same-day case.
- **Backend screen**: the fragment pattern of the DMS Drive / member accounts (ADR-018): logic and templates in `module-vat` (`Ui/TaxCodeControllerTrait`, `res/view/templates/Backend/TaxCodeController/`, the `_rate` partial as the one place a rate row is phrased), a thin host `module-backend` `Finance/TaxCodeController` (`use TaxCodeControllerTrait`), the layout pinned by `Ui/Config/Finance/taxCodeControllerConfig.inc.php` → `TaxCodeLayout::config()`, the hc1 add button in the backend namespace (`loadHeaderSlots()` resolves against the host). Backend group `finance` (owner-confirmed 2026-09-21; `groupDefaults['finance'] = 'tax-code'`, ADMIN, `list` convention — no `controllers` entry). URL `/backend/finance/tax-code/list`; actions `add`, `edit`, `toggle-active` (inline `data-fetch-toggle` switch), `add-rate?code=`, `actions`, `confirm-remove-rate`, `remove-rate`. Entity-token contexts: `taxCode` (id), `taxRateAdd` (keyed by code — a new row has no id), `taxRate` (rate id). Styling uses the shared backend classes only (`.be-tree--hub`, `.be-list__empty`, `.be-list__cell--muted`, `.be-form__hint`, `.be-list__table` for the rate history, badges) — no inline styles beyond the tree's `--node-depth`, no CSS and no JavaScript of its own. The navigation entry is NOT in the kernel seed (a host entry that fatals without the module would be wrong on a fresh install): a project adds «Finanzen» → «MWST-Codes» → `/backend/finance/tax-code/list` in the backend, like `member-accounts`.
- **Where the package is required**: the monorepo ROOT `composer.json` (so `vendor/` carries it for the harness environment); NOT `skeleton/composer.json` — like `module-member`, a project requires it when it needs it (owner 2026-09-21). No `RUNTIME=` paths therefore.
- **The module has no routes and no view area**: `vatConfig.inc.php` carries no `defaultGroup`; `/vat/…` resolves nothing. It is a library plus a fragment.
- `TaxRate::validFrom` and `createdOn` are `YYYY-MM-DD` strings compared as strings (ISO order); JSON keys are snake_case (`valid_from`, `created_on`), properties camelCase, per the File driver.

## rules

- When any module needs VAT on a document (quote, order, invoice, credit note) → MUST call `VatCalculator::calculate($currency, $lines, $serviceDate, $priceMode)` once, store the per-line `ResolvedRate` and the `TaxSummary` entries as the document's snapshot, and MUST NOT recompute from the tables later (PDF, posting, return, discount, loss read the snapshot — ADR-041 decision 6)
- When resolving a rate → MUST pass the SERVICE date of the document, never the invoice date (decision 4); MUST let `NoRateException` / `UnknownTaxCodeException` propagate to the user as an error, MUST NOT fall back to 0 % and MUST NOT add a «try» variant that returns null
- When computing a percentage of an amount anywhere near VAT → MUST use `VatCalculator::taxOf()` / `taxIn()` (or `Money::multiplyByRatio()` with the integer rate); MUST NOT multiply by a decimal rate or compute per line and sum
- When a document needs its total rounded to 0.05 → MUST do it as a separate rounding line in the document (debtor), MUST NOT round inside the tax summary or the calculator
- When storing a rate → MUST store the integer in hundredths of a percent; MUST NOT store or accept a float (`TaxRate::setRate()` throws; a form value goes through `TaxRate::percentToHundredths()`)
- When writing a `TaxRate` from any code path (backend, import, script) → MUST go through `VatMasterData::addRate($rate, $today)` — it stamps `createdOn`, applies the backdating rule and runs the validator; MUST NOT `persist()` a rate directly and MUST NOT edit an existing row (`addRate()` refuses a row with an id)
- When a rate changes (a new law) → MUST add a NEW row with `validFrom` today or later («Neuer Satz gültig ab» in the backend); MUST NOT backdate it into days existing rows cover — a `validFrom` before today is accepted only before the code's earliest row (backfilling history) or for the first rate of a code without any row
- When a rate row is wrong → MUST remove it only while it is not yet in effect or on the day it was entered for that same day (`VatMasterData::removeRate()` refuses otherwise); a wrong rate that applied is corrected by a new row from today and the affected documents by credit note
- When a tax code is no longer needed → MUST deactivate it (`active = false`); MUST NOT delete the row or add a delete action (ADR-043 decision 19 — documents reference it by code)
- When writing a `TaxCode` → MUST go through `VatMasterData::saveCode()`; MUST NOT change `code` on an existing row (refused with `TaxCodeChangedException`) — a different kind of tax is a new code
- When referencing a tax code from a Doctrine entity → MUST store the `code` string, never a numeric id of the file-based row, plus what the consumer needs snapshotted: a DOCUMENT line (invoice, credit note) stores `rate` AND `label` (it is printed and must read the same for ever); a JOURNAL line stores the `rate` only (`journal_line.tax_rate` — the ledger is not a printed document; the return groups by code + rate, see `financial.md`)
- When showing tax codes to pick for a NEW document → MUST offer active codes only (`TaxCode::isActive()`); when displaying or recomputing nothing on an existing document → MUST read the document's snapshot, not the tables
- When adding a validation on `TaxCode` / `TaxRate` → MUST put it into `TaxCodeValidator` / `TaxRateValidator` (the write service and the backend run them), MUST keep the overlap check (same code, same `validFrom`) and the backdating rule intact
- When editing `tax_codes.json` / `tax_rates.json` or the `*.default.json` seeds by hand → MUST keep UTF-8 without BOM and integer rates; MUST NOT round-trip through Windows PowerShell (DATA-JSON-001, `persistence-file.md`)
- When mounting the backend screen elsewhere (another group, a project backend) → MUST `use TaxCodeControllerTrait` and delegate the controller layout config to `TaxCodeLayout::config()`, and MUST override `vatListBase()` to the mount's URL root — the templates build every URL from it
- When a template shows a rate row → MUST render the `Backend/TaxCodeController/_rate` partial (namespace `Z77\Module\Vat`); MUST NOT format percent and date inline
- When a category needs a German label in a screen → MUST read it from the UI layer (`CATEGORY_LABELS` in the trait, fallback = the value); MUST NOT put display text into `TaxCategory`
- When adding another country → MUST add its codes and rates as data (seed or backend) and its form mapping as a new country pack; MUST NOT change `TaxCategory` or the calculator for it (ADR-041 decision 9)
- When adding a method to this package → MUST have a production caller in the same change (CLAUDE.md «no just-in-case», plan «nothing in stock»); serialisation, pickers and the like arrive with the module that needs them

## known issues

- **VAT-RATE-001** — resolved 2026-09-21 (owner decision): a same-day typo is repairable in the backend — a row entered today for today can be removed today (`createdOn`); every other row in effect stays, and a wrong rate that applied is corrected by a new row from today plus credit notes. Don't assume a backdated correction is possible: `validFrom` before today is refused unless it backfills before the code's earliest row (or is the first rate of a code without rows, VAT-RATE-002).
- **VAT-RATE-002** — resolved (owner, 2026-09-21): the FIRST rate of a code that has no rate yet accepts any valid `validFrom`, past included — there is no existing range it could reach into, and an installation creating a code for documents with older service dates needs it to cover them. Don't assume this extends to the second rate: once a row exists, a backdated `validFrom` is backfill before the earliest row or refused. The check counts every row of the code (`TaxRateValidator`, `$all`), so a stored only row re-validated is not a «first rate».
- **VAT-SEED-001** — don't assume an existing installation receives the CH seed or a later seed change: the installer walk is seed-once per FILE (`data/framework/vat/*.json` present → untouched). The record-level import (ADR-032) is not wired for `TaxCode` / `TaxRate` yet (see pending); until then a new code goes in through the backend.
- **VAT-NAV-001** — don't expect a «MWST-Codes» entry in the navigation after install: the kernel seed carries none (a host entry that fatals without `module-vat` would be wrong on every fresh install). The project adds it in the backend under a section of its choice. Checked 2026-09-22 (P2 exit check S4): the seed has no «Finanzen» section either — a project with module-financial creates one itself and puts «MWST-Codes» there next to the ledger screens (`financial.md` FIN-NAV-001).
- **VAT-CAT-001** — don't assume `TaxCode::getCategory()` is one of the eight: it is the stored string, so a hand-edited file may carry an unknown value; `category()` returns null for it, the list shows the raw string, and the validator refuses it on the next save.

## pending

- **ESTV form mapping (country pack `CH`, P5)**: `ADR-041` decision 9 — code + rate → form field (200, 302, 312, 342, 400, 405, …), consumed only by financial's VAT return. Deferred to P5 with the return itself; the seed is the P1 content of the pack. Place: `packages/module-vat/src/CountryPack/` once a consumer exists.
- **Snapshot serialisation** — closed 2026-09-23 without a serialiser in this module: the invoice stores `ResolvedRate` as columns of its lines (`invoice_line.tax_code` / `tax_rate` / `tax_label`) and `TaxSummaryEntry` as rows of `invoice_tax` (code, category, label, rate, base, tax) — `debtor.md`, P3 part 2. No `toArray()` / `fromArray()` was needed. The **code picker** («active codes for a new document») arrives with the draft editor of P3 part 3; `InvoicingService` already refuses a deactivated code on a NEW document (`tax-code-inactive`) as the last line.
- **Import of `TaxCode` / `TaxRate`** (adopt a seed change into an existing installation, ADR-032): needs `#[ImportIdentity(['code'])]` / `#[ImportIdentity(['code', 'validFrom'])]`, `importEntities` in `vatConfig`, and a validator factory — today `ImportServiceFactory::fromDi()` in the kernel wires validators by a fixed map, so a module cannot register its own without a kernel edit. Needs a module-side seam first; the import must then write rates through `VatMasterData::addRate()`.
- `tests/module-vat.php` covers the trait only by reflection (no remove action for a code, no `$originalCode` re-set); a request-level check of the screen (mount, hc1 slot, toggle) is manual in a project installation until a controller harness exists.

## see also

- [`money.md`](money.md) — `Money`: `multiplyByRatio()` is the arithmetic behind `taxOf()` / `taxIn()`, `allocate()` the proportional VAT correction of a discount
- [`persistence-file.md`](persistence-file.md) — the File driver the two entities live on; seed-once `*.default.json`; the no-PowerShell rule for `data/**/*.json`
- [`backend.md`](backend.md) — the fragment/host mount pattern, header slots (`hc1`), the deviation-only `backendConfig`
- [`../02-decisions/adr-041-vat-model.md`](../02-decisions/adr-041-vat-model.md) — the binding model: separate code and rate, sum per code, computed once and carried, net posting, country packs
- [`../02-decisions/adr-043-order-status-and-stock-movements.md`](../02-decisions/adr-043-order-status-and-stock-movements.md) — decision 19, the reference rule for file-based master data held by Doctrine rows
