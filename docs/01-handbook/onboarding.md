# Onboarding — Start Here

**Status:** `[CURRENT]` — continuously updated
**Date:** 2026-04-05 · last checked against the code 2026-11-14

---

## What is the z77 Framework?

z77 is a PHP MVC framework for client projects in the web design/web development space. It is the successor to the Webdreams Framework (wdv-6.2.2), rebuilt from scratch — with the goal of being cleanly documented, maintainable, and understandable for successors.

## Who is this documentation for?

- **New developers** — taking over or extending the system
- **Claude Code** — AI assistant supporting development
- **The project owner** — as a reference

## What you should read first

1. This file (you are here)
2. [architecture.md](architecture.md) — System overview: packages, request lifecycle, CE principle
3. [conventions.md](conventions.md) — Coding standards you must follow
4. [create-page.md](create-page.md) — Your first practical step: add a page end-to-end
5. [create-module.md](create-module.md) — When you need a whole new module

## Important decisions that have already been made

Before changing anything: read the [Architecture Decision Records (ADRs)](../02-decisions/). Many things that look "strange" have a reason.

## Where is what?

There are **two vantage points**. Know which one you are in.

### The framework monorepo (`z77-ch/z77-framework`) — when you develop the framework

| Directory | Contains |
|---|---|
| `packages/kernel/` | The foundation: `Z77\Core` (boot), `Z77\Shared` (platform), `Z77\Persistence` (storage) — one package, three namespaces |
| `packages/module-frontend/` · `-backend/` · `-dms/` | The three web modules published with the kernel (public site, admin, document management) |
| `packages/module-*/` (the others) | Member area, API gateway, mail outbox and the business suite (VAT, contact, mandator, article, order, debtor, financial, receipt, creditor) — one package each; the list with one line per package is in the [root README](../../README.md#packages) |
| `packages/persistence-doctrine/` | The optional SQL driver (Doctrine ORM on MariaDB) the business modules need |
| `packages/kibot/` | A standalone chatbot package (no framework dependency) |
| `docs/` | All documentation — `01-handbook` (here), `02-decisions` (ADRs), `03-development`, `topics/` (single source of truth per work area) |
| `docs-lint/` | The deterministic docs linter (`npm run docs:check`) + [`STANDARD.md`](../../docs-lint/STANDARD.md) |
| `tests/` | Plain-PHP / node harnesses, one file per area, run through `php tests/run.php <group>` ([testing.md](../topics/testing.md); the database ones need a local MariaDB) |
| `skeleton/` | The monorepo's development project (path repositories onto `packages/`); new projects start from the published `z77/skeleton` |

Inside a package: `src/App/Config/` (routing + ACL config), `src/Ui/Controllers/`,
`src/Ui/Config/` (layout config), `src/Services/` · `src/Entities/` · `src/Repositories/`
(domain work), `res/view/templates/` (markup), `res/scss/` → `res/assets/css/` (styles).

### A project (consumes the framework) — when you build a client site

| Directory | Contains |
|---|---|
| `override/z77/…` | **Your code.** Controllers, configs, templates that shadow framework defaults (CE principle) — this is where you work |
| `config/vendor/` | Installer-generated config (`bootstrap`, `fileFinder`, `moduleManager`) — **regenerated on every `composer install`; do not hand-edit** ([ADR-036](../02-decisions/adr-036-config-split-vendor-client.md)) |
| `config/client/` | Hand-maintained config (`systemConfig`, `mail`, `auth`, `i18n`, `backup`, `database`, …) — seeded once by the installer, then **yours**; never committed (credentials) |
| `data/` | File-per-record storage + content (JSON) — the default persistence backend |
| `public/` | Web root: front-controller entry point + compiled assets |
| `var/` | Disposable, release-local runtime state (cache, throttles, flags) — [ADR-035](../02-decisions/adr-035-release-local-runtime-state-under-var.md) |
| `logs/` | Logs |
| `vendor/` | The framework packages, pulled in by Composer — **never edit; override instead** |

The golden rule: **you never edit `vendor/` or `packages/` from a project — you override.**
Why: [architecture.md → CE Principle](architecture.md#ce-principle-client-extension).

## Frequently Asked Questions

**I want to add a page — where do I start?**
[create-page.md](create-page.md). It is the end-to-end recipe (config → controller → template → nav).

**Do I edit files in `vendor/`?**
No. Never. Project code goes in `override/z77/…`, which shadows the framework default of the
same name. Framework updates then arrive via `composer update` without colliding.

**Where does data live? Do I need a database?**
Not for a website. The default File driver stores one JSON file per record under `data/`.
The business modules (order, debtor, financial, …) need the optional Doctrine driver
`z77/persistence-doctrine` (MariaDB) behind the same repository API — set it up with
`config/client/database.inc.php` and `php vendor/bin/z77-db migrate`. See
[../topics/persistence-architecture.md](../topics/persistence-architecture.md) and
[../topics/persistence-doctrine.md](../topics/persistence-doctrine.md).

**How do I run a project locally?**
`composer install`, then `composer dev` (starts `php -S localhost:8080 -t public`).

**I edited `config/…` and my change vanished — why?**
You edited a file in `config/vendor/`, which the installer regenerates on every
`composer install`/`update`. Those files come from `composer.json` (`extra` / `autoload`) —
change it there and re-run the install. Hand-maintained settings live in `config/client/`,
which the installer never overwrites.

**How do I change styling?**
Edit SCSS under `res/scss/`, run `npm run build` (or `npm run watch`). See
[css-conventions.md](css-conventions.md) and [../topics/css-watch.md](../topics/css-watch.md).

**Module vs. page — which do I create?**
Almost always a page ([create-page.md](create-page.md)). A new module is a whole new
environment or an isolated task-domain — the rare case ([create-module.md](create-module.md)).
