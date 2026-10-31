# documents

2026-10-31

> **Access model superseded (2026-06-17, revised 2026-06-20):** the access/routing design below
> (visibility + publicPath, admin-only management, `/media` via NavigationAlias) is replaced by
> **[`../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md`](../02-decisions/adr-017-document-management-ownership-acl-and-delivery.md)**
> (ownership + ACL with `user|role` subjects + `deliveryMode` ladder `sealed|protected|public` +
> static materialization + additive `Share` + `active` + structural `/media` via reserved route +
> a `dms` module). The
> **engine** documented here stays valid. Rebuild plan: [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md).
> This file's `## file map` still reflects the currently built code and is updated per rebuild phase.

## entry

1. `docs/02-decisions/adr-016-document-management-storage-and-delivery.md` — binding design (storage layout, scope, visibility/delivery, image derivatives); read BEFORE touching DMS code or model
2. `packages/module-dms/src/Blob/BlobStorage.php` — the byte-storage contract (the second of the two separate worlds: bytes ≠ metadata)
3. `packages/module-dms/src/Images/DocumentKind.php` — MIME classification that IS the upload allowlist

## file map

Domain (module-dms — `Z77\Module\Dms`):

SOURCE=/packages/module-dms/src/Blob/BlobStorage.php
SOURCE=/packages/module-dms/src/Blob/LocalBlobStorage.php
SOURCE=/packages/module-dms/src/Images/DocumentKind.php
SOURCE=/packages/module-dms/src/Images/UploadTypes.php
SOURCE=/packages/module-dms/src/Images/VariantSpec.php
SOURCE=/packages/module-dms/src/Images/ImageProfile.php
SOURCE=/packages/module-dms/src/Images/ImageProfileRegistry.php
SOURCE=/packages/module-dms/src/Images/ProcessedVariant.php
SOURCE=/packages/module-dms/src/Images/ImageProcessor.php
SOURCE=/packages/module-dms/src/Images/GdImageProcessor.php
SOURCE=/packages/module-dms/src/Entities/Folder.php
SOURCE=/packages/module-dms/src/Entities/Document.php
SOURCE=/packages/module-dms/src/Entities/AccessControlEntry.php
SOURCE=/packages/module-dms/src/Repositories/FolderRepository.php
SOURCE=/packages/module-dms/src/Repositories/DocumentRepository.php
SOURCE=/packages/module-dms/src/Repositories/AccessControlEntryRepository.php
SOURCE=/packages/module-dms/src/ValueObjects/Principal.php
SOURCE=/packages/module-dms/src/Services/AclService.php
SOURCE=/packages/module-dms/src/Services/Authz.php
SOURCE=/packages/module-dms/src/Services/SaveRequest.php
SOURCE=/packages/module-dms/src/Services/SaveService.php
SOURCE=/packages/module-dms/src/Services/UploadService.php
SOURCE=/packages/module-dms/src/Services/ChunkedUploadService.php
SOURCE=/packages/module-dms/src/Services/ChunkUploadException.php
SOURCE=/packages/module-dms/src/Jobs/ChunkCleanupJob.php
SOURCE=/packages/module-dms/src/Services/DocumentService.php
SOURCE=/packages/module-dms/src/Services/FolderService.php
SOURCE=/packages/module-dms/src/Services/ModuleDrive.php
SOURCE=/packages/module-dms/src/helpers.php
SOURCE=/packages/module-dms/data/documents/folders.default.json
SOURCE=/packages/kernel/shared/src/ValueObjects/UploadedFile.php
SOURCE=/packages/kernel/core/src/Http/Request.php
SOURCE=/packages/kernel/core/src/Http/Response/FileResponse.php

UI fragment + delivery (module-dms):

SOURCE=/packages/module-dms/src/Ui/DriveControllerTrait.php
SOURCE=/packages/module-dms/src/Ui/ChunkedUploadTrait.php
SOURCE=/packages/module-dms/src/Ui/DocumentDeliveryTrait.php
SOURCE=/packages/module-dms/src/Ui/DriveLayout.php
SOURCE=/packages/module-dms/src/Ui/Controllers/Media/OutputController.php
SOURCE=/packages/module-dms/src/App/Config/dmsConfig.inc.php
SOURCE=/packages/module-dms/res/assets/js/documents/drive.js
SOURCE=/packages/module-dms/res/assets/js/documents/upload-poster.js
SOURCE=/packages/module-dms/res/assets/js/documents/chunk-upload.js
SOURCE=/tests/dms-chunked-upload.php
SOURCE=/packages/module-dms/res/assets/js/documents/chunk-download.js
SOURCE=/packages/module-dms/res/assets/js/documents/chunk-download-sw.js
SOURCE=/tests/file-response-range.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/listAction.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_tree.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_breadcrumb.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_list.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_preview.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_upload.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_folderMove.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_bulkMove.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_bulkConfirmDelete.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_edit.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_trash.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_trashConfirmPurge.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DriveController/_actions.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DocumentController/move.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/DocumentController/confirmDelete.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/FolderController/edit.tpl.php
SOURCE=/packages/module-dms/res/view/templates/Documents/FolderController/confirmDelete.tpl.php

Host mounts (module-backend — thin, `use` the traits):

SOURCE=/packages/module-backend/src/Ui/Controllers/Documents/DriveController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Documents/DocumentController.php
SOURCE=/packages/module-backend/src/Ui/Config/Documents/driveControllerConfig.inc.php

Decisions + plans:

SOURCE=/docs/02-decisions/adr-016-document-management-storage-and-delivery.md
SOURCE=/docs/02-decisions/adr-017-document-management-ownership-acl-and-delivery.md
SOURCE=/docs/02-decisions/adr-019-code-organization-package-by-domain.md
SOURCE=/docs/02-decisions/adr-021-dms-drive-root-and-super-user-governance.md
SOURCE=/docs/03-development/dms-umbauplan.md
SOURCE=/docs/03-development/dms-extraction-bauplan.md
SOURCE=/docs/03-development/dms-authz-bauplan.md
SOURCE=/docs/03-development/dms-drive-root-bauplan.md
SOURCE=/docs/03-development/dms-bulk-select-bauplan.md

## mental model

A document management foundation for project modules: upload/store files (including
generated ones), organise them in a folder tree, classify and serve them. The core split
(ADR-016): **metadata** (folder tree, document records) lives in the entity layer, the
raw **bytes** live in a separate `BlobStorage` — `FileStorage` (JSON) is never used for
bytes. The blob store is **id-addressed** (layout B): a document's auto-increment `id` is
the blob key, all its blobs live under one per-id directory
(`data/blobs/<shard>/<id>/<variant>.<ext>`), so logical move/rename is metadata-only and
purge is one directory removal. Images get **eager, config-profile-driven** derivatives
(OPEN-8); everything else stores only the original.

- **Build status: engine complete + rebuilt onto ownership/ACL/deliveryMode + extracted into
  `module-dms`.** The ADR-016 engine (Phases 1–6: `BlobStorage` layout B, GD derivatives,
  two-phase `SaveService`, range-aware `FileResponse`, `DocumentKind`, `Mailer` — see
  [`mail.md`](mail.md)) stands. On top of it: the ADR-017 rebuild (ownership + ACL +
  `deliveryMode` ladder + structural `/media`) is done through **R6c core** ([`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md));
  the whole DMS vertical (domain + Drive UI fragment) now lives in **`module-dms`**
  (`Z77\Module\Dms`, ADR-019 — extraction **done 2026-07-01**, [`../03-development/dms-extraction-bauplan.md`](../03-development/dms-extraction-bauplan.md));
  management mutations are gated in the domain (`Authz`, R-authz-1 done — [`../03-development/dms-authz-bauplan.md`](../03-development/dms-authz-bauplan.md));
  **scope is the tree, not a label (ADR-020, built 2026-07-02):** the `area` field is GONE, the
  partitions are real entities (owner/ACL/`key`), reads are domain-gated
  deny-by-default (RF-4a = R-authz-2 done); **ONE drive root above the partitions (ADR-021,
  built 2026-07-03):** the tree top is a single mandatory system folder (`Folder::DRIVE_KEY`
  = `'drive'`, seeded via `module-dms` `folders.default.json` + lazy `driveRoot()` get-or-create
  with stray adoption), the partitions are its CHILDREN, the ACL bypass is `superUser`-only
  (an `admin` is a normal, grant-managed principal), root/partition-level acts (partition
  lifecycle + grants on the root or a partition) are SUPER_USER-only, and module writes run
  through a config-remappable, subtree-confined handle (`DocumentService::forModule` →
  `ModuleDrive`). Hosts are thin mounts: a controller
  `extends {Host}AbstractController` + `use DriveControllerTrait`/`DocumentDeliveryTrait` — no
  host scope at all (decision (b): the Drive shows every root the principal may read; backend is
  the sole host today) — **except** an OPTIONAL, SESSION-STICKY presentation root
  (`DriveControllerTrait::mountRoot`, built 2026-07-02): a `?key=<root-key>` switch trigger (carried
  by a nav entry's `Navigation::param`, see [`navigation.md`](navigation.md) NAV-PARAM-002) roots the
  Drive at ONE partition for a focused entry point, WITHOUT restricting access (see `## rules`).
  **Open:** share materialization + live image-thumbnail verification.
- **Surface = the `.dms` Drive fragment** (`DriveControllerTrait`, ADR-018/019). A three-pane
  Drive (left folder tree · middle document list · right preview) rendered as an embeddable
  `.dms` fragment; navigation + all mutations (upload, rename/move/delete, folder add/rename/
  move/delete, `setDeliveryMode`, ACL grant/revoke, trash restore/purge, active toggle) are
  in-place Fetch pane refreshes (`paneAction` + partials + `documents/drive.js`), with a
  full-reload `href` fallback for no-JS. It reuses the sibling byte-delivery endpoint
  (`DocumentDeliveryTrait` → `document/preview`|`download`) for thumbnails/preview. The public
  endpoint is `OutputController::serveAction` in `module-dms`, reached via the `/media`
  **reserved route** (`dmsConfig`; the seeded NavigationAlias was removed in R3). The Drive is
  **not host-scoped** (ADR-020 (b)): it shows every partition the session principal may
  `read` (superUser bypass = all, ADR-021) — unreadable ancestors of a granted subtree (incl.
  the drive root itself) render as path nodes
  (masked count, no content). Upload is a real multipart POST (since 2026-10-10 THE upload
  component of the kernel — `Z77\Shared` `partials/upload` + `upload.js`, UPLOAD-001 in
  [`fetch.md`](fetch.md); the Drive's own 396-line uploader is gone. Its target folder and
  «Original ausliefern» ride inside the component's form, its video poster frame comes from
  the per-file provider `documents/upload-poster.js`, and its two caps — transport for every
  file, a smaller one for `image/*` — are the policy's `maxBytesPer`), read via
  `Request::getUploadedFiles()`, never the JSON fetch envelope; the location is the target
  `folderId`, only `displayName` is user-editable. Every mutation AND every
  per-id read (bytes, modals, panes, trash) is gated in the domain (`Authz`/`serveFor`/
  `listDeleted`), not the trait (trait precedence is bypassable — RF-4a).
- **Delivery** (`DocumentService::serve`): returns a `FileResponse` pointing at the blob
  path for the requested variant; bytes are always streamed by the portable PHP
  range-stream (every range answer capped at 256 MiB, large files fetched in explicit pieces —
  DMS-CHUNK-DL-001; web-server-accelerated delivery — `X-Sendfile`/`X-Accel`/LiteSpeed — was
  rejected, see ADR-017; `FileResponse` has no `delivery`/`internalPath` parameters).
  Public serving is a **`deliveryMode`**, not a per-document flag (R5 removed `visibility`/
  `publicPath` + `publish`/`unpublish`): `OutputController` resolves the structural `/media`
  path via `resolve()`, branches on `effectiveDeliveryMode()` — `public` served openly (and on a
  static miss WRITTEN to `public/media/…` so the web server serves the next hit — lazy fill,
  rev. 2026-09-07, DMS-MAT-001), `protected`/`sealed` gated by
  `AclService::canRead()` (effective READ + active chain) **before any byte**; any miss/denial
  → 404 (existence never leaked). Soft-deleted is never served.
- **Two-phase save** (`SaveService`): the blob key is the document `id`, assigned only on
  flush. So save persists the record first (→ id), then writes the original + derivatives
  to `BlobStorage` under that id, then persists again with the `variants` map. Image
  derivatives are produced by `GdImageProcessor` (downscale-only, source format, JPEG q90,
  mild unsharp); `showOriginal`/`preserveOriginal` reduce generation to the `admin.s`
  thumbnail only.
- `Document` is collection-mode (auto-increment `id` = blob key, no `storageKey` field);
  `Folder` is a `TreeNode` in ONE tree (ADR-020: no scope partitions — the top-level folders
  ARE the partitions; a module-declared root carries a unique `key` + `system = true`, which
  locks rename/move/delete). Both round-trip through `mapToArray()`/`mapFromArray()`; `id` is
  hydrated reflectively (no setter), like other server fields. `mapFromArray` ignores unknown
  keys, so legacy `area` values in old JSON are harmless.
- Image profiles are **project-owned, partition-namespaced** DMS config (ADR-020 rev.
  2026-07-13): ONE `App/Config/imageProfilesConfig.inc.php` in the `Z77\Module\Dms` namespace —
  supplied by the PROJECT's override tree (`override/z77/module/dms/...`; the framework package
  ships none) — maps `partitionIdent => profileName => sizes`.
  `ImageProfileRegistry::fromConfig()` reads it; the partition ident is the root's
  **`key ?? slug`**, so a keyless (human-created) root like `front` resolves by its slug.
  WHICH profile an upload gets is a **folder assignment**: `Folder::$profile`, inherited down
  the chain like `deliveryMode`, resolved at save time with the partition's `default` profile
  as fallback; a partition without a config block yields only the framework-fixed `admin`
  profile (tool previews).
- `BlobStorage` is variant-aware (`put/get/path/size(id, variant, ext)`, `delete(id)`) but
  **profile-agnostic** — the set of variants (image profiles) is decided in the `module-dms`
  `Images`/`Services` layer, not in the store. `ext` is passed explicitly so the store never
  guesses an extension from disk.
- `DocumentKind::fromMime()` returns `null` for anything not on the allowlist; the MIME
  comes from a server-side `finfo` sniff, never the client content type.
- Access is a `deliveryMode` + ACL model, not a location: all bytes live in `data/blobs`
  (outside `public/`); `protected`/`sealed` are always PHP-streamed through the service with an
  `AclService` gate, `public` is additionally cached statically under `public/media/…` — a LAZILY
  filled, regenerable copy (written by the `OutputController` on the first static miss, removed by
  the mutations that stale it, no own state; rev. 2026-09-07). The structural `/media/<root-slug>/<folder-slug…>/<doc-slug>[.<variant>].<ext>`
  URL is resolved by `DocumentService::resolve()` — the first segment is simply the first
  folder-chain link (the partition root, ADR-020), not a `publicPath` lookup key or an area label.

## rules

- When a project must open a folder to SOME members (not every `customer`) → MUST name project roles through `dmsConfig['principalRolesHook']` (invokable `__invoke(AuthUser): list<string>`, read by `Services\PrincipalRoles` for NON-backend identities only) and give the folder role ACEs for those names; framework role names are dropped, only `[a-z0-9][a-z0-9._-]{0,99}` passes, a throwing hook grants nothing — F1, z77-rudaz.ch 2026-10-30, harness `tests/dms-principal-roles.php`
- When storing document bytes → MUST go through `BlobStorage`; MUST NOT write bytes into `FileStorage` or anywhere under `public/`.
- When resolving a blob for delivery → MUST obtain the path via `BlobStorage::path(id, variant, ext)`; MUST NOT assemble the blob path by string concatenation.
- When classifying an upload → MUST resolve the kind via `UploadTypes::kindFor()` (= `DocumentKind::fromMime()` on a server-side `finfo` sniff, plus the installation's `dmsConfig['extraUploadTypes']`) and store `UploadTypes::storedMime()`; a `null` result MUST be rejected (the allowlist); MUST NOT trust the client-supplied content type; the extension alone counts ONLY for a listed type, which becomes kind `file` (never inline, no variants, no static copy) — F4; a server-executable extension (`UploadTypes::EXECUTABLE`) is refused in every installation and never materialized (DMS-MEDIA-PHP-001) — harness `tests/dms-upload-types.php`.
- When a file arrives in CHUNKS (bigger than one request may be) → MUST go through `ChunkedUploadService` (folder `write` gate at begin AND append AND finish, owner-bound upload id, exact chunk sizes, every chunk verified against the SHA-256 the browser computed over the original slice, bytes only under `data/dms/chunks/`, never under `public/`) and MUST store through `UploadService::saveAssembled()` — the same pipeline as `save()` (kind on the sniffed bytes, duplicate, conflict, guarded overwrite); MUST NOT build a second classification/duplicate path for chunked files — DMS-CHUNK-001, harness `tests/dms-chunked-upload.php`.
- When a browser must receive a file that may be larger than the host's body cap (cyon: 500 MB) → MUST fetch it through `documents/chunk-download.js` (explicit Range pieces of `dmsConfig['downloadChunkBytes']` from a Range-capable `FileResponse` endpoint — the same gated action as the plain link) and MUST NOT offer it only as a plain one-answer link (413); MUST NOT raise `FileResponse::RANGE_MAX_BYTES` (256 MiB, every single range) toward the cap (a media player's `bytes=0-` and a resumed native download rely on it); the service worker MUST answer nothing but its `__z77-download/<token>` prefix — DMS-CHUNK-DL-001, harness `tests/file-response-range.php`.
- When moving or renaming a document or reparenting a folder → MUST change metadata only; MUST NOT move blob bytes (layout B — that is the whole point of id-addressing).
- When saving or moving a document → MUST target a folder; a `null` `folderId` is rejected. Since ADR-020 the top-level folders ARE the partitions (real entities) — the old "non-entity area root" special case is gone; a document may live directly in a root folder. `SaveService::save` / `DocumentService::move` throw on a null folder; `resolve()` refuses a `/media/<file>` with no folder segment; `move` additionally requires `write` on the target folder.
- When deleting → soft-delete MUST set `deletedAt` and MUST NOT remove bytes; a hard purge MUST respect `retentionUntil` and MUST call `BlobStorage::delete(id)` (which removes ALL variants at once). Implemented (2026-07-01): `DocumentService::delete` (soft) / `restore` (clear `deletedAt`, folder must exist) / `purge` (record + blob, retention-gated) / `listDeleted`; the Drive exposes them via the "Papierkorb" panel (`DriveController::trashAction`) — incl. «Papierkorb leeren» (`purgeAll` op, 2026-07-16: `<details>`-confirmed, loops the principal-scoped `listDeleted()` through the gated `purge()`, retention-blocked documents survive and are reported).
- When a mutation changes a public document's PATH (folderId, slug), its BYTES (in-place replace) or its GATE (delete/restore, `active`, `deliveryMode` — on the document or on a folder) → MUST call `DocumentService::invalidateMaterialized($doc)` (document) / `invalidateMaterializedFolder($id)` (subtree) and MUST NOT rebuild `public/media`. The eager `rebuildMaterialization()` is GONE (rev. 2026-09-07, DMS-MAT-001): it re-copied EVERY public byte of the installation on EVERY mutation. Ordering: BEFORE a path/bytes change (the old location is derivable only then — `move`, folder `rename`/`move`, upload overwrite), AFTER the flush of a gate-only change (a lazy fill racing the write re-gates on the committed state — `delete`, `setActive`, `setDeliveryMode`, `setFolderActive`, `setFolderDeliveryMode`). The copy is refilled by `OutputController::serveAction` → `materialize($doc, $variant)` on the next static miss, exactly ONE variant per request; `materialize()` re-gates itself (not deleted + effectively `public` + active chain) and writes via temp file + rename. A NEW document/upload needs NO call (nothing is materialized yet); `restore` needs none either. MUST NOT add a warm-up/pre-fill job — the first visitor pays one PHP request per file, and that IS the design (ADR-017 rev. 2026-09-07). MUST NOT treat a file's presence under `public/media` as state: the whole tree may be wiped at any time and refills.
- When listing the documents of a folder for ANY consumer (the Drive list, a slider, a gallery, a module) → MUST read them via `DocumentService::listByFolder($folderId)`, which returns the EDITOR'S order: `Document::$sortKey` ascending, id as the stable tie-breaker — never file/record order and never a re-sort by slug/name in the consumer (built 2026-09-07, DMS-SORT-001). `sortKey` is server-controlled (no `#[Clean]`): a new document is appended (`DocumentRepository::nextSortKey` — the single definition of "the end", used by `SaveService::save/saveFromUpload` and `DocumentService::move`), and it changes ONLY through `DocumentService::reorder($id, $newIndex)` (splice among the live siblings, dense renumber `0..n`, gated on `write` of the FOLDER — order is the folder's property and the renumber touches the siblings). Surface: the Drive list rows are server-rendered `draggable` and drive.js posts `{id, new_index, entity_csrf}` to `drive/sort` (`DriveControllerTrait::sortAction`, `#[Fetch]` + per-entity token, DMS-SEC-001 rule); `new_index` is the position among the OTHER rows, exactly like `BackendUserController::moveAction`. Rows persisted before the field existed read `0` and keep their id order — MUST NOT run a migration for that; a project that relied on a name order seeds it once (zihlundsee did, slider folders, 2026-09-07). MUST NOT expose `sortKey` in an edit form.
- When adding image sizes → MUST define them as config-driven profiles consumed via `ImageProfileRegistry` (`module-dms` `Images`); MUST NOT hardcode a fixed variant set. When `showOriginal` (document) or `preserveOriginal` (profile) is set → MUST serve the original bytes untouched (only the 160px `admin.s` thumbnail is generated); MUST NOT reprocess through GD.
- When a project needs image profiles (ADR-020 rev. 2026-07-13) → MUST define them in the project's DMS override config `override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php` (read via `ImageProfileRegistry::fromConfig()`; the framework ships none — a missing file = no project profiles), **two-level**: `partitionIdent => profileName => variantSpecs`, where the partition ident is the root folder's `key ?? slug`. The per-partition namespace is the collision safety (`front`'s `logo` ≠ `back`'s `logo`) — MUST NOT flatten it, MUST NOT name a profile `admin` (framework-fixed, built into `ImageProfileRegistry`), and MUST NOT resurrect the removed per-module `fromModules()` aggregation (a future module with programmatic saves defines its profiles under its partition key in this same file — module key = partition key).
- When choosing WHICH profile an upload gets → the binding is the **folder assignment** `Folder::$profile` (inherited down the chain like `deliveryMode`), set ONLY via the gated `FolderService::setProfile()` (effective `manage`, validated against the partition's registry block, drive root refused; surface: the Drive's combined edit modal — the field renders only when the partition has profiles). The save path resolves AUTOMATICALLY when `SaveRequest.profile` is null (`SaveService::resolveProfile`): effective folder profile ?? the partition's reserved **`default`** profile ?? none (only `admin` variants) — deliberately LENIENT (a stale assignment falls back, never fails the upload); an EXPLICIT `SaveRequest.profile` stays strict (unknown → throw). The RESOLVED name is persisted on `Document.profile`. MUST NOT set `Folder::$profile` directly from a controller and MUST NOT expect new profile sizes to apply retroactively (variants are generated at save time only; reprocessing is a later phase).
- When handling an HTTP upload → MUST get `UploadedFile` VOs via `Request::getUploadedFiles()` and hand them to `UploadService` (it gates error/size + the allowlist on a server-side `finfo` sniff, and requires `write` on the target folder — session principal); MUST NOT read `$_FILES`, persist a `Document`, or write a blob directly in a controller. The location is the `folderId` — there is NO `area` to force.
- When reasoning about the upload size limit → the cap is **not** a hardcoded app constant anymore (2026-07-02): `UploadService::create()` defaults to `serverMaxBytes()` = `min(upload_max_filesize, post_max_size)` from the PHP ini (a `0`/`-1` limit = unlimited → `PHP_INT_MAX`). Raising the ceiling is a **PHP-config change** (php.ini / cyon `.user.ini`), NOT a code change — there is no second app knob to drift out of sync with PHP. The upload modal shows this derived value. A caller MAY still pass a stricter ceiling to `create($maxBytes)`. MUST NOT reintroduce a fixed `DEFAULT_MAX_BYTES` constant. Since P0 (2026-07-02) the upload original is **moved** into the blob (`BlobStorage::putFile` → `move_uploaded_file`) and the checksum is **streamed** (`UploadedFile::sha256` = `hash_file`), so a non-image original never enters RAM. `UploadService::save()` runs the `fitsMemory` pre-check ONLY for an image original (`$kind->hasImageVariants()` — GD must decode the pixels; a rejected image throws cleanly instead of an OOM fatal that would orphan the row, ARCH-A003); a video/other file is bounded by the transport cap alone. So `memory_limit` is the tighter gate only for IMAGES, not for big videos. The byte-based `SaveService::save()`/`replace()` stay for `saveGenerated()` / in-memory producers; uploads go through `saveFromUpload()`/`replaceFromUpload()`.
- When an upload collides with a live document of the same `originalName` in the target folder (decided 2026-07-02) → identical bytes (checksum) MUST be skipped as a duplicate (`DuplicateUploadException` → info, never an error); different bytes MUST NOT be resolved silently — without `overwrite: true` the domain throws `NameConflictException` and the UI asks (`status: 'conflict'` + `data.conflicts`; `upload.js` retries only those files with `overwrite=1`). The confirmed overwrite replaces IN PLACE (`SaveService::replace`: id/slug/URL/owner/ACL/mode/active stay; bytes/mime/kind/size/checksum/dimensions/variants renew) and additionally requires `manage` on the existing document; an effectively `sealed` document or a running `retentionUntil` MUST block the overwrite.
- When rendering a public `/media` URL in a template or module → MUST build it via `DocumentService::publicUrl($doc, $variant)` (appends the content-version token `?v=<checksum-prefix>`); MUST NOT assemble the path by hand — a hand-built URL has no version token, and after an in-place replace a long-lived browser cache (deploy `.htaccess`, e.g. 1 year for images) would serve the OLD bytes until expiry. The version token changes exactly when the bytes change, so long cache headers on `/media` are allowed and desired. Known limit: an externally published BARE URL (without `?v`) stays stale after a replace until the cache expires.
- When a TEMPLATE needs a public image URL for a DMS document by its structural slug path → MUST use the global `mediaUrl(string $path, ?string $variant = null): ?string` helper (`module-dms/src/helpers.php`, registered via composer `autoload.files`; delegates to `DocumentService::urlForPath()`), e.g. `mediaUrl('front/imgs/logo.png')` → `/media/front/imgs/logo.png?v=…` or `null`; MUST guard the `null` (unresolved / soft-deleted / ambiguous) and MUST wrap the result in `e()`. The `$path` segments are SLUGS (folder + document), not raw upload names. MUST NOT call `resolve()`/`publicUrl()` by hand in a template, and MUST NOT put a `mediaUrl()` helper in `kernel/core` (layering: the helper lives in `module-dms`, so core stays free of DMS knowledge — like `UploadedFile` staying in `shared`). The document must be delivered `public`; `mediaUrl()` builds the URL blindly (a non-public document yields a URL that 404s).
- When resolving many DMS image URLs per page render (`urlForPath`/`mediaUrl`) → the lookup goes through `DocumentService::publicPathIndex()` (a DMS-scoped `DataCache` index: structural path → URL fields, built once from `folderSlugIndex()` + `listAll()`), NOT per-call repository reads; the URL string itself is assembled ONLY by the private `buildPublicUrl()` (the single format source shared with `publicUrl()`). MUST NOT reassemble the `/media` URL format anywhere else, and MUST NOT add manual cache invalidation — the index is dropped by the existing `Document`/`Folder` `invalidatesCache` (any DMS write → `clearAllApcu()`). MUST NOT cache hydrated entities in APCu (the index stores reduced arrays only, pattern `AclService`).
- When a template needs a DMS image WITH its `alt` / `figcaption` (not just the URL) → MUST use the global `mediaImage(string $path, ?string $variant = null): ?array` helper (`{url, alt, caption, width, height}` or null; alt/caption already resolved to the current request language with i18n-default fallback via `DocumentService::imageForPath()` → `localizeMap()`); MUST `null`-guard and `e()`-escape every value. MUST NOT resolve the language in the template or read raw `getAltMap()` there. `width`/`height` match the **requested variant** (the variant's own `w`/`h` from `variants[]`), not the original — for the original (`$variant === null`) they are the document dimensions (DMS-DIM-001).
- When STORING image `alt`/`caption` → MUST go through the gated `DocumentService::setImageText($id, $alt, $caption)` (effective `manage`, session principal — same gate as `rename()`); the maps are per-language `array<lang,string>` on dedicated `Document` fields (`alt`/`caption`), server-cleaned (configured languages only, trim + control-char strip, empties dropped). MUST NOT write `alt`/`caption` straight on the entity from a controller/UI, MUST NOT store a single-language string, and MUST NOT put them in `meta[]`.
- When resolving a VARIANT's blob (path/bytes/URL/materialized file) → MUST use the variant's OWN extension, NOT the document extension. A derivative can differ from the original: a video's poster variants are `jpg` though the original is `mp4` (P4). The ext is stored per variant in the meta (`ProcessedVariant::toMeta()` → `{w,h,bytes,ext}`); read it via `DocumentService::variantExt($doc, $name)` (falls back to the doc ext for the original and for legacy image variants where ext == doc ext). The served MIME for a derivative comes from `mimeForExt()`, not `Document::getMimeType()` (which is the original's type — serving a JPEG poster as `video/mp4` gives a broken image). This applies to `serve()`, `send()`, `publicUrl()`, `resolve()` (the `/media/<slug>.<variant>.<ext>` leaf ext belongs to the VARIANT), and `writeMaterialized()`. MUST NOT assume variant ext == doc ext.
- When persisting a new document → MUST go through `SaveService` (two-phase: persist → write blob(s) under the assigned `id` → persist `variants`); module-generated files use `saveGenerated()`. MUST NOT assume the blob key before the first flush — the `id` is the key.
- When a module needs documents (list/get/serve/delete/move/setActive/setDeliveryMode/grant/revoke/saveGenerated) → MUST use `DocumentService` (the only DMS API); MUST NOT touch `DocumentRepository`/`BlobStorage` directly. Build it via `DocumentService::create()`. There is NO `publish`/`unpublish`/`visibility` API — public access is a `deliveryMode` (R5).
- When serving bytes → MUST return the `FileResponse` from `DocumentService::serve()` (never a raw path or URL into `data/blobs`). The public `/media/<root-slug>/…` route MUST go through `OutputController::serveAction` → `resolve()` → `effectiveDeliveryMode()`: `public` served openly, `protected`/`sealed` gated by `AclService::canRead()` (effective READ + active chain) BEFORE any byte; any miss/denial → 404. A **management** byte-read (Drive preview/download) MUST go through `DocumentService::serveFor()` (effective `read`, session principal, RF-4a) — never `serve()` straight from a controller.
- When defining `Document` fields that are server-controlled (`kind, mimeType, sizeBytes, checksum, source, slug, ownerId, active, deliveryMode, width, height, retentionUntil, deletedAt, profile, variants`) → MUST NOT mark them `#[Clean]`; MUST set them server-side. Only `displayName` and `showOriginal` are user-editable. The blob key is the document `id` — there is NO `storageKey` field, NO `visibility`/`publicPath` field (removed R5), and NO `area` field (removed ADR-020 — scope is the folder chain).
- When resolving a public document → MUST use the structural `DocumentService::resolve(segments)` (folder-slug walk from the tree top — first segment = the partition-root slug — + variant-suffix leaf, `deliveryMode`-gated by the caller); MUST NOT treat a path as a flat `publicPath` lookup key or its first segment as an `area` label. A missing folder segment MUST be rejected — documents always live in a folder.
- When a module needs its storage partition → MUST resolve it via `DocumentService::rootFolder($key)` (get-or-create, idempotent, system path — the partition is created as a CHILD of the drive root, ADR-021); the `$key` MUST be a **code constant** of the module — MUST NOT come from request input (S2, root-squatting), MUST match the slug charset `[a-z0-9-]`, and MUST NOT be the reserved `Folder::DRIVE_KEY` (`'drive'` — rejected). Module partitions are created `system = true` → rename/move/delete-locked (S4: the partition slug is the top segment of every public URL). Keys are unique by construction (get-or-create); duplicates from corrupt data resolve deterministically (smallest id, S3 — the File driver has no unique index).
- When a module WRITES documents (generated files, its own folder structure) → MUST go through its scoped handle `DocumentService::forModule($moduleKey)` → `ModuleDrive` (`root()` / `folder(...$names)` get-or-create below the target / `saveGenerated()`); the write target is the module's own key unless `dmsConfig['moduleFolders']` remaps it (module key → partition key — ADR-021 rule 4, the "optional remapping" of ADR-020). The subtree boundary is domain-enforced: a write outside/above the module's partition throws — MUST NOT call `saveGenerated` with a foreign `folderId` and MUST NOT try to bypass the handle for cross-partition writes.
- When rendering a Drive surface for the drive root → MUST NOT offer move, delete, upload or the `active` switch (the domain rejects all four; DMS-ROOT-001). MUST keep the subfolder action — a child of the root is a new partition.
- When a Drive surface lets the user place or expose content → MUST state the EFFECTIVE delivery mode of the target (inherited, `DocumentService::effectiveFolderDeliveryMode`), because `public` is inherited silently down a partition and decides world-readability (DMS-DELIVERY-VIS-001).
- When touching the drive root (ADR-021) → it stores NO documents (`SaveService`/`move` reject it), its slug is NOT a `/media` path segment (`resolve()` starts at its children; materialization skips it), it can never be renamed/moved/deleted (system lock), its `deliveryMode` is fixed `null` (never `sealed` — the cap would forbid any public partition forever) and it is always `active`. Its existence is guaranteed twice: the `module-dms` seed `folders.default.json` AND lazy `DocumentService::driveRoot()` (get-or-create + adoption of stray top-level folders). MUST NOT create a second top-level folder.
- When editing a document or folder in the Drive (name / `key` / delivery mode / ACL) → the surface is the ONE combined edit modal (`DriveControllerTrait::combinedEdit` + `_edit` partial, 2026-07-03 — the separate rename/mode/ACL modals and their actions are gone); opening it requires effective `manage`; the `save` op applies only CHANGED values (a locked-but-unchanged field never trips a domain lock) and grant/revoke ops re-render the modal in place. The field locks in the modal are PRESENTATION — the domain gates (`rename`/`setKey`/`setFolderDeliveryMode`/`grant`, ADR-021) are the protection; MUST NOT rely on the lock flags. A folder's `key` is editable ONLY here and only for SUPER_USER + human (non-`system`) partitions (`FolderService::setKey`: unique tree-wide, slug charset, `drive` reserved).
- When granting/revoking on the drive root or a partition → SUPER_USER-only (`requireGrantLevel`, ADR-021/D5): a delegated `manage` on a partition lets an area admin work INSIDE it and delegate BELOW it, never widen the partition itself. The partition lifecycle (create/rename/move/delete, incl. move-to-top) is equally SUPER_USER-only (`FolderService::requirePartitionGate`). The ACL bypass belongs to `superUser` alone — an `admin` (level 80) has NO implicit DMS access anymore; MUST NOT reintroduce an ADMIN-level bypass.
- When adding a backend documents controller → MUST live in the `documents` group (`Ui/Controllers/Documents/`, namespace `Z77\Module\Backend\Ui\Controllers\Documents`) and be registered in `backendConfig` (group-nested `controllers`, all `ADMIN`); the host mount carries NO scope (ADR-020 (b)) — the Drive self-scopes via ACL; the host role config is only the coarse first line, the domain gates are the protection.
- When giving the Drive a focused entry rooted at ONE partition (not the full readable forest) → the scope is a SESSION slot (`dmsDriveRoot`) driven by a `?key=<root-key>` switch trigger, NOT a second controller or a host override. A nav entry carries `?key=front` via `Navigation::param` ([`navigation.md`](navigation.md) NAV-PARAM-002); `DriveControllerTrait::mountRoot()` resolves it by module key first, else by root SLUG (`FolderRepository::findRootByKey ?? findRootBySlug`, both find-only — NEVER `rootFolder`, which is get-or-create → a crafted `?key=` would create a partition, S2), stores the root id in the session, and every following pane/modal request reads the session (no URL threading). A human-created root (`key = null`, e.g. `front`) is addressable by its slug — set via the normal folder create/rename flow; alternatively a SUPER_USER can give a human partition a `key` in the combined edit modal (`FolderService::setKey`, ADR-021 revision — the REQUEST-side `?key=` resolution stays find-only, S2). A blank `?key=` (present but empty) resets to the full view; `?key` absent keeps the stored scope. The trait then confines the tree top, breadcrumb home, default selection, and upload/move target selects to that subtree. This is an ADR-020 (b) **presentation** extension — MUST NOT treat it as a security boundary: a superUser still bypasses ACL (ADR-021), and a crafted `?folder=`/`?doc=` OUTSIDE the subtree is still served (every per-id read stays ACL-gated, RF-4a; the tree/breadcrumb filtering is presentation, never the gate). A hard confine would need an ADDITIONAL subtree check on every per-id action. MUST NOT resolve `?key` via `rootFolder` (S2) and MUST NOT reintroduce the removed `area` label; the root is addressed by its `Folder.key` OR — for a human-created root — its `slug` (`findRootByKey ?? findRootBySlug`). Setting a key from the UI exists ONLY as `FolderService::setKey` behind the combined edit (SUPER_USER + human partitions, ADR-021 revision) — MUST NOT accept a key from any other request path. A host MAY still override `mountRoot()` to hard-pin a mount.
- When wiring a multipart upload through a controller → MUST send it as a real multipart POST with the CSRF header (`documents/upload.js`, not the JSON fetch envelope) and read the files via `Request::getUploadedFiles()`; MUST NOT try to carry files through `_Z77.core.fetch.post` (JSON-only).
- When delivering bytes from a controller action → MUST return the `FileResponse` from `DocumentService::serve()` directly (the `Dispatcher` calls `send()` on it; a `FileResponse` is not an `HtmlResponse`, so the page cache never stores it); the private backend preview/download (`DocumentDeliveryTrait`) MUST be `ADMIN`-gated by the host group, the public `/media` route stays `GUEST` and relies on `OutputController` → `canRead()` for the gate.
- When exposing the public `/media/<root-slug>/…` route → MUST keep it as the `reservedRoutes` entry in `dmsConfig` (`/media` → `dms/media/output/serve`, highest routing precedence, matched before the Fetch short-circuit); the trailing path is the structural folder-slug walk (R3/R4/ADR-020). MUST NOT reintroduce a `/media` NavigationAlias, a flat `publicPath`, or an `area` path segment (all removed).
- When adding a BULK Drive mutation (v1 built 2026-07-16: documents delete + move — [`../03-development/dms-bulk-select-bauplan.md`](../03-development/dms-bulk-select-bauplan.md)) → the endpoint MUST loop over the EXISTING per-document gated service methods (no bulk bypass in the domain; a denied/missing id is counted as skipped and reported in ONE flash, never an error); the POST carries no per-entity token → the action MUST be `#[Fetch]` (DMS-SEC-001 rule); the ids CSV MUST be re-gated server-side via `readableDoc` (`bulkDocs`, capped `BULK_MAX`); the selection UI stays client-state while the modal base URLs stay server-built (`data-bulk-*-url`, drive.js appends only `&ids=`); the ids MUST travel as ONE hidden CSV field in the modal form (`_z77CollectFormData` collapses repeated hidden fields — only checkbox GROUPS become arrays); the action-bar reveal is CSS `:has()`, not JS.
- When adding a DMS management mutation (rename/move/delete/restore/purge/setActive/setDeliveryMode/grant/revoke/folder add-rename-move-delete/upload) → the authorization gate MUST live in the domain service (`DocumentService`/`FolderService`/`UploadService`) via `Authz::require($type, $id, $level)`, NOT in the controller/trait. Rationale: PHP trait precedence lets a host controller silently override a trait method (even `final`), and a host can mount a controller under a public route — a UI-layer gate is bypassable. The gate reads the principal from the **session** (`Authz::current()`), never a caller-supplied one (a caller could forge an admin `Principal`). Denials throw `NotFoundException` (404, no existence leak). GUEST (`userId 0`) is never owner and matches no ACE → rejected. System creation (`saveGenerated`/`SaveService::save`) stays ungated (trusted). MUST NOT rely on the host config role gate alone — that is the coarse first line; the domain gate is the un-bypassable second (R-authz-1, `../03-development/dms-authz-bauplan.md`).

## known issues

- **DMS-CHUNK-001 — built 2026-10-31** (F3, z77-rudaz.ch, owner-approved 2026-10-31: files of several GB; cyon accepts at most 500 MB per request). Don't assume an upload is bounded by `upload_max_filesize` any more — a file bigger than one chunk goes up in pieces.
  - **Domain** `Services\ChunkedUploadService` (HTTP-agnostic): `begin(folderId, name, size, overwrite, showOriginal) → uploadId` (gate `write` FIRST, then name cleaned, size > 0 and ≤ `maxUploadBytes`, executable extension refused, free disk checked, abandoned uploads swept) · `append(uploadId, index, UploadedFile, sha256)` (integrity, owner 2026-10-31: the received bytes must hash to the browser's SHA-256 of that slice, else `ChunkUploadException` 422 with `retry` → the client re-sends; a missing/malformed hash is a plain 400 — verified chunks at fixed offsets + exact total size = the assembled file IS the original; owner + gate again, index in range, every chunk exactly `chunkBytes`, the last the rest; re-send idempotent) · `status(uploadId) → received indices` (resume) · `finish(uploadId, ?overwrite, ?poster) → Document` (all chunks + exact size, then `UploadService::saveAssembled()` = the SAME pipeline as `save()` via the shared private `store()`/`classify()`; the file is RENAMED into the blob — `SaveService::saveFromUpload/replaceFromUpload(…, isHttpUpload: false)`, `move_uploaded_file` would refuse it; the transport cap does not apply) · `abort(uploadId)` · `cleanup(olderThan)`. Bytes wait in `data/dms/chunks/<32 hex>/` (`manifest.json` + `data.bin`, never under `public/`); the owner is stored as `sha256(realm:id)`, anyone else gets «not found»; `flock` on the manifest for append/finish. **Deviation from the brief:** each chunk is written straight to its offset in `data.bin` instead of a part file concatenated at finish — no second multi-GB copy inside the finish request (time limit), no double disk. finish removes the upload on success and on every refusal EXCEPT a name conflict: the bytes stay so «überschreiben» finishes again without re-sending. User refusals are `ChunkUploadException` (German message, code = HTTP status).
  - **Endpoints** `Ui\ChunkedUploadTrait` (`use` it in any controller; all `#[Fetch]`, JSON via `$this->json()`): `chunk-begin` (folder_id, name, size, [overwrite], [show_original] → `{ok, uploadId, chunkBytes}`) · `chunk-append` (upload_id, index, sha256, file field `chunk` → `{ok, received}`; hash mismatch → 422 `{retry:true}`) · `chunk-status` (GET/POST upload_id → `{ok, name, size, chunkBytes, chunks, received[]}`) · `chunk-finish` (upload_id, [overwrite], [poster] → `{ok:true, status:"success", data:{id,name}, commands?}` / `{status:"duplicate"}` 200 / `{status:"conflict"}` 409) · `chunk-abort`. Every refusal `{ok:false, status:"error", error:"<German>", data:{message}}` with 400/403/404/409/413/415/422/500/507; technical messages are logged, never shown. A host adds finish commands by overriding `chunkUploadCommands(Document)`. The Drive mounts it inside `DriveControllerTrait` (`drive/chunk-*`) and answers the pane refresh + `close-modal` like `uploadAction`.
  - **Config** (`dmsConfig`): `chunkBytes` (default 8 MB; clamped at runtime below the PHP transport cap minus head-room, `effectiveChunkBytes()`), `maxUploadBytes` (default `null` = no cap beyond the free disk). **Job** `dms-chunk-cleanup` («DMS-Upload-Reste», `Jobs\ChunkCleanupJob`, payload `hours`, default 24 h) — registered WITHOUT `defaultSchedule` (it deletes; [`jobs.md`](jobs.md) rule); every `begin()` sweeps the same leftovers, so the disk stays in check while it is off. Whether it should ship `daily@…` as a JOBS-SCHED exception is the owner's call (open).
  - **Client** `documents/chunk-upload.js` (+ `.min.js`): `Z77ChunkUpload.upload(file, {base, folderId, overwrite, showOriginal, poster, onProgress, signal}) → Promise<finish answer>`; chunk size from the begin answer, never from the script; SHA-256 per chunk via WebCrypto (secure context: HTTPS or localhost — otherwise a clear error, no upload), sequential chunks, 3 tries each (network / 5xx / 429 / `retry`), then `chunk-status` and resume (≤ 3 resumes); a finish is never repeated; abort (signal) removes the upload server-side; a conflict keeps the upload id per `File` (WeakMap) so a second call with `overwrite: true` only finishes. CSRF from `meta[name=csrf-token]` as `X-CSRF-Token`; no markup, no storage. In the Drive it is the upload component's transport `dms-chunk`: the kernel `upload.js` got a generic hook (`_Z77.upload.transport(name, {accepts, max, send})`, form attributes `data-upload-transport` + `data-upload-transport-config` written by `partials/upload` from `$transport` / `$transportConfig`); a file bigger than one chunk goes chunked (its cap = `maxUploadBytes`, an image still the GD cap), a smaller one keeps the one-request POST; rows, conflict question and commands stay the component's.
  - **Recipe for a project member page** (rudaz «Dokumente»): in the project's controller `use \Z77\Module\Dms\Ui\ChunkedUploadTrait;` (URL = that controller + `/chunk-begin` …); in the action rendering the page `$this->layoutManager->addJs('documents/chunk-upload', 'Z77\\Module\\Dms', 'footer', true);` and in the template call `Z77ChunkUpload.upload(file, {base: '<controller URL>', folderId: <id>, onProgress: …})` from the page's own file input (an own small script file — CSP: no inline script), then show `answer.status` (`success` / `duplicate` / `conflict` → ask, call again with `overwrite: true`). The member session must hold folder `write` — a role ACE for a role named by `principalRolesHook` (F1); no other check is needed or effective, the domain gate is the protection. Small files may keep the project's existing multipart action (`UploadService::save()`).
  - **Verified:** `tests/dms-chunked-upload.php` 59/0 (fakes for gate/owner/store, the REAL `UploadService::classify` and `LocalBlobStorage::putFile(…, false)`); the client against a mocked XHR server (assembly, retry + resume, conflict → overwrite without re-send, abort) and the `upload.js` / `upload.min.js` transport hook against a stub DOM — both ad hoc in Node, not in the repo. **Verified on cyon 2026-10-31** (rudaz release 2026-10-31-12, framework 0ed9843): the owner uploaded a 1.8 GB `.avi` through the rudaz member «Dokumente» page — chunk-append steadily ≈ one 8 MB chunk per 5 s (the owner's upstream), the single-request finish (hash + move of 1.8 GB) went through, the document is in the DMS. Per-chunk SHA-256 (added the same day): `tests/dms-chunked-upload.php` 62/0, the Node client test incl. a damaged chunk 11/0. **NOT verified:** the Drive (backend) path in a browser and its pane refresh after a chunked finish; files much larger than 2 GB on cyon (finish time limit). The `.min.js` are editor-minified since 0ed9843.
- **DMS-CHUNK-DL-001 — built 2026-10-31** (F5, z77-rudaz.ch: a 1.8 GB `.avi` could be uploaded in chunks — DMS-CHUNK-001 — but neither opened nor downloaded in the Drive; every PHP answer above ~500 MB fails at cyon with 413, 100 MB works; the cap cannot be raised). Don't assume a plain download link works for every document any more — from `dmsConfig['chunkedDownloadFrom']` on, the Drive fetches it in pieces.
  - **Server** `FileResponse` (kernel): EVERY single range — open-ended `bytes=n-`, explicit `a-b`, suffix `-k` — is answered with at most `FileResponse::RANGE_MAX_BYTES` (256 MiB, a constant: half the host cap, owner 2026-10-31), always the FIRST slice of what was asked. A media player (`bytes=0-`, Safari's explicit `0-<last>`) gets ≤ 256 MiB per answer, streamed, reads the true total in `Content-Range` and asks on by itself; a resumed native download (`bytes=n-`) is cut only where it would have hit the 413 anyway; the chunked download's 32 MB pieces are untouched. `Content-Range` always names the true total; a request without `Range` is unchanged (200, whole file); 416 / 304 / multi-range → 200 as before. Range parsing lives in the private `FileResponse::byteRange()` (its `$max` parameter exists only for the harness).
  - **Client** `documents/chunk-download.js` (+ `.min.js`, standalone, no dependency): `Z77ChunkDownload.download(url, {filename?, chunkBytes = 8 MB, onProgress(loaded, total), signal}) → Promise<{size, filename, via}>`. Same-origin URLs only (refused before any request). First request `bytes=0-(chunk-1)` → total from `Content-Range`, name from `Content-Disposition` (`filename*` first, raw UTF-8 recovered), ETag; then sequential `bytes=a-b` with an explicit end. Per piece: status 206, `Content-Range` start = asked, end ≤ asked (a shorter piece is accepted — the next starts behind it), total = the first, body length = end − start + 1, ETag = the first (else «Die Datei wurde während des Downloads geändert — bitte neu starten.»). 3 tries per piece on network error / 5xx / 429 / short body (backoff 1 s, 2 s); 200 / 403 / 404 / 413 / a redirect (login) are final with a German message; `signal` aborts every request; `cache: 'no-store'`, `credentials: 'same-origin'`. The piece checks are ONE implementation (`Z77ChunkDownload.reader` / `.respond`) shared with the worker.
  - **Save paths**, the first that works: (a) **service worker** `documents/chunk-download-sw.js` (+ `.min.js`; Firefox, Chrome, Edge, Safari — the owner's Firefox takes this one): the page script registers it from ITS OWN directory (`document.currentScript` → `<dir>/chunk-download-sw(.min).js`, `updateViaCache: 'none'`; min page → min worker; the worker `importScripts` the library beside it), scope = that directory (published `public/assets/dms/js/documents/` → `/assets/dms/js/documents/`), so it controls no page. The page posts `{token, url, filename, chunkBytes}` with a `MessageChannel` port; the worker answers `ready`; the page navigates a hidden iframe to `<scope>__z77-download/<token>`; the worker answers that navigation with `new Response(ReadableStream)` — `attachment; filename="…"; filename*=UTF-8''…`, `application/octet-stream`, `Content-Length` = total, `nosniff` — and its `pull()` fetches the next piece itself (one piece ahead at most, so a multi-GB file never sits in RAM); progress / done / error / cancelled (the user stopped the browser download) go back over the port; the page pings the worker every 10 s while it streams (`waitUntil` holds it as well). Limits: the worker answers ONLY `<scope>__z77-download/<32 hex>` (everything else gets no `respondWith`), a token is crypto-random, single-use and expires 60 s after it was posted, the worker re-checks that the URL is same-origin and fetches nothing else; the server gates every piece exactly like the plain link. When the worker is not available or does not pick the download up within 15 s, nothing has reached the user yet and the page falls back. (b) `showSaveFilePicker` + `createWritable` (Chromium only) when no worker exists and the click's user activation is still alive; needs `filename` for the suggestion. (c) last resort — **costs RAM about the file size**: the pieces into one `Blob`, one object URL, `<a download>`, revoked after 60 s (Firefox private window: no worker there).
  - **Drive**: `DriveControllerTrait::chunkedDownload($doc)` (null below `chunkedDownloadFrom`, default 256 MB — files below behave exactly as before) feeds `_preview` and the ⋮ menu (`_actions`): «Download» carries `data-dms-chunk-download` (the gated `document/download` URL), `-name`, `-bytes` (`downloadChunkBytes`, 32 MB); «Öffnen» carries them too unless the kind is video/audio — those open the player dialog (`chunk['play']`, see below). `listAction` loads `documents/chunk-download`; drive.js takes over a plain left click on such a link (the href stays the fallback) and shows a progress line in `#flash-messages` (built with `textContent`, its × aborts), then a success / info (abgebrochen) / error flash. One download per URL at a time.
  - **Config** (`dmsConfig`): `chunkedDownloadFrom` (bytes, 0 = never), `downloadChunkBytes` (bytes, own key — the upload's `chunkBytes` is clamped to the PHP upload limit and says nothing about answers). The kernel cap `RANGE_MAX_BYTES` stays a constant.
  - **Recipe for a project page** (rudaz member «Dokumente»): the page's download action returns `DocumentService::serveFor()` (a Range-capable `FileResponse`, member session gate as today); in the action rendering the page `$this->layoutManager->addJs('documents/chunk-download', 'Z77\\Module\\Dms', 'footer', true);` — included as its own published file, never bundled or inlined (the worker URL and scope come from `document.currentScript`); in the page's own script file (CSP: no inline script) `Z77ChunkDownload.download('/member/main/dokumente/download?id=' + id, {filename: name, chunkBytes: 32 * 1024 * 1024, onProgress: function (loaded, total) { bar.value = loaded / total; }}).then(done, function (err) { if (err.name !== 'AbortError') note.textContent = err.message; });` for files from the project's own threshold on. Nothing to register by hand: the worker is registered on the first download; it needs HTTPS (or localhost) and the published assets (normal deploy). When the framework sets a CSP (pending, conventions.md), it must allow `worker-src 'self'` and `frame-src 'self'`.
  - **Verified on cyon 2026-10-31** (rudaz release 2026-10-31-14, framework 646f070): the owner downloaded an 867 MB `.wmv` through the Drive «Download» in Firefox (service-worker path) — progress line «wird vorbereitet» → progress → saved; 866,791,325 bytes and the SHA-256 identical to the original.
  - **Verified:** `tests/file-response-range.php` 63/0 (the cap on every range kind through `byteRange()` with a small `$max` via reflection, its default = the constant; built-in server: 206 with the true total, clamping, suffix, 416 / empty file / multi-range, ETag + 304, the bytes at every offset); ad hoc in Node (not in the repo): the library + worker against a fake Range server and a fake worker global, 78/0 for `.js` and `.min.js` (assembly, shorter pieces, empty file, Content-Range / length / ETag / total mismatch, retry 503 / network / 429 / short body, abort, names, the worker's prefix / token / origin / expiry limits, the streamed response, page ↔ worker end to end incl. fallback, user cancel and page abort, picker and Blob paths); drive.js wiring against a stub DOM 11/0 (`.js` and `.min.js`). The `.min.js` were written with the owner's editor minifier (terser, `mangle: false` — reproduces the committed `drive.min.js` / `chunk-upload.min.js` byte for byte). **NOT verified:** cyon's behaviour with these 206 pieces — the first check after deploy is `curl -r 0-33554431` (expect 206, 32 MB, `Content-Range … /<total>`) and `curl -r 0-` (expect 206, 256 MiB) on `…/document/download?id=` with a session cookie; the worker path in a real Firefox (and Chrome / Safari); the picker path in a real Chromium; the progress line's look. «Öffnen» of a LARGE video / audio (owner decision 2026-10-31, after Peter saw «new tab → 413»): a top-level navigation asks WITHOUT `Range`, so the new-tab link is replaced by the player dialog `drive/player?id=` (`playerAction`, `_player.tpl.php`, read gate like the preview): `<video>` / `<audio controls preload=metadata>` on the same gated preview URL — the browser's player asks `bytes=0-` and on, each answer capped at `RANGE_MAX_BYTES`, seeking included; no script. A format no browser plays (WMV, AVI, MKV, FLV, WMA — anything outside `DriveControllerTrait::BROWSER_PLAYABLE`: mp4 m4v webm ogv mov / mp3 m4a aac wav ogg oga opus flac weba) gets NO player — the owner saw a 600 MB `.wmv` stuck at 0:00 — but the hint and a Download link (chunked attributes from `chunkedDownloadFrom` on; drive.js listens document-wide, so it works inside the dialog). NOT verified in a browser / on cyon.
- **DMS-UPLOAD-TYPES-001** — built 2026-10-31 (F4, z77-rudaz.ch: a LAZ upload failed «file type 'application/octet-stream' is not allowed»). `dmsConfig['extraUploadTypes']` (list of extensions or ext => MIME) lets an installation accept types `finfo` cannot name. A listed extension WINS over the sniff (an IFC sniffed `text/plain`, an XML no longer count as previewable text) and becomes `DocumentKind::File`: not previewable, no variants, no poster, `alwaysDownload()` → `DocumentService::serve()` answers `attachment` even on the inline path (`/media`, protected and public), and `materialize()` never writes a static copy (the web server would pick the content type by extension — `.xml` would render inline); public `file` documents are therefore always streamed by PHP (Range-capable `FileResponse`). Stored MIME = the listed one or `application/octet-stream`, never the client's. `UploadTypes` ignores server-executable / page extensions (php…, html, svg, js). Size limit and duplicate / conflict logic unchanged. Empty list = exactly `fromMime()` (harness (B), 21 samples) — axo3 / zihlundsee unchanged. `mailable()` stays true for `file` (not asked for). Verified: `tests/dms-upload-types.php` 21/0; no browser upload yet.
- **DMS-MEDIA-PHP-001** — resolved 2026-10-31 (found while building F4, owner: «ja los»). `fromMime()` admits every `text/*`, so a `.php` file (sniffed `text/x-php`) uploaded as kind `text`; in a `public` folder the first `/media` hit materialized it as `public/media/…/<slug>.php`, and from then on the web server served that path ITSELF — the project's `public/.htaccess` (`AddHandler … .php`) applies to `public/media` too, so it executed. Who could upload: everybody with `write` on a folder — backend users and, since F1, members with a project write role. Three lines now: (1) `UploadTypes::kindFor()` refuses `UploadTypes::EXECUTABLE` (php…, phtml, phar, shtml, cgi, pl, py, sh, asp, aspx, jsp, htaccess) in EVERY installation, whatever the sniff — behaviour change: such a file can no longer be uploaded; (2) `materialize()` never copies such an extension (covers documents stored before); (3) `public/media/.htaccess` is written when missing — by `materialize()` AND by every `/media` request that reaches PHP (`OutputController` → `DocumentService::guardMedia()`; the first deploy probe on rudaz failed because an all-protected installation never materializes, fixed the same day) — `<FilesMatch>` on exactly those extensions with `SetHandler none` + `ForceType text/plain` (FileInfo level, the same `AddHandler` needs; deliberately NOT a deny file — public/media IS shared/media). Don't edit that `.htaccess` on a server, the framework writes it; an existing one is left alone. Verified: `tests/dms-upload-types.php` (G), 30/0; verified on cyon 2026-10-31 (rudaz release 2026-10-31-6): a `/media/x/y` request (404) wrote the guard, a hand-placed `.php` came back as `text/plain` with its source on both doors, not executed. Other installations get the guard with their next deploy and first `/media` request.

- **DMS-MENU-001** — built 2026-10-22 (owner). The Drive's ⋮ (folder tree `_tree.tpl.php`, document list `_list.tpl.php`) is a context menu like every row ⋮ (fetch.md MENU-001): `.dms-rowmenu` carries `data-menu-open` instead of `data-modal`, `DriveController` `_actions.tpl.php` renders only `.be-menu` content — the «Aktiv» switch as a real switch row (`.be-menu__switch`, `data-fetch-toggle` → `actions…&op=active`, the panes behind refresh, the menu stays open), the delivery hint as a `.be-menu__note` (`--warn` for public), the items (Bearbeiten, Verschieben, Neuer Unterordner / Bereich, Datei hochladen, Öffnen, Download, Löschen) opening their dialogs as before. `actionsAction` unchanged. Don't assume `data-modal` is gone: `drive.js` keeps it for the preview pane's Bearbeiten / Verschieben / Löschen; bulk actions, upload, new folder and trash have their own attributes and are untouched. Don't assume the ⋮ trigger survives a toggle — the pane refresh replaces it; the open menu stays usable and closes as usual. Verified: `tests/form-actions.php` (hub + both triggers). **Not verified in a browser.**
- **DMS-COPY-URL-001** — built 2026-10-23 (owner). A document whose EFFECTIVE delivery is `public` gets «Pfad kopieren» in its ⋮ (`_actions.tpl.php`): the `/media` PATH — no host, no `?v=` cache key (owner: the path is what gets pasted) — from `DocumentService::publicUrl()` (`DriveControllerTrait::publicPath()`), copied through core.js `data-copy` from an off-screen readonly input (not shown — owner 2026-10-23). Not offered for protected / sealed documents (no public address).
- **DMS-GD-DEPRECATION-001** — resolved 2026-10-10 (owner at the live look: «Fehler beim Hochladen einer PNG-Datei», «Server-Fehler (200)»). Every IMAGE upload was SAVED (original + variants on disk, the row in the database) but reported as failed: `GdImageProcessor` called `imagedestroy()`, a no-op since PHP 8.0 and DEPRECATED in 8.5; in dev mode (`debug.flag`) the error handler prints each notice as an HTML block into the response, so the upload's JSON came after five `<div>`s and `upload.js` could not parse it — row red «Server-Fehler (200)», the flash «1 Datei nicht übernommen», and the operator uploads again (duplicates). Videos were unaffected (no GD). Fix: `unset()` instead of `imagedestroy()` (the GdImage object is freed with its last reference). Don't call a PHP 8.5-deprecated function anywhere in a fetch path — dev mode turns it into a broken answer; already gone: `finfo_close()` (UploadedFile), `curl_close()` (GeoIpUpdateJob). Found by the owner's response copy, not by a harness: `tests/upload-component.php` checks the contract, not the GD run.

- **DMS-FORM-ACTIONS-001** — built 2026-10-10 (ADR-049 revision 2026-10-10, review `../03-development/forms-actions-review-2026-10-10.md` Part A rows 37–48). **Every DMS dialog shows its actions in ONE row under the header, through the shared partial** (`Z77\Shared` `partials/modalActions` → `.z77-form-actions`); no DMS template renders `.be-modal__footer` any more. Top row: the combined edit modal (`_edit`), document move, bulk move, folder move, folder add/rename (`FolderController/edit`), the ⋮ action hub (`_actions`, «Schliessen» only) and the trash. Bottom (`end`, the field-less-confirm exception): document delete, bulk delete, folder delete (incl. its not-found / blocked variants) and the new «Papierkorb leeren» confirm. **The edit modal** had its footer in the MIDDLE (between the fields and the ACL section) and its wrapper `.dms-edit` held a `<form>` around header + body, so the popup's flex chain (`_modal.scss`, `.z77-popup__body > *`) never reached the body — nothing was pinned. Now `.dms-edit`'s DIRECT children are header, action row, body: the save form (`id="dms-edit-<type>-<id>"`) sits inside the body and the row's «Speichern» submits it through `form="…"` (still the first submit in document order → Enter saves); the ACL section follows the fields in the same body. «Recht hinzufügen» is a one-line capture (type, subject, right and the button in ONE grid row); per-rule «Entfernen» stays row-bound. **The trash**: «Papierkorb leeren …» was a `<details>` at the END of the modal; it is a danger button in the top row now, beside «Schliessen». It opens a confirm step — a GET of the same panel with `confirm=purge-all` (`trashAction` renders `_trashConfirmPurge`; GET only, the POST of that step carries `op=purgeAll` into the existing branch, CSRF via the global Fetch gate as before). «Zurück» there re-opens the panel. `trashAction` now hands the panel its own address (`trashUrl`, selection kept, `confirm` dropped). Per-row «Wiederherstellen» / «Endgültig löschen» stay row-bound. Transport unchanged everywhere: fetch POST, answers re-render the popup and replace the Drive panes (`paneRefresh` / `replace-html`) — no `reload` anywhere in the DMS. Not touched (UPLOAD-001 migration): `_upload.tpl.php`. Verified: `php -l`, an ad-hoc render of all 14 dialog shapes (row position checked), `tests/upload-component.php` 101 ok; the browser click-through is open (`## pending`).
- **DMS-TOOLBAR-001** — built 2026-10-10 (owner, after the live look). **One group, one look — and the trash is a VIEW, not a tool.** The Drive's toolbar held four tools in three dresses: «Neuer Ordner» (icon + label), bare icon buttons for edit and move, «Löschen …» (icon + label, danger) and a bare trash icon. Now the four folder tools are `.be-btn` with icon AND label — «Neuer Ordner», «Bearbeiten», «Verschieben», «Löschen …» (an icon replaces a word only where the word does not fit, `backend-screen.md` → Controls). The TRASH moved to the RIGHT end of the same row as a TAB (`.be-viewtabs`, pushed by `.be-drive-tools__gap`): it carried the same `#icon-trash` as «Löschen …» standing beside it — one picture for «delete this folder» and «show what was deleted» — and it is not an operation on the open folder: emptying the trash is a rare cleanup on the whole COLLECTION (owner: «es hat nichts mit dem gerade geöffneten Ordner zu tun»). Hence the split by WHAT a control acts on: tools on the selection left, views of the collection right, and the right cluster is a tab strip because more such views are expected. A first attempt put the trash at the foot of the folder tree (the file-manager pattern) — rejected live by the owner: without a selection the tree is one line tall, so the entry read as a second root folder.
- **DMS-SEED-001**: don't look for a way to seed demo Drive data — there is none. `skeleton/_seed_drive_demo.php` built one partition root (`Ablage`, key `backend`) with folders and text documents, and every «re-seeded» note further down this file refers to it. It was removed on 2026-08-25 together with the rest of the skeleton installation (only `composer.*` and `dev.bat` remain), and the installer has no replacement: nothing in `Install` creates a root folder. Consequence for a fresh installation — the Drive starts empty, and the first partition is created by hand through the backend («Neuer Bereich» on the root, SUPER_USER, see DMS-ROOT-001). If a seed is wanted again it is new work, not a restore — the old script was explicitly a throwaway (`dms-extraction-bauplan.md`). Note that the skeleton INSTALLATION itself is not gone in the same sense: `composer.json` / `composer.lock` / `dev.bat` remain, so `composer install` in `skeleton/` rebuilds the tree. Only the seed script is unrecoverable.
- **DMS-ROOT-001** — resolved 2026-08-08. The ⋮ action hub offered the drive root four controls the domain rejects: «Verschieben» + «Löschen» (`assertSystemRootUnlocked`), «Datei hochladen» (`SaveService`: *the drive root cannot store documents*) and the `Aktiv` switch (the root is always active). All four are hidden for the root now; «Bearbeiten» stays (ACL grants) and the subfolder action stays but reads «Neuer Bereich» — a child of the root IS a new partition (`FolderService::add`, SUPER_USER-gated), which is the intended way to create one. The hub also states plainly what the root is. Presentation only — the domain gates were and are the protection.
- **DMS-DELIVERY-VIS-001** — resolved 2026-08-08. Nothing in the Drive showed the EFFECTIVE delivery mode, although it is inherited: under a `public` partition every subfolder inherits `public` and is `active` by default, so an upload is world-readable the moment it lands (zihlundsee: `front` is public → all 14 descendants inherit it). The action hub now names it («Auslieferung: Öffentlich / Geschützt / Versiegelt», with the inheritance note), and the upload modal warns when the pre-selected target delivers publicly. Note what this is NOT: the drive root does not make anything public — `resolveEffective()` falls back to `protected`, and materialization requires `public` AND an active chain (proof: `back` hangs directly under the root and is protected).
- **DMS-SEC-001 — security review 2026-07-02 ([`../03-development/dms-security-review-2026-07-02.md`](../03-development/dms-security-review-2026-07-02.md)).** Full DMS + backend-consumer read-through; three issues found and FIXED: (1) **CSRF on the active-toggle** (`DriveControllerTrait::actionsAction`) — it validated neither `#[Fetch]` mode nor a per-entity token, so a cross-site **Page-mode** POST (`Sec-Fetch-Mode: navigate` → `AccessGuard` skips its Fetch-only CSRF check) could flip any document/folder `active` gate; fixed by adding `#[Fetch]`. (2) same on `folderAddAction` (create folder) — fixed by `#[Fetch]`. (3) `Document::setOriginalName()` stored the raw client filename despite the docblock claiming sanitisation — now `basename()` + control-char strip (Content-Disposition hardening). **Rule for new mutating Drive actions:** a POST that carries no per-entity `validateEntityToken` MUST be `#[Fetch]`-annotated (so the global CSRF gate applies) — the dual GET/POST modal actions rely on the entity token, the toggle/create actions rely on `#[Fetch]`. Reviewed sound: `/media` GUEST gate (guest holds only the `guest` role → a `visitor` grant is not anonymous), path handling (id-addressed blobs, slug-equality resolve), MIME allowlist, template XSS escaping.

- **DMS-UI-001** — resolved 2026-06-15 (Phase 5). Backend folder/document UI + the public route now exist: `FolderController` + `DocumentController` (group `documents`), `MediaController` (frontend, `/media/{publicPath}` via the seeded `/media` alias). Private delivery is `GET /backend/documents/document/{preview,download}?id=` (4-segment convention, ADMIN). The conceptual `/documents/{id}/download` from the bauplan is realised as that convention URL, not a friendly alias.
- The authenticated multipart upload through the live browser UI was NOT manually clicked through (needs an admin login; the dev's admin password is not available here). The full **byte pipeline** behind it (save → blob → GD variants → serve/servePublic/publish/rename/move/soft-delete) IS verified e2e via a throwaway CLI smoke (2026-06-15, all green); HTTP routing/auth/alias verified via curl (backend → 302 login, `/media/x` → 404, no fatals). Remaining manual check: log in and upload a file through the modal.
- Web-server-accelerated delivery (`X-Sendfile` / `X-Accel-Redirect` / LiteSpeed) was **rejected** (2026-06-23): not portable (cyon ships no `mod_xsendfile`), and the PHP range-stream is sufficient for the low-volume, authenticated protected bytes. `FileResponse` lost its `delivery`/`internalPath` parameters and the `DELIVERY_*` constants; byte transfer is always the PHP range-stream. See ADR-017 Rejected Alternatives.
- `fileinfo` extension is REQUIRED (server-side MIME sniff in `UploadedFile::sniffMime()` + `FileResponse`). It was disabled in the dev `C:\php\php.ini` and is now enabled (`extension=fileinfo`). Don't assume `sniffMime()`/`mime_content_type()` work without it.
- `SaveService` is transactionless (File driver, `ARCH-A003`): if a blob write fails after the first flush, the `Document` row is orphaned (metadata without bytes). Cleanup-on-failure is a pending hardening.
- **MAIL-DMS-001** — resolved 2026-06-15 (Phase 6). `DocumentKind::mailable()` + `DocumentService::send(id, recipients, subject, body, variant?)` exist: send gates on `mailable()` (everything except video/audio), reads bytes via `BlobStorage`, and mails through the `Mailer` stack ([`mail.md`](mail.md)). Backend UI: `DocumentController::sendAction` + the `send` modal (warns when mail is unconfigured). `send()` throws `RuntimeException` when `config/mail` is absent/disabled — callers MUST surface that.
- `SaveService` byte pipeline is now exercised end-to-end (Phase-5 CLI smoke, 2026-06-15). `UploadService` itself (the `$_FILES` → `UploadedFile` → `finfo` gate layer) is covered by routing + its own unit pieces but not yet through a live authenticated multipart POST — see the manual-upload check above.
- The Drive is NOT host-scoped (ADR-020 (b), built 2026-07-02): it shows every partition root the principal may `read` — don't assume a per-host area isolation anymore; isolation is ACL (grant on a root = that partition). `showOriginal` is chosen at upload time only — toggling it later is not offered (variants are not retroactive; reprocess is a later phase). Image variants are reached via `?variant=s|m|l|xl` on the preview/serve URL, or as the `.<variant>.` filename segment on the structural `/media` path.
- The File driver cannot enforce root-`key` uniqueness (no unique index, no transactions — ARCH-A003): don't assume a hard guarantee; `rootFolder()` resolves duplicates deterministically (smallest id wins) and re-checks after its own create (S3).
- GD memory scales with PIXELS, not file size (a 24-MP photo decodes to ~120 MB) — don't assume the upload cap bounds processing memory. `GdImageProcessor::generate` guards this BEFORE decoding (`fitsMemory`: header dims × ~5 bytes/px + per-variant dest/convolution copies vs. remaining `memory_limit`, 20% margin): an image that does not fit yields NO derivatives (`[]`, original still stored/served — same graceful path as an unsupported format), never a mid-save fatal. Dev `memory_limit` raised 128M → 512M (2026-07-02) after a live upload fatal in `imageconvolution`.
- **DMS-MEM-001 — `fitsMemory` false negative silently skips variants (found 2026-07-16, zihlundsee).**
  Batch-uploading 45.4-MP photos left 3 of 9 documents WITHOUT any variants (no thumbnail, no
  derivatives) and WITHOUT any error — every file reported "Hochgeladen". Root cause:
  `GdImageProcessor::fitsMemory()` (and `UploadService::fitsMemory()`) subtract
  `memory_get_usage(true)` — RESERVED memory incl. ZendMM's retained-but-reusable cached chunks —
  from `memory_limit`. After a prior variant-generating request in the same persistent PHP worker
  (php -S dev, LSAPI on cyon) the reserved figure stays elevated (measured 52–278 MB), so the guard
  refuses images that actually process fine (real peak ~264 MB < 512M). NOT a real OOM, NOT the
  batch design (`upload.js` is strictly sequential, one file per request), NOT the PHP limits.
  **Guard FIXED 2026-07-16 (uncommitted):** both checks now measure against
  `memory_get_usage(false)` — proven safe under limit pressure (ZendMM reuses its cached chunks:
  the 3 failed originals process back-to-back under a 400M limit, full variant set each, real
  `GdImageProcessor`). **Dev live-CONFIRMED same day (skeleton):** a fresh browser batch upload of
  11 × 45.4-MP photos produced thumbnails/variants for ALL of them (before the fix the same batch
  dropped 3). Still OPEN: (a) surface the no-variants outcome in the upload envelope/log
  (today the skip reason is discarded — silent degradation); (b) repair the 3 zihlundsee docs
  (delete + re-upload — a re-upload over the LIVE doc is checksum-skipped, so delete first);
  (c) cyon re-test + `memory_limit` check (≥ ~380M needed). cyon note: `memory_get_usage()` is SAPI-independent;
  long-lived LSAPI workers make the `(true)` degradation worse, but production `memory_limit`
  must still be ≥ ~380M for 45-MP sources. Full analysis + repro:
  [`../03-development/dms-upload-variants-review-2026-07-16.md`](../03-development/dms-upload-variants-review-2026-07-16.md).
  Affected zihlundsee docs 35/37/39 need delete + re-upload once fixed (variants are not retroactive).

- **DMS-SORT-001 — built 2026-09-07 (zihlundsee: the slider sequence had to be encoded in filenames).** Don't
  assume `listByFolder()` is unordered anymore — it IS the manual order (`sortKey`, see `## rules`), and a
  consumer that re-sorts by slug silently discards what the editor arranged in the Drive. Mechanics mirror
  the backend-user list (server-rendered `draggable` rows, `drive/sort` POST, DOM relocation on success, no
  pane refresh). Known limits: HTML5 drag & drop is mouse/pointer only (no touch reorder — same as the
  backend tree/user lists); `move()` appends at the end of the target folder and leaves a GAP in the old
  group (dense renumbering happens on the next `reorder()` there — harmless, the sort is `[sortKey, id]`);
  `reorder()` does not bump `updatedAt` (metadata order only — a consumer keying a cache on
  `max(updatedAt)` must not depend on order, zihlundsee's slider CSS keys on the COUNT only). Verified:
  CLI smoke (order, append, reorder renumbering under a SUPER_USER principal); the browser drag itself is
  the developer's check.
- **DMS-MAT-001 — resolved 2026-09-07 (zihlundsee: deleting 10 slider images took > 70 s).** Don't assume
  a DMS mutation is cheap because it is metadata-only: until this fix EVERY public-relevant mutation
  (delete/restore/purge/move/active/mode/upload/folder rename+move/`saveGenerated`) ended in
  `rebuildMaterialization()` — wipe ALL partition dirs under `public/media`, then read every public
  blob (original + all variants) into RAM and write it back. zihlundsee carries 824 files / 650 MB
  there, and the bulk delete loops `delete()` per document → 10 × 650 MB of disk I/O for 10 images.
  Fixed by turning `public/media` into a LAZILY filled cache: mutations only REMOVE the files they
  stale (`invalidateMaterialized` / `invalidateMaterializedFolder`, a handful of unlinks), the
  `OutputController` writes exactly the requested variant on a static miss (`materialize`, copy via
  temp + rename, self-re-gated). `rebuildMaterialization()` / `writeMaterialized()` are removed;
  see the `## rules` entry for the ordering (before a path change, after the flush of a gate change).
  Deploy note: an existing `public/media` tree (a `shared/` dir in the release layout) stays a valid
  cache — nothing to migrate, nothing to clear. Known residual: a public hit that reads a document
  BEFORE a gate-change flush and writes AFTER the post-flush invalidation leaves a stale static file
  (window = one JSON flush, milliseconds); the File driver has no locking to close it, and the next
  mutation of that document or folder removes it. Also gone with the rebuild: the per-mutation
  `driveRoot()` heal (ADR-021 stray adoption) — it still runs at `rootFolder`/`FolderService::add/move`.
- **DMS-DIM-001** — resolved 2026-07-14. `mediaImage($path, $variant)` returned the ORIGINAL `width`/`height` regardless of the requested variant (`imageForPath()` read `$entry['width']`/`['height']` — the doc dims — and ignored `$variant`), so an `<img>` for a derivative got the original's dimensions (wrong aspect hint / CLS). Fixed: `imageForPath()` now takes the variant's own `w`/`h` from `variants[$variant]` (`ProcessedVariant::toMeta` `{w,h,bytes,ext}`) for a named derivative, and the document dims only for the original (`$variant === null`). `buildPublicUrl()` already returns `null` for an unknown variant, so the branch is safe. Verified: dimension-selection logic (null→original, `m`/`s`→variant dims); live check pending in the project.

## pending

- **Bulk selection + bulk actions v1 (delete/move) — BUILT + selection UI skeleton-confirmed 2026-07-16.**
  Confirmed in the skeleton browser test: checkboxes (after the `appearance: auto` fix — the
  backend normalize strips ALL form-control appearance, see [`css-dms.md`](css-dms.md)), bulkbar
  reveal, counter, Alle/Keine. Still to click through: the bulk move/delete modals themselves +
  «Papierkorb leeren» (since 2026-10-10 a top-row button with its own confirm step,
  DMS-FORM-ACTIONS-001).
  Drive list rows carry a quiet checkbox (`.dms-file__select`, hover-revealed, always-on for
  touch); once one is checked, a sticky action bar (`.dms-filelist-bulkbar`: counter, Alle/Keine,
  Verschieben, Löschen) appears via pure CSS `:has()`. drive.js adds shift-click range, the
  counter, and opens the bulk modals by appending `&ids=<csv>` to the server-built
  `data-bulk-*-url`. Trait: `bulkMoveAction` (GET modal `_bulkMove` + POST) /
  `bulkConfirmDeleteAction` (GET modal `_bulkConfirmDelete`) / `bulkRemoveAction` (POST) — all
  `#[Fetch]`, ids re-gated via `bulkDocs()` (readableDoc, cap 500), loop over the existing gated
  `DocumentService::delete/move`, partial success in ONE flash („N verschoben, M übersprungen“),
  `paneRefresh` success. v1 scope (user-approved): documents only, delete + move only; further ops
  dock onto the same bar later. Known limits: ids travel in the modal-GET query (very large
  selections → URL length; POST-modal flow is the escalation) and each loop iteration triggers the
  usual per-mutation cache clear (the per-mutation FULL `rebuildMaterialization` that made a
  10-image bulk delete take 70 s on zihlundsee is gone since 2026-09-07 — DMS-MAT-001). **Nachtrag same day: „Papierkorb leeren“** — the trash panel gained a
  JS-free `<details>`-confirmed `purgeAll` op (`trashAction`, now `#[Fetch]` — the op spans n
  documents, no per-entity token, DMS-SEC-001 rule; row ops keep their entity tokens): loops the
  principal-scoped `listDeleted()` through the existing gated `purge()` — retention-blocked
  documents are skipped, stay listed, and are reported in the flash.
  Plan: [`../03-development/dms-bulk-select-bauplan.md`](../03-development/dms-bulk-select-bauplan.md).

- **Folder-bound image profiles + project override config — DONE, framework side (2026-07-13).**
  Goal (reference project): a Drive folder (e.g. `drive/front/slider/home/main`) carries an
  image-size config; uploads resize accordingly, `default` as fallback. As built: (1)
  `Folder::$profile` (nullable, server-controlled, normalizing setter); (2)
  `ImageProfileRegistry::fromConfig()` replaces `fromModules()` — reads the ONE partition-namespaced
  project config (`override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php`,
  `partitionIdent => profileName => sizes`, ident = root `key ?? slug`), new `names()` +
  `DEFAULT = 'default'`; (3) `SaveService`: `chainInfoOf()` (one walk → partition ident + effective
  folder profile) + `resolveProfile()` (explicit = strict/throw; null = AUTO: folder ?? `default`
  ?? none, lenient on stale assignments), resolved name persisted on `Document.profile`; (4)
  `DocumentService::partitionIdentOf()`/`effectiveFolderProfile()`; (5) gated
  `FolderService::setProfile()` (manage, registry-validated, drive root refused); (6) combined
  edit modal: "Bildprofil" select on folders (rendered only when the partition has profiles,
  inherited-value hint, changed-only save); Drive `uploadAction` unchanged (`profile: null` =
  auto). Verified: CLI smoke 22/22 (registry, round-trip, 2-level inheritance, guest gate denial,
  save cases: inherited/default/stale-fallback/no-config/explicit-unknown-throw/explicit-override).
  ADR-020 revision note 2026-07-13. **Live-confirmed (2026-07-13, reference project):** profile assign via
  the edit modal + upload to `front/slider/home/main` produced the `slider` variants (the select
  needed a backend-CSS fix: the normalize reset left ALL backend `<select>`s unstyled/affordance-less
  — `_forms.scss` now styles `select` like `input` incl. the bare ACL-grant selects). **Admin-set
  reduction (decided with the dev 2026-07-13, after the live test):** when a project profile
  resolves, the `admin` contribution shrinks to `s` (list thumb) + `m` (preview pane,
  `ADMIN_PREVIEW`) — `l`/`xl` would duplicate the project's large variants (was 8 files/image,
  now 6); without a project profile the FULL `admin` ladder stays. Re-smoke 3/3 (exact variant
  sets per case). NOT retroactive — earlier uploads keep their `l`/`xl` (delete + re-upload to
  regenerate; an identical re-upload is checksum-skipped).
  Plan: [`../03-development/dms-folder-image-profiles-bauplan.md`](../03-development/dms-folder-image-profiles-bauplan.md).

- **Image `alt` + `figcaption` on documents — multilingual, DONE + live-confirmed (2026-07-12).**
  Model **B (per-language)**: ONE document / ONE blob (no duplication) — only the two
  display-text fields are language-keyed maps `array<lang,string>` (e.g. `{"de":"…","fr":"…"}`) as
  **dedicated** `Document` fields `alt` / `caption` (NOT `meta[]` — stable content attributes → typed getters
  `getAltMap()`/`getCaptionMap()` + clean Doctrine JSON column later; `mapFromArray` ignores unknown keys so
  old JSON is harmless). Built: (1) entity fields + getters/setters; (2) gated domain method
  `DocumentService::setImageText($id, $alt, $caption)` (same gate as `rename()`; `cleanTextMap` keeps only
  configured languages, trims + strips control chars, drops empties); (3) combined edit modal — `editVm()`
  passes `isImage`/`languages`/`altMap`/`captionMap`, `_edit.tpl.php` renders one alt + one caption input per
  language (`kind=image` only, flat field names `alt_<lang>`/`caption_<lang>`), `applyEditSave()` reassembles
  the maps and calls `setImageText` changed-only; (4) consumption helper `mediaImage(string $path, ?string
  $variant = null): ?array` → `{url, alt, caption, width, height}|null` with alt/caption already resolved to
  the current request language (i18n default fallback, the `t()` order) via `DocumentService::imageForPath()`
  → `localizeMap()`; (5) the `publicPathIndex` cache carries `alt`/`caption`/`width`/`height` so `mediaImage()`
  is O(1) like `mediaUrl()`. Verified: CLI smoke 10/10 (kind, round-trip, imageForPath localized, dims,
  mediaImage parity/miss, current-language pick, fr-only-under-de → empty, cache rebuild). Live-confirmed: the gated `setImageText`
  saves through the Drive edit modal (Bildtexte per language) in the browser. Plan:
  [`../03-development/media-url-helper-bauplan.md`](../03-development/media-url-helper-bauplan.md).

- **`mediaUrl()` template helper + DMS-scoped resolve cache — DONE + live-confirmed (2026-07-12).**
  Global `mediaUrl(string $path, ?string $variant = null): ?string` (in `module-dms/src/helpers.php`,
  registered via composer `autoload.files` — defined only when module-dms is installed, so `kernel/core` stays
  DMS-free) resolves a DMS document by its structural slug path to a public `/media/…?v=<checksum>` URL for
  templates; it delegates to `DocumentService::urlForPath()`. **Step 1 DONE** (helper + `urlForPath` + autoload,
  CLI smoke 5/5). **Step 2 DONE** (cache): `DocumentService` gained a `DataCache` dep + `folderSlugIndex()`
  (reduced id→{parentId,slug}) + `publicPathIndex()` (structural path → URL fields), pattern `AclService`, so a
  page-render resolving many images pays 1 folders + 1 documents load then O(1) lookups; the URL format is now
  assembled by ONE private `buildPublicUrl()` shared by `publicUrl()` (entity path) and `urlForPath()` (cached
  path) → byte-identical. Correctness covered by the existing `invalidatesCache` (any DMS write → `clearAllApcu`
  drops PageCache + the index). CLI smoke 5/5: parity, cache-hit ignores a raw file edit, rebuild-after-write,
  miss. Live-confirmed in the reference project's `overwrite/` header (logo renders with the `?v=` token) after the
  one-time `composer dump-autoload`. Plan + rationale:
  [`../03-development/media-url-helper-bauplan.md`](../03-development/media-url-helper-bauplan.md).

- **DMS extraction into `module-dms` (ADR-019) — DONE (Phase A + B, 2026-07-01).** The whole DMS
  vertical (domain **and** Drive UI fragment) lives in `module-dms` (`Z77\Module\Dms`). Phase A —
  domain out of `shared`/`persistence`: `Entities/` (`Document`, `Folder`, `AccessControlEntry`),
  `Repositories/`, `Services/` (`DocumentService`, `SaveService`, `SaveRequest`, `UploadService`,
  `AclService`, `FolderService`, `Authz`), `Images/` (all 7 image/kind classes), `ValueObjects/Principal`,
  `Blob/` (`BlobStorage`/`LocalBlobStorage`). Phase B — the Drive UI fragment: `DriveControllerTrait`,
  `DocumentDeliveryTrait`, `DriveLayout`, `OutputController`, all `Documents/*` templates, `drive.js`/
  `upload.js`, and the consolidated `FolderService` moved in; `module-backend` keeps only two thin host
  mounts (`Documents\DriveController` / `DocumentController` — `use` the trait; the `driveArea()`
  scope hook of that phase was removed again by ADR-020).
  `UploadedFile` stays in `shared/ValueObjects` (domain-less HTTP VO produced by `core/Http/Request` —
  moving it would make `core → module-dms` a cycle; deliberate deviation from ADR-019's target list).
  No data migration (`#[Entity]` paths explicit; EM auto-wires the repo namespace generically). Verified:
  autoload/`php -l` clean, no stale `Z77\Shared\{Documents,Images,Blob}` refs remain, live server + Drive
  confirmed by the user. Plan: [`../03-development/dms-extraction-bauplan.md`](../03-development/dms-extraction-bauplan.md).
- **DMS rebuild to ownership + ACL + delivery modes (ADR-017, revised 2026-06-20).** The access &
  delivery model is replaced (see the supersede note at the top). The previously blocking delivery
  decision is **resolved** (2026-06-20): a `deliveryMode` ladder (`sealed | protected | public`) +
  static materialization — `delivery=php` for protected/sealed (bytes stay in `data/blobs`), static
  `public/media/…` for public, additive `Share` (static `public/share/<hash>/…` + key-gated template)
  for shared. Full target model + data model: [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md)
  + ADR-017. **R1–R5 done (2026-06-22…29); R6 in progress (R6a + R6b done — `.dms` CSS + 3-pane Drive
  `DriveController` at `/backend/documents/drive/list`; see [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md)
  + [`css-dms.md`](css-dms.md)); R6c started 2026-06-30 — fetch-pane-updates done (`DriveController::paneAction`
  + pane partials + `drive.js`); **upload done 2026-07-01** (`DriveController::addAction`/`uploadAction` +
  `_upload` modal + `[data-drive-upload]` button reading the breadcrumb's server-built `data-add-url`;
  reuses `UploadService` + `documents/upload.js`, own endpoint so success `setRedirect`s back to the target
  folder — `upload.js` now follows an envelope redirect); **document actions done 2026-07-01**
  (`DriveController::editAction`/`moveAction`/`confirmDeleteAction`/`removeAction` — preview-pane
  `[data-modal]` buttons delegated in `drive.js`, reuse the legacy edit/move/confirmDelete modals +
  `DocumentService::rename`/`move`/`delete`; success = in-place pane refresh via shared `panes()`/
  `paneRefresh()`, not a reload; move/confirmDelete templates parametrized with `$postUrl`/`$removeUrl`);
  thumbnails work (GD enabled, `<img>` → `DocumentController::previewAction` variant `s`); **folder
  actions done 2026-07-01** (`DriveController::folderAdd/Edit/Move/ConfirmDelete/Remove` — toolbar
  "new folder" + breadcrumb-pane rename/move/delete buttons, reuse FolderController edit/confirmDelete
  modals + own `_folderMove` modal with self/descendant exclusion + cycle guard, pane-refresh success;
  slug/guard logic duplicates FolderController/SaveService → unify in a FolderService on extraction,
  ADR-019); **setDeliveryMode done 2026-07-01** (`DocumentService::setDeliveryMode`/`setFolderDeliveryMode`
  + `effectiveFolderDeliveryMode` + `hasSealedAncestor` + shared `resolveEffective`; structural sealed cap
  via `assertOpenable`; Drive `modeAction`/`folderModeAction` + `_mode` modal, "Modus" buttons in
  preview/breadcrumb, pane-refresh); **ACL grant/revoke UI done 2026-07-01** (`DriveController::aclAction`
  `?type=document|folder&id=` + `_acl` panel — list ACEs + revoke + grant form [subject role member/visitor
  or user id, rights read/write/manage]; self-refreshing: the POST returns the re-rendered panel as
  `text/html` so `popup.show` re-mounts it in place, no pane-refresh; reuses `grant`/`revoke`/`acesFor`,
  admin bypass); **R6c core (setDeliveryMode + ACL) complete**; **public materialization done 2026-07-01** (eager rebuild — superseded 2026-09-07 by the lazy fill, DMS-MAT-001)
  (`DocumentService::rebuildMaterialization(area)` — idempotent full rebuild of `public/media/<area>` from
  blob+metadata for every live/active-chain/effectively-public doc at the `/media`-mirroring path;
  triggered after each public-relevant mutation; `isActiveChain` made public + `OutputController` public
  fallback now checks the full active chain so PHP fallback ≡ static copy); **share materialization**,
  doc-group retirement + R7 pending.**
- **R1 done (2026-06-22)** — done **additively** so the system stays runnable (the old
  publish/serve chain still works): `Document` gained `ownerId, active, slug, width, height,
  deliveryMode`; `Folder` gained `ownerId, active, slug, deliveryMode`; new ACL store
  `AccessControlEntry` + auto-wired `AccessControlEntryRepository` (`findByResource`,
  `findForSubject`, file `documents/access_control.json`). `SaveService` now sets `ownerId`
  (fallback `createdBy`), `active`, `slug` (via `Naming::toSnakeCase`), and image `width`/
  `height` (`getimagesizefromstring`). `visibility`/`publicPath` are **kept but deprecated**
  → removed in R5 together with the publish/serve refactor. Folder `slug` population was
  pulled forward into R4a (the structural `/media` walk needs it); folder `ownerId`
  population at create time is still deferred to the surface rework (R6). Verified via a
  throwaway CLI round-trip smoke (ACE find*, entity field round-trip, SaveService population) — all green.
- **R2a done (2026-06-22)** — DMS authorization policy (uncached): `Principal` VO (`userId`,
  `roles`, `isAdmin`, `fromAuthUser`) + `AclService` (`effectiveRight`, `hasAccess`, `canRead`,
  `create`). Effective right = admin-bypass `manage`; owner of the resource OR any ancestor
  folder `manage`; else union of ACEs on the document + ancestor folders matching the
  principal's user id or roles, max on `none<read<write<manage`. `canRead` = `active` (doc +
  all ancestors) AND `read`. Ancestor chain walked via folder `parentId` (cycle-guarded).
  Policy only, NO delivery — `AclService` is first consumed in R4. Verified via throwaway CLI
  smoke (13 checks: owner / folder-owner / user-ACE / role-ACE inheritance / admin / guest /
  inactive doc + folder).
- **R2b done (2026-06-23)** — `AclService` now caches via APCu (`DataCache`), two layers,
  behaviour identical to R2a: (1) principal-independent inputs — the per-area folder index +
  the whole ACE set grouped by resource, loaded once (R2a re-read `access_control.json` once per
  ancestor — `FileStorage::load()` caches nothing); (2) the effective right per
  `(principal-signature, type, id)`, so a repeated lookup is a pure hit with no I/O. Invalidation
  reuses the framework's coarse mechanism: ACE/`Folder`/`Document` all carry
  `invalidatesCache: true`, so any write triggers `FileEntityManager → clearAllApcu()` and both
  layers drop together (no finer invalidation needed). Verified via throwaway CLI smoke (16 checks:
  the 13 R2a policy values + "APCu hit ignores an emptied ACE file" + "recompute after
  invalidation"). **R2 complete; next is R3 (reserved-route tier), then R4.**
  Detail: [`../03-development/dms-r2-acl-bauplan.md`](../03-development/dms-r2-acl-bauplan.md).
- **R3 done (2026-06-23)** — reserved-route tier (the routing prerequisite for R4). `/media` moved
  from the phantom navigation node (id 26) + NavigationAlias (id 8) to a `reservedRoutes` entry in
  `frontendConfig` (`/media` → `frontend/media/media/serve`), aggregated by
  `ModuleManager::getReservedRoutes()` and matched by `Request::matchReserved()` as the HIGHEST
  routing precedence — before alias/nav/convention AND before the Fetch short-circuit (an
  `<img src="/media/…">` request is Fetch mode). Node 26 + alias 8 removed from the seeds + the live
  skeleton. Delivery target is still `MediaController::serveAction` (R4 repoints it to the
  `module-dms` `OutputController` and replaces the `servePublic`/visibility gate with the
  `canRead()` ACL gate). Detail + the new tier: [`routing.md`](routing.md) ROUTE-RESERVED-001.
  **NOTE:** the `/media` NavigationAlias rules still listed under `## rules` below are superseded by
  this and are rewritten in R4 (when `MediaController` → `OutputController`).
- **R4a done (2026-06-23)** — folder `slug` population (pulled forward from R6 because the structural
  `/media` walk in R4 resolves folders by slug). The slug transform is now the shared
  `Naming::toSlug()` (snake_case + umlaut transliteration, `_`→`-`); `SaveService::slugify` reuses it.
  `FolderController::edit()` sets `slug` on create AND rename — server-controlled (no `#[Clean]`),
  unique among siblings (same area + parent, self excluded; `-2`/`-3`… on collision; `ordner`
  fallback for a punctuation-only name). `folders.json` is empty → no backfill (ADR: skeleton
  ephemeral, no migration). Verified via throwaway CLI smoke on `Naming::toSlug` (7 cases). The live
  folder-create-with-slug shares the still-pending admin-UI manual check. Detail + R4b/R4c:
  [`../03-development/dms-r4-delivery-bauplan.md`](../03-development/dms-r4-delivery-bauplan.md).
- **R4b done (2026-06-23)** — `DocumentService::resolve(area, segments)`: maps a structural `/media`
  path to `['document'=>Document, 'variant'=>string]` | `null`. Leading segments = area-root folder-slug
  chain; last segment = `<slug>.<ext>` (original) or `<slug>.<variant>.<ext>` (derivative — variant in
  the filename, ADR §8; slugs never contain a dot → unambiguous split). Variant validated against
  `$doc->getVariants()`. Pure resolution — NO `deliveryMode`/ACL/`active` gate (that is R4c). Returns
  `null` on folder/doc/variant miss, on soft-deleted, and on **ambiguity** (!=1 live doc with slug+ext
  in the folder → never serve a guess; per-folder doc-slug uniqueness is a save-side R5 hardening).
  Verified via throwaway CLI smoke (12 checks). Next: R4c (`module-dms` + `OutputController`).
- **R4c done (2026-06-23)** — new package **`module-dms`** + the authorized `OutputController`
  (GUEST endpoint behind the `/media` reserved route). The route moved from `frontendConfig`
  (`frontend/media/media/serve`, R3) to `dmsConfig` (`dms/media/output/serve`); the former
  `MediaController` + `media` group (module-frontend) are **deleted**. `serveAction`: first slug =
  area, rest = structural path → `DocumentService::resolve()` → `effectiveDeliveryMode()` branch —
  `public` served openly (active self-check; full ancestor-active chain + materialization = R5),
  `protected`/`sealed` gated by `AclService::canRead()` (effective READ + active chain) **before any
  byte**; any miss/denial → **404** (existence never leaked). Bytes via the PHP range-stream
  `FileResponse` (`serve()` gained an optional `cacheControl` override: public = `immutable`,
  protected = `private, no-cache`). **Installer/composer:** `module-dms` added to `skeleton/composer.json`
  (require + path repo + override autoload `Z77\Module\Dms\`); the installer auto-discovers it
  (PSR-4 `Z77\Module\*`) and regenerates `moduleManager.inc.php` on `composer install` — run by the dev.
  **Live-verified 2026-06-29:** `composer update z77/module-dms` registered the path package (a bare
  `composer install` rejects a require that is only in `composer.json` but not in `composer.lock`); a
  throwaway seed (two text docs in area `test`) + curl confirmed `public` → 200 + bytes + immutable cache
  header, `protected` (unauth) → 404, slug-miss → 404, wrong-area → 404 — the full reserved-route →
  `OutputController` → `resolve()` → `effectiveDeliveryMode()` → `canRead()` gate chain. Detail:
  [`../03-development/dms-r4-delivery-bauplan.md`](../03-development/dms-r4-delivery-bauplan.md).
- **R5 done (2026-06-29)** — `DocumentService` façade refactor. Removed: `publish`/`unpublish`/
  `visibility` (methods) + `visibility`/`publicPath` (fields on `Document`) — public access is now a
  `deliveryMode`, not a per-document flag. Present/finalised: `grant`/`revoke` (idempotent ACE per
  `(resource, subject)`), `setActive` (output gate), `effectiveDeliveryMode` (inherited via the folder
  chain, `sealed` cap), structural `resolve()`, `serve()` `cacheControl` override. `SaveService` now
  assigns a **folder-unique** slug: `uniqueSlug()` dedups per `folder + ext` over the live documents
  (`-2`/`-3`…, fallback `datei`) — same scheme as `FolderController::uniqueSlug`, so `resolve()` never
  meets an ambiguous `(slug, ext)` pair in one folder (a different ext does not collide; the leaf
  disambiguates by ext). Verified via a throwaway CLI smoke (18/18: deliveryMode, owner/guest/member
  canRead, grant/idempotency/raise, setActive gate, revoke, serve→FileResponse, invalid-reject, slug
  dedup); smoke + data removed after. **Still open → R6:** `setDeliveryMode` UI action + public/share
  materialization (`public/media/…`). Detail: [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md) R5.
  Cross-topic fix surfaced here: `DataCache::clearAllApcu()` now also drops the in-process tier — see
  [`cache.md`](cache.md).
- Harden `SaveService`: remove the orphaned `Document` if a blob write fails after the first flush.
- **`area`→root-folder rework (ADR-020) — DONE (2026-07-02, all RF phases in one pass, system
  deliberately allowed to break in between).** The `area` field is removed from `Document`/`Folder`;
  scope = the tree (roots are the partitions; `Folder.key` addresses a module root via
  `DocumentService::rootFolder($key)` get-or-create). `AclService` folder index is tree-wide;
  `listByArea` → `listByRoot`/`listAll`; `resolve(segments)` walks from the tree top (first segment =
  root slug); materialization rebuilds per root-slug under `public/media` (wipe guard S5: only child
  dirs, never the base; empty-slug chain → loud throw). Read gates landed with it (RF-4a = R-authz-2
  DONE): `serveFor` (byte reads), `readableDoc`/`readableFolder` on every per-id Drive request,
  domain-scoped `listDeleted`, tree/list ACL filtering (decision (b) — all readable roots, path-node
  ancestors). Module roots are `system = true` → rename/move/delete-locked (S4); move-to-top and
  top-level create are `requireAdmin` (partition creation). Data: skeleton DMS data re-seeded (`_seed_drive_demo.php`,
  root `Ablage` key=`backend`; the throwaway seed and the whole skeleton tree were
  removed 2026-08-25 — there is no demo seed any more, see DMS-SEED-001). Verified: `php -l` clean, CLI smoke 27/27
  (rootFolder idempotency/key validation/locks, resolve over root slug, root-grant→subtree ACL,
  materialization incl. wipe guard, RF-4a denials), curl (public 200 + bytes, protected/sealed/miss
  404, drive 302, home 200). Plan: [`../03-development/dms-rootfolder-bauplan.md`](../03-development/dms-rootfolder-bauplan.md).
- **Same-name upload handling + `publicUrl()` — DONE (2026-07-02).** Checksum dedupe (identical
  bytes → skipped, info flash), name conflict → `status 'conflict'`/`data.conflicts` envelope →
  `upload.js` confirms once and retries only the colliding files with `overwrite=1` →
  `SaveService::replace` (in place: id/slug/URL/ACL/mode stay, bytes/variants renew; guards:
  `manage` on the doc, effectively-`sealed` and running `retentionUntil` block). Browser-cache
  freshness via `DocumentService::publicUrl()` (`?v=` checksum token) instead of name rotation —
  long `/media` cache headers stay valid. Verified: CLI smoke 9/9 (replace mechanics + publicUrl)
  + curl live flow (first upload / identical re-upload skipped / conflict envelope / overwrite
  success + checksum renewed on same id / sealed overwrite → error). Open: deploy `.htaccess`
  cache rule for `/media` documented at deployment time.
- **Upload size limit is PHP-config-driven (2026-07-02).** The app cap = `min(upload_max_filesize,
  post_max_size)` (`UploadService::serverMaxBytes()`), plus a `memory_limit` pre-check
  (`fitsMemory`) because the file is loaded whole into RAM before it reaches `BlobStorage`.
  To allow large files (e.g. 500 MB video on cyon) ALL of these must be raised in the PHP config
  (php.ini / cyon `.user.ini`): `upload_max_filesize` and `post_max_size` ≥ the file size, AND
  crucially `memory_limit` ABOVE the file size (~1.2×, so ~768 MB for a 500 MB file) — on shared
  hosting `memory_limit` is usually the real ceiling. **`php.ini` is per-machine — it is NOT
  synced between dev computers.** A fresh PHP install ships the tiny defaults (`2M/8M/128M`) and
  silently caps uploads again; apply the STANDARD dev values below to `C:\php\php.ini` on every
  dev machine (set 2026-07-13):

  ```ini
  max_execution_time  = 300
  max_input_time      = 300
  max_input_vars      = 5000
  max_file_uploads    = 200
  upload_max_filesize = 500M
  post_max_size       = 500M
  log_errors          = On
  memory_limit        = 512M
  ```

  With these, the Drive transport cap is 500 MB; `memory_limit 512M` stays the tighter gate for
  IMAGES only (GD pixel decode — the upload modal shows the derived image cap separately).
  Deploy note: document the required `.user.ini` values alongside the `/media` cache rule. The move-based
  save (no whole-file-in-RAM: `move_uploaded_file` for the original + `hash_file` for the
  checksum) is **BUILT (2026-07-02, P0)** — `memory_limit` is no longer the ceiling for
  non-image uploads (only image/poster bytes still decode in RAM); verified 200 MB @ 64M peaks
  at 2 MB. So on cyon a 500 MB video needs only the transport limits (`upload_max_filesize` /
  `post_max_size` ≥ file), not a raised `memory_limit`.
- **Upload UX rebuild (2026-07-02, Opus 4.8) — P0–P5 BUILT, lint/build clean + smokes green;
  live browser click-through still open.** Per-file sequential XHR uploader (live progress bar
  + cancel per file), two-cap client-side size gate (transport for all, memory cap only for
  `image/*`), and browser-extracted video posters (canvas frame shipped in the SAME request →
  server runs the ONE poster through the GD/`ImageProfile` pipeline so the video document gets
  `s/m/l/xl` variants like an image; Drive thumbnail + `/media` poster work automatically).
  As-built: `uploadAction` now returns a PER-FILE envelope (`success{id,name}` / `duplicate{name}`
  / `conflict{name}` / `error{name,message}`) and NO LONGER redirects/aggregates; the client
  drives the queue, pauses on a conflict for an inline overwrite prompt, and on finish closes the
  modal + refreshes the panes in place (last active breadcrumb crumb's `data-pane`).
  `UploadService::effectiveMaxUploadBytes()` (+ `BASE_RESERVE` 64 MB) feeds `data-max-image-bytes`;
  `serverMaxBytes()` feeds `data-max-bytes` + the "max N MB" hint. Poster path:
  `DocumentKind::acceptsPoster()` (video-only), `SaveRequest::posterBytes`, `SaveService::saveFromUpload`
  poster branch, `UploadService::save(?UploadedFile $poster)` + `posterBytesFor()` (image-sniff +
  memory-guard, a bad poster NEVER fails the video), `fileVm`/`previewVm` thumbnail keyed on
  variant-presence (not `kind==='image'`). `.dms-upload-list` CSS component added. Left open:
  poster on the OVERWRITE path (TODO in `SaveService::replaceFromUpload`). **Live admin
  click-through DONE (2026-07-02): per-file progress + video thumbnail confirmed working**;
  two bugs found & fixed there: (a) the upload modal is a page-level backend `<dialog>` OUTSIDE
  the `.dms` fragment, so `--dms-*` tokens didn't inherit → the `.dms-upload-*` rows (incl. the
  progress fill) rendered token-less; fix = `class="dms"` on the list `<ul>`; (b) a variant's
  blob was resolved with the DOCUMENT extension, which breaks a video poster (`s.jpg` vs `mp4`)
  → 404 — see the variant-extension rule below. Full plan + per-phase as-built:
  [`../03-development/dms-upload-ux-bauplan.md`](../03-development/dms-upload-ux-bauplan.md).
- **Drive session-sticky presentation root (option A / delivery (b)) — BUILT (2026-07-02, Opus 4.8).**
  `DriveControllerTrait::mountRoot(): ?int` (default `null` = full readable forest) now reads a
  SESSION slot (`dmsDriveRoot`) driven by a `?key=<root-key>` switch trigger: `?key` present →
  `FolderRepository::findRootByKey` (find-only, NOT `rootFolder`/S2) → store id in session; blank
  `?key=` → reset to full; `?key` absent → keep the stored scope. Memoized per request. The scope
  travels **through a nav entry's `Navigation::param`** (`key=front` → `urlFor` appends `?key=front`
  — [`navigation.md`](navigation.md) NAV-PARAM-002), NOT a second controller/host mount and NOT URL
  threading (deliberately dropped after the URL-abuse discussion: URL = module/group/controller/action;
  the root is a parameter, not a controller). With a scope set, `buildViewModel` builds the tree top
  from the single mount-root subtree (`makeNode` split out of `$build`) and stops the breadcrumb walk
  at the mount root (breadcrumb-home pseudo-root; `rootLabel`/`rootFolderId` new vm keys, full-view
  label neutral `'drive'` — was a stale hardcoded `'Ablage'`); `folderParam()` defaults an empty
  `?folder=` to the mount root; `folderOptions()` offers only the subtree. **Presentation only** —
  access unchanged (admin ACL-bypass + RF-4a per-id gate; a crafted out-of-subtree `?folder=` is still
  served). Verified: `php -l` clean; the `null`/no-scope path (existing backend Drive) is behaviourally
  unchanged. `?key` resolves by module key first, else by root **slug** (`findRootByKey ?? findRootBySlug`),
  so a human-created root needs NO code key — there is deliberately no UI action to set a folder's key
  (S2). **To use it:** a nav entry to `backend/documents/drive/list` with `param = key=<root-slug>` (e.g.
  `key=front` → the `front` root, addressed by its existing slug — no `folders.json` edit needed). A second
  "Dokumente (alle)" entry SHOULD carry `param = key=` (blank) to reset to the full view (else it inherits
  the sticky session scope). **Open:** live admin click-through; the option-B hard confine if ever wanted;
  a formal ADR addendum for `Navigation::param` (tracked in [`navigation.md`](navigation.md) pending).
- **Drive root + SUPER_USER governance (ADR-021) — BUILT (2026-07-03, U1–U6 in one pass).**
  Decisions D1–D5 taken with the user (root slug NOT in URLs; seed in `module-dms`; bypass
  `superUser`-only; root mode fixed `null`; delegation below partitions allowed). As built:
  `Principal::isSuperUser()` replaces `isAdmin()` (AclService bypass + `Authz::requireSuperUser`);
  installer/`SetupController`/skeleton dev user provision `roles: ['superUser']` (username stays
  `admin`); `Folder::DRIVE_KEY` + `FolderRepository::findDriveRoot()` + `DocumentService::driveRoot()`
  (get-or-create + stray adoption; until 2026-09-07 also run on every mutation via
  `rebuildMaterialization`, now only at its own callers — `rootFolder`, `FolderService::add/move`); partitions = root
  children (`findRoots`, `rootFolder` creates under the root, `rootIdOf`/`rootKeyOf` return the
  chain link BELOW the root); `resolve()`/`folderSlugPath()` skip the root segment (URLs
  unchanged); drive root locked (no docs — `SaveService::assertFolderTarget` + `move` guard;
  no rename/move/delete; mode/active fixed); partition lifecycle + root/partition grants
  SUPER_USER-only (`requirePartitionGate`, `requireGrantLevel`); module write boundary via
  `DocumentService::forModule()` → `ModuleDrive` + `dmsConfig['moduleFolders']` remap; seeds:
  `module-dms/data/documents/folders.default.json` + skeleton re-seed (`front`/`pages`
  reparented; data since re-seeded by the dev — root id 1, partitions `front`/`back`). Verified:
  `php -l` clean, throwaway CLI smoke 19/19 (root exists/partitions/reserved
  key/idempotency/boundary/resolve/no-root-docs/bypass split/gates/adoption), smoke data removed.
  **Drive UI fixes after the first live click-through (2026-07-03):** (1) the breadcrumb HOME is
  now always ONE anchor — the mount root, else the DRIVE ROOT (name, e.g. `Drive`) — and the crumb
  walk stops there, so the home never repeats as a crumb (was `drive › Drive › front`, which
  suggested two roots; the neutral `'drive'` label only remains as a no-root fallback); (2) a
  STORED session scope (`dmsDriveRoot`) that no longer resolves (re-seeded ids) is cleared and
  falls back to the full view — a stale id used to render an EMPTY tree; (3) a `?key=` mount shows
  ONLY the mounted subtree as the tree top (no ancestors above it) — unchanged behaviour, now
  covered by (2) against stale ids.
  **Combined edit modal (2026-07-03, decided with the user):** ONE `_edit` modal per resource
  replaces the separate rename/mode/ACL modals (`modeAction`/`folderModeAction`/`aclAction` +
  `_mode`/`_acl` + the legacy `DocumentController/edit` template are REMOVED, also from
  `backendConfig`): form fields (name; folder: + `key`; delivery mode) post `op=save` (only
  changed values applied), the embedded ACL section posts `op=grant|revoke` (modal re-renders in
  place). Opening gates on effective `manage`. NEW `FolderService::setKey()` (ADR-021 revision):
  a SUPER_USER can key a human partition from this modal (unique, charset, `drive` reserved,
  `system` partitions locked); the ⋮ hub shrank to Bearbeiten/Verschieben/Löschen (+ active
  toggle, folder add/upload, open/download).
  **Live click-through confirmed by the dev (2026-07-03)** — breadcrumb/mount fixes + the
  combined edit work in the running backend.
  **Open:** a `moduleFolders` remap in a real module (first consumer: `financial`).
  Review + plan:
  [`../03-development/dms-drive-root-bauplan.md`](../03-development/dms-drive-root-bauplan.md).
- **Click through the action rows (DMS-FORM-ACTIONS-001, 2026-10-10)** — in the running backend:
  the edit modal (row pinned under the header, body scrolls, Enter in the name field saves, a
  grant from the one-line capture re-renders the modal), the trash («Papierkorb leeren …» →
  confirm → «Zurück» / «Endgültig löschen»), and one move + one confirm dialog.
- **Open rebuild work** (detail in [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md)): live image thumbnails (`gd` now enabled — verify `s/m/l` generation); share materialization (`public/share/<hash>/…` + `Share`/`ShareItem`). Manual check open: admin click-through of the Drive after the ADR-020 rebuild (tree over roots, module-root lock messages, upload incl. conflict dialog, trash pane-refresh).

## see also

- [`tree.md`](tree.md) — `Folder` implements `TreeNode`; ONE tree, no scope partitions (ADR-020) — the `scopeOf` callback returns `null`; since ADR-021 the single drive root is the tree top and its children are the partitions
- [`persistence-architecture.md`](persistence-architecture.md) — `Document`/`Folder` entities + repositories follow the `UnifiedEntityManager` / repository pattern; the Doctrine switch is a planned DMS milestone
- [`persistence-file.md`](persistence-file.md) — `Document` uses collection mode (auto-increment `id` as blob key); `BlobStorage` is the bytes sibling of `FileStorage`
- [`mail.md`](mail.md) — the `Mailer` stack behind `DocumentService::send()` (own SMTP build, `Message`/`MimeMessage`/`SmtpTransport`, `config/mail`)
- [`css-dms.md`](css-dms.md) — the `.dms` embedded fragment CSS (tokens + `.dms-` components) for the Drive surface
