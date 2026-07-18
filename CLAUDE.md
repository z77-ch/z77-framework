# CLAUDE.md — z77 Framework

This document is the primary context for Claude Code. Read it completely before analyzing or writing code.

> **Why is this file public?** Deliberately. z77 is developed AI-assisted and documented
> for knowledge transfer — successors will most likely work with an AI coding assistant
> too. This file is part of the published working method, not a leftover internal.

## What is this?

z77 is a PHP MVC framework for client projects. Successor to the Webdreams Framework (wdv-6.2.2). Developed by an experienced solo developer, documented for knowledge transfer to successors.

## Philosophy — DO NOT DEVIATE

> **ALERT for Claude Code:** If you or the developer make decisions that contradict this philosophy, point it out immediately.

The framework is **open source** (MIT license) — deliberately and by conviction:

- Money is earned through the **use** of the framework (client projects), not through the framework itself
- Open source enforces clean documentation — what is public must be understandable
- Successors should be able to view, learn from, and prepare with the framework publicly
- No black box for clients — transparency builds trust

**Consequence:** The framework will only be published on GitHub/Packagist once `docs/01-handbook/` is completely filled. An empty or half-finished framework will not be published.

## Documentation

All decisions, concepts, and conventions are documented in `docs/`.

### Primary entry point: `docs/topics/`

**`docs/topics/{thema}.md` is the single source of truth per work area.**
Read the matching topic doc BEFORE writing or analyzing code. Pendenzen, known bugs, and architectural rules per area live there — NOT in memory, NOT in commit messages, NOT scattered across reviews.

- Topic structure is enforced by `npm run docs:check` (deterministic linter, no AI).
- Standard reference: [`docs-lint/STANDARD.md`](docs-lint/STANDARD.md)
- Trigger map (which topic to read for which keyword): see [`docs/README.md` → Topic trigger map](docs/README.md#topic-trigger-map)

When updating status/pendenzen for a work area → update the topic's `## known issues` / `## pending` sections, then verify `npm run docs:check` is green. Do NOT create memory files for pendenzen.

### Secondary docs

- **New here?** → [`docs/README.md`](docs/README.md)
- **System understanding** → [`docs/01-handbook/architecture.md`](docs/01-handbook/architecture.md)
- **Conventions** → [`docs/01-handbook/conventions.md`](docs/01-handbook/conventions.md)
- **Why built this way?** → [`docs/02-decisions/`](docs/02-decisions/) (referenced from topics when relevant)
- **What is planned?** → [`docs/03-development/roadmap.md`](docs/03-development/roadmap.md)
- **Detailed reviews** → [`docs/03-development/`](docs/03-development/) (linked from topics when topic-level info isn't enough)

## Key Rules

The non-negotiables. Each links to the doc/ADR that owns the full reasoning — read it
before deviating.

1. **CE-first — projects override, never fork.** A project writes its config, controllers,
   and templates under `override/`; the framework packages in `vendor/` are never edited.
   → [`architecture.md` → CE Principle](docs/01-handbook/architecture.md).
2. **Config = single, semantically named source.** Every setting lives at exactly one
   place whose name announces what it is about. A scope records only its deviation from a
   global default, never a copy. → [`conventions.md` → Configuration](docs/01-handbook/conventions.md).
3. **Every action returns a typed `Response` via a helper** (`$this->html()`, `->fetch()`,
   `->json()`, `->redirect()`, `->file()`, `->noContent()`, `->void()`) — never a directly
   instantiated response. → [ADR-003](docs/02-decisions/adr-003-controller-response-objects.md).
4. **HTTP input only through `Request`.** Use `DI::getRequest()` — never touch `$_SERVER`,
   `$_POST`, or `$_GET` in a controller. → [`conventions.md` → HTTP Input](docs/01-handbook/conventions.md).
5. **Name transformations only through `Naming`.** No inline `strtolower` / `str_replace` /
   `ucwords` for routing, class, method, or property names. → [`conventions.md` → Naming transformations](docs/01-handbook/conventions.md).
6. **File-name casing follows the layer.** URL/browser-reachable = kebab-case lowercase;
   PHP = PascalCase/camelCase; JSON persistence keys = snake_case. The name is a key, not
   cosmetics. → [`conventions.md` → File Names](docs/01-handbook/conventions.md).
7. **As little JavaScript as possible.** Reach for CSS, then server-generated CSS, and only
   then JS — and justify the JS in the commit/PR. → [`conventions.md` → JavaScript](docs/01-handbook/conventions.md).
8. **Build module-agnostic.** A recurring pattern becomes a shared, opt-in building block
   (component, partial, trait, convention loader), never hard-wired into one view. →
   [`conventions.md` → Reusability](docs/01-handbook/conventions.md).
9. **Topic docs are the single source of truth per area.** Read `docs/topics/{thema}.md`
   before working; keep `npm run docs:check` green after editing.

## What you must NOT do

- **Do not edit framework code to change project behaviour** — override under `override/`
  instead (Rule 1). Editing `vendor/` breaks the next `composer update`.
- **Do not access `$_SERVER` / `$_POST` / `$_GET`** anywhere outside `Request` (Rule 4).
- **Do not instantiate a response directly** — always go through the helper (Rule 3).
- **Do not duplicate a config value** or park a setting in an unrelated config just because
  that file is global (Rule 2).
- **Do not add a JS file without a written reason** why CSS / server-generated CSS cannot
  do it (Rule 7).
- **Do not store pendenzen, bugs, or status in memory or commit messages** — they belong in
  the topic doc's `## known issues` / `## pending` sections.
- **Do not copy old-framework (wdv-6.2.2) code directly** — read → analyze → review →
  decide → only then integrate (see Working Method).
- **Do not deviate from an ADR unilaterally** — ADRs in `docs/02-decisions/` take
  precedence; if reality contradicts one, raise it, don't silently diverge.
- **Do not edit `data/**/*.json` (or `core/data/**/*.default.json`) via PowerShell
  round-trips** (`Get-Content` → `Set-Content` / `Out-File` / `>`, even with
  `-Encoding utf8`) — Windows PowerShell 5.1 reads no-BOM UTF-8 as CP1252 and writes
  a BOM, which corrupts umlauts (`Ü` → `Ãœ`) and makes `FileStorage::load()` blank the
  file. Use the Read+Edit / Write tools (UTF-8, no BOM); if a shell is unavoidable, use
  the Bash tool. → [`persistence-file.md` DATA-JSON-001](docs/topics/persistence-file.md).

## Working Method

- Before any work on a topic → read `docs/topics/{thema}.md` first (single source of truth per area)
- Architecture decisions (ADRs) in `docs/02-decisions/` take precedence — do not deviate unilaterally
- New features follow the lifecycle in `docs/03-development/triage.md`
- Always follow conventions from `docs/01-handbook/conventions.md`
- Old framework (wdv-622): always read → analyze → review → decide → only then integrate. Never copy directly.
- Language: all code, comments, docs, and exception messages in English. Communicate with the developer in German.

### When updating topics

- Edit `docs/topics/{thema}.md` directly, then run `npm run docs:check`.
- Pendenzen / bugs go into the topic's `## known issues` and `## pending` sections.
- Cross-topic dependencies → use `## see also` with concrete reasons.
- Never store pendenzen / status updates in memory — memory is only for setup info and behavioral feedback that does NOT belong in the codebase.
