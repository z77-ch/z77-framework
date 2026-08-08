# Review: ADR-032 data import — does the model hold?

2026-08-08

> **Status (2026-08-08): decisions taken, ADR-032 amended.** All four questions at the bottom
> were answered by the developer: (1) key adoption on manual assignment — **yes**; (2) near-match
> heuristic — **narrow** (resolved parent + name + slot, unclaimed targets only); (3) i18n —
> **TranslationCatalog feature**, not this mechanism; (4) DMS `folders.json` — **out of scope
> entirely** (not merely deferred: blobs are never seeded, base structures are created in the DMS
> backend, module roots self-heal via `rootFolder()`). R001/R002/R003/R004/R006 and the scope
> table are folded into
> [`../02-decisions/adr-032-data-import-identity-and-content-hash.md`](../02-decisions/adr-032-data-import-identity-and-content-hash.md).
> Next step: the bauplan — written: [`import-service-bauplan.md`](import-service-bauplan.md).
>
> **Round 2 (2026-08-08): foreign sources + live-project evidence.** Second pass at the
> developer's request, walking the live installations (`z77-axo3.ch`, `z77-1.0.0-zihlundsee.ch`)
> and widening the lens to the migration case: wdv-6.2.2 data (e.g. Fakturierung / order
> entities) arriving as a **SQL dump** placed on the server. Findings IMP-R014…R021 below;
> round-2 decisions recorded at the end.

Scope: critical re-analysis of
[`../02-decisions/adr-032-data-import-identity-and-content-hash.md`](../02-decisions/adr-032-data-import-identity-and-content-hash.md)
against the real data files it must serve — `navigation.json`, `navigation_aliases.json`,
`metadata.json`, plus the other seeded families (i18n catalogs, content documents, DMS
`folders.json`). Goal: find the holes **before** the build plan is written. Read
[`../topics/navigation.md`](../topics/navigation.md) +
[`../topics/installer.md`](../topics/installer.md) first.

## verdict

**The two-dimensional model (declared identity × content hash, human applies) holds.** No
finding below invalidates it. But the ADR as written over-promises in four places: the 4-tuple
fallback is not a reliable identity (IMP-R001), ref-bearing identities are required — not
optional — for two of the three navigation files (IMP-R002), the four-outcome table cannot
produce the `unclear` the ADR's own rename example demands (IMP-R003), and the installed base
that motivated the whole feature (hand-created «Service» sections) defeats key matching on
first contact (IMP-R004). All four have clean resolutions; they must be in the build plan, and
R001/R003/R004 should be folded into ADR-032 as an amendment once confirmed.

## findings

### IMP-R001 — the 4-tuple is not unique, by the framework's own rules

ADR-032's attribute example declares `['module','group','controller','action']` as
Navigation's identity fallback. But ADR-015 explicitly allows the opposite:
**"Multiple navigation entries MAY share one 4-tuple — identity is the alias `path`, not the
4-tuple"** ([`navigation.md`](../topics/navigation.md), NAV-DUP-001 superseded).
`NavigationValidator::validateModule()` enforces only the all-or-nothing field structure, not
uniqueness. A navigation exported from a real project (the ADR's "tree from another project"
case) can legitimately carry several entries with the same 4-tuple and different aliases. Two
source records would then match one target record — or one source record two targets.

**Resolution:** an identity rule only *matches* when its value is unique **on both sides**
(bijective). Duplicate on either side → all involved records fall to `unclear`. This is a
matching-algorithm invariant, not a per-entity annotation — it belongs in the `ImportService`
core. For the framework's own backend entries the 4-tuple is unique in practice, so the
shipped-defaults case keeps working; the rule only refuses to guess where guessing is wrong.

### IMP-R002 — identity rules MUST support resolved-ref fields; two of three files need it

As written, `#[ImportIdentity(['key'], ['module',…])]` covers plain fields only. That leaves
two holes:

1. **Ref entries have no identity at all.** A ref carries no routing fields and no key — the
   framework's own default ships one (id 18, ref-to-self under id 6, the opener pattern).
   Every ref would land in `unclear` forever. Its natural identity is
   `(resolved parentId, resolved ref)`.
2. **`MetaData`'s identity is `(navigationId, language)`** — and `navigationId` is a ref. The
   ADR names this pair as class-A identity without noting that it only exists *after*
   resolution through the id map.

**Resolution:** an `#[ImportIdentity]` rule may name `#[ImportRef]` fields; they contribute
the **resolved identity of the target**, exactly as the hash already does. Navigation gains a
third fallback `['parentId', 'ref']`. This also forces what the ADR states only as
entity-*file* order: identity classification of a dependent entity cannot start before its
referenced entity's plan (including manual assignments) is settled — the plan is computed in
dependency order, per record, not just applied in it.

### IMP-R003 — the outcome table cannot produce the `unclear` the ADR promises

The table says: identity match → skipped/changed; no match → **new**; not determinable →
unclear. Now run the ADR's own example through it: the framework renames
`login-user` → `backend-user`. The target's «Benutzer» entry has identity
`backend/system/login-user/list`; the shipped record has `backend/system/backend-user/list`.
Both identities are *fully determinable* and simply don't match → outcome **new** → the import
creates a second «Benutzer» and leaves the broken one — the exact duplicate the fourth state
exists to prevent. The ADR's Context promises `unclear` here; the decision mechanism never
gets there.

**Resolution:** a second pass over the `new` set — **near-match detection**: a `new` record
whose *(resolved parent identity, name, slot)* coincide with an existing target record that no
source record claimed is downgraded to `unclear` with that record as the suggested match.
Heuristic deliberately narrow (same parent + same name); anything fuzzier guesses. This pass
is also what catches renamed-target cases for entities without refs (alias path changes:
old path unclaimed + same navigationId → suggest).

### IMP-R004 — the installed base defeats key matching on first contact

The feature's motivating cases — «Service» (backup 1.1.0) and «Jobs» — were **worked around
by hand**: [`backup.md`](../topics/backup.md) and [`jobs.md`](../topics/jobs.md) instruct the
developer to create the entries in the backend UI. Those hand-created containers have
`key: null`. The shipped default carries `key: "service"`. First import on exactly these
projects: key doesn't match, 4-tuple empty (container), near-match (R003) fires on
*(slot, name)* → `unclear` — good — but the developer's manual assignment resolves only *this*
plan. The next import asks again, every time.

**Resolution: key adoption on manual assignment.** When the developer assigns an `unclear`
source record to an existing target record, and the source carries a `key` the target lacks,
the apply **writes the key onto the target record**. The one-time human judgement becomes the
durable identity; the second import matches silently. This is the single most important UX
decision in the feature — without it, class-C matching never converges.

### IMP-R005 — hash the normalized entity, never the raw JSON

Runtime files predate newer entity fields (`param` was added 2026-07-02; older
`navigation.json` records simply lack the key — `mapFromArray` defaults it to `''`).
Raw-JSON hashing would flag every legacy record as `changed`, key order and formatting would
add more noise, and `application_ld` is a nested structure whose JSON object-key order is
semantically irrelevant. **Resolution:** both sides are hashed as
`hydrate (mapFromArray) → mapToArray → strip id/sort_key/ref-fields → recursively
key-sort → hash`. One normalization path, shared with the validator input.

### IMP-R006 — a fifth outcome: `blocked`

An alias whose navigation is `unclear`, or whose navigation the developer declined, cannot be
applied: its `navigationId` has no entry in the id map. The four outcomes have no word for
this. **Resolution:** outcome `blocked (dependency)` — shown with the blocking record. And
because manual assignments *unblock* downstream records, the plan is **recomputed after every
assignment/decision** — the preview is iterative, not a one-shot report. (Cheap: plan
computation is in-memory over two arrays.)

### IMP-R007 — ordering inside one file: topological, refs patched late

Cross-file order (Navigation → Alias → MetaData) is in the ADR. Missing: **within**
`navigation.json`, a new child can precede its new parent in the file, and a ref may point
forward (id 18 refs its own parent). Apply order per entity: topological over `parentId`;
`ref` values that point to not-yet-applied records are patched in a second phase, before
validation of the ref entry (validateRef requires the target to exist).

### IMP-R008 — target-side assignment for `id` and `sort_key` of new records

Excluded from the hash, but unspecified for insert. `id`: assigned by the target's
`EntityManager` like any backend add. `sort_key`: next free key in the **resolved** target
sibling group (`TreeService::nextSortKey`) — a new «Jobs» lands at the bottom of the
installation's «Service» section, wherever that sits. Source values are never copied.

### IMP-R009 — validators are not the whole write path for every entity

For Navigation/Alias/MetaData, `validate → repo save` (with `invalidatesCache`) is the
complete write path — v1 is safe. But `Folder` is not: creating a folder materializes a
physical directory (R4, `DocumentService`/`FolderService` own slug paths and `rootFolder()`
semantics). An import that inserts a Folder record via repo+validator produces a folder with
no directory. **Resolution (decided):** `folders.json` is **out of scope entirely** — blobs are
never seeded, a base folder structure is created in the DMS backend itself, and
`DocumentService::rootFolder($key)` already self-heals missing module roots at runtime. The
per-entity apply hook (domain service instead of raw repo) is noted as the mechanism should a
concrete need ever appear; it is not built in v1.

### IMP-R010 — declined proposals recur forever (accepted, but say it)

No ledger means no memory of "declined": the customer renamed «E-Mail» → «EMail», every
future import proposes the revert again. This is the accepted price of "a human beats a
ledger" and is fine while imports are rare (framework updates). If it ever grates, a
per-record dismissal flag stored **target-side** (not a seeds ledger — it records the
developer's decision about their own record) can be added without touching the model. Not v1.

### IMP-R011 — staleness guard between plan and apply

The plan is computed, the tab stays open, a colleague reorders the navigation, apply writes
decisions against a world that moved. **Resolution:** the plan carries a fingerprint (hash of
each involved runtime file at plan time); apply verifies and on mismatch recomputes instead
of writing. Same optimistic pattern as the entity-CSRF screens, one hash per file.

### IMP-R012 — scope over the other seeded families

| Family | Verdict |
|---|---|
| `navigation.json` | in scope — needs `key` (NAV-KEY-001) + R001/R002/R003 |
| `navigation_aliases.json` | in scope — `path` is a true natural key; needs R006 (blocked) |
| `metadata.json` | in scope — identity `(navigationId, language)` via R002 |
| i18n `de.json` / `fr.json` / `route-slugs.fr.json` | **not this mechanism** — flat `key → value` maps, no entities, no ArrayMappable. Identity is trivially the catalog key. A missing-key report + adopt belongs in `TranslationCatalog` (the backend editor already owns these files) as a small separate feature — forcing them through entity import would mean inventing fake entities |
| `content/*.{lang}.json` | **out of scope** — per-page starter content, file-level seed-once is correct; importing framework content into a customer's edited pages is undesirable by definition |
| DMS `folders.json` | **out of scope** (decided) — identity would be fine (`key`, class B), but R009 side effects + no current need: blobs are never seeded, base structures are created in the DMS backend, module roots self-heal via `rootFolder()` |

### IMP-R013 — source selection and upload hygiene

The backend screen offers two sources: **(a)** shipped defaults, discovered by walking every
installed framework package's `data/**/*.default.json` (the same walk INST-SEED-001 gives the
installer — build it once, share it), and **(b)** an uploaded JSON file. For (b): the target
entity type is chosen in the UI from a whitelist — never inferred from file content; size cap;
"schema check" = hydrate through `mapFromArray` + `#[Clean]` and reject on structural
mismatch, reported per record in the plan. `superUser`-only, entity-CSRF, and the upload is
parsed — never stored.

## round 2 — foreign sources (wdv-622 SQL dumps) and the live projects

Second pass, driven by two inputs: (a) what actually lies in the two running installations,
(b) the migration requirement — the importer is a **service that reads data in**, including
old-framework data (wdv-6.2.2 Fakturierung: order/customer/invoice tables) delivered as a SQL
dump on disk. Verdict: **the core model (identity × hash × plan) survives unchanged — but three
seams must be designed into the core NOW** (source adapter, mapping contract, natural-key ref
resolution), or the wdv case forces a rewrite later. One round-1 rule (R013 "never stored") does
not survive contact with large sources and is amended.

### IMP-R014 — live-project evidence: ids collide, keys are absent, update lag is real

`zihlundsee` (28 nav entries): local ids **25 = «Wohnen», 26 = «Lage», 28 = «Gut zu wissen»** —
the shipped default uses **25 = «Service», 26 = «Backup», 28 = «Jobs»**. Same numeric ids,
completely different records; an id-based match would pair «Jobs» with «Gut zu wissen». `axo3`
already carries «Drive» (23) and «Service» (25), zihlundsee has neither — two installations, two
different lag states against the same defaults. Neither file has a `key` field. This is the
installed base Decision 3 (id never identity), NAV-KEY-001 and R004 (key adoption) were designed
for — now confirmed with real data, not hypotheticals.

### IMP-R015 — the core must be source-agnostic: the reader seam

The plan/apply core never touches a raw format. An `ImportSource` (reader) yields **normalized
record arrays per target entity type**; everything downstream — identity, hash, plan, id map,
validators — is identical for every source. Readers: (a) native entity JSON
(`*.default.json` / export from another z77 project), (b) uploaded JSON, (c) staged files on the
server, including the SQL-dump reader. v1 ships (a)+(b); the seam — one interface between
"where records come from" and "what happens to them" — exists from the first commit, because it
dictates the core's signatures.

### IMP-R016 — foreign schemas need a mapping layer, and mappings are project code

wdv order tables do not look like z77 entities: column names differ, values need transforms
(date formats, enums, encodings, amounts), FKs point at foreign tables. That is a
`ImportMapping` per source schema: column→field map + transforms + FK declarations, feeding the
normalized records of R015. **Mappings live in the project's `override/` tree (CE principle,
Key Rule 1)** — a wdv-Fakturierung map is client-migration code, not framework. The framework
ships the contract and generic transform helpers only.

### IMP-R017 — ref resolution needs a second mode: by natural key

The per-run `source id → target id` map only resolves refs whose targets are **in the same
run**. A migration is multi-run in practice: addresses first, invoices weeks later. An order
referencing wdv customer 4711 must then resolve against the **already existing** target record
by declared identity (email / customer number) — "resolve by target-type identity" as the
declared alternative to "resolve via source-id map", per ref field, in the mapping. Without it,
every multi-stage migration dead-ends. (In-run resolution stays the default; the id map is
unchanged.)

### IMP-R018 — SQL-dump reader: restricted grammar, declared encoding

Not a SQL parser — a **mysqldump-INSERT reader**: `INSERT INTO \`t\` VALUES (…),(…);`, the
regular subset mysqldump emits (quoted strings, escapes, NULL, multi-row). Anything else in the
file (DDL, comments, SET) is skipped. The trap is **encoding**: wdv-era databases are latin1 or
mixed; the mapping declares the source encoding and the reader converts to UTF-8 — the same
corruption class the framework already documents as DATA-JSON-001 (`Ü` → `Ãœ`), now on the
inbound side.

### IMP-R019 — scale changes the plan UX and the apply path

28 navigation entries → per-record decisions. 10'000 invoice rows → impossible as a click list.
Three consequences: **(a)** the plan aggregates by outcome — `new` is bulk-acceptable as a
group; only `changed` / `unclear` demand individual attention (they are few by nature);
**(b)** the plan is **persisted**, and apply can run as an ADR-031 **job** — a bulk apply in a
web request is a timeout; **(c)** staging + persisted plans live in a transient area excluded
from backup archives, the BACKUP-JOBS-001 precedent (a restore must not resurrect a half-applied
import).

### IMP-R020 — R013 amended: sources are staged snapshots, not "never stored"

Round 1 said uploads are "parsed — never stored". That cannot hold: a SQL dump is too big to
re-upload per request, and a job-based apply (R019) needs the source at run time, after the
request is gone. Replaced by: **every source becomes a timestamped snapshot in a fixed,
framework-owned staging directory** — content hash, index, lock; exactly the pattern the axo3
propbase pipeline already proves in production (`raw/` + `index.json` + `locks/import-1.lock`).
Dumps are **placed** into that directory by the developer (no user-supplied paths anywhere —
the screen lists the inbox, never takes a path); small uploads are staged on arrival. Snapshots
are deleted on apply/discard. The rest of R013 stands: type chosen from a whitelist, hydrate +
`#[Clean]` as the schema check, `superUser`-only.

### IMP-R021 — sync vs import: the service must not become propbase

axo3's propbase pipeline is a **sync**: external source of truth, repeatable, full replace
(raw → built → atomic `current` switch). The ADR-032 import is **adoption**: after apply, the
target is the source of truth and the source is dead. A re-imported wdv dump is a *new
adoption plan* (mostly `skipped`), never a mirror. The service therefore never grows
"replace-all" or "delete what the source lacks" semantics — that would quietly turn every
import source into a master. Propbase stays its own mechanism; the two share only the staging
pattern.

### round-2 scope note

Order entities do not exist in z77 yet — the wdv case constrains the **architecture** (R015,
R016, R017 designed into the core now), not the v1 target scope, which stays Navigation /
NavigationAlias / MetaData. First realistic bulk target after that: member `accounts.json`
(both live projects run the member module; identity = `email`, class A). The SQL-dump reader
and the mapping contract implementation are v2, built when the first real migration is due.

## consequences for the build plan

Ordered; each step is independently shippable:

1. **NAV-KEY-001** — `Navigation::key` (entity, validator uniqueness among non-null,
   server-controlled, read-only in the form, defaults get `service`/`drive`/`webseiten`/
   `stammdaten`/…). No import code yet — the field stands alone.
2. **INST-SEED-001** — `writeDataFiles()` walks all installed framework packages; extract the
   package-data walk so step 6a reuses it. Fixes the never-deployed
   `module-dms/data/documents/folders.default.json` on fresh installs.
3. **ImportService core** (no UI): the **`ImportSource` reader seam first** (R015 — core
   signatures take normalized records, never files); descriptors (`#[ImportIdentity]` with
   ref-capable ordered rules, `#[ImportRef]` with the two resolution modes — source-id map |
   target-identity, R017), bijective matching (R001), near-match pass (R003), normalized hash
   (R005), plan computation in dependency order with outcomes
   `skipped | changed | new | unclear | blocked` (R006), id map, topo apply + ref patching
   (R007), target-side id/sortKey (R008), writes through validators.
4. **Plan → apply loop**: **persisted plans + snapshot staging** (R019/R020: staging dir with
   hash/index/lock, excluded from backup archives), iterative recompute on assignment (R006),
   key adoption on manual assignment (R004), staleness fingerprint (R011), apply runnable as an
   ADR-031 job for bulk sets (R019).
5. **Backend screen**: plan grouped by outcome with **bulk-accept for `new`** (R019),
   per-record decision for `changed` (defaults to No) with diff display, assignment picker for
   `unclear`, blocked-by display; sources = shipped defaults, upload, staging inbox (no
   user-supplied paths, R020).
6. **Sources v1**: (a) shipped-defaults discovery via the shared package walk, (b) upload +
   inbox with the R020 staging rules. **v2 (first real migration):** the `ImportMapping`
   contract (project-level `override/` code, R016) + the mysqldump-INSERT reader with declared
   source encoding (R018).
7. **Docs**: `docs/topics/import.md` written with the code. (The ADR-032 amendments — round 1:
   R001, R002, R003, R004, R006, scope; round 2: R015–R021 — are done, 2026-08-08.)

Separate, independent of the import build: **i18n missing-key adoption in `TranslationCatalog`**
(decision 3) — tracked in [`../topics/translation.md`](../topics/translation.md).

## decisions (taken 2026-08-08)

1. **Key adoption on manual assignment (R004)** — **yes.** Apply writes the source `key` onto
   the manually assigned target record; class-C matching converges.
2. **Near-match heuristic (R003)** — **narrow.** `(resolved parent, name, slot)`, unclaimed
   targets only. Widening later is cheap, un-guessing a wrong match is not.
3. **i18n (R012)** — **TranslationCatalog feature**, not the entity import. The backend editor
   already owns the catalog files; identity is trivially the key.
4. **DMS `folders.json` (R009/R012)** — **out of scope entirely** (developer: base structures
   are created in the DMS itself; blobs are never seeded; no current need). Not "deferred" —
   revisited only if a concrete need appears.

## round-2 decisions (taken 2026-08-08)

5. **Staging inbox (R020)** — **yes.** Sources become snapshots in a fixed, framework-owned
   staging directory (hash + index + lock, propbase pattern), transient and excluded from
   backup archives (BACKUP-JOBS-001 precedent), deleted on apply/discard. The screen lists the
   inbox — never accepts a path. Supersedes round-1 R013's "never stored".
6. **Bulk + job (R019)** — **yes, both.** Plans are persisted; apply can run as an ADR-031 job;
   the plan screen groups by outcome with bulk-accept for `new`, individual decisions for
   `changed` / `unclear`. Small plans keep applying in-request.
7. **Mappings are project code (R016)** — **yes.** `ImportMapping` implementations live under
   the project's `override/` tree (CE principle); the framework ships the contract + generic
   transform helpers only.
8. **SQL-dump reader is v2 (R018)** — **yes.** v1 lays the seams (`ImportSource` interface,
   natural-key ref resolution, staging); the restricted mysqldump-INSERT reader (declared
   source encoding, latin1→UTF-8) is built when the first real migration is due.
