# ADR-018 — CSS design tokens scoped to the viewArea wrapper

**Status:** `[APPROVED]`
**Date:** 2026-06-22 (revised 2026-08-08)

> **Revision 2026-08-08 — structural layout primitives MAY be shared; visual components stay
> per-area.** The rejected option (a) below ("one shared token + component base package consumed
> by all areas") is about **visual** components — buttons, forms, cards, badges, list rows. Those
> remain per-area and unshared; the reasoning stands unchanged ("the DMS must not inherit the
> host's button styling"). It does **not** reject sharing a **structural layout primitive**: a
> component that contributes geometry only — track sizing, scroll containment, drag-resize
> handles, collapse behaviour — and carries no typography, no fill colours, no radii, no
> component identity. Driver: the content layer inside the shell (pane layout + resizing) must be
> embeddable in a frontend or member host for the same reason the DMS fragment is (see Context).
> Binding rules 5–7 below govern it. Without this precision the framework would grow a second
> pane/resize implementation per host — the duplication ADR-018 exists to prevent, one layer up.

---

## Context

Today every module declares its design tokens (`--color-*`, spacing, typography, effects)
on `:root` (frontend and backend each in four token partials). The backend additionally
themes a second token family `--be-*` via `[data-be-theme]` / `[data-be-palette]`
attribute selectors on `<html>`.

`:root` tokens are **global**. The moment two viewAreas' stylesheets coexist on one page —
exactly what the planned DMS embedding does (a DMS document-view fragment rendered inside a
frontend or backend page) — their `:root` token sets collide and the later-loaded set wins
for the whole document.

The goal: an embeddable DMS document view that looks **identical in every backend module**
(order, financial, …), is **optionally loadable and overridable** in the individual
frontend, and is maintained **once**. Two ways to get there:

- (a) extract one shared token + component base package consumed by all areas;
- (b) scope each viewArea's tokens to its own wrapper element so areas compose without
  collision.

## Decision

Design tokens are declared on the **viewArea wrapper element**, not on `:root`.

Each viewArea owns a wrapper class — `.fe` (frontend), `.be` (backend), `.dms` (DMS) —
placed on the skeleton root: `<html>` for full-page viewAreas, the fragment root container
for an embedded widget. Token values resolve through CSS custom-property inheritance to the
nearest wrapper ancestor, so a nested viewArea (DMS inside frontend) uses its own tokens for
its subtree while the host uses its own for the rest.

Binding rules:

1. **Every wrapper declares a COMPLETE token set.** A token a descendant reads but the
   wrapper omits inherits the host's value → inconsistent rendering. Completeness is what
   makes the isolation hold.
2. **Only genuinely global declarations stay on `:root`:** `@font-face` (font-family names
   are global regardless of scope — use unique names per area), `color-scheme`, and a
   last-resort `<html>` background / scrollbar default.
3. **Token-value isolation (this ADR) is separate from component-selector isolation.** Two
   areas' `.btn` selectors still collide on one page. An *embeddable* widget (DMS inside the
   individual frontend) therefore additionally **prefixes its component blocks** (`.dms-…`).
   ViewAreas that never coexist (frontend standalone, backend standalone) keep their
   existing class names.
4. **Override** by redeclaring an embedded area's tokens on that area's wrapper
   (`.dms { --color-primary: … }`) in CSS loaded *after* the embedded bundle. Whether the
   embedded bundle is loaded at all is the host's choice (its `layoutConfig`).

Rules 5–7 govern **shared structural layout primitives** (revision 2026-08-08). They are the
single exception to "no shared component base" and are gated by a hard test:

5. **The geometry-only test decides.** Strip every declaration that names a colour, font, or
   radius. If the component still does its job, it is structural and MAY be shared. If it stops
   working or stops being recognisable, it is a visual component and stays per-area. A pane grid
   with drag handles passes; a button, a badge, a list row does not.
6. **A shared primitive lives in `packages/kernel/shared` and carries an area-independent
   prefix** — never `.be-*` / `.fe-*` / `.dms-*`, since it renders inside all of them. Rule 3
   (component-selector isolation) is satisfied by being the *same* component everywhere, not by
   a per-area prefix.
7. **Rule 1 applies unchanged: it declares its own COMPLETE token set on its own wrapper.** The
   geometry-only test keeps that set small (line, handle, handle-active — not a palette). Each
   host binds it inside its own scope per rule 4
   (`.be .z77-split { --split-line: var(--be-line); … }`). A host that does not bind gets the
   primitive's neutral defaults — never the host's values by accident.

## Reasoning

- **Composability without a shared base.** Encapsulation per wrapper makes any viewArea
  fragment embeddable anywhere — precisely the DMS requirement. Option (a) would couple
  every area to one component library, the opposite of "the DMS must not inherit the host's
  button styling".
- **One uniform rule beats a DMS special-case.** The framework is documented for
  successors; fewer exceptions to learn.
- **The backend is already halfway there.** Its `--be-*` theme/palette tokens are scoped to
  `<html data-be-*>`, not pure `:root`. This decision generalises that pattern and pulls the
  remaining `--color-*` block onto the same scope.
- **Timing.** Frontend/backend token sets are still small (four token partials each) — the
  refactor is mechanical and low-risk now, expensive later.

## Consequences

Easier: embedding a viewArea fragment in another (DMS); theming per subtree; overriding
tokens for a single embedded area; reasoning about where a token value comes from (nearest
wrapper).

To watch: each wrapper must keep its token set complete (a new token must be added to every
wrapper that needs it); `@font-face` family names must be unique per area; component-class
collisions are **not** solved by this decision and need a prefix for embeddables.

Refactor surface (see [`../03-development/css-wrapper-token-bauplan.md`](../03-development/css-wrapper-token-bauplan.md)):
move the `:root { … }` token blocks in the four token partials of frontend and backend onto
the wrapper class; add the wrapper class to each skeleton root (default + fetch); reconcile
the backend's `[data-be-theme]` / `[data-be-palette]` overrides with the `.be` scope. No
component-selector changes — they resolve `var()` at the wrapper.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Single shared token + component base package (`module-ui`) consumed by all areas | Couples every area to one component library; an embedded DMS would inherit the host's component styling — the opposite of the "identical everywhere, host-independent" goal. More cross-package coupling than encapsulation buys. **Narrowed on revision (2026-08-08):** this rejection covers *visual* components only. Structural layout primitives (geometry, no colour/font/radius) may be shared under binding rules 5–7 — they carry no styling to inherit, so the reason above does not apply to them. |
| Give every host its own pane/resize implementation (the strict reading of "no shared base") | Would put a second and third copy of grid-track sizing, scroll containment and pointer drag logic into frontend and member the moment the content layer is embedded there — the exact duplication this ADR prevents at the token layer. The `shell.js` resize is already hard-wired to two fixed column variables and cannot serve a second caller. |
| Keep tokens on `:root`, prefix only DMS tokens (`--dms-*`) | Solves DMS but leaves the general `:root` collision risk for any future second-area-on-one-page case, and keeps a DMS special-case instead of a uniform rule. |
| Per-area token name prefixes everywhere (`--fe-*`, `--be-*`, `--dms-*`) | Verbose; every component must reference its area's prefix. Wrapper scoping + complete sets achieves the same isolation with shared, simpler names. |
