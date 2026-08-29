# import

2026-08-08

## entry

1. `packages/kernel/shared/src/Import/ImportPlanner.php` — the core: identity × content classification into the five outcomes
2. `packages/kernel/shared/src/Import/ImportServiceFactory.php` — the ONE wiring point (screen + job), vendor discovery, source specs
3. `packages/module-backend/src/Ui/Controllers/Service/ImportController.php` — the backend surface (`/backend/service/import/list`)

## file map

SOURCE=/packages/kernel/shared/src/Attributes/ImportIdentity.php
SOURCE=/packages/kernel/shared/src/Attributes/ImportNearMatch.php
SOURCE=/packages/kernel/shared/src/Attributes/ImportRef.php
SOURCE=/packages/kernel/shared/src/Import/ImportDescriptor.php
SOURCE=/packages/kernel/shared/src/Import/ImportPlanner.php
SOURCE=/packages/kernel/shared/src/Import/ImportPlan.php
SOURCE=/packages/kernel/shared/src/Import/ImportPlanEntry.php
SOURCE=/packages/kernel/shared/src/Import/ImportOutcome.php
SOURCE=/packages/kernel/shared/src/Import/ImportApplier.php
SOURCE=/packages/kernel/shared/src/Import/ImportApplyResult.php
SOURCE=/packages/kernel/shared/src/Import/ImportService.php
SOURCE=/packages/kernel/shared/src/Import/ImportServiceFactory.php
SOURCE=/packages/kernel/shared/src/Import/ImportStaging.php
SOURCE=/packages/kernel/shared/src/Import/ImportPlanStore.php
SOURCE=/packages/kernel/shared/src/Import/ImportSource.php
SOURCE=/packages/kernel/shared/src/Import/ImportSourceException.php
SOURCE=/packages/kernel/shared/src/Import/ImportStaleException.php
SOURCE=/packages/kernel/shared/src/Import/Source/JsonEntitySource.php
SOURCE=/packages/kernel/shared/src/Jobs/ImportApplyJob.php
SOURCE=/packages/kernel/shared/src/Entities/Navigation.php
SOURCE=/packages/kernel/shared/src/Entities/NavigationAlias.php
SOURCE=/packages/kernel/shared/src/Entities/MetaData.php
SOURCE=/packages/kernel/shared/src/Tree/TreeNodeTrait.php
SOURCE=/packages/kernel/shared/src/Backup/BackupService.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/ImportController.php
SOURCE=/packages/module-backend/res/view/templates/Service/ImportController/listAction.tpl.php

## mental model

The import ADOPTS records into a running installation (ADR-032) — framework defaults added after
the installation was seeded (the «Jobs» case), data from another z77 project, later wdv-6.2.2
bulk data. It never syncs, never replaces, never deletes: after apply the installation is the
source of truth. Every source record is classified along two declared dimensions — identity
(WHICH record is this?) and content (is it still IDENTICAL?) — into a plan the developer decides
on per record; the applier writes through the existing entity validators. Ids are never identity:
they are in-file link addresses, rewritten through a per-run id map at apply.

- **Declaration is on the entity**: `#[ImportIdentity]` (ordered fallback rules, may name ref
  fields → resolved target identity), `#[ImportRef]` (FK + `resolveBy: map|identity`),
  `#[ImportNearMatch]` (narrow suggestion heuristic). `TreeNodeTrait` declares `parentId` as
  `#[ImportRef('self')]` — every tree entity gets it for free.
- **Registration is module config**: `'importEntities' => [Class::class, …]`, aggregated by
  `ModuleManager::getImportEntities()`. A fixed menu — the screen never accepts an entity class
  from a request. v1: `Navigation`, `NavigationAlias`, `MetaData` (declared by module-backend).
- **Five outcomes**: `skipped` (identical) · `changed` (field diff, per-record opt-in, default No)
  · `new` (bulk-acceptable) · `unclear` (assign a match / force-new — a human decides) · `blocked`
  (a referenced record is unclear/declined; transitive, only insertions block).
- **The screen groups by MEANING, not by outcome** (`ImportController::GROUPS`): `changed` splits
  into **«Kennung nachtragen»** (the diff touches nothing but `key` — pure identity backfill,
  nothing visible changes, bulk-acceptable) and **«Inhalt weicht ab»** (a real difference, one by
  one, with a `bei dir → wird zu` table). Every group carries its explanatory sentence, and each
  row says the CONSEQUENCE («bekommt die Kennung «navigation»»), never a field list. Rows are
  labelled with their tree position («Stammdaten › Navigation»), because a bare name repeats.
  Presentation only — the core outcome model is untouched.
- **A rule matches only bijectively** (unique on BOTH sides) — the 4-tuple is legally non-unique
  (ADR-015), ambiguity goes to `unclear`, never a guess.
- **Near-match** downgrades a would-be `new` to `unclear` + suggestion when (resolved parent,
  name, slot) coincide with an unclaimed target — catches framework renames
  (`login-user` → `backend-user`) and keyless hand-created containers.
- **The plan is never patched**: decisions (`{class}#{sourceIndex}` → accept/reject/assignment/
  force-new) are stored in the plan store; every screen load and the apply recompute the plan
  from source + decisions, so one assignment can unblock a whole subtree.
- **Key adoption is just a `changed` diff**: assigning a keyless target to a key-carrying source
  record makes `key` a content diff; accepting writes it — the next import matches silently.
- **Apply**: `new` inserts with refs rewritten via the id map, `id`/`sort_key` assigned
  target-side (`TreeService::nextSortKey`); `changed` overwrites only the diffed CONTENT fields —
  never refs (reparenting stays with `moveAction`), never `sort_key`. Small plans run in-request;
  above 50 accepted records the screen queues the `import-apply` job (ADR-031).
- **Staleness guards both sides** (IMP-R011): target fingerprints (per record set) are checked
  under the apply lock; source files are hash-pinned in the stored spec — either mismatch throws
  `ImportStaleException` instead of writing.
- **Staging** `data/framework/import/` (`inbox/` → `snapshots/` + index + lock, `plans/`):
  transient, excluded from backup archives (`BackupService::DATA_EXCLUDES`), no user-supplied
  paths anywhere — the screen lists the inbox, sources are staged as frozen snapshots.
- **Vendor discovery** is the runtime twin of the installer's package walk: the entity's
  `#[Entity]` path names the default file, the FileFinder namespaces name the data roots —
  override tier first, so a project can override a seed (CE).

## flow

```text
Quelle (Vendor-Defaults | Inbox-Datei + Entity-Typ aus der Whitelist)
  → Snapshot + Quell-Hashes → Plan-Store (source, decisions: {}, fingerprints)
  → Plan berechnen (jede Anzeige, jede Entscheidung: Neuberechnung)
       Runde 1 auf schlüssellosem Bestand: Container unclear+Vorschlag,
       Routables via 4-Tupel changed (Key-Diff = Adoptions-Angebot), Kinder blocked
  → zuordnen / force-new / übernehmen / ablehnen  (pro Eintrag; «alle neuen» als Gruppe)
  → Apply (Request ≤ 50 akzeptierte, sonst import-apply-Job)
       Fingerprints prüfen → topologisch einfügen → Validator → Protokoll (last_result)
  → erneuter Plan: Übernommenes ist skipped; Plan verwerfen löscht Snapshot + Store
```

## rules

- When making an entity importable → MUST declare `#[ImportIdentity]` on the class and
  `#[ImportRef]` on every FK property, and MUST register the class in a module's
  `importEntities` config — the descriptor throws on a missing identity declaration, and an
  unregistered class is invisible to the screen by design.
- When an identity rule names a ref field → the referenced entity class MUST be part of the same
  source (record sets travel together) — the planner compares the RESOLVED target identity, and a
  ref pointing outside the source is `unclear` by design.
- When adding a shipped record to a `*.default.json` that framework code relies on → MUST give it
  a stable `key` (Navigation) or natural identity; a keyless shipped container is `unclear` on
  every installed base.
- When applying → writes MUST go through the entity's validator (registered in
  `ImportServiceFactory::fromDi()`); an importable entity without a validator factory is applied
  unvalidated — add the factory when registering the entity.
- When extending the applier → `changed` MUST NOT rewrite ref fields or `sort_key`; reparenting
  belongs to `moveAction` with its guards.
- When reading a plan → MUST treat it as derived state: recompute from source + decisions, never
  serialize/patch computed entries (an assignment changes downstream outcomes).
- When adding a row or group to the screen → MUST state what ACCEPTING does, in German, and MUST
  NOT surface raw field names or planner English as the row text (the planner's `reason` belongs
  in the `title` tooltip). A reader six months later has to understand the row without the ADR.
- When adding a bulk («alle markieren») control → MUST restrict it to groups that are harmless by
  nature (`new`, `changed-key`); a content difference MUST stay a per-record decision (ADR-032 §8).
- When touching staging → MUST keep it under `data/framework/import` (backup-excluded,
  BACKUP-JOBS-001 pattern) and MUST NOT accept file paths from a request — inbox basenames only.

## see also

- [`../02-decisions/adr-032-data-import-identity-and-content-hash.md`](../02-decisions/adr-032-data-import-identity-and-content-hash.md) — the binding decisions (1–15) incl. the amendments
- [`../03-development/review-import-adr-032.md`](../03-development/review-import-adr-032.md) — the two-round pre-build review (IMP-R001…R021) with the live-project evidence
- [`../03-development/import-service-bauplan.md`](../03-development/import-service-bauplan.md) — build plan + phase log (Entstehungsgeschichte)
- [`navigation.md`](navigation.md) — `Navigation::key` (NAV-KEY-001), the identity the navigation import stands on
- [`installer.md`](installer.md) — seeding is file-level seed-once (INST-SEED-001 package walk); the import is the record-level answer
- [`jobs.md`](jobs.md) — the `import-apply` job runs on the ADR-031 queue
- [`backup.md`](backup.md) — why `framework/import` is excluded from data archives
- [`translation.md`](translation.md) — i18n catalogs are deliberately NOT this mechanism (TRANS-SEED-001)

## known issues

- **IMP-001**: don't assume `changed` apply moves an entry — ref diffs (e.g. `parent_id`) are
  DISPLAYED but never written; the developer reparents via the navigation screen (`moveAction`
  guards). Only content fields are overwritten.
- **IMP-002**: don't assume declined proposals stay away — without a ledger (deliberate, ADR-032)
  a rejected `changed` record reappears in every future plan. Accepted trade-off (IMP-R010);
  a target-side dismissal flag is the designed extension if it ever grates.
- **IMP-003**: don't assume the screen can upload — v1 sources are vendor defaults + the inbox
  (`data/framework/import/inbox/`, via FTP/Explorer). The fetch layer posts JSON, not multipart;
  an upload form needs its own path (see pending).
- **IMP-004**: don't be surprised by missing backend chrome on an installation that has no
  «Import» navigation entry yet. The screen is reachable by convention route
  (`/backend/service/import/list`), but the backend chrome is driven by the CURRENT navigation
  entry: `subnav.tpl.php` returns early when `getActiveSectionBySlot()` finds none (no sidebar),
  and `topbar.tpl.php` falls back to the literal label «Menü» (the section switcher behind the
  grid icon still works). Pre-existing behaviour of every convention route — the import screen is
  just the first one meant to be used without an entry. Self-healing: the first import creates
  the «Import» + «Jobs» entries, after which the chrome renders normally.
- **IMP-005**: don't put free-form content into a `.be-tree__row` inside a `.be-tree--hub`
  container — that row is an explicit 6-column grid (`1rem 2.4rem 1.6rem …`), so forms and
  buttons auto-place into the narrow icon columns and overlap. Detail/action rows are plain flex
  divs; a control that belongs INTO the row needs `grid-column: 6`. (The same misuse exists in
  `Service/JobController/listAction.tpl.php` — its action buttons overlap for the same reason.)

## pending

- ~~**Browser click-through by the developer**~~ — done 2026-08-29: clicked through and
  applied on a live installation, worked as designed.
- **Upload form** — multipart upload into staging (`stageContent` exists); needs a dedicated
  fetch path like the DMS upload. Inbox covers the workflow until then.
- **v2 — foreign sources (first real migration)**: `ImportMapping` contract (project-level
  `override/` code) + mysqldump-INSERT reader with declared source encoding (latin1→UTF-8);
  `ImportRef` `resolveBy: 'identity'` is declared but the planner throws on it until then.
- **Plan size**: the planner is in-memory — fine for v1 targets (≤ hundreds). Before a wdv bulk
  migration, decide streaming/chunking at the `ImportSource` seam (review IMP-R019).
- **NAV-SEED-001** (tracked in [`navigation.md`](navigation.md)) — module-owned navigation
  entries still ship in the kernel default; per-package seeds would let the import plan scope
  per module.
