# ADR-024 — Public is developer-owned: seed once on first install, never overwrite

**Status:** `[APPROVED]`
**Date:** 2026-07-14

---

## Context

The installer copied `vendor/{package}/res/assets/` into `public/assets/{module}/` on **every**
`composer install` / `composer update`, overwriting existing files when `debug=true`. Framework
and project assets share the same directory **and the same filenames** (`base.css`, `desktop.css`,
`core.js`, favicon, …), and a project builds its own CSS/JS into that same directory
(`sass override/…scss:public/assets/frontend/css`).

Result: a routine `composer install` overwrote the project's compiled assets — and its favicon —
with the framework baseline (INST-ASSET-002, live incident 2026-07-13 on the reference project: header/mobile
styling disappeared; sources in `override/…/scss` were untouched, a rebuild restored them).

Several richer schemes were explored and rejected (see below). The framework owner's decision is
the simplest one that removes the footgun entirely.

## Decision

**`public/` and `override/` belong to the developer. Composer never overwrites them.**

1. **Ownership.** `vendor/z77/*` = framework (the developer never touches it). `override/z77/*`
   (incl. per-module `layoutConfig`) and everything under `public/` = developer.
2. **Seed on first install only.** The installer deploys the framework public baseline
   (entry files: `index.php`, `.htaccess`, favicons; plus `res/assets` → `public/assets/{module}`)
   **only when `public/` does not exist yet** (a fresh project). Once `public/` exists, the
   installer does not touch it on any later `install` / `update`.
3. **No overwrite, no force command.** There is no `debug`-driven overwrite and no
   "publish/force" command. Anything that force-copies over developer files is exactly the
   footgun that caused INST-ASSET-002.
   > **Amended by ADR-026 (2026-07-15):** an *interactive, per-file, default-No* deploy prompt
   > is now allowed on update. The blanket ban still holds for **non-interactive** runs (CI /
   > deploy) and for any unattended/"yes-to-all" force — those remain forbidden.
4. **Single asset tier.** Assets live only in `public/assets/{module}` — there is no
   `public/assets/vendor/*`. The generated `fileFinder` `assetPaths` has one entry per namespace.
   The override happens at the **source** level (`override` before `vendor` in `sourcePaths`).
5. **The developer keeps public in sync.** New or changed framework assets stay in `vendor`; it is
   the developer's job (with Claude's help) to deploy them into `public`. Two supported moves:
   - `composer install` on a project whose `public/` is absent → seeds the full baseline;
   - **create a new project → `composer install` → migrate the old project's data/override in.**

   (`config/*.inc.php`, `data/`, admin provisioning keep their existing policies — this ADR is
   about `public/` and `override/`, not the config/data files.)

## Reasoning

- `composer install` is the frequent command (deploy, fresh checkout, CI, adding a module). Any
  overwrite policy there eventually destroys developer work. A first-install-only seed cannot.
- The developer already owns `public/` and `override/`; the tooling should mirror that, not fight
  it. If they want the framework baseline back, they delete the relevant `public/` files (or the
  whole `public/`) and re-install, or start a fresh project and migrate — both deliberate.
- Single tier matches the source-level override model; the second public tier was never populated
  and its `fileFinder` entry was a config lie.
- Claude assists with migration/sync, so "the installer won't auto-carry new files" is not a
  practical burden — it is a safety property.

## Consequences

- `composer install/update` is always safe on an existing project — it never overwrites `public/`
  (assets, favicon, entry files) or `override/`.
- A new/changed framework asset does **not** reach an existing project automatically. To pick it
  up: delete the target file(s) (or `public/`) and re-install, or new-project + migrate, or copy
  it in by hand. Deliberate, developer-controlled.
- Fresh projects still work out of the box: an absent `public/` is fully seeded on first install.
- Framework development (skeleton): to see a changed framework asset in `skeleton/public`, delete
  the file (or `skeleton/public`) and `composer install`, or copy it manually — a plain
  `composer install` will not update an existing `public/`.
- Adding a module: config (`moduleManager`, `fileFinder`) still registers it every install, but
  its public assets stay in `vendor` until the developer deploys them (the module resolves once
  its files are in `public/assets/{module}`).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Two tiers (`public/assets/vendor/{package}` for framework) | Adds a second public directory + mental model; the single-tier developer-owned model is simpler and matches the source-level override system. Attempted once and reverted. |
| Per-file seed on every install (skip existing, copy missing) | Still lets composer write into `public/` after first install; the owner wanted composer to keep its hands off `public/` entirely once it exists. |
| Explicit `publish-assets` force command | A force-overwrite is precisely the footgun that caused INST-ASSET-002. Built once, then removed by decision. |
| `install` overwrites / `update` skips | `install` is the frequent command and would still clobber; with symlinked path-repos `update` barely refreshes content. |

## See also

- [`../topics/installer.md`](../topics/installer.md) — INST-ASSET-002, implementation
