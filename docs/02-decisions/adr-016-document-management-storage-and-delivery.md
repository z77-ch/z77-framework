# ADR-016 — Document management: storage layout, scoping, visibility & delivery

**Status:** Superseded by ADR-017 (2026-06-17) — the access model (visibility flag + publicPath, admin-only management, `/media` via NavigationAlias) is replaced by ownership + ACL + `active` + structural `/media` via a reserved route. The **engine** decisions below (id-addressed `BlobStorage` layout B, eager GD derivatives + config image profiles, two-phase save, range/inline `FileResponse`, `DocumentKind` allowlist, `Mailer`) remain valid and are carried forward.
**Date:** 2026-06-15

---

## Context

The framework needs a document management foundation (DMS) for client-project modules:
upload files via the browser, save files from any module (including generated ones such
as invoice PDFs), organise them in a folder tree, classify them, and expose them to a
module as a service (show / delete / move / send). Design and options were worked out in
[`../03-development/dokumentenverwaltung-bauplan.md`](../03-development/dokumentenverwaltung-bauplan.md);
this ADR records the binding decisions (the plan's OPEN-1…7).

Constraints shaping the decisions:

- **Two separate worlds:** metadata (folder tree, document records) live in the
  persistence layer (`#[Entity]`); the bytes (blobs) live in a **new** `BlobStorage`,
  separate from the metadata driver. `FileStorage` (JSON-only) is never abused for bytes.
- **File-first, Doctrine-ready.** Expected volume (~3'000–10'000 records cumulative)
  crosses the File O(n) limit (`ARCH-A001`, ~5k) in the foreseeable future, so a Doctrine
  switch is a planned milestone, not a "maybe". Entities must be Doctrine-tauglich from
  day one (server-controlled fields, no File-driver quirks).
- The store holds **legally retention-bound** documents (CH: invoices/accounting often
  10 years) → longevity, auditability, retention enforcement matter.
- CLAUDE.md: keep framework code minimal, no unnecessary dependencies, **portability is a
  hard requirement** (Windows dev, PHP built-in server, shared hosting).

## Decision

### 1. Blob layout — ID-based (`data/blobs/<shard>/<documentId>.<ext>`)

The blob key is the document's auto-increment `id`. Logical move/rename is a
**metadata-only** operation; bytes are never moved on disk.

### 2. Scope (`area`) — framework-enforced

`Folder.area` partitions the top-level forest. A module registers ownership of an `area`
via a config flag — the exact same opt-in pattern as `viewArea`. The framework enforces
isolation between modules of one installation. A module needing several areas uses its
module key plus an optional sub-scope.

### 3. Document fields incl. soft-delete & retention

`Document` carries (server-controlled, no `#[Clean]`): `kind, mimeType, sizeBytes,
checksum (sha256), area, source, visibility, publicPath, retentionUntil, deletedAt`.
Plus an optional `meta` array for module-specific data (e.g. invoice number). There is
**no** `storageKey` field — the blob key is the document `id` itself (layout B), so a
separate key would only duplicate `id`; `BlobStorage` is addressed by `int $id`.

- **`deletedAt`** — soft-delete; retention-bound documents are never hard-deleted on a
  normal "delete".
- **`retentionUntil`** — legal retention deadline; any purge MUST respect it.
- **`checksum`** — carries the integrity that the ID-based layout does not build in.

### 4. Storage mode — Collection mode

`Document` uses collection mode (ADR-010), because only it yields the auto-increment `id`
used as the blob key (document mode has no `id` → would need a UUID). The whole-file
rewrite per upload is acceptable because the Doctrine switch is planned before the
critical volume.

### 5. Mail transport — in-house (Phase 6)

`Mailer` (interface + SMTP `Transport` + `Message` VO) is built in-house, not pulled as a
dependency. Proven in the predecessor WDV framework; honours "no unnecessary
dependencies". Built only in Phase 6.

### 6. Versioning — out of scope for v1

Replace = overwrite bytes (same `id`/blob key, new `checksum`/`updatedAt`). No version
history.

### 7. Visibility & delivery — metadata-driven, single physical store

Storage location and visibility are **separate concepts**. All bytes — private and public
— live only in `BlobStorage` outside `public/`. There is no second physical store for
public files; visibility is a metadata property (`visibility: private | public`, default
`private`). A public document additionally carries `publicPath` (string|null), a stable
URL key independent of the internal blob path.

Delivery is **always** through `DocumentService::serve()`:

- `GET /documents/{id}/download` — private: load → `deletedAt IS NULL` → authorize → deliver.
- `GET /media/{publicPath}` — public: resolve by `publicPath` → `visibility=public` +
  `deletedAt IS NULL` → deliver.

> **Superseded (2026-06-23, ADR-017):** the web-server delegation switch below was
> **rejected** — `X-Sendfile`/`X-Accel-Redirect`/LiteSpeed are not portable (cyon ships no
> `mod_xsendfile`) and the PHP range-stream is sufficient. Byte transfer is now **always**
> the portable PHP range-stream; `FileResponse` has no `delivery`/`internalPath` switch.
> The Range-support / portability reasoning below still holds for that single path.

**Transfer is portable.** When the web server can do it, `serve()` delegates the actual
byte transfer via `X-Accel-Redirect` (Nginx) / `X-Sendfile` (Apache) and PHP only does
auth / authorization / metadata resolution / logging — so large files (videos, archives)
get Range requests, seek/resume and efficient streaming for free. **When no web-server
delegation is available** (PHP built-in server, dev, shared hosting, Windows), `serve()`
falls back to a **PHP stream with Range support** (`Accept-Ranges` / `Content-Range`,
chunked). Delivery strategy is configuration, not hard-coded.

## Reasoning

- **ID-based layout wins on the move pattern, not on convenience.** The decisive argument
  was the **folder-reparent asymmetry**: the folder tool can move folders. Re-parenting a
  folder with 1'000 documents costs **zero** file operations under ID-based layout (only
  `parentId` changes), versus **1'000 real file moves** on a transactionless File driver
  under a path-mirrored layout (`ARCH-A003` half-states). Single-document moves are also
  rare for invoices/balances (filed once). The price of ID-based layout — disk not
  self-describing without the DB, no dedup, no built-in integrity — is accepted; the
  `checksum` field carries integrity, and a human-readable read-only export mirror remains
  possible later **without** a data-model change.
- **Framework-enforced `area` for consistency.** `viewArea` already establishes this exact
  opt-in ownership pattern; reusing it costs little and gives enforced isolation when an
  installation hosts several modules — preferable to trusting each module.
- **Separating storage from visibility removes a whole class of bugs.** The rejected
  "copy private → public temp → serve" approach causes needless I/O, cleanup/race
  problems, inconsistent delete states and duplication. One store + a metadata flag has
  none of these, and keeps a single delete/retention path.
- **`publicPath` independent of the blob path** is what the ID-based layout makes possible:
  a stable, human-meaningful URL that can change without touching the bytes, and an
  internal path that never contains user input.
- **Portability beats purity on delivery.** "Never stream through PHP" is correct for
  large files on Nginx/Apache but breaks the PHP built-in server, shared hosting and
  Windows dev. A PHP Range-stream fallback keeps the hard portability requirement; the
  fast path is taken automatically where available.

## Consequences

- **Path-traversal is structurally excluded.** No user input in the blob path. `publicPath`
  is a **lookup key, never a filesystem path** — resolved by equality (unique index),
  sanitised on **write**, never concatenated on read. The `/media/{publicPath}` route needs
  a catch-all segment (slashes allowed) but disk access stays ID-based.
- **Invariant `publicPath NOT NULL ⇒ visibility=public`.** Switching public→private or
  soft-deleting blocks `/media/...` delivery. `serve()` always checks `deletedAt IS NULL`;
  the public route additionally checks `visibility=public`.
- **`serve()` carries a delegation switch** (`X-Accel-Redirect` / `X-Sendfile` / PHP
  Range-stream) chosen by config/capability — a new infrastructure concern to document and
  test on each target.
- **`FileResponse` gains an inline variant** (currently hard-coded `attachment`,
  `packages/kernel/core/src/Http/Response/FileResponse.php`) and Range support for the fallback.
- **Public delivery may set cache headers** (`ETag` from `checksum`,
  `Cache-Control: public, immutable`); a CDN / read-only mirror can be added later without
  data-model or module-API change.
- **`Document` uses collection mode**, so it keeps an auto `id`; the per-upload whole-file
  rewrite is the known collection-mode trade-off, bounded by the planned Doctrine switch.
- Topic-doc `docs/topics/documents.md` is created together with Phase-1 code (the docs
  linter requires existing `SOURCE=` paths).

### 8. Image derivatives — eager, config-driven profiles

Images get **pre-generated** derivatives at save time (eager), not on-the-fly: fastest
serve (static byte serve via X-Sendfile) and responsive `srcset` needs discrete widths
anyway. On-the-fly is only justified for arbitrary sizes (image proxy). Library: **GD**
(PHP extension, no Composer dependency; WebP/AVIF available in the dev env).

Variant sizes are **named profiles in config**, not a fixed enum:

- A framework-fixed **`admin` profile** (list thumbnail + preview-pane sizes) is generated
  for every image so the management tool is universally browsable.
- **Project profiles** (`slider`, `team`, …) are freely defined in config. The save call
  selects one (`SaveService::save(bytes, meta, profile: 'slider')`); `admin` + the selected
  profile are generated. No profile → `admin` only.

**Sharpness escape hatch.** GD's encoding is weaker than professional tools. Two flags
skip reprocessing and serve the original bytes 1:1: **`showOriginal`** (per document) and
**`preserveOriginal`** (per profile, the default for all its images). When set, only the
160px `admin.s` thumbnail is generated for the tool; the front-end serves the original.
Trade-off (deliberate): no responsive downscale → mobile loads the original — correct for
hand-prepared hero/slider images. The default path (`showOriginal=false`) uses improved GD
quality (`imagecopyresampled`, JPEG quality 90–92, unsharp-mask after downscale).

Format v1 = source format; per-variant `format` (WebP/AVIF) is a later opt-in. Profile
changes are **not** retroactive (old images keep old widths); a reprocess command is a
later phase.

**Blob layout refinement (still B, not a reversal): per-`id` directory.**
```text
data/blobs/<shard>/<id>/orig.<ext>
data/blobs/<shard>/<id>/{s,m,l,…}.<ext>     # kind=image only
```
Still ID-based → blob key = `id` (the directory), variants resolved by name. Move =
nothing, purge = remove the directory (all variants at once). `BlobStorage` becomes
variant-aware (`put/get/path(id, variant)`, `delete(id)`) but stays profile-agnostic —
profiles live in `shared/Documents/`.

Document fields added: `profile` (string|null), `showOriginal` (bool), `variants`
(map|null, `{name:{w,h,bytes}}` for `srcset`). All relevant only for `kind=image`.

## Placement (package layout)

Derived from existing patterns, not invented: `z77/shared` holds the driver-agnostic
domain split **by kind** (`Entities/`, `Repositories/`, `Validators/`, `Services/`,
`ValueObjects/`), with feature-specific machinery in a **feature namespace** (precedent:
`Z77\Shared\Content\` holds `BlockRegistry`/`ContentRenderer`). `z77/persistence` is where
data physically lives. `z77/core` owns HTTP/runtime. The modules own controllers.

| Component | Package → path | Analog |
|---|---|---|
| `Folder`, `Document` entities (`#[Entity('file', …)]`, collection mode) | shared → `src/Entities/` | `Content.php` |
| `FolderRepository`, `DocumentRepository` (extends `FileRepository`) | shared → `src/Repositories/` | `ContentRepository.php` |
| `FolderValidator`, `DocumentValidator` | shared → `src/Validators/` | `ContentValidator.php` |
| `DocumentKind` (enum/registry + allowlist) | shared → `src/Documents/` (new feature NS) | `src/Content/BlockRegistry.php` |
| `VariantSpec`, `ImageProfile`, `ImageProfileRegistry` (config profiles) | shared → `src/Documents/` | `src/Content/BlockRegistry.php` |
| `ImageProcessor` (interface) + `GdImageProcessor` | shared → `src/Documents/` | — |
| `DocumentService`, `SaveService`, `UploadService`, `FolderService` | shared → `src/Services/` | `ContentService.php` |
| `UploadedFile` VO | shared → `src/ValueObjects/` | `UserPreferences.php` |
| `Mailer`, `SmtpTransport`, `Message` (Phase 6) | shared → `src/Mail/` (new feature NS) | — |
| `BlobStorage` (interface) + `LocalBlobStorage`, later `S3BlobStorage` | persistence → `src/Blob/` (new NS) | `File/Storage/FileStorage.php` |
| `Request` upload support; `FileResponse` inline + Range | core → `src/Http/` | extend existing files |
| `FolderController`, `DocumentController` (tree/DnD, upload, private download) | module-backend → `src/Ui/Controllers/Documents/` | `Controllers/Content/Navigation*` |
| `MediaController` (`GET /media/{publicPath}`, public) | module-frontend | public-facing endpoint |
| Blobs on disk: `data/blobs/<shard>/<id>.<ext>` | RUNTIME (installed app) | `data/content/…` |

Placement decisions:

- **`BlobStorage` lives in `z77/persistence` (`Blob/` namespace), not `core`.** It is a
  storage driver (FS → S3), sibling to `FileStorage`, but a separate namespace because
  bytes ≠ JSON metadata (decision: two separate worlds).
- **Domain services are built on-demand via `::create()` at the consumer boundary**
  (like `ContentService`, ADR-012), **not** registered as DI singletons. Only `BlobStorage`
  and `UnifiedEntityManager` are framework infrastructure.
- **`UploadService` stays HTTP-agnostic.** The `$_FILES` → `UploadedFile` bridge lives in
  `core/Http/Request`; `UploadService` consumes ready `UploadedFile` VOs and validates them.
  This keeps it next to the other domain services in `shared` without importing an HTTP
  runtime object. (Refines the plan, where `UploadService` read `$_FILES` directly.)

The `docs/topics/documents.md` topic-doc is created as the **first step of Phase 1** — the
moment `BlobStorage` + `DocumentKind` exist, so its `## file map` lists real `SOURCE=`
paths (the docs linter requires existing paths) and grows per phase thereafter.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Blob layout A — content-addressed (`sha256`) | Dedup/integrity/free move, but needs refcount + GC → overengineering for now |
| Blob layout C — path-mirrored (`data/documents/<area>/<path>/file.pdf`) | Folder reparent = N real file moves on a transactionless driver (half-states); name collisions; larger path-traversal surface (user filenames in path) |
| `area` = free string (module owns consistency) | Minimal framework code, but no enforced isolation; inconsistent with the existing `viewArea` pattern |
| `area` = none (one global structure) | Simplest, but zero isolation between modules of one installation |
| Document mode (ADR-010) for `Document` | No auto `id` → would need a UUID as blob key; collection mode gives the `id` directly |
| Copy private blob → `public/` temp → serve | Needless I/O, cleanup/race problems, inconsistent delete states, duplication — the core problem OPEN-7 removes |
| "Never stream through PHP" (web-server only) | Breaks PHP built-in server / shared hosting / Windows dev; violates the hard portability requirement |
| External mail library | "No unnecessary dependencies"; in-house SMTP/MIME proven in WDV |
| Versioning in v1 | Overengineering for v1; replace = overwrite bytes |
