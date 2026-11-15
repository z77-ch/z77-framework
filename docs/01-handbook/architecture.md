# Architecture

**Status:** `[CURRENT]`
**Date:** 2026-07-15 · packages section updated 2026-11-15

The bird's-eye view of the system: the packages, how a request becomes a response,
where data lives, and how a project overrides framework defaults. This is the **map** —
each section links to the topic doc or ADR that owns the detail. Read this first, then
follow the links into the area you are working on.

---

## Overview

z77 is a PHP MVC framework for client projects (PHP 8.2+ for the kernel and the web
modules, 8.4+ for the business modules and the Doctrine driver). A request enters through a single
front controller, is parsed into a `module / group / controller / action` 4-tuple,
optionally served from a page cache, otherwise dispatched to a controller action that
returns a typed `Response` object. Data is read and written through a driver-abstracted
persistence layer (file-based by default, no database required). Everything a project
customises — configs, controllers, templates, styles — is layered on top of the
framework packages through the **CE (Client Extension) override** mechanism, so the
framework itself is never edited in a project.

Three ideas carry the whole design:

- **Explicit over implicit** — no magic; the route, the config, the response type are all
  visible in code (see [`conventions.md`](conventions.md)).
- **Template ≠ value** — structure (templates) and content (data) are always separate.
- **CE-first** — projects override, never fork.

---

## Packages

The framework is developed as **18 Composer packages** in one monorepo
(`z77-ch/z77-framework`, directory `packages/`). Four of them — the kernel and the three web
modules — plus the docs (`z77/docs`) are split into read-only consume repos on every push
and published on Packagist; the others are consumed through path repositories until the
business-suite release ([`../03-development/roadmap.md`](../03-development/roadmap.md)).
Details: [`../topics/packaging.md`](../topics/packaging.md).

| Package | Namespace(s) | Role | Packagist |
|---|---|---|---|
| `z77/kernel` | `Z77\Core`, `Z77\Shared`, `Z77\Persistence` | The foundation. One package, three namespaces. | yes |
| `z77/module-frontend` | `Z77\Module\Frontend` | Public website surface (pages, layout, i18n switch). | yes |
| `z77/module-backend` | `Z77\Module\Backend` | Authenticated admin surface (login, content, system) — and the host of the business modules' screens. | yes |
| `z77/module-dms` | `Z77\Module\Dms` | Document management + authorized document/image delivery. | yes |
| `z77/module-member` | `Z77\Module\Member` | Member (customer) accounts: registration, sign-in, invitations, tenants. | no |
| `z77/module-api` | `Z77\Module\Api` | Stateless JSON API gateway (bearer key, device channel for the phone app). | no |
| `z77/module-mailing` | `Z77\Module\Mailing` | Mail outbox, sent in throttled batches by a job. | no |
| `z77/persistence-doctrine` | `Z77\Persistence\Doctrine` | The SQL driver (Doctrine ORM, MariaDB) behind the same repository API. | no |
| `z77/module-vat` · `-contact` · `-mandator` · `-article` · `-order` · `-debtor` · `-financial` · `-receipt` · `-creditor` | `Z77\Module\…` | The business suite: VAT codes, contacts, own company, articles, orders, receivables, bookkeeping, receipts, payables (creditor: in progress, [creditor.md](../topics/creditor.md)) ([ADR-040](../02-decisions/adr-040-business-module-cut.md)). | no |
| `z77/kibot` | `Z77\KiBot` | Standalone chatbot, no framework dependency. | no |

**Why the kernel is one package with three namespaces:** `Core` (boot — the runtime that
starts and wires the framework), `Shared` (platform — the common base every module builds
on: auth, entities, conventions), and `Persistence` (storage) are mutually cyclic and
functionally inseparable. Splitting them into three Composer packages advertised an
independence the code does not have. They ship as one package, keep three namespaces and
directories. Full reasoning: [ADR-023](../02-decisions/adr-023-kernel-package-core-shared-persistence.md).

Every module depends downward on the foundation (`z77/kernel`). The web modules, member,
api and dms depend on nothing else; mailing needs only the Doctrine driver. The business modules depend one way along the cut of
[ADR-040](../02-decisions/adr-040-business-module-cut.md) (order → debtor → vat / contact /
mandator; debtor reaches financial only through a port) and need `z77/persistence-doctrine`.
They bring no layout of their own: each ships its screens as a controller trait plus
templates, mounted by a thin host controller in `module-backend` (e.g.
`Ui/Controllers/Order/OrderController.php` → `OrderControllerTrait`). Code organisation is
by domain vertical slice
([ADR-019](../02-decisions/adr-019-code-organization-package-by-domain.md)).

---

## Modules and URL grouping

A URL maps to a **4-tuple**: `module / group / controller / action`. The group is a UI
area *inside* a module (e.g. backend's `Content`, `Finance`, `System`), which keeps larger
modules organised instead of a flat controller pile. Controllers live at
`src/Ui/Controllers/{Group}/{Name}Controller.php`; their templates mirror that path at
`res/view/templates/{Group}/{Controller}/{action}Action.tpl.php`. Naming and the URL schema:
[ADR-005](../02-decisions/adr-005-module-architecture-and-url-grouping.md).

---

## Request lifecycle

A single front controller boots the framework and runs one request end to end:

```text
Bootstrap::pullUp() → Request::runParsing()
  1. extractLanguage()      strip /de/ or /fr/ prefix (absent = default language)
  2. reserved routes        longest-prefix match (fetch requests: straight to convention)
  3. resolve                NavigationAlias (slug translation for a non-default language
                            happens inside this lookup) → static nav → convention
                            (/module/group/controller/action)
  → ControllerHandler locks the 4-tuple

Dispatcher::execute()
  1. action constraints     #[HttpMethod], #[Fetch]/#[Page] attributes
  2. AccessGuard            ACL first (starts session); denied → login redirect / 403
                            stateless route (/api): run action → Response, steps 3–5 skipped
  3. language reconciliation (ADR-013; a signed-in account's language, ADR-056)
  4. PageCachePolicy        BYPASS · 304 · HIT · MISS
  5. resolve response       HIT → serve cached HTML (action NOT run)
                            MISS/BYPASS → run action → Response → assemble layout → (store)
  → Response::send()
```

Every action returns a **typed Response object** via a helper (`$this->html()`,
`->fetch()`, `->json()`, `->redirect()`, `->file()`, `->bytes()`, `->void()`, `->noContent()`) — never
instantiated directly ([ADR-003](../02-decisions/adr-003-controller-response-objects.md)).
The step-by-step version, with the four files you write to add a page, is the spine doc
[`create-page.md`](create-page.md). Deeper: routing → [`../topics/routing.md`](../topics/routing.md),
page cache → [`../topics/cache.md`](../topics/cache.md), view assembly →
[`../topics/view-layer.md`](../topics/view-layer.md), fetch/JSON endpoints →
[`../topics/fetch.md`](../topics/fetch.md).

---

## Persistence

Data access is a **driver-abstracted Repository layer** (Data Mapper + Repository +
Ports & Adapters). An entity declares its backend with an attribute —
`#[Entity('file', 'framework/routing/navigation.json')]` — and
`UnifiedEntityManager::getRepository(Class)` is the sole consumer API: it resolves the
driver, boots it lazily, and returns a `RepositoryInterface`. Consumers never see a
driver-specific type; switching a backend means changing only the `#[Entity]` attribute.

The **File driver** (one JSON file per record) is in the kernel and is the default — zero
infrastructure, ideal for one-pagers and small sites. The **Doctrine driver** (MariaDB, full
SQL) is the separate, optional package `z77/persistence-doctrine`
([ADR-039](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md)): the
kernel names it in its driver map, the first Doctrine entity boots it, and an installation
without the package runs as before. The full flow, driver contract, and the known
abstraction limits (Identity Map, flush scope and `remove()` timing differ per driver;
`findBy` is O(n) on File) live in
[`../topics/persistence-architecture.md`](../topics/persistence-architecture.md); file-per-record
rationale in [ADR-010](../02-decisions/adr-010-file-per-record-storage.md).

---

## CE Principle (Client Extension)

A project never edits framework code. Instead it **overrides**: the project's
`composer.json` maps each framework namespace to an `override/` path *in addition to* the
package's own source root, and Composer searches the override path first. A class or
template placed under `override/` shadows the framework default of the same name.

```json
"autoload": { "psr-4": {
    "Z77\\Core\\":             ["override/z77/core/src/"],
    "Z77\\Module\\Frontend\\": ["override/z77/module/frontend/src/"]
}}
```

So building a project means writing the config, controller, and templates in `override/`
while the framework packages sit untouched in `vendor/`. Framework updates arrive via
`composer update` without ever colliding with project code. This is the proven principle
carried over from the predecessor (wdv-6.2.2) and the reason updates stay cheap. How the
consumed packages are wired at install time: [`installer.md`](installer.md) and
[`../topics/installer.md`](../topics/installer.md).

---

## Where things live

| You are looking for | Go to |
|---|---|
| Coding standards, namespaces, file names | [`conventions.md`](conventions.md) |
| Add a page (end-to-end recipe) | [`create-page.md`](create-page.md) |
| Add a module | [`create-module.md`](create-module.md) |
| CSS / SCSS standards | [`css-conventions.md`](css-conventions.md) |
| Template layer | [`templates.md`](templates.md) |
| Install / project wiring | [`installer.md`](installer.md) |
| Why something is built this way | [`../02-decisions/`](../02-decisions/) (ADRs) |
| The detail of one work area | [`../topics/`](../topics/) (single source of truth per area) |
