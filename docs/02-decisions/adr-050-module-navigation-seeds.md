# ADR-050 — Every module brings its menu entries: added once when missing, never overwritten

**Status:** `[APPROVED]` — the owner's specification, approved 2026-09-29 («ja los legen»)
**Date:** 2026-09-29
**Builds on:** [ADR-024](adr-024-asset-ownership-and-first-install-seed.md) (first-install seed),
[ADR-032](adr-032-data-import-identity-and-content-hash.md) (import, identity),
`navigation.md` NAV-KEY-001 / NAV-SEED-001, `installer.md` INST-SEED-001

---

## Context

The backend menu is data (`data/framework/routing/navigation.json`). Only the kernel ships a
seed; every module's entries (Finanzen, Kontakte, MWST-Codes, Mandant …) were added by hand in
each installation. The owner, 2026-09-29: «jedes Modul hat seine Navigationspunkte und stellt
sie bei der Installation zur Verfügung, damit sie sofort da stehen — dann aber nie mehr
überschrieben; der Import fügt nur hinzu, was fehlt».

What blocks it today (NAV-SEED-001):

- The installer copies a `*.default.json` only when the target file does not exist, whole
  file, the first package wins — two modules cannot both seed `navigation.json`, and a module
  installed later seeds nothing.
- The import reads ONE default file per entity.
- A seed links a child to its parent by `parent_id` — an id valid inside that one file. A
  module cannot hang an entry under another package's group.

The target structure (owner 2026-09-29, built by hand in z77.ch first): work areas carry the
daily work, «Stammdaten» groups what is set up once, by topic.

```
Webseiten    Inhalte · Metadaten · Übersetzungen · Navigation (Nav Alias)   kernel
Finanzen     Journal · Auswertungen                                          module-financial
Kontakte     Kontakte                                                        module-contact
Drive        Dokumente                                                       module-dms
Stammdaten   ▸ Firma      Mandant                                            kernel / module-mandator
             ▸ Finanzen   Geschäftsjahre · Kontenplan · MWST-Codes           module-financial / module-vat
             ▸ Kontakte   Adresstypen                                        module-contact
             ▸ System     Benutzer                                           kernel
Service      Backup · E-Mail · Jobs · Import · Formular-Protokoll            kernel
```

## Decision

### 1. Each package ships its own navigation seed

`<package>/data/framework/routing/navigation.d/<package>.json` — its own file, so no two
packages collide. Not a `*.default.json`: the whole-file seeding does not touch it.

An entry names its parent by **`parent_key`** (the `key` of an entry in ANY package), not by
id. A root area carries `slot` and no parent. Areas and groups carry a `key` (they have no
route to be recognised by); a leaf with a route may.

The package that owns the work owns the area; a group under «Stammdaten» belongs to the
package of its topic (`stammdaten-finanzen` → module-financial). A package whose parent is
another package's key declares that package as a dependency, or accepts that its entry waits.

### 2. Recognised by the import's identity — never by id

The same rules as the import (ADR-032, `Navigation` `#[ImportIdentity]`), in order: `key`, then
the route (`module`, `group`, `controller`, `action`), then parent + ref. An entry that exists
without a key but with the same route (z77.ch's «Journal») is the same entry.

### 3. The installer adds what is missing — and nothing else

On every `composer install` / `update` the installer collects all packages' `navigation.d`
files and ADDS each entry that is not recognised in the installation's `navigation.json`:
parent resolved by `parent_key`, placed at the end of its siblings. It never changes, moves,
renames or removes an existing entry — the project may have changed it. A parent that does not
exist (its package not installed) → the entry is skipped and the installer says so.

This replaces the installer decision of 2026-08-08 («seed-once at file level, merging is the
import's job») for the navigation — records are merged ADD-ONLY, by identity.

### 4. The import adds what is missing; the user steers the rest

The import reads the same `navigation.d` files (all packages, not one default file). New
entries: added (bulk). Changed content fields: proposed per record as today, default «No» —
the user decides. Position (parent, order) of an existing entry: never written (IMP-001 stays).

### 5. The kernel seed follows the target

`kernel/core/data/framework/routing/navigation.default.json` keeps what a fresh installation
needs before any module: Webseiten (with Navigation), Drive's place, Stammdaten with «System»
(Benutzer), Service. Module entries move out of it into their packages (Drive → module-dms).

## Reasoning

- One file per package: no collision, the package owns its entries.
- `parent_key` instead of an id: ids are local to a file and to an installation.
- Identity instead of id: the same entry is recognised in an installation that built its menu
  by hand — nothing doubled.
- Add-only: the menu is the project's after installation; the framework never takes it back.

## Consequences

- Installer: a new step «navigation seeds» (collect, recognise, add) — `installer.md`.
- Import: the vendor source for `Navigation` becomes the union of all `navigation.d` files, with
  `parent_key` resolved against the target (today a ref outside the source is `unclear`).
- Seeds in: kernel, module-financial, module-contact, module-vat, module-mandator, module-dms,
  module-debtor (its own area «Aufträge», see the implementation notes).
- z77.ch already has the target structure (built by hand 2026-09-29); its entries without a key
  are recognised by route; the areas/groups carry the keys named above.
- Resolves NAV-SEED-001.

## Implementation notes (2026-09-29)

Built as specified; where the text left a choice, this is what was decided (confirmed by the owner 2026-09-30; the debtor placement changed to its own area):

- **File name** `navigation.d/<package-dir>.json` under the package data root: `kernel.json`
  (in `kernel/core/data`), `module-financial.json`, … One file per name wins across tiers — a
  project may replace a package's seed by putting a file of the same name into its override
  data root (CE).
- **Ref entries** name their target by **`ref_key`** (the Navigation opener's ref-to-self child:
  `parent_key: navigation, ref_key: navigation`). Seeds carry no `id` / `parent_id` / `ref` at all
  — `NavigationSeeds::read()` refuses them.
- **Module leaves carry keys too** (`journal`, `auswertungen`, `geschaeftsjahre`, `kontenplan`,
  `mwst-codes`, `kontakte-liste`, `adresstypen`, `mandant`); an installation that has them keyless
  (z77.ch) is recognised by route, and the import offers the key as «Kennung nachtragen».
- **Firma** (`stammdaten-firma`) ships with **module-mandator** together with «Mandant» — no empty
  group in a project without it. The kernel ships `stammdaten-system` (Benutzer).
- **Part 5 deviates in form, not in intent:** the kernel's backend structure (Webseiten,
  Stammdaten › System, Service) moved into `navigation.d/kernel.json` like every other package, so
  an existing installation also receives a new kernel entry. `navigation.default.json` keeps only
  what other seeds reference BY ID — the frontend starter pages (the alias and metadata defaults
  point at ids 3–12) and Login/Logout (the `/login` alias). A fresh install: `writeDataFiles()`
  copies that file, then the seed step adds the whole backend menu — the result is the target tree
  (`tests/navigation-seeds.php` NSEED-2/-7). The starter pages stay out of `navigation.d` on
  purpose: they are the customer's content, and add-only on every run would bring back a page the
  project deleted. Drive moved to module-dms completely (its «place» too): a project without DMS
  has no dead Drive area.
- **Recognition** reuses the import's planner (`NavigationSeeds::merge()` →
  `ImportPlanner::plan()`), not a copy of its rules: skipped/changed = present, new = added,
  unclear/blocked/invalid = skipped and named.
- **module-debtor** — owner 2026-09-30: order processing is apart from the books («Fibu und
  Order/Debitoren sind getrennt»). Its own area «Aufträge» (`auftraege`, root 2) with Debitoren —
  module-order (P7) adds its screens there — and Stammdaten › «Aufträge»
  (`stammdaten-auftraege`) with Zahlungskonditionen, Zahlungsziele, Mahnstufen. Roots renumbered:
  Webseiten 0, Finanzen 1, Aufträge 2, Kontakte 3, Drive 4, Stammdaten 5, Service 6; groups Firma
  0, Finanzen 1, Aufträge 2, Kontakte 3, System 4.
- **Parent in a package not required:** module-vat and module-debtor do not require
  module-financial; without it their entries wait (the installer names them on every run).
- **An installation seeded from the OLD kernel default** keeps Navigation and Benutzer directly
  under Stammdaten (recognised by key, never moved) and gets the new groups — among them an empty
  «System»; moving Benutzer is the project's decision.

## Rejected Alternatives

- **Each module appends to the kernel's `navigation.default.json`** — one file, first package
  wins, a later module never seeds.
- **The installer overwrites module entries on update** — the project's changes would be lost.
- **Only the import, triggered by hand** — the owner wants the entries there at installation.
- **The import may move entries** — the owner: the import only adds what is missing.
