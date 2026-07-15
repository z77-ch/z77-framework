# ADR-011 — Block field schema lives on the BlockRenderer

**Status:** `[APPROVED]`
**Date:** 2026-06-04

---

## Context

The backend content editor (Phase 2/3a) edits a document's blocks as a single
validated JSON textarea. Phase 3b adds a **visual, per-type block editor**
(add/remove/reorder blocks, one form per block type). To build a form for a block
type, the backend needs that type's **field schema** — which fields it has and how
each is authored (text, textarea, select, url, bool, list of scalars, list of
objects).

A `BlockRenderer` so far only knows how to *render* a type (`type()` + `render()`),
not which fields it has. The fields were implicit in each renderer's `render()`
body (`$block['eyebrow']`, `$block['items'][n]['title']`, …). The editor needs that
knowledge explicitly. Where should the schema live?

- **A — `schema()` on `BlockRenderer`** (every renderer declares its own fields).
- **B — optional `BlockSchemaProvider` interface** (renderers opt in; schema-less
  types fall back to the JSON textarea).
- **C — separate descriptor + parallel registry** (schema fully decoupled from the
  renderer).

## Decision

Add `schema(): array` to the `BlockRenderer` interface. Every renderer returns a
list of **field descriptors** — plain arrays (same lightweight style as the
`routeInfo` / `headerUser` view-models), not value objects.

A descriptor:

```php
['key' => 'level', 'kind' => 'select', 'label' => 'Ebene',
 'options' => [1 => 'H1', 2 => 'H2', /* … */], 'default' => 2]
```

- `key` — the block field this input reads/writes
- `kind` — `text | textarea | select | url | bool | list`
- `label` — German field label
- `options` — value⇒label map (required for `select`)
- `item` — required for `list`: a kind string (`'text'`) for a list of scalars, or
  a list of descriptors for a list of objects (repeater, e.g. `features.items`)
- `default` — optional initial value for a fresh block

`BlockRegistry` exposes `schema(string $type): ?array` and `schemas(): array` so the
editor can build its forms. The block storage shape is unchanged — the client still
serialises blocks to the same `blocks` JSON the server already validates; the JSON
textarea stays as a raw/advanced fallback.

## Reasoning

- **Co-location, zero drift.** A type's fields and how it renders are intrinsically
  linked (the renderer reads exactly those fields). One class owning both means they
  can never disagree — the failure mode of B/C (schema says field X, renderer reads
  field Y) is structurally impossible.
- **Every registered type is editable.** Making `schema()` mandatory enforces the
  CMS invariant "every block type can be authored" — aligns with the framework's
  "no black box for clients" philosophy. A type with no schema would be an
  editor-invisible defect, not a feature.
- **Cheap now.** The interface change touches 7 in-repo renderers; the framework is
  pre-release (not yet published), so a breaking interface change has near-zero
  external cost. Doing it now avoids an optional-then-mandatory migration later.
- **Layering is clean.** Descriptors are abstract field *data* (key/kind/label), not
  HTML and not backend code. They live in `shared`, which both the frontend module
  (module block types) and the backend editor already depend on. A frontend renderer
  declaring "my hero has eyebrow/title/subline" is its own domain knowledge, not a
  backend dependency.

## Consequences

- Adding a block type is now a single, complete unit: `type()` + `schema()` +
  `render()` in one class — and it is automatically editable in the backend.
- A module contributing block types (via `contentBlocks`) must also describe their
  fields — the editor support comes for free, no extra registration.
- The editor reads `BlockRegistry::schemas()`; no second registry to keep in sync.
- The descriptor shape is the contract between renderer and editor — extending it
  (new `kind`, validation hints) is an additive change to the documented shape.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| B — optional `BlockSchemaProvider` | Editability becomes optional → a type can silently fall back to raw JSON, undermining the "every type authorable" invariant; two interfaces for one concept. |
| C — separate descriptor + parallel registry | Full decoupling buys nothing here (rendering and authoring describe the *same* fields) and adds a second per-type registration that can drift from the renderer. |
