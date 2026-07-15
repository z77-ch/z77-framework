# ADR-012 — Content services are consumer-built factories, not DI registrations

**Status:** `[APPROVED]`
**Date:** 2026-06-04

> **Note 2026-07-01 — placement criterion refined by [ADR-019](adr-019-code-organization-package-by-domain.md).**
> This ADR's decision (consumption services are factories, not DI registrations; "module-owned service
> providers" as the future) is **unchanged and reinforced**. Only its incidental placement rationale —
> `ContentService` lives in `shared` "because it is cross-module" — is refined: cross-module alone is not
> the criterion; **extractability** is (ADR-019 Rule 4). Content is *entangled* (host-supplied block
> renderers), so it correctly stays in `shared` — now as a per-domain folder (`shared/Content/`).

---

## Context

`ContentService` and `BlockRegistry` were registered in `core/Bootstrap::pullUp()`
as shared DI services. Two concerns surfaced:

1. **Wrong owner.** `ContentService` is *consumption* — load a content document by
   slug, gate, render. Its only collaborators are content-specific; its only
   consumer is a controller that wants to show content. It is not framework
   infrastructure (unlike Request, EntityManager, NavigationService, Router, Auth —
   things routing/dispatch/security need on nearly every request).
2. **A growing junk drawer.** If every feature registers its services in the one
   composition root, `pullUp()` accumulates feature wiring and becomes hard to read.
   The clarity argument that motivates a lean container is undermined by piling
   feature services into it.

A key clarification removed a false lead: in this container `DI::set()` is **lazy**
(stores a closure; `createInstance()` runs on first `get()`). So registration was
never an instantiation cost — `BlockRegistry` was only ever assembled when content
was actually rendered. The issue is **registration location / ownership**, not
timing.

`BlockRegistry` looked special because it is assembled from *every* module's
`contentBlocks`. But "must see all modules" is a property of the **assembly logic**
(it walks the `ModuleManager`), not a reason to register the result in the
container — the assembly can be a factory just as well.

## Decision

Take the whole content subsystem out of the DI container; build it on demand at the
consumption boundary via factories:

- `BlockRegistry::assemble()` — static factory: core defaults
  (`DefaultBlockRegistry::create()`) + every module's `contentBlocks`, walked via
  `DI::getModuleManager()`. Returns a registry; registers nothing.
- `ContentService::create()` — static factory: composes the `ContentRepository`
  (from `DI::getUnifiedEntityManager()`) + a `ContentRenderer` over an assembled
  `BlockRegistry`. Lives in `Z77\Shared\Services` (content is cross-module: the
  frontend renders it, a future backend preview will too — and shared is the layer
  both depend on).

`core/Bootstrap` no longer registers `'BlockRegistry'` or `'ContentService'`.
Consumers build what they need: `ContentService::create()->render($slug, $lang)`
(frontend), `BlockRegistry::assemble()` (backend editor for `types()`/`schemas()`).

## Reasoning

- **Registration belongs to a composition root or the owning module — not a junk
  drawer.** Framework infrastructure stays in Bootstrap. Consumption objects are
  not registered at all; they are constructed where they are used.
- **On-demand = construct, not register.** The factory returns an instance the
  consumer holds locally. No global container mutation, no `DI::set()` outside the
  composition root (which would scatter wiring and be *worse* for clarity than a
  fat Bootstrap).
- **"Sees all modules" ≠ "must be a DI singleton."** The module walk is the
  factory's job; pulling `ModuleManager` from DI inside a factory is the same
  pattern `AuthService` already uses (`DI::getModuleManager()` from `shared`).
- **Lifecycle is the only thing the singleton gave us** (assemble once, reuse). It
  is recovered by convention, not registration: build once at the consumption
  boundary (the controller) and reuse for N renders — do not assemble per
  block/slug. The assembly is cheap regardless (stateless renderers).

## Consequences

- `core/Bootstrap::pullUp()` holds only framework infrastructure; content adds zero
  entries. New content-like features follow the same rule (factory, not Bootstrap).
- `ContentService` moved `Z77\Core\Services` → `Z77\Shared\Services` (it was
  "frontend-facing" yet core-hosted — a layering inversion now removed).
- The DI keys `'ContentService'` and `'BlockRegistry'` no longer exist; code uses
  the factories. `DI::set()` must not be called from controllers (composition is
  not a request-handling concern).
- When many modules eventually ship their own services, the next step is
  **module-owned service providers** (core iterates modules, each registers its
  services) — a structural split, not a temporal "second bootstrap stage".

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep `BlockRegistry`/`ContentService` as DI singletons | Registers consumption in the framework-wide container; grows the composition root into a junk drawer. Lazy `get()` was cited as a benefit, but the factory gives the same on-demand build without any registration. |
| A second, deferred Bootstrap stage (register content "when needed") | Wrong axis (timing, not ownership). Needs a trigger → consumer coupling + ordering bugs; saves only a stored closure (instantiation was already lazy). |
| Controller calls `DI::set()` to register on first use | Scatters wiring across actions (worse for clarity than one fat Bootstrap); global mutation mid-request; `set()` is first-wins → silent no-op on collision. Reading from DI is fine; writing is composition-root work. |
