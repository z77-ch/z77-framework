# financial

2026-09-22

## entry

1. `packages/module-financial/src/Services/AccountService.php` — the write side of the chart of accounts: save, update on a validated draft, deactivate, and «KMU-Kontenrahmen übernehmen» only into an empty chart
2. `packages/module-financial/src/Services/FiscalYearService.php` — opening a fiscal year: validation, monthly periods, the `journal-entry.{code}` number range, one unit of work
3. `tests/module-financial.php` — the harness against MariaDB: the migration through `z77-db`, the KMU chart's consistency, account and fiscal-year validation, periods, the range, rollback

## file map

SOURCE=/packages/module-financial/composer.json
SOURCE=/packages/module-financial/README.md
SOURCE=/packages/module-financial/src/App/Config/financialConfig.inc.php
SOURCE=/packages/module-financial/src/Entities/Account.php
SOURCE=/packages/module-financial/src/Entities/AccountType.php
SOURCE=/packages/module-financial/src/Entities/FiscalYear.php
SOURCE=/packages/module-financial/src/Entities/Period.php
SOURCE=/packages/module-financial/src/Entities/PeriodState.php
SOURCE=/packages/module-financial/src/Repositories/AccountRepository.php
SOURCE=/packages/module-financial/src/Repositories/FiscalYearRepository.php
SOURCE=/packages/module-financial/src/Validators/AccountValidator.php
SOURCE=/packages/module-financial/src/Validators/FiscalYearValidator.php
SOURCE=/packages/module-financial/src/Services/AccountService.php
SOURCE=/packages/module-financial/src/Services/FiscalYearService.php
SOURCE=/packages/module-financial/src/Services/FinancialException.php
SOURCE=/packages/module-financial/src/Services/InvalidAccountException.php
SOURCE=/packages/module-financial/src/Services/AccountNumberChangedException.php
SOURCE=/packages/module-financial/src/Services/ChartNotEmptyException.php
SOURCE=/packages/module-financial/src/Services/InvalidFiscalYearException.php
SOURCE=/packages/module-financial/src/Ui/AccountControllerTrait.php
SOURCE=/packages/module-financial/src/Ui/AccountLayout.php
SOURCE=/packages/module-financial/src/Ui/FiscalYearControllerTrait.php
SOURCE=/packages/module-financial/src/Ui/FiscalYearLayout.php
SOURCE=/packages/module-financial/res/charts/kmu.json
SOURCE=/packages/module-financial/res/migrations/Version20260922071232.php
SOURCE=/packages/module-financial/res/view/templates/Backend/AccountController/listAction.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/AccountController/edit.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/AccountController/confirmAdoptKmuChart.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/FiscalYearController/listAction.tpl.php
SOURCE=/packages/module-financial/res/view/templates/Backend/FiscalYearController/open.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/AccountController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Finance/FiscalYearController.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/accountControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/Finance/fiscalYearControllerConfig.inc.php
SOURCE=/packages/module-backend/res/view/templates/Finance/AccountController/list.hc1.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Finance/FiscalYearController/list.hc1.tpl.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/persistence-doctrine/src/Repositories/NumberRangeRepository.php
SOURCE=/tests/module-financial.php
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md
SOURCE=/docs/02-decisions/adr-040-business-module-cut.md
SOURCE=/docs/02-decisions/adr-042-ledger-and-money.md
SOURCE=/docs/03-development/order-debtor-financial-bauplan.md

## mental model

`z77/module-financial` (plan §5, ADR-040, ADR-042) is the double-entry bookkeeping every posting source reaches through one door, `LedgerService::post()` — it knows no module. It is built in three parts: **part 1 (this state)** is the chart of accounts and the fiscal years with their periods; part 2 adds the journal (`JournalEntry` / `JournalLine`), manual entries with change log and `LedgerService`; part 3 the reports. The VAT return, the period transitions and the year-end are P5. All entities are Doctrine (`#[Entity('doctrine')]`, announced in `financialConfig.inc.php` → `doctrineEntities`); the package depends on kernel, `persistence-doctrine` and `module-vat` (plan §2 — part 1 does not call `module-vat` yet; part 2's journal lines carry a tax code, base and amount, ADR-041/042) and requires PHP 8.4.

- **`Account`** (table `account`: `number` unique, `name`, `type`, `parent_id` FK to `account`, `postable`, `active`). The number is digits only, at most 10, stored as a string — string order is the chart's order (`1` < `10` < `100` < `1000` < `1020` < `106` < … < `11`), which `AccountRepository::allInOrder()` uses and the list indents by. Grouping is a plain parent reference, no nested set. A **group** (KMU class `1`, main group `10`, group `100`) is an account with `postable = false`; only postable accounts will be allowed on a journal line (part 2).
- **Five types** (`AccountType`: `asset`, `liability`, `equity`, `expense`, `revenue` — owner decision 2026-09-22): equity is its own type so the balance sheet splits equity from debt by type, without a number rule. German labels live in the UI (`ACCOUNT_TYPE_LABELS` in the trait: Aktiven, Fremdkapital, Eigenkapital, Aufwand, Ertrag). A group carries a type as well; for a group mixing types (KMU classes 7 and 8, group 69 with `6950 Finanzertrag`) it is informational.
- **Validation** (`AccountValidator`): number required, digits, unique; name required; type one of the five; the parent must be a GROUP, never the account itself or one of its descendants (the validator walks the chain upwards, with a visited set against a cycle already in the data); a group WITH children cannot become postable. Deliberately no rule tying the number to the parent's number or the type to the parent's type — the KMU chart mixes types inside a class, and its groups are number RANGES, not prefixes (`1020` sits in group `100`, `1850` in `180`).
- **Write side = `AccountService`**: `save($new)`, `update($account, $values)` (the parent travels as the entity under the key `parent`), `setActive()`, `adoptKmuChart()`. `update()` validates a detached `clone` first and applies the values to the managed entity only when it passed (ADR-039 decision 9, the `ContactService` model); a refused change never reaches the next flush. The **number is immutable** (`AccountNumberChangedException`): settings and other modules' configuration name accounts by number (plan §5.1, §5.4). No delete anywhere — an account is deactivated; journal lines will reference it. A unique-number race in `save()` becomes the validator's field error (`uniq_account_number`).
- **KMU chart by button, not by seed** (owner decision 2026-09-22): `res/charts/kmu.json` (UTF-8 without BOM, a list of `{number, name, type, postable, parent}` with parents before children) is the Swiss SME chart in the Sterchi structure — classes 1–9, main groups, groups and 196 rows with the common postable accounts, German names. `adoptKmuChart()` refuses unless the chart is EMPTY (`AccountRepository::isEmpty()`, any row counts, active or not — `ChartNotEmptyException`), validates every row before persisting any, and writes all in ONE flush; two adoptions racing collide on the unique number and the loser writes nothing. Rationale: an installation that migrates its wdv chart (P5b) must not get a second chart forced into it. Owner decisions 2026-09-22 on the content: class 28 ships in the **legal-entity variant only** («Eigenkapital (juristische Personen)») — a sole proprietorship adds Kapital / Privat by hand in the backend, there is no second chart file; class 9 «Abschluss» carries its main groups only — its postable closing accounts arrive with the year-end in P5.
- **`FiscalYear`** (table `fiscal_year`: `code` unique, `start_date` unique, `end_date`) and **`Period`** (table `fiscal_period`: `fiscal_year_id` FK, `start_date`, `end_date`, `state`; unique on year + start). Owner decisions 2026-09-22: FREE start and end dates (deviating, shortened, extended years), at most 24 months; years are contiguous — a new year starts the day after the latest one ends, the first may start anywhere; periods are CALENDAR MONTHS clipped to the year (15.3.–31.12. → ten periods, the first 15.3.–31.3.).
- **The code** names the year and its number range `journal-entry.{code}` (`FiscalYear::journalEntryRange()`, the one place it is composed): lower-case kebab (`2026`, `2026-27`), at most 16 characters, unique, proposed from the dates (`FiscalYearService::proposeCode()`: `2026` inside one calendar year, `2026-27` across). A code is needed rather than the start year because two years can start in the same calendar year (short year 1.1.–30.6.2026, then 1.7.2026–30.6.2027) — orchestrator's follow-on decision to the owner's free dates. Immutable: `FiscalYear` has no public setter (code and dates come with the constructor), and part 1 has no edit and no delete of a year. An empty code is reported only when both dates are valid — otherwise the code would have been proposed from them, and the date errors say what to fix.
- **Opening a year** (`FiscalYearService::open()`): validate (`FiscalYearValidator`), derive the monthly periods, then ONE unit of work through `UnifiedEntityManager::getTransaction(FiscalYear::class)->run()` — first `getRepository(NumberRange::class)->create('journal-entry.{code}')` (lock order: `NumberRange` first), then `persist()` of the year with its periods (cascade). The range is created at 0 and committed with the year, so the first draw under contention never takes the new-row path (DOCTRINE-NR-003); an exception anywhere rolls back year, periods and range together. The range must be NEW: `create()` answers `false` for a row that already exists (a leftover of a removed year, an import), and continuing it would start the year's entries at its old number + 1 unnoticed — refused as a `code` field error, rolled back (review M1). `open()` OWNS its unit of work and refuses to run inside an open one (`LogicException`): nested, the flush would happen at the caller's commit, where the race mapping can no longer turn a unique-index violation into a field error (review L1) — the production caller is the backend controller. A race of two openings of the same next year collides on `uniq_fiscal_year_start` and becomes a `start_date` field error.
- **Period state** (`PeriodState`: `open`, `vat-settled`, `closed` — ADR-042 decision 10): every period is created `open`; there is no transition and no state setter in part 1 (P5). The column exists because part 2's `LedgerService` refuses by it.
- **Backend screens**: the fragment pattern (ADR-018, like `module-vat` and `module-contact`): logic and templates here (`Ui/AccountControllerTrait`, `Ui/FiscalYearControllerTrait`, `res/view/templates/Backend/…`), thin hosts in `module-backend` `Ui/Controllers/Finance/`, layouts pinned by `Ui/Config/Finance/*ControllerConfig.inc.php` → `AccountLayout::config()` / `FiscalYearLayout::config()`, hc1 buttons in the backend namespace. Backend group **`finance`** (existing, owner-confirmed for the tax codes). URLs `/backend/finance/account/list` (actions `add`, `edit`, `toggle-active`, `confirm-adopt-kmu-chart`, `adopt-kmu-chart` — the last two only while the chart is empty) and `/backend/finance/fiscal-year/list` (`open`; the list shows every year with its periods and states, fetch-joined). Entity-token context `account` (id); opening a year is a new entity (session CSRF). No CSS and no JavaScript of their own; the code proposal for changed dates is made on the server when the field is left empty.
- **The navigation entries are NOT in the kernel seed** (a host entry that fatals without the module would be wrong on a fresh install): a project adds «Kontenplan» → `/backend/finance/account/list` and «Geschäftsjahre» → `/backend/finance/fiscal-year/list` in the backend, like the tax codes and contacts.
- **Migration** `Version20260922071232` (`res/migrations`, namespace `Z77\Module\Financial\Migrations`): generated with `z77-db diff` against an empty database, reviewed — `utf8mb4_unicode_ci`, `ENGINE = InnoDB` spelled out, expand only, no DROP. The table is `fiscal_period`, not `period` (a MariaDB keyword, `PERIOD FOR`). `tests/module-financial.php` applies it through the `z77-db` application and proves `diff` reports «No changes» afterwards.
- **Where the package is required**: the monorepo ROOT `composer.json` only (so `vendor/` carries it for the harness); NOT `skeleton/composer.json` — a project requires it when it needs it, like `module-contact`. No `RUNTIME=` paths therefore. The module has no routes and no view area (`financialConfig.inc.php` carries no `defaultGroup`).

## rules

- When writing an `Account` from any code path (backend, import, a migration of a wdv chart) → MUST go through `AccountService` (`save()`, `update()`, `setActive()`); MUST NOT `persist()` an account directly — the validator lives there
- When changing an EXISTING (managed) account → MUST hand the cleaned values to `AccountService::update($account, $values)`, the parent as the entity under `parent`; MUST NOT call `mapFromArray()` or a setter on the loaded account first (ADR-039 decision 9)
- When an account is no longer needed → MUST deactivate it; MUST NOT delete the row or add a delete action — journal lines reference it
- When an account needs a different number → MUST create a new account and deactivate the old one; MUST NOT change `number` on an existing account (refused with `AccountNumberChangedException`)
- When grouping accounts → MUST put a postable account under a GROUP (`postable = false`); MUST NOT make an account with children postable or give a postable account children
- When offering the KMU chart → MUST call `AccountService::adoptKmuChart()` and offer the button only while `AccountRepository::isEmpty()`; MUST NOT seed the chart in the installer or a migration (owner, 2026-09-22 — a migrated chart must not get it forced on it)
- When editing `res/charts/kmu.json` → MUST keep UTF-8 without BOM, parents before children, five-type values and the Sterchi numbers, and MUST run `php tests/module-financial.php` (B-section checks the structure); MUST NOT round-trip it through Windows PowerShell (DATA-JSON-001)
- When a fiscal year is opened (backend, import, test) → MUST go through `FiscalYearService::open()` OUTSIDE any open unit of work (it refuses a nested call) and MUST treat a leftover `journal-entry.{code}` range as a conflict to resolve, not to reuse (refused on `code`); MUST NOT persist a `FiscalYear` or a `Period` directly or create `journal-entry.{code}` elsewhere — the year, its periods and its range are one unit of work
- When code needs the name of a year's journal-entry range → MUST use `FiscalYear::journalEntryRange()`; MUST NOT compose `'journal-entry.' . …` inline
- When choosing a fiscal-year code → MUST keep it lower-case kebab of at most 16 characters (it is the dotted segment of a range name); MUST NOT reuse a code or rely on case
- When a period's state has to change (VAT return, close — P5) → MUST add the transition to this module with its own rules (one way: `open` → `vat-settled` → `closed`, ADR-042 decision 10); MUST NOT write `fiscal_period.state` any other way
- When adding a column or an entity to this module → MUST run `php vendor/bin/z77-db diff --namespace="Z77\Module\Financial\Migrations"` and commit the reviewed migration with the entity, expand/contract; MUST NOT change `Version20260922071232` once an installation may have applied it
- When mounting the screens elsewhere → MUST `use AccountControllerTrait` / `FiscalYearControllerTrait`, delegate the layout config to `AccountLayout::config()` / `FiscalYearLayout::config()`, and override `accountListBase()` / `fiscalYearListBase()` to the mount's URL root
- When adding a method to this package → MUST have a production caller in the same change (CLAUDE.md «no just-in-case»); `accountExists()` and the posting rules arrive with `LedgerService` in part 2

## known issues

- **FIN-NAV-001** — don't expect «Kontenplan» / «Geschäftsjahre» entries in the navigation after install: the kernel seed carries none (like CONTACT-NAV-001). The project adds them under the «Finanzen» section next to «MWST-Codes».
- **FIN-CHART-001** — don't assume the KMU chart is the full Sterchi chart: it is the classes, main groups, groups and the COMMON postable accounts (196 rows). An installation adds what its business needs (e.g. rounding accounts, plan §5.1 / §6.1) through the backend.
- **FIN-CHART-002** — don't assume the chart fits a sole proprietorship out of the box: class 28 is the variant for legal entities («Aktien-, Stamm-, Anteilschein- oder Stiftungskapital», reserves). Decided (owner, 2026-09-22): legal-entity variant only, no second chart file — a Personenunternehmen adds its equity accounts (Kapital, Privat) by hand in the backend after the adoption.
- **FIN-FY-001** — don't assume the proposed code is always free: two years in the same calendar year with the same shape (a short year 1.1.–31.3.2026 followed by 1.4.–31.12.2026) both propose `2026`; the second is refused on `code` and the user types another one (e.g. `2026-b`).
- **FIN-FY-002** — don't assume a fiscal year can be corrected after opening: part 1 has no edit and no delete of a year. A year opened with wrong dates on an installation is removed by hand in the database (year, periods, `number_range` row) while nothing is posted yet; a guarded correction path is not built.
- **FIN-TYPE-001** — don't assume `type` and `postable` stay freely editable once journal lines exist: part 1 has no journal, so nothing refuses a type change or a postable account turned into a group yet (see pending).

## pending

- Part 2: `JournalEntry` / `JournalLine`, manual entries with `EntryChange`, `LedgerService::post()` / `reverse()` / `accountExists()` — refusing non-postable or inactive accounts and `closed` / `vat-settled` periods by `fiscal_period.state` (plan §5.2–§5.4, ADR-042).
- Part 2: once journal lines exist, decide whether a posted account may still change `type` or become a group (FIN-TYPE-001).
- Part 3: reports (trial balance, balance sheet, income statement, account statement, journal) as DBAL SQL in the repositories (plan §5.5).
- P5: period transitions, VAT return, year-end — including the postable closing accounts of class 9 (owner, 2026-09-22: they arrive with the year-end, not before; the chart ships class 9 with its main groups only).
- Publishing: the package is not a split target yet (`.github/workflows/split.yml`, repo `z77-ch/module-financial`, Packagist) — owner's step when the package is ready to publish.

## see also

- [`persistence-doctrine.md`](persistence-doctrine.md) — the driver, `doctrineEntities`, `z77-db diff` / `migrate`, the transaction port and `NumberRange::create()` the opening uses
- [`vat.md`](vat.md) — the sibling module in the same backend group; tax codes the journal lines will carry in part 2
- [`contact.md`](contact.md) — the module this one mirrors: draft-before-mutate, deactivate-never-delete, fragment mount
- [`money.md`](money.md) — `Money` and `MoneyType`, the amounts of the journal lines in part 2
- [`backend.md`](backend.md) — the fragment/host mount pattern, header slots, the `finance` group
