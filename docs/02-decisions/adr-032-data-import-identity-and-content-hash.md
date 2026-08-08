# ADR-032 — Data Import: Declared Identity, Content Hash, Developer Decides

**Status:** `APPROVED`
**Date:** 2026-08-08
**Amended:** 2026-08-08 — round 1: bijective matching, ref-capable identity rules, near-match
pass, `blocked` outcome, key adoption. Round 2 (foreign sources — wdv-6.2.2 SQL dumps, live-project
evidence): source-agnostic reader seam, mappings as project code, natural-key ref resolution,
snapshot staging (supersedes "never stored"), bulk/job apply, import-is-not-sync. Both from the
pre-build review [`review-import-adr-032.md`](../03-development/review-import-adr-032.md)
(developer-confirmed).

---

## Context

Seeding is **file-level** seed-once. `Install::writeDataFile()` copies a `*.default.json` to its
runtime target only when that target is absent. Once `data/framework/routing/navigation.json`
exists, every record added to the shipped default afterwards is invisible to that installation —
forever.

That is not a theoretical gap. It has already cost twice:

- **Backup** (1.1.0) — the «Service» topbar section and its two entries do not appear in existing
  projects; [`backup.md`](../topics/backup.md) tells the developer to recreate them by hand in the
  backend navigation UI.
- **Jobs** (2026-08) — same note again in [`jobs.md`](../topics/jobs.md), same manual workaround,
  node id 28.

Every framework feature that needs a backend entry will repeat it. `INST-CONFIG-001` already names
the missing class — each installer target is `regenerate-always`, `seed-once`, or **`merge`** — and
`merge` has never been built. It blocks publication.

A second, unrelated need points at the same mechanism: importing bulk data into a running
installation — an address master from a previous system, a navigation tree taken from another
project. Same operation: read foreign records, insert what is missing, leave the rest alone.

**Why a naive diff does not solve it.** A record present in the source and absent in the target has
two possible causes, and the file cannot tell them apart: it is new from the framework, or the
developer deleted it deliberately. An importer that assumes the first fights the backend UI — every
update resurrects what was removed on purpose.

## Decision

**1. An `ImportService`, not a trait on the entity.** Import needs a repository, a validator and a
per-run id map. Entities in this framework are plain data objects (`ArrayMappable`, attributes, no
infrastructure). An `Importable` trait would drag all three into the entity. The service owns the
work; the entity only *describes* itself.

**2. The description lives in attributes**, in the style already used by `#[Entity]` and
`#[Clean]` — declarative, no code per entity:

```php
#[Entity('file', 'framework/routing/navigation.json', invalidatesCache: true)]
#[ImportIdentity(['key'], ['module', 'group', 'controller', 'action'], ['parentId', 'ref'])]
class Navigation
{
    #[ImportRef(Navigation::class)] private ?int $parentId = null;
    #[ImportRef(Navigation::class)] private ?int $ref      = null;
}
```

`#[ImportIdentity]` takes **ordered fallbacks**: the first rule that yields a complete value wins.
Yields none → the record has no identity (class C below). A rule MAY name `#[ImportRef]` fields —
they contribute the **resolved identity of the target**, exactly as the hash does (IMP-R002). Two
consumers require this, so it is core, not an extra: ref entries (no key, no 4-tuple — identity is
`(resolved parent, resolved ref)`) and `MetaData` (identity `(resolved navigationId, language)`).
Consequence: the plan itself is computed in dependency order — a dependent entity's identity cannot
be classified before its referenced entity's plan (including manual assignments) is settled.

A rule only **matches** when its value is unique **on both sides** — in the source file AND in the
target data (bijective, IMP-R001). The framework itself allows duplicate 4-tuples (ADR-015:
identity is the alias path, not the 4-tuple), so a real project export can carry several entries
sharing one; any ambiguity on either side sends all involved records to `unclear` instead of
guessing.

**3. The numeric `id` is never identity.** It is a link target *inside the import file* and nothing
else. At apply time the service builds a per-entity map `source id → target id` and rewrites every
`#[ImportRef]` field through it. Two installations built from the same defaults have different ids
for the same thing; anything that compares ids across installations is wrong by construction.

**4. Identity comes in three classes.**

| Class | Where the key comes from | Example |
|---|---|---|
| **A — natural** | already in the record, unique by nature | `NavigationAlias.path`, `MetaData: navigationId + language`, an address `email` |
| **B — assigned** | the framework sets a stable key because no natural one exists | `Folder.key = 'drive'` — server-controlled, code constant, unique among roots (ADR-020) |
| **C — none** | human-created record, nothing stable to match on | a container the customer added in the backend |

Class B is not new — `Folder.key` is exactly this pattern. Navigation lacks it, which is why its
containers («Service», «Drive», «Webseiten») cannot be matched at all today.

**5. A content hash is the second dimension.** It answers "is this record materially identical",
independent of the field list — a new field on the entity flows in automatically. It is computed
over the **content fields**: everything except `id`, except `sort_key`, and with every
`#[ImportRef]` field contributing **the resolved identity of its target**, never a local number.

```text
source: hash(name, module, group, controller, action, slot, param, active, parent: "service")
target: hash(name, module, group, controller, action, slot, param, active, parent: "service")
```

Both sides say `parent: "service"` even where the container is id 25 in one installation and id 33
in the other. Two different records that happen to carry identical content — a divider `—` under
`service` and another under `webseiten` — still differ, because the resolved parent differs.

`sort_key` is excluded on purpose: it is server-managed and changes on every drag & drop, so
including it would mark every sibling of a reordered entry as different — noise with no information.

**6. Five outcomes, not two.**

| Identity | Hash | Outcome |
|---|---|---|
| matches | equal | **skipped** — identical, already present |
| matches | differs | **changed** — proposed with a field diff (`name: E-Mail → EMail`) |
| no match | — | **new** — will be created |
| not determinable (class C) | — | **unclear** — similarity hint, manual assignment |
| ref target unresolved | — | **blocked** — its referenced record is `unclear` or was declined |

Two states cannot express "same record, but you renamed it". That case — and the framework's own
renames, e.g. the entry that still points at the removed `login-user` controller — is precisely
where a purely additive importer silently creates a duplicate. The fourth state is what keeps the
uncertainty visible instead of guessing; the fifth (IMP-R006) is what keeps a dependent record
(an alias whose navigation has no id-map entry) from being applied against a guess.

**`new` is provisional until a near-match pass confirms it (IMP-R003).** Identity-no-match alone
cannot distinguish "genuinely new" from "same record, framework changed the identity fields" — the
rename case would classify as `new` and create the duplicate anyway. Second pass over the `new`
set: a record whose *(resolved parent identity, name, slot)* coincide with an existing target
record that no source record claimed is downgraded to `unclear` with that record as the suggested
match. The heuristic is deliberately narrow — same parent AND same name; anything fuzzier guesses.
Widening later is cheap, un-guessing a wrong match is not.

**7. The service computes a plan; a human applies it.** The plan is rendered in the backend as a
per-record list with outcome and reason. Nothing is written before the developer confirms. This is
what replaces a ledger of applied seeds: a person decides whether an absent record is new or
deliberately gone, so no applied-state has to be tracked and kept in sync. The plan is iterative:
a manual assignment can unblock dependent records, so it is recomputed after every decision.

**A manual assignment adopts the key (IMP-R004).** When the developer assigns an `unclear` source
record to an existing target record, and the source carries a `key` the target lacks, applying the
plan writes that key onto the target record. This is what makes class-C matching converge: the
installed base created «Service» by hand (the backup.md / jobs.md workaround, `key: null`) — with
adoption the one-time human judgement becomes the durable identity and the next import matches
silently; without it every import asks the same questions again.

**8. `changed` may be adopted, per record, defaulting to No.** The same shape as ADR-026's asset
deploy: shown, never pre-selected, never bulk-applied by default. Refusing updates entirely would
leave the framework unable to correct its own records (a renamed controller stays broken); applying
them silently would overwrite the customer's adjustments. Opt-in per record is the only option that
serves both, and the diff makes the trade-off visible at the moment of decision.

**9. Every write goes through the existing validator.** `NavigationValidator`, `NavigationAliasValidator`
and friends. An import that writes raw JSON is a backdoor around every invariant the framework
enforces — slot XOR parentId, alias uniqueness, ref rules. A rejected record becomes a plan entry
with the validation message, not a broken navigation tree.

**10. The installer never imports.** It may report that vendor ships newer defaults; the import
itself is a deliberate action in the backend. Consistent with ADR-024/025: the installer reports,
the developer decides. Import is `superUser`-only; every source is validated by hydration
(`mapFromArray` + `#[Clean]`) before a plan is computed, and the target entity type is chosen
from a whitelist in the UI — never inferred from file content.

**11. The core is source-agnostic — the reader seam (IMP-R015).** An `ImportSource` (reader)
yields normalized record arrays per target entity type; identity, hash, plan, id map and apply
never touch a raw format. Readers: native entity JSON (shipped defaults, exports from another
z77 project), uploaded JSON, staged server-side files — including, in v2, a **restricted
mysqldump-INSERT reader** (no SQL parser: only `INSERT INTO … VALUES` rows, DDL/comments
skipped, source encoding declared per mapping and converted to UTF-8 — wdv-era databases are
latin1, the inbound twin of DATA-JSON-001). This is what makes the service carry the migration
case: wdv-6.2.2 data (Fakturierung / order tables) dumped from the old database and read in.

**12. Mappings for foreign schemas are project code (IMP-R016).** Column→field maps, value
transforms and FK declarations for a foreign source implement an `ImportMapping` contract and
live under the project's `override/` tree (CE principle, Key Rule 1) — a wdv-Fakturierung map is
client-migration code. The framework ships the contract and generic transform helpers only.

**13. Refs resolve in two modes (IMP-R017).** Default: the per-run `source id → target id` map.
Declared alternative per ref field: **by target-type natural identity** — an order referencing
wdv customer 4711 resolves against the already existing member by customer number / email. This
is what keeps multi-run migrations working (addresses first, invoices weeks later).

**14. Sources are staged snapshots; bulk applies as a job (IMP-R019/R020).** Every source
becomes a timestamped snapshot in a fixed, framework-owned staging directory — content hash,
index, lock (the pattern the axo3 propbase pipeline proves in production); the screen lists this
inbox and never accepts a path. Staging and persisted plans are transient state, excluded from
backup archives (BACKUP-JOBS-001 precedent), deleted on apply/discard. Plans are persisted; the
plan screen groups by outcome (`new` bulk-acceptable; `changed`/`unclear` individual); apply MAY
run as an ADR-031 job — a bulk apply in a web request is a timeout. Small plans keep applying
in-request.

**15. Import is adoption, never sync (IMP-R021).** After apply, the target is the source of
truth and the source is dead; a re-imported dump yields a new plan (mostly `skipped`), never a
mirror. The service never grows "replace-all" or "delete what the source lacks" semantics. The
propbase pipeline (external source of truth, repeatable full replace) stays its own mechanism;
the two share only the staging pattern.

## Reasoning

- **Hash alone cannot work.** It carries project-local ids, it is flipped by `sort_key` noise, and
  above all it detects *difference*, never *sameness under change*. After two years of backend
  edits nearly every record hashes differently, and the one genuinely new entry drowns in the list.
- **Identity alone is not enough either.** It answers "same record?" but not "still identical?" —
  so a framework-side correction would be invisible. The two dimensions are complementary; each
  covers the other's blind spot.
- **Resolving beats comparing.** A reference must be resolved to what it points at, then compared
  as identity. Comparing reference *numbers* is wrong in both directions: include them and get
  false differences, drop them and get false matches. There is no hash formula that repairs this —
  it needs an identity rule underneath.
- **Declarative fits the codebase.** `#[Entity]`, `#[Clean]`, `#[Csrf]`, `#[Fetch]` are the
  established way an entity states facts about itself. A new module becomes importable by setting
  two attributes — no code, no registration, no importer per entity (Key Rule 8).
- **A human beats a ledger.** An applied-seeds file is state that can drift, be restored from a
  backup of another environment, or be lost. The judgement it encodes — "is this absence
  intentional?" — is the developer's anyway.
- **Uncertainty propagates downward, and that is correct.** A record whose parent has no identity
  cannot be hashed stably, so it becomes `unclear` too. That is honest, and it is the sharpest
  argument for giving framework-owned containers a `key`.

## Consequences

- **`Navigation` gains a `key` field** — server-controlled, `null` for everything created in the
  backend, a code constant for framework-owned entries (`service`, `drive`, `webseiten`). Unique
  among key-bearing entries, not editable in the edit form. This is a prerequisite: without it every
  container is class C, and everything below it is `unclear`.
- **Framework-owned seed records move to their module.** «Drive»/«Dokumente» (ids 23/24) and «Jobs»
  (id 28) currently sit in the kernel's `navigation.default.json` — module navigation in kernel data.
  Each package ships its own seeds and the installer collects across packages.
- **`writeDataFiles()` must scan every installed framework package.** It scans only
  `packages/kernel/core/data` today, so `packages/module-dms/data/documents/folders.default.json`
  never reaches a project at all. Pre-existing defect, surfaced by this ADR.
- **Multi-file imports need a declared entity order** (Navigation → NavigationAlias → MetaData), so
  a reference is only resolved after its target exists in the map.
- **No delta against "your version".** Without a ledger the plan compares the full shipped default
  against the installation, so the developer judges a complete list rather than "these three are
  new since 1.1.0". Accepted for the first iteration; a stable `seed_key` per shipped record can add
  the delta later without changing the model.
- **Bulk import is covered by the same service** — an address master is class A (`email`), no tree,
  no framework keys. That was the second motivation and needs no separate mechanism.
- **Scope (decided 2026-08-08): the import covers Navigation, NavigationAlias and MetaData.**
  The other seeded families are explicitly not it: **i18n catalogs** are flat `key → value` maps,
  not entities — missing-key adoption is a small feature of the `TranslationCatalog` backend
  editor, which already owns those files (IMP-R012). **Content documents** stay file-level
  seed-once — importing framework starter content into a customer's edited pages is undesirable by
  definition. **DMS `folders.json` is out of scope entirely** — blobs are never seeded, a base
  folder structure is created in the DMS backend itself, and module roots self-heal at runtime
  (`DocumentService::rootFolder($key)`); revisited only if a concrete need appears (IMP-R009).
- **`INST-CONFIG-001`'s `merge` class gets its answer:** data files stay seed-once at file level;
  record-level reconciliation is this manual, planned import. No installer target changes policy.
- **v1 builds the seams, v2 the migration tooling.** v1 ships the JSON readers over the reader
  interface, natural-key ref resolution, staging + persisted plans + job apply. The
  `ImportMapping` contract implementation and the mysqldump reader are built when the first real
  migration is due — no dead code before a real dump and real target entities (an order module)
  exist to test against.
- **The backup's `DATA_EXCLUDES` gains the import staging directory** when it is built —
  transient state must not ride a restore into another environment (BACKUP-JOBS-001).
- **A `docs/topics/import.md` is created with the implementation**, not before — a topic doc's file
  map must list files that exist.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Content hash as the sole identity | Carries local ids, flipped by `sort_key`, and cannot express "same record, renamed" — produces duplicates exactly in the interesting cases |
| Numeric `id` as identity | Ids are per installation; the same id means different records in two projects, and the same record has different ids |
| A ledger `data/framework/_seeds-applied.json` + automatic installer merge | State that can drift, be restored from the wrong environment, or be lost; and it encodes a judgement ("intentionally deleted?") that belongs to the developer |
| An `Importable` trait on the entity | Needs repository, validator and id map — infrastructure inside a plain data object, against the entity model of this framework |
| A hand-written importer per entity | Duplicated logic per module, drifts, and violates Key Rule 8 (module-agnostic building block) |
| Import writing records directly to storage | Bypasses every validator — a single malformed import breaks routing invariants the framework guarantees elsewhere |
| Adopting `changed` records automatically | Overwrites customer adjustments; a rename in the backend would be reverted on every import |
| Refusing `changed` entirely (INSERT-only) | Leaves the framework unable to correct its own records — an entry pointing at a removed controller stays broken forever |
| Module navigation declared in module config (like `navSlots`, ADR-022) | Removes the need to seed, but navigation entries must stay renameable, reorderable and deactivatable in the backend; the tree would become half config, half data |
| Installer imports automatically on `composer update` | Contradicts ADR-024/025 (installer reports, developer decides) and would apply decisions unattended, in CI, with no preview |
| Sources parsed in-request, never stored (round-1 rule) | Cannot hold for foreign sources: a SQL dump is too large to re-upload per request, and a job-based apply needs the snapshot after the request is gone — superseded by staged snapshots (Decision 14) |
| A general SQL parser for dump sources | The mysqldump INSERT subset is regular and sufficient; a full parser is a maintenance liability for a format nobody hand-writes |
| One service doing import AND propbase-style sync | Sync semantics (replace-all, source stays master) would quietly turn every import source into a master — the opposite of adoption; kept as two mechanisms sharing only the staging pattern |
