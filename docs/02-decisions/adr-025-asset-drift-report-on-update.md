# ADR-025 — Report framework-asset drift on update (read-only, developer decides)

**Status:** `[APPROVED]` — deploy step amended by [ADR-026](adr-026-opt-in-interactive-asset-deploy.md) (2026-07-15)
**Date:** 2026-07-14

---

## Context

ADR-024 made `public/` developer-owned: the installer seeds the framework asset baseline
**only on the first install** (`public/` absent) and never touches `public/` afterwards. Correct —
but it leaves a discoverability gap: when a framework update (e.g. `z77 1.0.0 → 1.0.1`) changes
`core.js` or `backend/base.css`, **nothing tells the developer that these files changed.** The
`FileFinder` keeps serving the old deployed copy from `public/`, silently, with no error. The
developer's own customizations then run on a stale framework baseline, and they only find out when
something breaks.

The developer is firm on one point: **`public/` stays 100% in their responsibility — the installer
must never write into an existing `public/`.** So the fix cannot be an auto-copy or a force/publish
command (both rejected in ADR-024). The tooling may only **inform**.

Heavier schemes were considered and rejected (see below). The chosen one is the simplest that
closes the gap without ever touching `public/`.

## Decision

**On an update (an install where `public/` already exists), the installer prints a read-only list
of framework assets in `vendor` that differ from what is deployed in `public/`. It writes nothing.
The developer decides what to adopt.**

1. **Read-only.** The report only hashes files and prints paths. It never creates, copies, or
   overwrites anything under `public/`. ADR-024 (seed-once, hands off `public/`) stands unchanged.
   > **Amended by ADR-026 (2026-07-15):** on *interactive* updates the collected drift is now
   > offered as an opt-in, per-file, default-No deploy. Non-interactive runs stay read-only as
   > described here.
2. **On-the-fly diff, no manifest.** For each framework namespace that ships `res/assets/` in
   `vendor`, the installer walks the source files and compares each (by `sha1`) against its
   counterpart under `public/assets/{name}/` (same relative path). No stored manifest, no ledger,
   nothing to maintain — the two trees on disk are the whole truth.
3. **Two categories, source-side only:**
   - `~ changed` — the file exists in both, hashes differ.
   - `+ new` — the file ships in `vendor` but is absent in `public/`.
   `removed` is deliberately **not** reported: comparing new-`vendor` against deployed-`public`
   cannot distinguish "the framework dropped a file" from "the developer added their own", so a
   removed-list would be guesswork.
4. **Cannot distinguish framework-change from developer-edit — and that's fine.** The diff lists
   every file whose deployed copy differs from the shipped one. If the developer customized a file,
   it shows up too. The developer knows which files they touched; the list is "these are the ones to
   look at", not "these are the ones the framework changed". Precision beyond that needs the manifest
   scheme below, which the owner declined for now.
5. **Fresh install unchanged.** When `public/` is absent, the first-install seed (ADR-024) runs and
   there is nothing to diff — the report is skipped.

## Reasoning

- The gap is real (silent stale assets after update) and the earlier answer to "how do I know?" was
  honestly "you don't". A read-only report is the minimum that fixes it.
- A per-project hash **manifest** (record every asset hash at install, diff on update) was the first
  sketch. It is more than needed: it is an artifact someone/something has to write and keep in sync,
  and the on-the-fly `vendor` ↔ `public` diff yields the same list with **nothing stored**.
- The report respects the ownership line ADR-024 drew: it informs, it does not act. It is therefore
  not the "publish/force command" ADR-024 rejected — it is its safe complement.
- Not distinguishing framework-change from developer-edit is an acceptable, honest limitation: the
  developer is the one person who always knows what they customized.

## Consequences

- After `composer update` on an existing project, the developer sees exactly which framework asset
  files to review, and deploys the ones they want by hand (`cp vendor/z77/.../res/assets/... public/...`).
- `public/` is still never written by the installer — ADR-024 intact.
- Zero maintenance: no manifest, no changelog discipline required for the mechanism to work.
- Framework development (skeleton): the same report runs on `composer install` in `skeleton/`, so a
  changed `res/assets` file that has not been copied into `skeleton/public` shows up as `~ changed`.
- Limitation: a developer-customized asset re-appears in the list on every update (it always differs
  from the shipped file). If that becomes noisy, the manifest/version scheme (below) is the upgrade.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Per-project hash **manifest** (store all asset hashes at install, diff on update) | An artifact to write + keep in sync; the on-the-fly `vendor`↔`public` diff produces the same list with nothing stored. Reconsider only if framework-change vs developer-edit must be told apart. |
| Base + overlay auto-publish (framework → public, then re-apply developer overrides) | Step 1 writes into `public/` → violates the owner's hard rule that `public/` stays theirs. Rejected by the owner. |
| Force/`publish-assets` command that copies into `public/` | Already rejected in ADR-024 — a force-overwrite is the INST-ASSET-002 footgun. |
| Git-tag changelog shipped per release (`git diff v1.0.0..v1.0.1 -- res/assets`) | More precise (only framework changes) but needs release tooling + a stored installed-version. Deferred; the on-the-fly diff is enough now and needs no release process. |

## See also

- [`adr-024-asset-ownership-and-first-install-seed.md`](adr-024-asset-ownership-and-first-install-seed.md) — the seed-once ownership rule this report complements
- [`../topics/installer.md`](../topics/installer.md) — INST-ASSET-DIFF-001, implementation
