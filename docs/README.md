# z77 Framework 1.0.0 — Documentation

Welcome. This documentation serves two purposes:

1. **Knowledge transfer** — Successors and new developers understand the system
2. **Development** — New features are planned and implemented in a structured way

---

## Start Here

**New to the project?** → [01-handbook/onboarding.md](01-handbook/onboarding.md)

**Claude Code?** → [../CLAUDE.md](../CLAUDE.md)

---

## Structure

### [01-handbook/](01-handbook/) — Knowledge Transfer
Everything a new developer needs to understand the system and work with it.
Two layers: **Reference** (understand the system) and the **Cookbook** (step-by-step
recipes — what a project follows when building).

**Reference — understand the system**

| Document | Content |
|---|---|
| [onboarding.md](01-handbook/onboarding.md) | Day 1: What is this, where is what, how do I start |
| [dev-environment.md](01-handbook/dev-environment.md) | Toolchain setup & verification (PC switch): tools, SSH/gh access, post-sync steps + `verify-dev-env.sh` |
| [architecture.md](01-handbook/architecture.md) | How the framework is structured |
| [conventions.md](01-handbook/conventions.md) | Coding standards, namespaces, file names |
| [css-conventions.md](01-handbook/css-conventions.md) | CSS/SCSS standards: BEM, tokens, components |
| [templates.md](01-handbook/templates.md) | Template layer: location, context injection, partials |
| [installer.md](01-handbook/installer.md) | Composer installer: configuration, generated files, directory structure |
| [vision.md](01-handbook/vision.md) | Why this framework, goals, scope |

**Cookbook — build recipes (for projects)**

| Recipe | Content |
|---|---|
| [create-module.md](01-handbook/create-module.md) | Step-by-step: Creating a new module |
| [create-page.md](01-handbook/create-page.md) | Step-by-step: Creating a new page (routing → action → assets → rendering) |
| [dms-images.md](01-handbook/dms-images.md) | Image-size profiles config + displaying DMS images (`mediaUrl`/`mediaImage`) |
| [patterns/](01-handbook/patterns/README.md) | Reusable project components (slider, …) + graduation lifecycle |

### [topics/](topics/) — Single Source of Truth per Work Area

One file per work area: entry points, file map, mental model, rules, known issues,
pendenzen. **Read the matching topic doc before writing or analyzing code in that area.**
Structure is enforced by `npm run docs:check` ([docs-lint/STANDARD.md](../docs-lint/STANDARD.md)).

#### Topic trigger map

| You are working on / keywords | Read |
|---|---|
| backend, dashboard, service panel, user preferences, system pages | [topics/backend.md](topics/backend.md) |
| backup, restore, cron, z77-backup CLI | [topics/backup.md](topics/backup.md) |
| content block types | [topics/block-types.md](topics/block-types.md) |
| bootstrap, DI container, debug flag | [topics/bootstrap.md](topics/bootstrap.md) |
| cache, DataCache, APCu, page cache | [topics/cache.md](topics/cache.md) |
| content, structured content, content files | [topics/content.md](topics/content.md) |
| CSS/SCSS backend, werkbank | [topics/css-backend.md](topics/css-backend.md) |
| CSS/SCSS dms | [topics/css-dms.md](topics/css-dms.md) |
| CSS/SCSS frontend, public design | [topics/css-frontend.md](topics/css-frontend.md) |
| CSS watch, `npm run watch` / `build` | [topics/css-watch.md](topics/css-watch.md) |
| documents, DMS, drive, upload, delivery | [topics/documents.md](topics/documents.md) |
| entities, hydration, entity data handling | [topics/entity-data-handling.md](topics/entity-data-handling.md) |
| fetch, AJAX, CSRF, form validation | [topics/fetch.md](topics/fetch.md) |
| i18n, languages, locale switching | [topics/i18n.md](topics/i18n.md) |
| installer, `composer install`, project setup | [topics/installer.md](topics/installer.md) |
| login, auth, session, AccessGuard | [topics/login.md](topics/login.md) |
| mail, SMTP | [topics/mail.md](topics/mail.md) |
| messages, flash messages | [topics/messages.md](topics/messages.md) |
| metadata, SEO | [topics/metadata.md](topics/metadata.md) |
| navigation | [topics/navigation.md](topics/navigation.md) |
| packaging, monorepo split, Packagist, release/tagging | [topics/packaging.md](topics/packaging.md) |
| persistence design, repositories, drivers | [topics/persistence-architecture.md](topics/persistence-architecture.md) |
| file driver, JSON storage | [topics/persistence-file.md](topics/persistence-file.md) |
| routing, router, Request, ControllerHandler | [topics/routing.md](topics/routing.md) |
| security, hardening, setup token, password policy | [topics/security.md](topics/security.md) |
| stylesheet, asset pipeline, AssetCleaner | [topics/stylesheet.md](topics/stylesheet.md) |
| translation, Translator | [topics/translation.md](topics/translation.md) |
| tree, hierarchy | [topics/tree.md](topics/tree.md) |
| view layer, partials, HtmlView | [topics/view-layer.md](topics/view-layer.md) |
| templates (create/change) | [01-handbook/templates.md](01-handbook/templates.md) |

### [02-decisions/](02-decisions/) — Architecture Decision Records (ADRs)
Why was X built this way and not another? Every important decision has its own document.
Prevents successors from reversing decisions that have already been thought through.

### [03-development/](03-development/) — Feature Lifecycle
New features from idea to implementation.

| Document/Folder | Content |
|---|---|
| [roadmap.md](03-development/roadmap.md) | Milestones and priorities |
| [triage.md](03-development/triage.md) | Process: when is what addressed |
| [ideas/](03-development/ideas/) | Raw ideas — not yet evaluated |
| [concepts/](03-development/concepts/) | Elaborated concepts — under discussion |
| [specs/](03-development/specs/) | Technical specs — approved, ready for implementation |

### [04-changelog/](04-changelog/) — Version History

---

## External Packages (z77 vendor, outside this repo)

Vendor/domain integrations are separate repositories — never part of this monorepo
(see [ADR-027](02-decisions/adr-027-vendor-integrations-as-external-packages.md)).
The Composer vendor `z77` is branding, not location. Currently:

| Package | Content | License |
|---|---|---|
| `z77/propbase` | PropBase (myprop.ch) real-estate API core — framework-agnostic, own repo + docs | proprietary → MIT planned |
| `z77/module-propbase` | z77 adapter for PropBase (controllers, presenters, templates) — planned | proprietary |

Projects consume these via Composer (path repository in development).

---

## Status Conventions

| Status | Meaning |
|---|---|
| `[IDEA]` | Raw idea, not yet evaluated |
| `[CONCEPT]` | Elaborated, under discussion |
| `[APPROVED]` | Spec approved, implementation can start |
| `[IN PROGRESS]` | Currently being implemented |
| `[DONE]` | Implemented and in production |
| `[REJECTED]` | Deliberately not pursued further (with reason) |
