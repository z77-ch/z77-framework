# ADR-046 — Publication record: the installer may refresh what it wrote itself

**Status:** `[APPROVED]` — approved by the owner 2026-09-23
**Amended:** 2026-10-10 — the installer deletes its own unchanged leftovers and the record forgets them (see Addendum; built, owner approval pending)
**Date:** 2026-09-23
**Amends:** [ADR-024](adr-024-asset-ownership-and-first-install-seed.md) §3,
[ADR-025](adr-025-asset-drift-report-on-update.md) ("nothing is stored"),
[ADR-026](adr-026-opt-in-interactive-asset-deploy.md) decision 1 ("non-interactive writes nothing")

---

## Context

`public/` belongs to the developer (ADR-024): the installer seeds the framework baseline on the
first install and never touches it again. ADR-025 added a read-only drift report on update, ADR-026
turned it into an opt-in, per-file, default-No prompt. All three rest on one assumption: **the
installer cannot tell a framework change from a developer edit**, so it must ask.

That assumption produced a silent failure, measured on z77.ch at the P2 exit check (2026-09-22,
INST-ASSET-DIFF-001):

- the framework packages are symlinked `path` repositories, so a package change is live in
  `vendor/` at once;
- `public/assets/backend/css/base.css` was hours old — the new CSS classes of commit 29cda26 were
  missing and the one-line journal form rendered as a vertical stack;
- the reason: the installer asks per file («This may be YOUR own edit — overwrite? [y/N]») and a
  **non-interactive `composer install` answers No to every prompt**. The run ended with
  «Asset deploy: nothing written», naming nothing. A stale copy survives every install, silently,
  and nothing in the app errors — the `FileFinder` serves the old file happily.

The assumption is wrong in one important case: for a file the installer wrote itself and nobody
touched since, there is nothing to ask about. What was missing was the evidence.

## Decision

**The installer keeps a publication record of what it wrote into `public/`, and may write there
without asking whenever the evidence says nothing of the project's is at stake.**

1. **The record.** `var/state/published-assets.json`: project-relative path → the sha1 the file had
   when the installer wrote it. Written by every path that publishes a file (first install,
   automatic write, interactive deploy). It covers the `res/assets` trees plus the two
   framework-owned entry files `index.php` and `.htaccess` — not the branding files beside them
   (favicons, `site.webmanifest`), which nearly every project replaces.
2. **Where it lives.** Release-local runtime state under `var/state/` (ADR-035), beside
   `debug.flag` / `noindex.flag`: a fixed path, not a config key, gitignored, not carried by a
   deploy. **Not** in `shared/`: it is derived, rebuildable state that describes THIS release's
   `public/` against THIS release's `vendor/`, and it must not outlive the tree it describes.
3. **Two unattended writes, and only two.** In every run mode, interactive or not:
   - a file whose deployed copy still has exactly the recorded sha1 while the shipped file changed
     → refreshed;
   - a file that is absent from `public/` and has no record entry → published (nobody can have
     edited what never existed here).
4. **Everything else still needs an explicit yes.** A file that differs from the record ('edited'),
   a file the record does not know ('unrecorded'), and a file that was published here and has since
   been deleted ('removed here') are never written on their own. Interactive: warned, asked, default
   No (ADR-026 unchanged). Non-interactive: named, with the reason, once.
5. **In sync ⇒ ours (adoption, and self-healing).** Whenever `public/` and `vendor/` hold the same
   bytes and the record says anything else — no entry, or a wrong one — the record is corrected.
   Nothing is written into `public/`.
6. **The record is a record, not a manifest.** It says only what WE last wrote. It is written
   atomically (temp file + `rename()`), saved in a `finally` around every write loop, and never
   pruned.

## Addendum 2026-10-10 — the installer takes back its own leftovers, and the record forgets them (built; owner approval pending)

**Incident (INST-ASSET-003, `topics/installer.md`).** The DMS dropped `documents/upload.js`; the
project's `public/assets/dms/js/documents/` kept serving it after a `composer update`. Every
update path walked what `vendor/` SHIPS, so a path that had vanished from `vendor/` was looked at
by nothing — while the record held the one fact nobody read in that direction.

**Point 3 gains a third unattended operation, with the same evidence standard as the first two:**

- a file that NO walked package ships any more, whose deployed copy still has exactly the recorded
  sha1 → **deleted**, and the emptied directories below the asset root with it.

Identical to the record means nobody edited it since we wrote it — deleting it loses nothing, and
a package that brings the file back has it published as new on the next run. A file that differs
from the record, or that the record never knew, stays and is not even named: it may be the
project's own, and naming it would stand in every install log forever.

**Point 6 is narrowed: the record is pruned in exactly two cases**, both inside a package asset
tree the run actually walked (a tree whose `vendor/` source did not exist this run is never
entered, so a disabled or half-installed package cannot trigger a delete): the installer itself
removed the file, or found it already gone from disk. Without the pruning, a returning file would
read as «− removed here» for ever and never be published again. «Never pruned» stays true for every
other path — in particular, nothing is ever dropped on a guess about what `vendor/` still ships.

Bounded by construction (`collectDroppedAssets()` / `unpublishDroppedAssets()`): only keys under
`public/{assetDir}/{name}/`, only keys that are relative, forward-slashed and free of `..` (the
record is a file on disk and could be edited), delete and record save share the write loop's
`finally`. Verified: `tests/installer-asset-cleanup.php`, 54 checks.

## Reasoning

- **Point 3 is not a relaxation of ADR-024, it is its enforcement.** ADR-024 protects the
  developer's work. A file byte-identical to the one the installer wrote contains no developer work
  — by evidence, not by assumption. Keeping it stale protects nothing and breaks the app.
- **The dangerous case from INST-ASSET-002 is untouched.** That incident was an automatic,
  unattended overwrite of a *compiled build artefact*. Such a file differs from the record the
  moment the project builds it, so it lands in 'edited' and still needs a typed `y`.
- **Silence was half the defect.** «Nothing written» named nothing; an operator reading a deploy
  log had no way to see that a file stayed stale. Every run now names what it wrote and what it
  kept, each file once.
- **Point 5 makes the mechanism survivable.** Without the self-healing half, a single wrong hash —
  from an aborted run, from the hand copy the installer itself suggests, from any path that writes
  the file outside the installer — would freeze that file as 'edited' for good: it would never match
  again, so it would never be refreshed again. «In sync ⇒ ours» is a statement about the present,
  which is exactly what a hash comparison can support.
- **Point 5 also removes the migration.** An installation from before the record adopts its
  in-sync files on the next install and needs no manual step.
- **`var/state/` over `shared/`** follows ADR-035's own criterion: state describing this release's
  code belongs to the release. A record that outlived the tree it describes would be worse than no
  record — it would claim knowledge about files it never saw.

## Consequences

- **Monorepo / `path` repositories (the daily case).** After `npm run build:backend`, a
  `composer install` in the project republishes the untouched CSS without a question. The trap that
  `vendor/` looks current while `public/` is stale is gone for every file the project did not touch.
- **Deploy / CI.** A non-interactive run now writes into `public/` — but only files it can prove
  it wrote itself, or that were never there. It names both what it wrote and what it kept.
- **Release switch.** A new release starts with an empty `var/state/`, so nothing is refreshed on
  the first run there; every differing file is 'unrecorded' and held. Safe by construction, and it
  costs only the adoption round.
- **A project that compiles its own CSS into `public/`** keeps owning that file: it differs from
  the record from its first build on, is never written silently, and appears in the kept list of
  every install until the project deploys it itself. That list is the price of the protection.
- **New files now appear in `public/` unattended.** A new module's assets reach a project without a
  prompt. A file the project deliberately deleted does NOT come back — that is what the record
  distinguishes.
- **Packagist future (INST-ASSET-CRLF-001).** The record compares bytes. Dist archives ship LF; a
  Windows checkout of `public/` may hold CRLF. Then every text asset would differ from its published
  copy and stay 'edited' forever. Noted, deliberately not built for — see `topics/installer.md`.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep asking per file, non-interactive stays read-only (status quo, ADR-025/026) | The defect itself: a deploy answers No, so a stale copy survives every install and nothing says so. |
| A shipped manifest per package (hashes of what the framework ships) | ADR-025 rejected it and the reason holds: it must be maintained, versioned and shipped, and it answers the wrong question. What the framework ships is `vendor/`, readable live. The open question is what WE wrote into `public/`. |
| Store the record in `shared/` (survives release switches) | It would outlive the `public/` tree it describes and could claim knowledge about a release's files it never saw. ADR-035 puts state about this release's code in the release. |
| Store it inside `public/` (travels with the tree, in git) | Web-reachable, and it would put installer bookkeeping into the developer-owned tree. |
| A `--force` / "yes to all" flag instead of the record | The INST-ASSET-002 footgun, re-armed. The record writes only what it can prove is its own; a force flag writes everything. |
| Adopt only when no entry exists (no self-healing) | One aborted run or one hand copy leaves a wrong hash, and the file is frozen as 'edited' forever — the mechanism silently stops working for exactly the files it was built for. |
| Prune record entries for files no longer shipped | Would trust one run's view of `vendor/`. A disabled module or a half-installed tree would delete entries that are still true. A sha1 per path is cheap. |
| Include the branding files (favicons, `site.webmanifest`) in the record | Nearly every project replaces them, so they would differ forever and stand in every install log — permanent noise for files that change once a year. |
