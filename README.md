# z77 Framework

A clean, fully-documented PHP MVC framework for client projects — the successor to the
Webdreams framework (wdv-6.2.2), rebuilt from scratch for maintainability and knowledge
transfer.

z77 turns a URL into a typed response through an explicit, traceable pipeline: no magic,
no hidden conventions you cannot look up. A website runs with **no database** (data is one
JSON file per record), and a project never edits framework code — it **overrides**, so
updates stay cheap. On top of the web stack, z77 brings a Swiss business suite (orders,
invoices with QR-bill, receivables, double-entry bookkeeping, VAT) as optional modules.

> **Open source by conviction (MIT).** The framework earns nothing by itself — it earns
> through the client projects built with it. That is why it is public and cleanly
> documented. Status in detail: [Status](#status).

---

## Highlights

- **Explicit over implicit** — the route, the config, and the response type are all visible
  in code. No magic.
- **No database required for a website** — the default file driver stores one JSON file per
  record; the optional Doctrine driver (MariaDB) sits behind the same repository API and is
  what the business modules use.
- **CE override model** — projects layer their code over the framework packages and never
  fork; framework updates arrive via `composer update` without collisions.
- **Structured content, not WYSIWYG chaos** — templates and content are always separate.
- **Documented by design** — every architectural decision is an ADR; every work area has a
  single-source-of-truth topic doc. A deterministic linter keeps the docs honest.
- **Minimal dependencies** — PHP 8.2+ for the web stack, 8.4+ for the business modules.

---

## Packages

All packages live in this monorepo under [`packages/`](packages/). Each has its own
`README.md`; the topic docs in [`docs/topics/`](docs/topics/) hold the detail.

**Web stack — published on Packagist** (`^1.0`, current release line 1.x):

| Package | What it does |
|---|---|
| [`z77/kernel`](packages/kernel/) | The foundation: boot, routing, request/response, view layer, i18n, auth, file persistence, cache, jobs, backup, mail, PDF — one package, three namespaces (`Z77\Core`, `Z77\Shared`, `Z77\Persistence`). |
| [`z77/module-frontend`](packages/module-frontend/) | The public website: pages, navigation, language switch, content blocks, public forms. |
| [`z77/module-backend`](packages/module-backend/) | The admin interface: login with 2FA, content and navigation editing, users, system and service screens, dashboard, help and the KI-Assistent — and the host of the business modules' screens. |
| [`z77/module-dms`](packages/module-dms/) | Document management: Drive, uploads with image variants, ACL, authorized delivery under `/media`, ZIP and chunked transfers. |

**Monorepo only** (consumed through `path` repositories until the business-suite release):

| Package | What it does |
|---|---|
| [`z77/module-member`](packages/module-member/) | Member accounts for a customer area: self-registration with double opt-in, passwordless sign-in, invitations, several tenants per account. |
| [`z77/module-api`](packages/module-api/) | Stateless, versioned JSON API gateway: bearer keys for servers, app users with e-mail code sign-in for the phone app `/app`. |
| [`z77/module-mailing`](packages/module-mailing/) | The mail outbox («Postausgang»): queued mails sent in throttled batches by a job, retries, attachments resolved at send time. |
| [`z77/persistence-doctrine`](packages/persistence-doctrine/) | The SQL driver: Doctrine ORM on MariaDB behind the kernel's repository API, migrations through `z77-db`. |
| [`z77/module-vat`](packages/module-vat/) | VAT codes with dated rates, rate resolution by service date, VAT calculation in integer money. |
| [`z77/module-contact`](packages/module-contact/) | Contacts (person or organisation) with typed addresses, shared by order, debtor and later modules. |
| [`z77/module-mandator`](packages/module-mandator/) | The installation's own company: letterhead, UID, VAT liability and the ledger-account settings. |
| [`z77/module-article`](packages/module-article/) | Article master: product and variant, price history («gültig ab»), tax code, revenue account, the stock seam. |
| [`z77/module-order`](packages/module-order/) | Quotes and orders as one record, order status as data with a change log, order PDFs, the invoice drafted from the order. |
| [`z77/module-debtor`](packages/module-debtor/) | Receivables: invoices and credit notes with Swiss QR-bill, Faktura-Journal, payments (camt.054), dunning. |
| [`z77/module-financial`](packages/module-financial/) | Double-entry bookkeeping: chart of accounts, fiscal years with periods, journal, year opening and close, reports. |
| [`z77/module-receipt`](packages/module-receipt/) | The receipt journal: a phone captures a receipt (photo, amount, text), the owner corrects and posts it into the books. |
| [`z77/kibot`](packages/kibot/) | A standalone chatbot with topic routing over hand-maintained sources (Anthropic Messages API); no framework dependency. |

The documentation itself is published as **`z77/docs`** (from [`docs/`](docs/)) — see
[AI-assisted development](#ai-assisted-development).

---

## Status

| Area | State |
|---|---|
| Web stack (`kernel`, `module-frontend`, `module-backend`, `module-dms`) | **Stable.** Released on Packagist (1.x), running client websites. |
| `module-member`, `module-api` | Built and used in client projects through `path` repositories; not yet on Packagist. |
| Business suite (`persistence-doctrine`, `vat`, `contact`, `mandator`, `article`, `order`, `debtor`, `financial`, `receipt`, `mailing`) | Built and in use on the author's own installation; under acceptance. Published together as release **2.0.0** once it runs on a production server ([roadmap](docs/03-development/roadmap.md)). |

**Not built yet** (decided or planned — no dates):

- **VAT return** («MWST-Abrechnung»): the quarterly return with the ESTV form mapping and the
  «VAT settled» lock — [vat.md](docs/topics/vat.md), [financial.md](docs/topics/financial.md).
- **Stock journal**: `module-article` has the stock seam, but the ledger behind it books
  nothing yet (`PendingStockLedger`) — no stock balances, no stocktake —
  [article.md](docs/topics/article.md).
- **Creditor module** (accounts payable, payment runs) — an idea for a module of its own —
  [invoice-intake-automation.md](docs/03-development/ideas/invoice-intake-automation.md).
- **Documents by e-mail** beyond the invoice (quote, order confirmation, delivery note,
  dunning notice) and the **newsletter** — [mailing.md](docs/topics/mailing.md).
- **Shop, subscriptions, shipping tariffs** — out of scope of the business suite so far.

The full, prioritized list of open items: [pendenzen-2026-11-12.md](docs/03-development/pendenzen-2026-11-12.md);
each topic doc's `## pending` is the source of truth.

---

## Prerequisites

| To… | You need |
|---|---|
| **Run a website project** | PHP **8.2+** (CLI) with `mbstring fileinfo zip gd` enabled (`apcu` recommended — faster cache, optional) · Composer **2.x** |
| **Use the business modules** | PHP **8.4+** with `pdo_mysql` · MariaDB **10.6+** |
| **Customize styles** | Node.js **≥20** + npm — compiles SCSS via Dart Sass (a local dev-dependency, not global) |

The framework ships **pre-compiled CSS and minified JS**, so a project runs with only PHP +
Composer — npm is needed only when you edit SCSS (or, in the monorepo, a framework script).
Full toolchain setup + a verification script:
[docs/01-handbook/dev-environment.md](docs/01-handbook/dev-environment.md).

## Quickstart

```bash
# 1. Create a new project from the skeleton — no GitHub account needed
composer create-project z77/skeleton my-project
cd my-project

# 2. Run it locally
composer dev            # serves http://localhost:8080 from public/
```

That's it: `create-project` pulls the skeleton and the web-stack packages from Packagist
and runs the installer, which lays out `public/`, `config/`, `data/`, and `override/` and
seeds a runnable starter site.

**Prefer GitHub?** The skeleton is also a template repo: open
[z77-ch/z77-skeleton](https://github.com/z77-ch/z77-skeleton) → **Use this template** →
clone your new repo → `composer install` → `composer dev`.

You write your project in `override/z77/…`; the framework packages stay untouched in
`vendor/`. Add your first page by following
[docs/01-handbook/create-page.md](docs/01-handbook/create-page.md).

**Using the monorepo-only packages** (member, api, mailing, the business suite): clone this
repository next to your project and require them through `path` repositories, as described
step by step in [docs/01-handbook/new-project-manual.md](docs/01-handbook/new-project-manual.md).

**Working on styles?** Then also:

```bash
npm install             # once, installs Dart Sass locally
npm run watch           # recompiles SCSS → CSS on change
```

## AI-assisted development

z77 is built to be worked on with an AI coding assistant (e.g. Claude Code). During
install the installer offers to add **`z77/docs`** — the complete, AI-optimized framework
documentation — as a dev dependency (`vendor/z77/docs`, version-matched to the framework,
excluded from `--no-dev` deploys). It also seeds a project **`CLAUDE.md`** that points the
assistant at the docs and the override rules. Open the project, ask *"what do we have
here?"* — the assistant reads the docs and can start working. Added it later?
`composer require --dev z77/docs`.

This repository's own [`CLAUDE.md`](CLAUDE.md) is public on purpose: it is the working method
the framework is developed with.

---

## Documentation

Start at **[docs/README.md](docs/README.md)**. The fastest paths:

| You want to… | Read |
|---|---|
| Understand the system | [docs/01-handbook/architecture.md](docs/01-handbook/architecture.md) |
| Get oriented on day 1 | [docs/01-handbook/onboarding.md](docs/01-handbook/onboarding.md) |
| Add a page | [docs/01-handbook/create-page.md](docs/01-handbook/create-page.md) |
| Add a module | [docs/01-handbook/create-module.md](docs/01-handbook/create-module.md) |
| Build a backend screen | [docs/01-handbook/backend-screen.md](docs/01-handbook/backend-screen.md) |
| Set up and deploy a project | [docs/01-handbook/new-project-manual.md](docs/01-handbook/new-project-manual.md), [docs/01-handbook/release-structure.md](docs/01-handbook/release-structure.md) |
| Know why something is built this way | [docs/02-decisions/](docs/02-decisions/) (ADRs) |
| Dig into one work area | [docs/topics/](docs/topics/) (single source of truth per area; keyword map in [docs/README.md](docs/README.md#topic-trigger-map)) |

---

## Architecture in one paragraph

The foundation `z77/kernel` (boot, platform, storage in three namespaces) carries every
module. A single front controller parses each request into a
`module / group / controller / action` tuple, optionally serves it from a page cache,
otherwise dispatches to a controller action that returns a typed `Response`. The web
modules (`frontend`, `backend`, `dms`) provide the surfaces; the business modules bring
their screens as fragments that `module-backend` mounts, and depend on each other one way
(order → debtor → vat / contact / mandator; financial knows no module). Full picture:
[docs/01-handbook/architecture.md](docs/01-handbook/architecture.md).

---

## Tests

Plain-PHP harnesses, one file per area: `php tests/<name>.php` (the database harnesses need a
local MariaDB). The docs linter: `npm run docs:check`.

---

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE) © Peter Ruepp (z77)
