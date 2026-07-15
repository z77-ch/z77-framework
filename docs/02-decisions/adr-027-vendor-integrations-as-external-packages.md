# ADR-027 — Vendor/Domain Integrations Live Outside the Framework Monorepo

**Status:** `[APPROVED]`
**Date:** 2026-07-15

---

## Context

The PropBase integration (myprop.ch real-estate API, extracted from the wdv-6.2.2
project sihlestate-temp as the framework-agnostic package `webdreams/propbase`) needs
a home in the z77 world. An estimated 20–50 real-estate client projects will consume
it, so the z77-side adapter (controllers, presenters, templates, assets) is clearly
worth packaging rather than copying per project.

The question: does such an integration belong in `packages/` next to `module-dms`,
or outside the framework monorepo?

Key constraints:

- `packages/` is published as a whole under MIT (see CLAUDE.md philosophy: money
  comes from *using* the framework, not from the framework itself).
- The z77 adapter for PropBase is the competitive advantage across those 20–50
  projects and stays proprietary.
- The PropBase core is deliberately framework-agnostic (ports & adapters, pure
  PHP 8.x + cURL) and has its own lifecycle driven by the myprop.ch API, independent
  of framework releases.

## Decision

Vendor- or domain-specific integrations are **separate repositories**, never part of
the framework monorepo. The Composer vendor name `z77` is branding, not location —
it is shared by framework and non-framework packages alike.

For PropBase this means two packages (both outside this repo):

| Package | Content | License | Visibility |
|---|---|---|---|
| `z77/propbase` (`Z77\PropBase\`) | framework-agnostic core: client, mapper, store, service | MIT (after trademark/API-terms clearance) | public |
| `z77/module-propbase` (`Z77\Module\Propbase\`) | z77 adapter: controllers, presenters, templates, SCSS/JS, config factory | proprietary | private |

Naming rule derived from this: standalone libraries use `Z77\<Name>\`; packages that
require `z77/kernel` use `Z77\Module\<Name>\` — regardless of which repo they live in.
The legacy vendor `webdreams` is retired and must not appear anywhere.

## Reasoning

- **License boundary:** `packages/` = MIT and public. A proprietary adapter inside it
  would either break the license or leak the paid integration work.
- **Scope:** the framework is generic infrastructure for *every* client project.
  A myprop.ch integration is one vendor, one domain — a bakery website must not ship
  a real-estate module.
- **Lifecycle:** the myprop.ch API changes independently of framework releases; the
  integration needs its own versioning and release cadence.
- **Preserved agnosticism:** keeping the core free of `z77/kernel` keeps it usable
  outside z77 (legacy wdv projects, other consumers) and publishable on its own.

## Consequences

- The framework monorepo stays 100% MIT-publishable; no per-package license checks.
- Client projects consume integrations via Composer (path repository during
  development, VCS repository for deployment) — same mechanism as `webdreams/propbase`
  uses today in the reference project.
- Each external integration carries its own repo, docs, and version — slightly more
  coordination than a monorepo, accepted deliberately.
- Precedent for future integrations (payment providers, booking systems, …):
  same pattern, no case-by-case discussion needed.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Adapter as a module inside `packages/` | License break (proprietary in MIT monorepo); framework scope polluted with vendor-specific domain code |
| Absorb the core into one z77 module (single package) | Throws away the deliberately built framework-agnosticism; core has its own API-driven lifecycle and non-z77 consumers |
| Separate Composer vendor for proprietary packages (e.g. `z77-x/`) | Artificial fragmentation; vendor name says nothing about license — visibility is controlled per repository |
