# Architecture

**Status:** `[CURRENT]`
**Date:** 2026-07-15

The bird's-eye view of the system: the packages, how a request becomes a response,
where data lives, and how a project overrides framework defaults. This is the **map** —
each section links to the topic doc or ADR that owns the detail. Read this first, then
follow the links into the area you are working on.

---

## Overview

z77 is a PHP 8.2+ MVC framework for client projects. A request enters through a single
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

The framework ships as **four Composer packages**, developed in one monorepo
(`z77-ch/z77-framework`) and split into read-only consume repos on every push. Details:
[`../topics/packaging.md`](../topics/packaging.md).

| Package | Namespace(s) | Role |
|---|---|---|
| `z77/kernel` | `Z77\Core`, `Z77\Shared`, `Z77\Persistence` | The foundation. One package, three namespaces. |
| `z77/module-frontend` | `Z77\Module\Frontend` | Public website surface (pages, layout, i18n switch). |
| `z77/module-backend` | `Z77\Module\Backend` | Authenticated admin surface (login, content, system). |
| `z77/module-dms` | `Z77\Module\Dms` | Document management + authorized document/image delivery. |

**Why the kernel is one package with three namespaces:** `Core` (boot — the runtime that
starts and wires the framework), `Shared` (platform — the common base every module builds
on: auth, entities, conventions), and `Persistence` (storage) are mutually cyclic and
functionally inseparable. Splitting them into three Composer packages advertised an
independence the code does not have. They ship as one package, keep three namespaces and
directories. Full reasoning: [ADR-023](../02-decisions/adr-023-kernel-package-core-shared-persistence.md).

Modules depend downward on exactly one foundation (`z77/kernel`); there are no
module-to-module dependencies. Code organisation is by domain vertical slice
([ADR-019](../02-decisions/adr-019-code-organization-package-by-domain.md)).

---

## Modules and URL grouping

A URL maps to a **4-tuple**: `module / group / controller / action`. The group is a UI
area *inside* a module (e.g. backend's `Users`, `Content`, `System`), which keeps larger
modules organised instead of a flat controller pile. Controllers live at
`src/Ui/Controllers/{Group}/{Name}Controller.php`; their templates mirror that path at
`res/view/templates/{Group}/{Controller}/{action}.tpl.php`. Naming and the URL schema:
[ADR-005](../02-decisions/adr-005-module-architecture-and-url-grouping.md).

---

## Request lifecycle

A single front controller boots the framework and runs one request end to end:

```text
Bootstrap::pullUp() → Request::runParsing()
  1. extractLanguage()      strip /de/ or /fr/ prefix (absent = default language)
  2. reserved routes        longest-prefix match
  3. slug translation       localized → canonical segments (non-default language)
  4. resolve                NavigationAlias → static nav → convention (/module/group/controller/action)
  → ControllerHandler locks the 4-tuple

Dispatcher::execute()
  1. action constraints     #[HttpMethod], #[Fetch]/#[Page] attributes
  2. AccessGuard            ACL first (starts session); denied → login redirect / 403
  3. language reconciliation (ADR-013)
  4. PageCachePolicy        BYPASS · 304 · HIT · MISS
  5. resolve response       HIT → serve cached HTML (action NOT run)
                            MISS/BYPASS → run action → Response → assemble layout → (store)
  → Response::send()
```

Every action returns a **typed Response object** via a helper (`$this->html()`,
`->fetch()`, `->json()`, `->redirect()`, `->file()`, `->void()`, `->noContent()`) — never
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

The **File driver** (one JSON file per record) is implemented and is the default — zero
infrastructure, ideal for one-pagers and small sites. `Doctrine` (full SQL) and `Memory`
(tests) are designed-for but not yet built. The full flow, driver contract, and the known
abstraction limits (no Unit of Work, no Identity Map, `findBy` is O(n) on File) live in
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
