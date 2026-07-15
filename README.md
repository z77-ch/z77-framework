# z77 Framework

A clean, fully-documented PHP MVC framework for client projects — the successor to the
Webdreams framework (wdv-6.2.2), rebuilt from scratch for maintainability and knowledge
transfer.

z77 turns a URL into a typed response through an explicit, traceable pipeline: no magic,
no hidden conventions you cannot look up. It runs with **no database** by default (data is
one JSON file per record), and a project never edits framework code — it **overrides**, so
updates stay cheap.

> **Status:** v1.0.0. Open source by conviction (MIT). The framework earns nothing by
> itself — it earns through the client projects built with it. That is why it is public
> and cleanly documented.

---

## Highlights

- **Explicit over implicit** — the route, the config, and the response type are all visible
  in code. No magic.
- **No database required** — the default file driver stores one JSON file per record;
  a Doctrine (SQL) driver is designed-for behind the same repository API.
- **CE override model** — projects layer their code over the framework packages and never
  fork; framework updates arrive via `composer update` without collisions.
- **Structured content, not WYSIWYG chaos** — templates and content are always separate.
- **Documented by design** — every architectural decision is an ADR; every work area has a
  single-source-of-truth topic doc. A deterministic linter keeps the docs honest.
- **PHP 8.2+**, minimal dependencies.

---

## Prerequisites

| To… | You need |
|---|---|
| **Run a project** | PHP **8.2+** (CLI) with `curl apcu mbstring json openssl dom xml fileinfo gd` enabled · Composer **2.x** |
| **Customize styles** | Node.js **≥20** + npm — compiles SCSS via Dart Sass (a local dev-dependency, not global) |

The framework ships **pre-compiled CSS**, so a project runs with only PHP + Composer — npm
is needed only when you edit SCSS. **There is no JavaScript build step**; JS ships as-is.
Full toolchain setup + a verification script:
[docs/01-handbook/dev-environment.md](docs/01-handbook/dev-environment.md).

## Quickstart

```bash
# 1. Start from the project skeleton (GitHub template)
#    → "Use this template" on z77-ch/z77-skeleton, then clone your new repo

# 2. Install the framework (pulled into vendor/ via Composer)
composer install

# 3. Run it locally
composer dev            # serves http://localhost:8080 from public/
```

You write your project in `override/z77/…`; the framework packages stay untouched in
`vendor/`. Add your first page by following
[docs/01-handbook/create-page.md](docs/01-handbook/create-page.md).

**Working on styles?** Then also:

```bash
npm install             # once, installs Dart Sass locally
npm run watch           # recompiles SCSS → CSS on change
```

---

## Documentation

Start at **[docs/README.md](docs/README.md)**. The fastest paths:

| You want to… | Read |
|---|---|
| Understand the system | [docs/01-handbook/architecture.md](docs/01-handbook/architecture.md) |
| Get oriented on day 1 | [docs/01-handbook/onboarding.md](docs/01-handbook/onboarding.md) |
| Add a page | [docs/01-handbook/create-page.md](docs/01-handbook/create-page.md) |
| Add a module | [docs/01-handbook/create-module.md](docs/01-handbook/create-module.md) |
| Know why something is built this way | [docs/02-decisions/](docs/02-decisions/) (ADRs) |
| Dig into one work area | [docs/topics/](docs/topics/) (single source of truth per area) |

---

## Architecture in one paragraph

Four Composer packages: `z77/kernel` (the foundation — boot, platform, storage in three
namespaces) plus `z77/module-frontend`, `z77/module-backend`, `z77/module-dms`. A single
front controller parses each request into a `module / group / controller / action` tuple,
optionally serves it from a page cache, otherwise dispatches to a controller action that
returns a typed `Response`. Full picture:
[docs/01-handbook/architecture.md](docs/01-handbook/architecture.md).

---

## License

[MIT](LICENSE) © Peter Ruepp (z77)
