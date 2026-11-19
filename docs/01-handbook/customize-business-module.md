# Customizing a business module («Business-Modul anpassen»)

**Status:** `[CURRENT]`
**Last updated:** 2026-11-19

How a project changes or extends the business modules without touching `vendor/`. Written for a
developer who sets up a client project together with an AI coding assistant: every name, path and
key below exists in the code; the topic doc linked per section owns the detail and wins when this
page disagrees.

**Prerequisite:** the modules are enabled and migrated —
[`business-suite-setup.md`](business-suite-setup.md) §1–3. Part 1 is the patterns every module
shares; Part 2 is one short section per module.

---

## Part 1 — the patterns

### 1.1 Where project code goes

A project never edits `vendor/` — it overrides under `override/` (CE principle,
[`architecture.md` → CE Principle](architecture.md#ce-principle-client-extension)). For a business
module `{m}` (`debtor`, `order`, …) the override tier exists only once the module's namespace is in
the project's `autoload.psr-4` (`"Z77\\Module\\Debtor\\": ["override/z77/module/debtor/src/"]`,
[`business-suite-setup.md` §2](business-suite-setup.md#2-switch-the-modules-on)).

| What | Project path | Effect |
|---|---|---|
| Module config | `override/z77/module/{m}/src/App/Config/{m}Config.inc.php` | **replaces** the package file (§1.2) |
| Registry addition | `override/z77/module/{m}/src/App/Config/{key}Config.inc.php` | **adds** to the registry key (§1.5) |
| Template, partial, help, PDF | `override/z77/module/{m}/res/view/templates/…` (same relative path) | replaces that one file |
| Backend host controller + layout pin | `override/z77/module/backend/src/Ui/Controllers/{Group}/…` · `…/src/Ui/Config/{Group}/…` | replaces the thin host (§1.3) |
| Extendable entity | `override/z77/module/article/src/Entities/Product.php` (and `Variant.php`) | same FQCN, adds columns (§1.4) |
| Navigation seed | `override/z77/module/{m}/data/framework/routing/navigation.d/<name>.json` | read before the package seeds (§1.7) |
| Project migrations | `override/z77/project/res/migrations/` | namespace `Z77\Project\Migrations` (§1.8) |

### 1.2 Config — an override REPLACES the whole file (BOOT-CONFIG-001)

The current rule ([`bootstrap.md` → module config override](../topics/bootstrap.md#module-config-override)):
a module config is read through `ModuleManager::getModuleConfig()` → `ConfigManager::getArrayConfig()`
→ `FileFinder::getFirstSourceMatch()`. The **first file found wins** — `override/…` before `vendor/` —
and there is **no merge**. A project that wants to change one key must copy the **entire** config,
and from then on misses every key a package update adds or changes. Only registry keys with an
extension file are additive (§1.5). A merge («the override carries only its deviation») is proposed
and **not implemented** (`bootstrap.md` → pending).

What follows for a project:

1. **Prefer a place that is not the module config.** Most business settings are data edited in the
   backend (the Mandant's accounts, payment targets, order statuses, document mails), and the
   registries take extension files. Copy a module config only for a key that has no other home.
2. **If you copy:** copy the package file of the installed version unchanged, then change the key.
   Keep keys as literals — `php .releases/check.php` compares an override's top-level keys and its
   `jobs` entries with the framework file and warns about missing ones; a computed array
   (`array_merge`, `+`, spread) is not compared ([`packaging.md`](../topics/packaging.md) PKG-OVERRIDE-001).
3. **After every `composer update`:** compare the copy with the new package file.
4. **Removed keys are refused, not ignored:** `debtorAccounts` in a `debtorConfig` copy and
   `vatAccounts` in a `financialConfig` copy throw — those accounts live on the Mandant record since
   2026-09-23 ([`mandator.md`](../topics/mandator.md)).

```php
<?php
// override/z77/module/contact/src/App/Config/contactConfig.inc.php
// FULL copy of vendor/z77/module-contact/src/App/Config/contactConfig.inc.php (BOOT-CONFIG-001),
// changed: contactListLimit.
namespace Z77\Module\Contact\App;
// … the package file's `use` lines …
return [
    'viewArea'         => false,
    'moduleRole'       => AuthRole::ADMIN,
    'doctrineEntities' => [Contact::class, Address::class, ContactAddress::class],
    'contactListLimit' => 500,
    'dashboard'        => [\Z77\Module\Contact\Dashboard\ContactDashboard::class],
    'cache'            => ['enabled' => false],
];
```

### 1.3 Templates, help, controllers

**Templates and partials.** A business screen's templates live in the module under
`res/view/templates/Backend/{X}Controller/…` (page, `.toolbar`, `.help`, rows, windows), the PDFs
under `res/view/templates/pdf/…`. A file of the same relative path under
`override/z77/module/{m}/res/view/templates/` wins — the whole file, no merge. Templates hold no
logic ([`templates.md`](templates.md)); a change that needs data the controller does not pass is a
controller change, not a template change.

**Help texts.** A help is a template (`*.help.tpl.php`, [ADR-048](../02-decisions/adr-048-help-service.md))
and is overridden like any template. For an installation's own remark no code is needed: an admin
writes it in the help window (✎ «Hilfe ergänzen»); it is stored as `data/help/<topic id>.txt` and
appended to the shipped help ([`backend.md`](../topics/backend.md), [`kibot.md`](../topics/kibot.md)).
Prefer that — an overridden help no longer receives the package's corrections.

**Controllers.** A business module has no routes of its own (`viewArea => false`). Its screens are a
`Ui/{X}ControllerTrait` mounted by a thin host controller in `module-backend`
([`backend-screen.md` §0](backend-screen.md#0-where-the-files-go--the-mount)):

```php
// vendor/z77/module-backend/src/Ui/Controllers/Finance/DebtorController.php
class DebtorController extends BackendAbstractController
{
    use DebtorControllerTrait;
}
```

A project screen of its own (or a replaced host) goes to the same path under
`override/z77/module/backend/src/`, with its layout pin
`Ui/Config/{Group}/{x}ControllerConfig.inc.php` returning the module's layout helper (e.g.
`\Z77\Module\Debtor\Ui\DebtorLayout::config()`). The trait stays the module's — never copy it.
A new screen follows the area recipe ([`backend-screen.md`](backend-screen.md), Rule 8).

### 1.4 Entities and repositories

The framework allows exactly one entity extension: **extendable master data**
([`backend-screen.md` §7](backend-screen.md#7-data-behind-the-area),
[ADR-039 addendum (b)](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md#b-extendable-master-data-entities--the-abstract-class-carries-everything-the-entity-is-empty)).
Today only `module-article` is built that way: `AbstractProduct` / `AbstractVariant` carry every
field and the logic, `Product` / `Variant` are empty. The project overrides only the empty class —
same FQCN, repeats the class attributes (incl. the unique constraint), adds its columns:

```php
<?php
// override/z77/module/article/src/Entities/Product.php
namespace Z77\Module\Article\Entities;
// … the attributes of vendor/z77/module-article/src/Entities/Product.php, repeated …
class Product extends AbstractProduct
{
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $grapeVariety = null;
    // getter / setter
}
```

Then `php vendor/bin/z77-db setup` writes the migration into the project directory (§1.8). Other
entities (`Contact`, `Invoice`, …) are **not** extendable yet — they move to the pattern when a
project needs it, as a framework change, not in the project.

A **new** entity of the project is announced through an extension file, never by copying the
module config ([`persistence-doctrine.md` → rules](../topics/persistence-doctrine.md#rules)):

```php
<?php
// override/z77/module/order/src/App/Config/doctrineEntitiesConfig.inc.php
return [\Z77\Module\Order\Entities\DeliveryWindow::class];
```

Repositories, services and traits have no extension point. Composer's psr-4 lets a project shadow
any class of the same FQCN, but that is the «fork by copying a class» the CE principle forbids
(ADR-039 addendum (b) point 3): the project owns the whole file and loses every update. Where a
module needs to be extended, ask for a registry or an extendable class in the framework.

### 1.5 Registries — add, do not copy (Rule 9)

Modules do not call each other by name: a module **declares** classes under a config key, the
asking module collects the union over all registered modules
([`conventions.md` → Reusability](conventions.md#reusability--build-module-agnostic)). A project
adds to a registry in one of two ways:

- **Extension file** (where the registry reads one): `override/z77/module/{m}/src/App/Config/{key}Config.inc.php`
  returning the same shape as the config key — under any registered module; put it under the module
  the addition belongs to. `ModuleManager::getConfigExtensions()` collects every such file.
- **A project module** (where it does not): a module of the project's own
  ([`create-module.md`](create-module.md), project module under `override/z77/module/{name}/src/`)
  declares the key in its own `{name}Config.inc.php`. Every registry below loops over all module
  keys, so a project module counts like a package. The alternative — a full copy of a business
  module's config — carries BOOT-CONFIG-001.

| Key | Asked by | Shape | Extension file |
|---|---|---|---|
| `doctrineEntities` · `importEntities` | `ModuleManager` (Doctrine driver · backend import) | list of entity classes | yes |
| `openWorkChecks` | persistence-doctrine `OpenWorkChecks`; financial asks `period-close`, `ledger-report`, `vat-return` | scope ⇒ list of `OpenWorkCheckInterface` | yes |
| `dashboard` | kernel `DashboardContributors` | list of `DashboardContributor` | yes |
| `contactRoles` | contact `ContactRoles` | role key ⇒ `ContactRoleInterface` | yes |
| `invoicePostedListeners` | debtor `InvoicePostedListeners` («Journal verbuchen») | list of `InvoicePostedListener` | yes |
| `subledgerReconciliations` | financial year close | list of `SubledgerReconciliationInterface` | yes |
| `bankEntryOwners` | financial bank check | list of `BankEntryOwnerInterface` | yes |
| `bankMovementClaims` | financial «Privat» | list of `BankMovementClaimInterface` | **no** |
| `bankAccountHints` | financial bank account window | list of `BankAccountHintInterface` | **no** |
| `paymentMethodUsage` | receipt «Zahlungsarten» | list of `PaymentMethodUsageInterface` | **no** |
| `mailAttachments` | mailing `AttachmentProviders` | type ⇒ `AttachmentProvider` | yes |
| `documentMails` | kernel `DocumentMailKinds` («Beleg-Mails») | kind ⇒ seed (subject, facts, body, placeholders) | yes |
| `mailInlineImages` · `mailCompany` | kernel `InlineImages` · `MailCompany` | name ⇒ class · one class from one module | yes · **no** |
| `apiServices` · `apiDeviceScopes` · `appScreens` | module-api (phone app `/app`) | see [api.md](../topics/api.md) | **no** |
| `jobs` | `ModuleManager::getJobs()` | job key ⇒ definition (§1.6) | **no** |
| `outbox` (settings, not a list) | mailing `OutboxSettings` | key ⇒ value, merged key by key | yes |

A bad entry (unknown class, wrong interface) fails loudly at the first use, naming module and file.
Example — a project's own contact role:

```php
<?php
// override/z77/module/contact/src/App/Config/contactRolesConfig.inc.php
return ['partner' => \Z77\Module\Contact\Roles\PartnerContactRole::class];  // key: [a-z][a-z0-9-], 2–32 chars
```

Kernel registries outside the business suite (`accountRemovedListeners`, `localFileResolvers`,
`statsEvents`) follow the same pattern — see their topic docs.

### 1.6 Jobs

A job is a class implementing `Z77\Shared\Jobs\Job` (`run(JobContext): JobResult`), declared under a
module's `jobs` key ([`jobs.md`](../topics/jobs.md)). There is no `jobs` extension file: a project job
belongs in a **project module's** config (§1.5). A job key declared by two modules is refused.

```php
// in the project module's {name}Config.inc.php
'jobs' => [
    'shop-stock-sync' => [                        // a literal key — the deploy check reads literals only
        'class'           => \Z77\Module\Shop\Jobs\StockSyncJob::class,
        'label'           => 'Lager-Abgleich',
        'runAs'           => AuthRole::CRON_JOB,  // raise only when the job needs more rights
        'maxAttempts'     => 3,
        'defaultSchedule' => 'daily@03:15',       // omit for a job that deletes data
    ],
],
```

- `defaultSchedule` is seeded once; after that the schedule belongs to the operator (Service › Jobs:
  switch, «Zeitplan setzen / ändern …», ▶ «Jetzt starten»). Forms: `every:15m`, `hourly@:20`,
  `daily@03:15`, `weekly@mon,03:15`, `monthly@1,06:00`.
- A job that deletes installation data ships **no** schedule (three documented duty exceptions,
  JOBS-SCHED-001).
- A long job asks `JobContext::hasTimeLeft()` and returns `JobResult::again($cursor)`; it never
  `sleep()`s. The backend only queues — the cron runner `vendor/bin/z77-run` executes.

### 1.7 Navigation

Every module ships its menu as `data/framework/routing/navigation.d/<package>.json`; `composer
update` adds missing entries and never changes existing ones (add-only,
[ADR-050](../02-decisions/adr-050-module-navigation-seeds.md)). Once installed, the menu belongs to the
installation — rename or move entries in the backend navigation screen. A project that wants its
entries seeded on every installation ships a seed of its own under
`override/z77/module/{m}/data/framework/routing/navigation.d/<name>.json`; the override tier is
read first, and a file with a package seed's name **replaces** that seed. A key is never renamed.
On a release layout with shared `data/`, a module enabled later does not get its entries on the
server (INST-NAV-SHARED-001, [`business-suite-setup.md` §5](business-suite-setup.md#5-navigation-seeds)).

### 1.8 Migrations

Schema only through migrations, only from the CLI ([`persistence-doctrine.md`](../topics/persistence-doctrine.md)).
A migration goes **where the entity's class file lives**: a package class → that module's
`res/migrations` (shipped); a class under `override/` → `override/z77/project/res/migrations/`,
namespace `Z77\Project\Migrations` — the ONE place for the project's own schema.

```
php vendor/bin/z77-db setup        # change the entity, run setup: writes the migration where it belongs, shows the SQL, applies it
php vendor/bin/z77-db status       # names project root and database; must end with New 0
```

- `z77-db` collects persistence-doctrine, every **registered** module and the project directory and
  runs them in **timestamp order across all of them** (DOCTRINE-MIG-002) — the project's migration
  runs after the module tables it points at only because it is newer.
- A `DROP` is written but not applied without `--allow-drop`. A hand-written migration:
  `z77-db generate --namespace="Z77\Project\Migrations"`.
- Never put a migration under `override/z77/module/{m}/res/migrations` — refused (DOCTRINE-MIG-003).
- On a server: run on the new release (`next`) before switching `current`
  ([`release-structure.md`](release-structure.md)).

### 1.9 Tests and checks

The docs define **no project-side test runner**. `php tests/run.php` and its harness contract
([`testing.md`](../topics/testing.md)) belong to the framework repository and are not installed into
a project. What a project has:

- `php vendor/bin/z77-db status` — every migration applied (`New 0`).
- `php .releases/check.php` — before a deploy; warns about override configs missing framework keys
  (PKG-OVERRIDE-001) and checks the release target (rules in the project's `.releases/RULES.md`).
- A project harness of its own, if written, has only the framework's harness contract as a documented
  shape to copy (check lines, sections, `N passed, M failed`, own throwaway schema).

### 1.10 Never

- Edit anything under `vendor/` (Rule 1) or hand-edit `config/vendor/*.inc.php` (regenerated).
- Copy a module config only to extend a registry that has an extension file (Rule 2).
- Copy `Abstract{X}`, a trait, a service or a repository into `override/` (a fork).
- Put a migration under a module path in `override/`, or change the schema without a migration.
- Put a business rule, an account or a check only in JavaScript (Rule 7).

---

## Part 2 — the modules

Each section: purpose · config file (package path `vendor/z77/<package>/src/App/Config/…`) and the
keys a project most likely changes · extension points · jobs · topic doc. «Override» below always
means the full copy of §1.2 unless an extension file is named.

### module-order

Quotes and orders as one record, order status, order PDFs, the invoice drafted from the order.

- **Config** `orderConfig.inc.php` — no tunable key. The order statuses are data
  (`data/framework/order/order_statuses.json`, seeded once; Stammdaten › Auftragswesen ›
  Auftragsstatus): a status in phase «Abgeschlossen» carries «Wird gesetzt beim Verbuchen».
- **Contributes** `openWorkChecks` (`period-close`), `invoicePostedListeners`, `dashboard`,
  `mailAttachments` (`order-pdf`), `documentMails` (`quote`, `order-confirmation`, `delivery-note` —
  texts edited in the backend, Service › E-Mail › Beleg-Mails).
- **Templates** order window and PDFs under `res/view/templates/Backend/OrderController/` and `pdf/`.
- **Jobs** none. → [order.md](../topics/order.md)

### module-debtor

Invoices and credit notes with QR-bill, Faktura-Journal, payments (camt.054), dunning.

- **Config** `debtorConfig.inc.php` — `accountingGateway`: `LedgerAccountingGateway` (books into
  module-financial) or `NullAccountingGateway` for an installation that keeps its books elsewhere
  (override). The receivable / discount / loss / rounding / dunning-fee accounts are on the Mandant,
  not here. Payment terms, payment targets (IBAN / QR-IBAN) and dunning levels are data in the backend.
- **Asks** `invoicePostedListeners`. **Contributes** `openWorkChecks` (`period-close`,
  `ledger-report`, `vat-return`), `subledgerReconciliations`, `bankAccountHints`, `bankEntryOwners`,
  `bankMovementClaims`, `contactRoles` (`debtor`), `dashboard`, `mailAttachments` (`invoice-pdf`,
  `dunning-pdf`), `documentMails` (`invoice`, `invoice-replacement`, `credit-note`, `dunning`).
- **Server** `data/archive/debtor/` must be writable (archived invoice PDFs).
- **Jobs** `debtor-bank-link` «Debitoren-Bankabgleich», `every:1m`. → [debtor.md](../topics/debtor.md)

### module-financial (incl. the bank)

Chart of accounts, fiscal years, journal, year opening and close, reports, bank accounts and
camt.053 statements.

- **Config** `financialConfig.inc.php` — `bankPosting` → `voucherFromGross` (`'100.00'`: from this
  gross amount a bank posting with a tax code needs a file); optional `journalListLimit` (default 200,
  not in the package file). Both only via override. The chart (`res/charts/kmu.json`) is adopted by a
  button, never seeded; «Privat» / «Übertrag eigenes Konto» are Mandant accounts.
- **Asks** `openWorkChecks` (`period-close`, `ledger-report`, `vat-return`),
  `subledgerReconciliations`, `bankEntryOwners`, `bankMovementClaims`, `bankAccountHints`.
  **Contributes** `openWorkChecks`, `dashboard`.
- **Jobs** none. → [financial.md](../topics/financial.md), bank: [bank.md](../topics/bank.md) and the
  setup page [`bank-setup.md`](bank-setup.md)

### module-mandator

The installation's own company: letterhead, UID, VAT liability, the ledger accounts every business
module posts with.

- **Config** `mandatorConfig.inc.php` — nothing to change: every setting is on the ONE record
  (Stammdaten › Firma › Mandant, tabs *Briefkopf*, *UID und MWST*, *Konten*, *Belege*). Start values
  of a new record: `Services\MandatorAccounts::DEFAULTS`.
- **Contributes** `dashboard`, `mailInlineImages` (`logo`), `mailCompany` (the company foot of every
  template mail).
- **Jobs** none. → [mandator.md](../topics/mandator.md)

### module-vat

VAT codes with dated rates, rate lookup by service date, `VatCalculator`.

- **Config** `vatConfig.inc.php` — no tunable key. Codes and rates are file data
  (`data/framework/vat/`, Swiss codes seeded once), edited under Stammdaten › Finanzen › MWST-Codes.
  A company not liable to VAT is switched on the Mandant, not here.
- **Extension points** none; consumers call its services. No migrations (file storage).
- **Jobs** none. → [vat.md](../topics/vat.md)

### module-contact

Contacts (person or organisation) with typed addresses; the contact-role registry.

- **Config** `contactConfig.inc.php` — `contactListLimit` (rows of the contact list; default
  `ContactService::DEFAULT_LIST_LIMIT`) — only via override (the §1.2 example). Address types are file
  data (`data/framework/contact/address_types.json`).
- **Asks** `contactRoles` — the switches in the contact editor («Debitor», «Kreditor», a project
  role via `contactRolesConfig.inc.php`). **Contributes** `dashboard`.
- **Jobs** none. → [contact.md](../topics/contact.md)

### module-article

Product groups, articles (product + default variant), price history, the stock seam
`Stock\StockLedger`.

- **Config** `articleConfig.inc.php` — no tunable key.
- **Extension point** the extendable `Product` / `Variant` (§1.4) — the reference case of the pattern.
  No registry key.
- **Jobs** none. → [article.md](../topics/article.md)

### module-receipt

The receipt journal: a phone captures a receipt at `/app`, the backend corrects and posts it.

- **Config** `receiptConfig.inc.php` — `foreignCurrencies` (`['EUR', 'USD']`, the currencies a
  document may carry; also read by module-creditor), `bankMatch` → `daysBefore` 2, `daysAfter` 10.
  Both via override. Payment methods and receipt templates are backend data (not seeded).
- **Asks** `paymentMethodUsage`. **Contributes** `openWorkChecks` (`period-close`, `ledger-report`,
  `vat-return`), `bankEntryOwners`, and to module-api `apiServices`, `apiDeviceScopes`
  (`receipt.capture`), `appScreens`.
- **Jobs** none. → [receipt.md](../topics/receipt.md), [api.md](../topics/api.md)

### module-creditor

Suppliers as a contact role and the Kreditorenjournal for supplier PDF invoices. The module exists
(master data, journal, posting, PDF intake job); whether it ships in the first release is open — check
the status in [creditor.md](../topics/creditor.md) before relying on it.

- **Config** `creditorConfig.inc.php` — `intake` → `maxBytes` (20 MB), `folder`
  (`data/framework/creditor/intake`); `tools` → `pdftotext` (`'auto'` = from PATH); `postingVariant`
  (only `'direct'` is built — any other value is refused); `bankMatch` → `daysBefore` 3, `daysAfter`
  45, `daysAroundPaid` 5. All via override. The AI step is switched in `config/client/kibot.inc.php`
  (`creditorAi`), not here.
- **Contributes** `openWorkChecks`, `bankEntryOwners`, `paymentMethodUsage`, `contactRoles`
  (`creditor`).
- **Jobs** `creditor-intake` «Kreditoren-Eingang», `every:1m` — reads the intake folder and runs the
  recognition. → [creditor.md](../topics/creditor.md)

### module-mailing

The outbox «Postausgang»: queued mails sent in batches by a job.

- **Config** `mailingConfig.inc.php` — `outbox` → `batchSize` 10, `pauseSeconds` 60, `maxAttempts` 3,
  `retryMinutes` [5, 15, 60]. The one business setting with a **deviation-only** file:
  `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php` (`<?php return ['batchSize' => 20];`),
  merged key by key. The mail account is `config/client/mail.inc.php` ([mail.md](../topics/mail.md)).
- **Asks** `mailAttachments` (attachments resolved at send time). **Contributes** `dashboard`.
- **Jobs** `mail-outbox` «E-Mail-Versand (Postausgang)» — no `defaultSchedule`; queued whenever mails
  are added. → [mailing.md](../topics/mailing.md)

### persistence-doctrine

The SQL driver behind `UnifiedEntityManager` and the CLI `z77-db` — required by every module with
database storage. Not a module: no module config, no routes.

- **Config** `config/client/database.inc.php` — `host`, `port`, `name`, `user`, `password` (seed-once,
  per installation; on Windows `127.0.0.1` instead of `localhost`, DOCTRINE-HOST-001).
- **Extension points** the extension files `doctrineEntitiesConfig.inc.php` and
  `openWorkChecksConfig.inc.php` (owns the `OpenWorkChecks` registry); the project migration
  directory (§1.8).
- **Jobs** none. → [persistence-doctrine.md](../topics/persistence-doctrine.md)

---

## See also

- [`business-suite-setup.md`](business-suite-setup.md) — enable, migrate, first backend steps
- [`bank-setup.md`](bank-setup.md) — the bank: setup order, settings, extending
- [`create-module.md`](create-module.md) — a project module (for jobs and module-config-only registries)
- [`backend-screen.md`](backend-screen.md) — the recipe for a backend screen
- [`../topics/bootstrap.md`](../topics/bootstrap.md) — BOOT-CONFIG-001 and its pending merge
