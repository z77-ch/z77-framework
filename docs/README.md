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
