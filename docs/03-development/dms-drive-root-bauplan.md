# DMS drive root + SUPER_USER governance — requirement review & rebuild plan

**Date:** 2026-07-03
**Status:** BUILT (2026-07-03) — D1–D5 decided with the user (all per recommendation; D5 =
delegation below partitions allowed), U1–U7 implemented; decision record:
[ADR-021](../02-decisions/adr-021-dms-drive-root-and-super-user-governance.md); as-built
summary: [`../topics/documents.md`](../topics/documents.md) `## pending`
**Relates to:** [ADR-017](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md),
[ADR-020](../02-decisions/adr-020-dms-scope-by-root-folder-not-area-label.md),
[`../topics/documents.md`](../topics/documents.md)

---

## 1. Requirements (as stated 2026-07-03)

- **R1 — single drive root.** The folder structure MUST have exactly one root ("drive") — the
  topmost entry of the DMS management hierarchy.
- **R2 — root identity + default seed.** The root has `ownerId = null` (= owned by z77 core)
  and `parentId = null`. If it is missing, the system creates it. Additionally a
  `folders.default.json` seed containing the root ships with the framework (currently missing;
  stated location: `core/data/documents`).
- **R3 — SUPER_USER-only grants at the root.** Only users with `AuthRole::SUPER_USER` may
  assign permissions there.
- **R4 — installer provisions SUPER_USER.** The first install creates a SUPER_USER account,
  not an `admin` account.
- **R5 — SUPER_USER manages partitions.** The SUPER_USER creates/mutates the necessary
  subfolders (partitions) and grants access to them.
- **R6 — config-driven module target + unique `key`.** A config controls into which folder a
  module (e.g. a future `financial`) writes. A module may create subfolders below its target
  but may NEVER write above it. The folder `key` (added 2026-07-02) addresses a folder
  directly and MUST be unique.

## 2. Findings — where docs, ADRs and code disagree

### F1 — R1 contradicts ADR-020 Rule 1 (no single root)

ADR-020 explicitly decided: *"Ein Baum; die obersten Ordner sind die Partitionen"* — the
top-level folders (`parent_id = null`) ARE the partitions; there is no node above them. The
whole codebase implements that: `FolderRepository::findRoots()` = `parent_id = null`
(`FolderRepository.php:25`), `FolderService::add/move` treat `parentId === null` as
"create a partition" (`FolderService.php:61,110`), `resolve()` walks from the tree top,
materialization keys on the root slug.

R1 puts ONE real drive-root folder above the partitions. This is an ADR revision — but NOT a
reversal of ADR-020's philosophy. Its core motivations survive intact:

- "real entity instead of a non-entity special case" — the drive root REPLACES the last
  remaining virtual thing (the invisible "forest top") with a real, ownable, ACL-capable folder;
- the ACL ancestor walk gains a true anchor: a grant on the drive root now reaches everything
  (useful for a global read/manage grant), and the awkward `requireAdmin`-on-`parentId === null`
  special case (`FolderService.php:61,113`) becomes a normal gate on a real resource;
- `key` addressing, slug URLs, ACL machine: unchanged in principle.

→ needs an ADR (proposal: **ADR-021 — DMS drive root and SUPER_USER governance**, plus a
revision note in ADR-020, same pattern as ADR-017's).

### F2 — docs say "Super-User", code gates at ADMIN (pre-existing inconsistency)

ADR-020 Rule 3 and the `FolderService` docblock say partition creation is
**"nur Super-User"** — but the actual gate is `Authz::requireAdmin()` →
`Principal::isAdmin()`, which passes for every role at hierarchy level ≥ `ADMIN` (80)
(`Principal.php:29-38`, `AuthRole.php:13-23`). A plain `admin` passes everywhere today; the
ACL bypass in `AclService` (`AclService.php:77`) is also ADMIN-level. Docs and code disagree
TODAY; R3/R5 resolve the ambiguity in favour of a real SUPER_USER distinction.

### F3 — installer and setup provision `admin`, contradicting R4 (and F2's doc language)

- `Install.php:775-786` — interactive provisioning writes `'roles' => ['admin']`
  (`ADMIN_USERNAME = 'admin'`, `Install.php:31`).
- `SetupController.php:86` — the token-gated non-interactive path sets `[AuthRole::ADMIN]`.
- `skeleton/data/framework/auth/loginUsers.json` — the dev user holds `["admin"]`.

### F4 — no default folder seed exists; skeleton data drifted from the docs

No `folders.default.json` exists anywhere (`packages/kernel/core/data/` has routing/i18n/seo/content
defaults only; `module-dms` ships no `data/` at all). `skeleton/data/documents/folders.json`
is hand-seeded dev data: two roots (`front`, `pages`), all `key = null`, all `system = false`,
no drive root. Note: `documents.md` (ADR-020 rebuild entry) still describes the seed as
"root `Ablage` key=`backend`" — the live file no longer matches (harmless dev-data drift, but
it shows the absence of a canonical seed).

### F5 — `key` uniqueness is claimed but not enforced

`Folder.php:46` claims *"Unique among roots (enforced by `rootFolder()`)"* — but
`rootFolder()` only get-or-creates (`DocumentService.php:160-182`); nothing REJECTS a
duplicate key on save. `FolderRepository.php:17-19` (S3) openly documents that duplicates are
possible and resolved by smallest-id-wins. R6 demands unique → needs an explicit save-side
guard (a hard guarantee stays impossible on the File driver — no unique index, ARCH-A003 —
until the Doctrine milestone; the S3 deterministic fallback stays as the safety net).

### F6 — config-driven module target: compatible with ADR-020, but the write boundary does not exist

ADR-020 rejected *"Modul adressiert per Config statt Code-Key"* — BUT explicitly allowed
*"Config nur als optionales späteres Remapping"*. R6 IS exactly that remapping, so there is
no ADR conflict **as long as** the module's `key` stays a code constant (its identity) and the
config only remaps key → target folder.

What is genuinely missing is the **boundary**: module writes run on the trusted system path
(`saveGenerated` / `SaveService::save` are deliberately ungated, R-authz-1) — nothing today
prevents module code from writing to an arbitrary `folderId` outside its partition. R6's
"create subfolders below, never write above" is a new invariant the current API cannot
express and must enforce in the domain (same reasoning as R-authz-1: not in the caller).

### F7 — `ownerId = null` = system: already consistent, no change

`Folder::setOwnerId()` coerces non-positive ids to `null` (`Folder.php:93-96`) and
`rootFolder()` creates module roots with `ownerId = null` (`DocumentService.php:172`) —
"null = owned by z77 core / system" is already the implemented convention (ADR-017).

## 3. Decision points (recommendation first)

- **D1 — drive-root slug in public URLs?** **Recommendation: NO.** `/media/<partition-slug>/…`
  and `public/media/<partition-slug>/…` stay as they are; the drive root is an organizational
  + ACL anchor, not a path segment. A constant extra segment on every URL carries no
  information, and every existing materialized path would move. Consequence: `resolve()` and
  materialization start at the drive root's CHILDREN.
- **D2 — seed location: `core/data` vs `module-dms/data`.** The requirement states
  `core/data/documents`. The installer deploys `*.default.json` from ANY framework package's
  `data/` dir (`Install.php` `writeDataFiles`, see `installer.md`), and ADR-019 moved the whole
  DMS vertical into `module-dms` — core knows nothing about DMS entities.
  **Recommendation: `packages/module-dms/data/documents/folders.default.json`** — identical
  install result, no core→module coupling. → to discuss (deviating from the stated location).
- **D3 — what does ADMIN mean after the split?** Today `isAdmin()` lumps ADMIN + SUPER_USER
  into one full ACL bypass. Options:
  (a) **bypass becomes SUPER_USER-only; ADMIN gets rights exclusively via ACL grants**
  (recommended — matches R3/R5: the SUPER_USER is the DMS governor, an admin is a managed
  area principal); (b) ADMIN keeps the bypass except for root-level acts (smaller change, but
  keeps two "super" roles and R3 stays half-true). Consequence of (a): existing `admin` users
  lose Drive access until granted (or re-provisioned) — intended, and consistent with R4.
- **D4 — drive root `deliveryMode`.** MUST NOT be `sealed`: the structural sealed cap
  (`assertOpenable`, `DocumentService.php:779-782`) would forbid any `public` partition
  forever. **Recommendation: `null`** — the existing inheritance fallback `'protected'`
  (`DocumentService.php:482`) remains the global default, unchanged behaviour.
- **D5 — scope of "only SUPER_USER grants THERE" (R3).** **Recommendation:** SUPER_USER-only
  for (i) grants/revokes ON the drive root, (ii) creating/moving/deleting partitions (= direct
  children of the root), and (iii) grants ON partitions. BELOW a partition, normal ACL applies:
  a `manage` grant given by the SUPER_USER lets the area admin delegate further — exactly
  ADR-020's "existence vs access" split (R5).

## 4. Rebuild plan (each phase leaves the system runnable)

### U1 — role split + provisioning (no DMS structure change yet)

1. `Principal`: add `isSuperUser()` (level ≥ `SUPER_USER`); implement D3 —
   `AclService` bypass (`AclService.php:77`) switches to the decided level.
2. `Authz`: add `requireSuperUser()`; keep `requireAdmin()` only if D3(b), else retire it.
   Re-point the partition-level gates (`FolderService.php:62,113`, `UploadService.php:147`).
3. Grant/revoke gate per D5: `DocumentService::grant/revoke` (`DocumentService.php:799,839`)
   additionally require SUPER_USER when the resource is the drive root or a partition.
4. Installer: `Install.php:775-786` provisions `['superUser']` (decide the username — keep
   `admin` as a name or rename to `super`); `SetupController.php:86` likewise;
   re-provision the skeleton dev user.
5. Docs: `login.md`, `security.md`, `installer.md`.

### U2 — the drive root itself

1. Reserved key constant (e.g. `DocumentService::DRIVE_KEY = 'drive'`); reject it in
   `rootFolder()` and forbid a second `parentId = null` folder (save guard in
   `FolderService`/`SaveService`).
2. Seed `folders.default.json` (per D2): one record — `id 1`, `name`/`slug` (e.g. `drive`),
   `key = 'drive'`, `system = true`, `ownerId = null`, `parentId = null`, `active = true`,
   `deliveryMode = null` (D4).
3. `ensureDriveRoot()` get-or-create (same mechanics as `rootFolder()`, S3 re-resolve) —
   called lazily wherever the root is first needed; the seed makes it present from install,
   the lazy create covers R2's "if missing, the system creates it".
4. Rework root semantics — partitions become the CHILDREN of the drive root:
   `FolderRepository::findRoots/findRootByKey/findRootBySlug` (`FolderRepository.php:23,35,53`),
   `DocumentService::rootFolder` creates under the drive root, the `parentId === null` branches
   in `FolderService.php:61,110,171`, `DocumentService.php:142`, `SaveService.php:465` switch to
   `parentId === driveRootId`.

### U3 — delivery + materialization (per D1)

`resolve()` walks from the drive root's children (first URL segment = partition slug —
externally unchanged); `rebuildMaterialization()` unchanged paths; verify the effective-mode
chain and `assertOpenable` through the new root level (D4). Curl smoke: public 200,
protected/sealed/miss 404 — identical to the ADR-020 verification set.

### U4 — Drive UI

Tree top/breadcrumb over the root's children (forest view stays for non-super users — they see
the partitions they may read, as today); show the drive root as a real top node only for
SUPER_USER (manage/grant entry point). `mountRoot` `?key=` resolution
(`DriveControllerTrait.php:91`) keeps its semantics (partition keys/slugs).

### U5 — module target config + write boundary (R6)

1. Config (the ADR-020 "optional remapping"): `dmsConfig` key, e.g.
   `'moduleFolders' => ['<module-key>' => '<folder-key>']`; default (no entry) = the module's
   own key. The module's key stays a CODE CONSTANT (S2 — never request input).
2. Domain-enforced boundary: a scoped module handle (e.g.
   `DocumentService::forModule(string $moduleKey)`) that resolves the configured target folder
   once and asserts EVERY write (`saveGenerated`, folder create, move target) lies inside that
   subtree — creating subfolders below is allowed, writing above/outside throws. Enforced in
   the domain, not the caller (same rationale as R-authz-1).
3. Future `financial` etc. only declare their key + optional config entry.

### U6 — `key` uniqueness (R6)

Save-side duplicate check in the key-setting paths (`rootFolder()`, `ensureDriveRoot()`):
throw on an existing different folder with the same key; keep the S3 smallest-id fallback as
the read-side safety net (File driver has no unique index — the hard guarantee arrives with
the Doctrine milestone). Keys stay on partition roots (+ the drive root) only — no UI setter
(S2). Extend to arbitrary subfolders only if a module ever needs a subfolder target (then:
unique tree-wide).

### U7 — documentation closure

ADR-021 (drive root + SUPER_USER governance, decisions D1–D5); revision notes in ADR-020
(Rules 1/3 superseded in part) and ADR-017 (admin bypass → super-user bypass); update
`documents.md` (`## mental model`, `## rules`, `## pending`), `installer.md`, `login.md`,
`security.md`; `npm run docs:check` green.

## 5. Risks / notes

- **D3(a) is a behaviour break for existing `admin` users** — intended, but the skeleton dev
  login must be re-provisioned in the same step or the Drive becomes invisible.
- File-driver TOCTOU (S3/ARCH-A003) still limits key/singleton guarantees to best-effort +
  deterministic resolution; unchanged risk level.
- Skeleton DMS data is ephemeral (ADR-020 migration decision) — re-seed, no migration.
- The drive root must never be `sealed` (D4) and never inactive (`active` gates the whole
  tree via `isActiveChain`) — the mode/active mutations on the root should be locked or
  validated accordingly (add to U2 guard list).
