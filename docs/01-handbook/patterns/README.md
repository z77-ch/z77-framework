# Patterns — build recipes for project components

**Status:** `[CURRENT]`
**Last updated:** 2026-07-13

Recipes for **productive frontend components built in client projects** (slider, gallery,
accordion, …). A pattern page tells a future project exactly how to build the component —
which files, which framework pieces, which conventions — with a living reference
implementation in a real project.

## Why here

- The framework repo is the ONE place every project knows and reads (`docs/01-handbook/` is
  the project-facing handbook — see `dms-images.md`, `create-page.md`).
- Docs follow code ownership: as long as a component lives in a project's override tree, the
  transferable KNOWLEDGE lives here as a recipe pointing at the reference implementation.
  Never document cross-project knowledge inside one project's repo — future projects do not
  look into old projects.

## Component lifecycle (graduation)

1. **Project code** — the component is built and proven in ONE project
   (`<project>/override/z77/module/frontend/...`). The pattern page documents the recipe and
   points at that project as the reference implementation.
2. **Framework component** — once a second project needs it (proven, not speculative — the
   framework stays minimal, nothing on stock), the component graduates into `module-frontend`
   (partial + CSS component + JS; projects override only config/content). The pattern page is
   then rewritten to point at the framework component instead.

## Page schema

Every pattern page follows this outline:

```markdown
# Pattern: <name>

**Status:** `[CURRENT]`
**Last updated:** <date>
**Graduation:** project code (reference: <project>) | framework component (module-frontend)

## Purpose            — what the component does, when to use it
## Ingredients        — every file the project creates (override paths: tpl / scss / js)
## Content & DMS      — folder conventions, image profiles, mediaUrl/mediaImage usage
## Build steps        — step by step, in order
## Reference          — living implementation: project + paths
## Pitfalls           — what breaks and why (escaping, caching, responsive traps)
```

The outline is the required COVERAGE, not a rigid heading set — a page may split an aspect
into finer sections (e.g. numbered build steps per layer), as long as every aspect is covered
and an "Ingredients & build order" overview exists.

Write the page WHILE building the component in its first project — never speculatively
before, never long after.

## Pages

- [`slider.md`](slider.md) — fanned, CSS-driven, DMS-fed image slider. Documents the **mechanism**
  (config · controller · generated-CSS handling · markup contract · JS build+load); the visual
  layout stays project-specific. Pure-CSS radios + `createCss` for the count-dependent rules.
  Reference: a live project.
