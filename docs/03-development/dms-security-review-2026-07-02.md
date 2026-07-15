# DMS security & bug review — 2026-07-02

Full read-through of the DMS tool (`packages/module-dms`) and its backend consumers
(`packages/module-backend/.../Documents/*`) for security holes and bugs, cross-checked
against [`../topics/documents.md`](../topics/documents.md) + ADR-016/017/019/020. Reviewer:
Fable 5. Three issues found and fixed (all in this pass); the rest of the surface is sound.

## Scope reviewed

- **Domain services:** `DocumentService`, `SaveService`, `UploadService`, `FolderService`,
  `AclService`, `Authz`, `SaveRequest`.
- **UI traits:** `DriveControllerTrait` (all 20 actions), `DocumentDeliveryTrait`.
- **Public delivery:** `Ui/Controllers/Media/OutputController` (the only GUEST endpoint).
- **Blob layer:** `BlobStorage` / `LocalBlobStorage`, `FileResponse` (range stream).
- **Entities:** `Document`, `Folder`, `AccessControlEntry`, `Principal`, `DocumentKind`,
  `ProcessedVariant`.
- **JS:** `documents/upload.js`, `documents/drive.js`, `core.js` (fetch/CSRF wiring).
- **Templates:** all `Documents/**` (XSS sinks).
- **Host mounts + infra:** backend `DriveController`/`DocumentController`, `backendConfig`,
  `dmsConfig`, `AccessGuard`, `CsrfService`, `Dispatcher` (attribute enforcement), `Request`
  (mode/CSRF/uploads).

## Findings (fixed)

### F1 — CSRF on the active-toggle (`actionsAction`) — Medium

`DriveControllerTrait::actionsAction` handled both the GET hub render and the POST `active`
toggle, but carried **neither `#[Fetch]` nor a per-entity CSRF token**. The framework's CSRF
gate (`AccessGuard`) only validates a token when `RequestMode === Fetch && isPost()`; the mode
is derived from `Sec-Fetch-Mode` (`navigate` → Page). So a cross-site **Page-mode** form POST
to `/backend/documents/drive/actions?type=…&id=…&op=active` skipped the CSRF check entirely,
and — since the action lacked `#[Fetch]` — the `Dispatcher` did not reject it either. The
toggle reads `value` from the JSON body, which defaults to `false` when absent (a form body is
not JSON), so **no crafted body is even needed**: luring a logged-in admin to a malicious page
could flip the `active` output gate (deactivate → stops public delivery / previews) of any
document or folder id. The domain `manage` gate does not help — CSRF rides the admin's own
session.

**Fix:** added `#[Fetch]` to `actionsAction`. The action is only ever reached via the fetch
layer (`data-fetch-get` hub + `data-fetch-toggle`), so requiring Fetch mode rejects the
Page-mode downgrade in the `Dispatcher` (`enforceActionConstraints`, before any state change)
and makes the global `AccessGuard` CSRF check authoritative for the toggle POST.

### F2 — CSRF on folder creation (`folderAddAction`) — Low

Same root cause: `folderAddAction` (GET modal + POST create) had no `#[Fetch]` and no CSRF
token (a new folder has no id for an entity token). A Page-mode POST bypasses `AccessGuard`.
Harder to exploit than F1 (the folder `name` comes from the JSON body, which a plain form
cannot supply — it needs the `text/plain` JSON-crafting trick), and the impact is low (create
a folder under a writable parent). Still a real CSRF gap.

**Fix:** added `#[Fetch]` (the modal is loaded via `data-fetch-get`, the form posts via
`data-fetch-post`, so both verbs are already Fetch in real use). Now the global CSRF gate
covers the create POST.

> Note: every other dual GET/POST action (`edit`, `move`, `folderEdit`, `folderMove`, `mode`,
> `folderMode`, `acl`, `trash`) validates a per-entity token (`validateEntityToken`) on its
> POST and is therefore safe regardless of request mode. F1/F2 were the only two mutating
> actions that validated neither a token nor the request mode.

### F3 — `originalName` stored unsanitised — Low

`Document`'s docblock states the original filename is "sanitised server-side", but
`setOriginalName()` stored the raw client value. The upload path (`UploadedFile::fromPhpFile`
→ `SaveRequest` → `SaveService`) never strips it either. The value flows into a
`Content-Disposition: … filename="…"` header on download (`FileResponse`, `addslashes` does not
strip newlines) and into the tool UI. Impact is limited — PHP's `header()` blocks CRLF
injection, templates escape with `e()`, and upload is ADMIN-only — but the claim was false and
a control-character / path-bearing filename was accepted verbatim.

**Fix:** `setOriginalName()` now takes `basename()` (drops any path component) and strips
control bytes (`\x00-\x1F\x7F`), preserving UTF-8. Matches the docblock and hardens the
download header.

## Reviewed and sound (no change)

- **Path traversal:** blobs are id-addressed (`data/blobs/<shard>/<id>/<variant>.<ext>`); the
  only string inputs to a blob path (`variant`, `ext`) are regex-validated in
  `LocalBlobStorage::file()`. `resolve()` matches `/media` segments by **slug equality** against
  stored folders/documents (never concatenates request input into a path). `folderSlugPath()`
  uses server-generated slugs and refuses an empty segment (S5). No user input reaches a path.
- **`/media` authorization (GUEST):** `OutputController` → `resolve()` →
  `effectiveDeliveryMode()`: `public` requires the full active chain (same predicate as
  materialization), `protected`/`sealed` require `AclService::canRead()` (effective READ +
  active chain) **before any byte**; every miss/denial → 404 (no existence leak). A guest
  principal holds only the `guest` role (`AuthService`), and ACE role-matching is exact — so a
  `visitor` grant is **not** world-readable (it needs a logged-in visitor). Correct.
- **Upload gate:** `UploadService` enforces the size cap, the `finfo` MIME allowlist
  (`DocumentKind::fromMime`, never the client type), the `write` gate on the target folder,
  and for an overwrite additionally `manage` + sealed/retention blocks. The poster is sniffed
  to `image/*` and ignored on any mismatch (never fails the video). Matches `documents.md`.
- **Upload CSRF:** `uploadAction` is `#[Fetch, HttpMethod('POST')]` → a Page-mode form POST is
  rejected by the `Dispatcher`; the fetch upload carries `X-CSRF-Token` (validated by
  `AccessGuard`). A cross-site file upload is additionally impractical (file inputs can't be
  pre-filled).
- **XSS:** every user-controlled string in the templates (`displayName`, `originalName`,
  folder `name`, ACL subject) is output through `e()` (`htmlspecialchars`, `ENT_QUOTES`).
- **Variant extension:** the poster-variant-vs-document-extension bug (a video poster served as
  `s.mp4`/`video/mp4`) was found and fixed in the prior pass — `serve`/`publicUrl`/`resolve`/
  `writeMaterialized`/`send` all resolve a variant by its own stored `ext`
  ([`../topics/documents.md`](../topics/documents.md) `## rules`).
- **Domain-gated mutations (RF-4a):** every mutation and per-id read goes through
  `Authz::require(...)` in the service (session principal, admin bypass), not the trait —
  un-bypassable by host wiring. The whole backend `documents` group is ADMIN.

## Observations (not fixed — by design / known)

- **ACL role matching is exact, not hierarchical:** a `member` does not inherit a `visitor`
  grant (`AclService::matchesSubject` uses `in_array`). More restrictive, not less — safe, but
  a possible admin UX surprise. Left as-is (matches the R2 policy).
- **`grant()` does not verify a user id exists** — a dangling ACE for a non-existent user is
  harmless (matches nobody). Left as-is.
- **`SaveService` is transactionless (ARCH-A003):** a blob write failing after the first flush
  orphans the metadata row; the overwrite path deletes the old blob before writing the new one
  (a failure loses bytes). Pre-existing, documented in `documents.md ## known issues`.
- **`rebuildMaterialization()` full-wipe** on every mutation is O(public tree); fine at DMS
  volume, a targeted diff is a later optimisation (documented).

## Verification

- `php -l` clean on the three changed files (`DriveControllerTrait`, `Document`).
- Sanitiser unit-checked: `../../etc/passwd` → `passwd`, `a\r\nX: y.pdf` → `aX: y.pdf`,
  `C:\Windows\evil.exe` → `evil.exe`, `Ümlaut Dätei.jpg` preserved.
- `Dispatcher::enforceActionConstraints` confirmed to throw on a mode mismatch **before** the
  action runs, so `#[Fetch]` reliably blocks the Page-mode CSRF downgrade.
- PHP is live via the `vendor/z77/module-dms → packages/module-dms` symlink — no reinstall
  needed for the fixes to take effect.

## Files changed

- `packages/module-dms/src/Ui/DriveControllerTrait.php` — `#[Fetch]` on `actionsAction` +
  `folderAddAction`.
- `packages/module-dms/src/Entities/Document.php` — `setOriginalName()` sanitisation.
