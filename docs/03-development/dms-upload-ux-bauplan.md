# DMS Upload UX rebuild — per-file progress, cancel, client-side size gate, video poster

Status: **BUILT (2026-07-02, Opus 4.8) — P0–P5 all done; lint/build clean + focused smokes
green. Standing open item: the live admin browser click-through (P2/P3/P4 acceptance lists).**

As-built summary (per phase):
- **P0** — path-based move save (see the P0 block below). DONE.
- **P1** — per-file `uploadAction` envelope (`success{id,name}` / `duplicate{name}` /
  `conflict{name}` / `error{name,message}`, no redirect/aggregation); `effectiveMaxUploadBytes()`
  + `BASE_RESERVE` (64 MB); `addAction` renders `data-max-bytes` (transport, all) +
  `data-max-image-bytes` (memory, images). Formula smoke 5/5.
- **P2** — `upload.js` full rewrite: per-file sequential XHR queue, live progress bar,
  per-row cancel (queued/uploading/conflict), inline overwrite prompt (pauses the queue on
  the row), two-cap client gate, completion = close modal + in-place pane refresh (last
  active breadcrumb crumb's `data-pane`), else keep modal open. `node --check` clean.
- **P3** — `_upload.tpl` gains the two `data-` caps + `[data-z77-upload-list]` container;
  file input `files[] multiple` + empty-folder guard kept.
- **P4** — video poster: client `extractVideoPoster` (frame 5 s/1 s, `readyState>=2` wait,
  1250 px JPEG q0.75, skip on undecodable) shipped alongside the video in the SAME request;
  server `DocumentKind::acceptsPoster()` (video), `SaveRequest::posterBytes`,
  `SaveService::saveFromUpload` poster branch (poster → `generateVariants($id,'jpg',…)` +
  dims), `UploadService::save(?UploadedFile $poster)` + `posterBytesFor()` (image-sniff,
  memory-guard, never fails the video), `uploadAction` reads `poster`; `fileVm`/`previewVm`
  thumbnail now variant-presence (not `kind==='image'`). Overwrite-poster left as TODO.
  GD pixel-path smoke: a 1250×700 JPEG → s/m/l/xl (s present). DONE.
- **P5** — `.dms-upload-list` / `.dms-upload-row` component (`_upload.scss`, tokens+BEM,
  state modifiers), added to `dms.scss`; `npm run build:dms` clean, classes in bundle.

NOTE (doc-internal): where P1.3 and P3 disagreed on which cap is `data-max-bytes`, P1.3 is
authoritative — `data-max-bytes` = transport (all files), `data-max-image-bytes` = memory
cap (images only). The template comment reflects this.

### Post-build fixes (live admin test, 2026-07-02)

Two real bugs surfaced during the browser click-through and are fixed:

1. **Progress fill invisible (token scope).** The upload modal is a page-level backend
   `<dialog data-z77-popup>` rendered OUTSIDE the `.dms` fragment, so the `--dms-*` tokens
   (defined only on `.dms`, ADR-018) did not inherit — the whole `.dms-upload-*` component
   (border, surface, spacing, and the `color-mix`/fallback progress fill) rendered token-less.
   Fix: `class="dms"` on the `[data-z77-upload-list]` `<ul>` re-anchors the tokens in that
   subtree (no bleed into the rest of the `be-*` modal). Also gave the fill a var() fallback +
   bumped the sweep to 20 %.
2. **Video poster never displayed (variant extension bug).** `DocumentService::serve()` (and
   `publicUrl`/`resolve`/`writeMaterialized`/`send`) built the VARIANT blob path with the
   DOCUMENT extension — fine for images (variant ext == doc ext) but wrong for a video poster
   (`s.jpg` vs original `mp4`) → 404 + a `video/mp4` MIME on JPEG bytes. Root fix: the variant
   extension is now stored in the variant meta (`ProcessedVariant::toMeta()` adds `ext`), and a
   `variantExt()` helper (+ `mimeForExt()`) is used everywhere a variant is resolved (legacy
   image variants fall back to the doc ext). `fileVm`/`previewVm` already keyed the thumbnail on
   variant-presence. Existing pre-fix video docs backfilled with `ext:"jpg"`.

Delivery note: `packages/module-dms` is symlinked into `skeleton/vendor` (PHP live without a
reinstall); the `res/assets` JS/CSS are COPIED into `skeleton/public/assets/dms` on the first
install only. `public/` is seed-once (ADR-024), so a source asset edit needs a manual re-copy
`res/assets/... → public/assets/dms/...` (or delete the file + `composer install` to re-seed) —
a plain `composer install` will not update an existing `public/` file.

---

Status: PLANNED (2026-07-02). Implementation target: Opus 4.8.
Topic: [`../topics/documents.md`](../topics/documents.md) (read its `## rules` + the upload section first).

This plan is written as an executable spec: each phase lists the exact files, the
contracts (signatures / envelope shapes), and MUST / MUST NOT rules in the house style.
Phases are ordered so each is independently testable. Do the server contract (P1) before
the client rewrite (P2), because P2 depends on the per-file envelope shape.

---

## 0. Goal & scope

Rebuild the Drive upload modal from a single batch `fetch()` POST into a **per-file,
sequential XHR uploader** with:

1. **A progress bar per file** (real upload progress — needs `XMLHttpRequest`, `fetch()`
   cannot report upload progress).
2. **A file list** showing each queued file by name, each row cancellable **during** upload
   (abort a running/queued file; already-finished files stay saved — no server delete).
3. **A client-side size gate**: JS knows the effective max upload size (delivered as a
   `data-` attribute on the form, no extra round-trip) and refuses an over-sized file
   **before** any byte is sent.
4. **Video poster**: for a video file the browser extracts a poster frame via `<canvas>`
   and uploads it alongside the video; the server runs that ONE poster image through the
   existing GD/`ImageProfile` pipeline to produce the standard `s/m/l/xl` variants, so the
   video document gets `variants` like an image (Drive thumbnail + `/media/...poster` work
   automatically). GD cannot decode video, so the frame MUST come from the browser.

### Confirmed decisions (2026-07-02)

- **Upload model:** one request per file, **sequential** (never two large files in RAM at
  once; per-file progress + per-file cancel; simpler per-file conflict handling).
- **Limits to JS:** as a `data-max-bytes` attribute on the modal form (`addAction` already
  renders the modal and knows the value) — NO separate fetch endpoint.
- **Cancel:** abort running/queued only; finished uploads remain (no server-side delete).
- **Poster:** client sends ONE poster image; server generates variants via GD.
- **No whole-file-in-RAM for uploads (2026-07-02, dev insight):** the upload original is
  stored by **moving the PHP temp file** into the blob (`move_uploaded_file`), and the
  checksum is computed by **streaming** (`hash_file`) — NOT by `file_get_contents`. So a
  500 MB video never enters PHP memory; `memory_limit` stops being the ceiling for non-image
  uploads (confirmed: 500 MB uploads work on cyon with the old move-based framework). Only
  the small variant SOURCES (an image original, or a client poster) are read into RAM for
  GD — guarded by the existing memory pre-check. This is P0 and supersedes the earlier
  "streaming is a later hardening" note.

### Non-goals (explicitly out)

- No parallel uploads. No client-generated variant sizes. No re-poster of an existing video
  via overwrite (P4 covers first upload; note the overwrite path as a TODO). No client-side
  hashing (server `hash_file` covers it without trusting the client).

---

## P0. Core — path-based upload save (no whole-file-in-RAM) — DONE (2026-07-02)

> **Status: BUILT & verified. Opus starts at P1.** As-built summary:
> - `UploadedFile::sha256()` (streamed `hash_file`) + `imageSize()` (path-based `getimagesize`)
>   — [`packages/kernel/shared/src/ValueObjects/UploadedFile.php`].
> - `BlobStorage::putFile(id, variant, ext, sourcePath, isUpload=true)` + `LocalBlobStorage`
>   impl (`move_uploaded_file`, else rename/copy, `chmod 0644`).
> - `SaveService::saveFromUpload(UploadedFile, SaveRequest, ?string $checksum=null)` +
>   `replaceFromUpload(...)` + private `variantsFromStoredOriginal()` — move original in,
>   stream checksum, image derivatives read the small stored original back from the blob.
>   The `?string $checksum` param is an addition to the spec: the caller passes the checksum
>   it already computed so the file is hashed only ONCE.
> - `UploadService::save()` is now path-based: no `bytes()` on the original; `fitsMemory`
>   gates ONLY an image original (`$kind->hasImageVariants()`); `replaceExisting()` takes the
>   `UploadedFile` + checksum. The byte-based `SaveService::save()`/`replace()` STAY for
>   `saveGenerated()` and in-memory producers.
> - Verified: `php -l` clean on all 5 files; throwaway CLI smoke 9/9 — a 200 MB file through
>   `sha256()`+`putFile()` at `memory_limit=64M` peaks at **2 MB** (no OOM, bytes/size/checksum
>   intact, temp gone after move).
> - **NOT yet built (belongs to P1):** `effectiveMaxUploadBytes()` and the two `data-` caps.
>   Already present from earlier today: `serverMaxBytes()`, `iniBytes()`, `fitsMemory()`;
>   `addAction` currently passes `serverMaxBytes()` as `maxBytes`.

The current save path funnels every upload through `UploadedFile::bytes()`
(`file_get_contents` → whole file as a PHP string), then `hash('sha256', $bytes)`, then
`BlobStorage::put(..., $bytes)` (`file_put_contents`). That is why a 500 MB video needs
>500 MB RAM. It is NOT necessary: PHP already has the upload as a temp file on disk. This
phase makes the original blob a **file move** and the checksum a **streamed hash**, so big
non-image uploads never enter PHP memory. Do this before P1 — P1's per-file endpoint and
P4's poster both build on the path-based save.

**Files:** `packages/module-dms/src/Blob/BlobStorage.php` + `LocalBlobStorage.php`,
`packages/kernel/shared/src/ValueObjects/UploadedFile.php`, `SaveService.php`, `UploadService.php`.

### P0.1 BlobStorage — a move-based writer

Add to the interface + `LocalBlobStorage`:

```php
// Move a source file into the blob slot (the source must be gone afterwards for an
// uploaded temp file — use move_uploaded_file; for a non-upload source use rename/copy).
public function putFile(int $id, string $variant, string $ext, string $sourcePath, bool $isUpload = true): void;
```

- `LocalBlobStorage::putFile`: resolve the target via the same `file()` path scheme
  (defensive `variant`/`ext` validation stays), `mkdir` the per-id dir, then
  `$isUpload ? move_uploaded_file($sourcePath, $target) : (rename ?: copy)`. Throw on
  failure (same contract as `put`). MUST NOT `file_get_contents` the source.
- Keep the byte-based `put()` — it is still used for generated files (bytes already in RAM)
  and for image/poster VARIANT blobs (small, produced by GD as strings).

### P0.2 UploadedFile — streamed hash + size (no full read)

Add:

```php
public function sha256(): ?string;   // hash_file('sha256', $tmpPath) — streamed, O(1) memory
public function imageSize(): ?array; // getimagesize($tmpPath) — path-based, for images only
```

- `sha256()` MUST use `hash_file` (never read the whole file). Compute it BEFORE the move
  (after the move the temp path is gone).
- Keep `bytes()` for the cases that genuinely need the bytes (small variant sources) — do
  NOT call it on the upload original anymore.

### P0.3 SaveService — a path-based upload save

Add a sibling to `save()`:

```php
// Persist an UPLOADED file: move original into the blob, stream the checksum, and (only
// for an image, or a video with a poster) load the small variant source into RAM for GD.
public function saveFromUpload(UploadedFile $file, SaveRequest $req, ?string $posterBytes = null): Document;
```

Sequence:
1. Build the `Document` (as `save()` does) but set `sizeBytes` from `$file->size`,
   `checksum` from `$file->sha256()`, and for an image `width`/`height` from
   `$file->imageSize()` — all BEFORE touching bytes.
2. Persist → obtain `id`.
3. `blob->putFile($id, ORIGINAL, $ext, $file->tmpPath, true)` — the move (no RAM).
4. Variants:
   - **Image:** read the now-stored original back as bytes from the blob
     (`file_get_contents(blob->path(id, ORIGINAL, ext))` — small, memory-guarded) and run
     the existing `generateVariants()`. (Alternatively give `GdImageProcessor` a path-based
     entry; either is fine — the point is only the ORIGINAL move avoids the big read.)
   - **Video with `$posterBytes`:** run `generateVariants($id, 'jpg', $posterBytes, $req)`
     and set `width`/`height` from `getimagesizefromstring($posterBytes)` (P4).
   - **Everything else:** no variants.
5. Persist the `variants` map.

MUST NOT read the upload original into a PHP string. `save(string $bytes, …)` stays for
`saveGenerated()` and any in-memory producer. `replace()` (overwrite) SHOULD get the same
move treatment; if time-boxed, at minimum use `putFile` for the new original.

### P0.4 UploadService — hand the path, not the bytes

- `UploadService::save(...)` MUST NOT call `$file->bytes()` for the original. It computes the
  checksum for the duplicate/conflict check via `$file->sha256()` and delegates to
  `SaveService::saveFromUpload(...)`.
- The `fitsMemory` pre-check now applies ONLY to what actually enters RAM: an image original
  (or a poster), not a moved video. So gate it on the variant source size, not the whole
  upload. For a non-image, non-poster upload the memory check is a no-op (nothing is loaded).

**Acceptance P0 (throwaway CLI):** save a large dummy file through `saveFromUpload` with a
low `memory_limit` and confirm peak memory stays flat (no OOM, `memory_get_peak_usage` far
below the file size) and the blob + checksum are correct; confirm an image still produces
`s/m/l/xl`.

---

## P1. Server — per-file upload endpoint (contract change) — DONE (2026-07-02)

> **Status: BUILT & lint-clean.** As-built: `uploadAction` now takes `$files[0]` (one file
> per POST), returns the per-file envelope (`success {id,name}` / `duplicate {name}` /
> `conflict {name}` / `error {name,message}`), calls `rebuildMaterialization()` on success,
> and NO LONGER redirects or aggregates. `UploadService::effectiveMaxUploadBytes()` +
> `BASE_RESERVE` (64 MB) added; formula smoke 5/5 (dev 1024M/1088M/512M → image cap ~373 MB,
> transport 1024 MB). `addAction` now passes BOTH `maxBytes` (= `serverMaxBytes`, transport,
> all files, drives the "max N MB" hint) AND `maxImageBytes` (= `effectiveMaxUploadBytes`,
> memory cap, images only). NOTE: P1.3 is authoritative over the stale P3 wording that said
> `data-max-bytes` = `effectiveMaxUploadBytes` — the honest two-cap model is transport for
> all + the stricter image cap for `image/*` (see P1.3). Poster read (P1.1) deferred to P4
> (needs the `UploadService::save($poster)` param).

**File:** `packages/module-dms/src/Ui/DriveControllerTrait.php` (`uploadAction`).

Today `uploadAction` loops all files in one POST and, on success, returns a **redirect**
envelope. The new client sends **one file per request** and drives the UI itself, so the
endpoint must return a **per-file result** and MUST NOT redirect.

### 1.1 Request shape (one file per POST)

- `files[]` — exactly one file (keep the `files` field name; `getUploadedFiles('files')`
  returns a 1-element array — minimal change, the loop still works with 1 element).
- `folder_id` — target folder (unchanged).
- `show_original` — unchanged.
- `overwrite` — `1` on the retry after a confirmed conflict (unchanged semantics).
- `poster` — OPTIONAL single image part (only for videos, P4). Read via
  `DI::getRequest()->getUploadedFile('poster')`.

### 1.2 Response envelope (per file)

Return a `FetchResponse` with a `status` and `data`, NEVER a redirect:

- `status: 'success'`, `data: { id: <int>, name: <string> }` — saved.
- `status: 'duplicate'`, `data: { name }` — identical bytes already present (was a flash
  before; now a per-file result so the row can show "übersprungen").
- `status: 'conflict'`, `data: { name }` — same name, different bytes, no overwrite consent.
- `status: 'error'`, `data: { name, message }` — validation/size/memory/type/other.

MUST:
- Keep the domain call `UploadService::create()->save(...)` and its exception mapping
  (`DuplicateUploadException` → `duplicate`; `NameConflictException` → `conflict`;
  `\RuntimeException` → `error` with `getMessage()`).
- Call `rebuildMaterialization()` after a successful save (a new file may land in a public
  folder) — unchanged, but now per request.
- Keep the `readableFolder($folderId)` gate and the CSRF requirement (`AccessGuard`).

MUST NOT:
- Redirect. Push after-redirect flashes. Aggregate multiple files. The client aggregates
  the per-file outcomes and refreshes the Drive once at the end.

### P1.3 Two size caps for the client gate (transport vs. memory)

After P0 there are TWO relevant ceilings, and which one applies depends on the file type:

- **Transport cap** = `serverMaxBytes()` = `min(upload_max_filesize, post_max_size)`.
  Applies to EVERY file (PHP will not accept more over the wire).
- **Image/memory cap** = `effectiveMaxUploadBytes()` = `min( serverMaxBytes(),
  floor( (memoryLimit - BASE_RESERVE) / 1.2 ) )`. Applies ONLY to files whose bytes enter
  RAM — i.e. **images** (GD). A **video is moved** (P0) and its poster comes from the client,
  so a video is bounded by the transport cap ONLY, not by `memory_limit`.

**File:** `UploadService.php` — keep `serverMaxBytes()`; add `effectiveMaxUploadBytes()`
(memory-based, as above; unlimited memory → `serverMaxBytes()`; `BASE_RESERVE` ≈ 64 MB; the
`/1.2` mirrors `fitsMemory`; clamp ≥ 0).

**File:** `DriveControllerTrait::addAction` — render BOTH into the modal:
`data-max-bytes` = `serverMaxBytes()` (all files) and `data-max-image-bytes` =
`effectiveMaxUploadBytes()` (images). The displayed "max. N MB" hint uses the transport cap;
the image cap is the stricter gate the JS applies only to `image/*`.

This is the honest model: a 500 MB video passes on cyon (transport 500 MB, moved, no RAM);
a 500 MB IMAGE would still be refused if `memory_limit` cannot hold it (images must decode).

**Acceptance P1:** `curl` a single-file multipart POST (needs an admin session cookie) and
confirm the JSON envelope shapes for success / duplicate / conflict / oversize. Verify
`effectiveMaxUploadBytes()` at runtime returns `min(serverMaxBytes, memoryBudget)` (unit:
throwaway CLI with faked ini values, mirror of the existing `smoke_upload_limits` approach).

---

## P2. Client — sequential XHR uploader (rewrite `upload.js`)

**File:** `packages/module-dms/res/assets/js/documents/upload.js` (full rewrite; keep the
`_Z77.scriptInit['documents-upload']` registration + scope contract).

### 2.1 Model

- On submit (or on file-input change — see P3), build a **queue** of file items from
  `input.files`, each `{ file, row, xhr, status }`.
- Upload **strictly sequentially**: start file N+1 only after file N reaches a terminal
  state (success / duplicate / error / cancelled / conflict-resolved).
- Each upload is an `XMLHttpRequest` (not `fetch`) so `xhr.upload.onprogress` drives the
  row's progress bar.
- Send `X-CSRF-Token` (from `meta[name=csrf-token]`) and `X-Requested-With` headers, POST
  to `form.action`, body = a `FormData` carrying **one** `files[]` entry (+ `folder_id`,
  `show_original`, and — for video — the `poster` blob from P4).

### 2.2 Per-row UI (see P3 for markup)

For each file render a row: name · progress bar · % / status text · cancel (✕) button.

- `onprogress` → set the bar width `(loaded/total)*100`.
- On terminal status set the row state class: `is-done` / `is-skipped` / `is-error` /
  `is-cancelled` / `is-conflict`.
- Cancel button: if the row is **queued** → remove it from the queue (never starts). If it
  is **uploading** → `xhr.abort()` and mark `is-cancelled`. If it is **finished** → the
  button is hidden/disabled (decision: no server delete). Cancelling the in-flight file
  MUST advance the queue to the next file.

### 2.3 Conflict handling (per file, inline)

On `status: 'conflict'` for a row: show an inline prompt in that row
("existiert bereits — überschreiben? [Ja] [Nein]"). `Ja` → re-POST the SAME file with
`overwrite=1`. `Nein` → mark the row skipped and continue. (Replaces today's single global
`window.confirm`.) A global `confirm()` is an acceptable fallback if inline proves fiddly,
but inline is the target for the new list UI.

### 2.4 Completion

When the queue is drained:
- If ≥1 file succeeded → close the modal and refresh the Drive **in place** (reuse the
  existing pane refresh the rest of the Drive uses — `drive.js` `paneRefresh()` / the
  current folder's `data-pane` endpoint). A full `window.location.reload()` is the
  acceptable fallback.
- If nothing succeeded → keep the modal open so the user sees the per-row errors.

MUST NOT: send more than one file per request; start a second request before the first
finished; rely on an envelope redirect (P1 removed it).

---

## P3. Modal template — file list + size gate

**File:** `packages/module-dms/res/view/templates/Documents/DriveController/_upload.tpl.php`.

- Add `data-max-bytes="<?= (int) $maxBytes ?>"` to the `[data-z77-upload-form]` element
  (`$maxBytes` is now `effectiveMaxUploadBytes()` from P1).
- Keep the file input `name="files[]" multiple`.
- Add an empty container `[data-z77-upload-list]` where `upload.js` renders one row per
  selected file (name, progress bar, status, cancel button).
- The submit button stays, but the flow may start on file-selection; keep submit as the
  explicit trigger to avoid surprising auto-uploads. Keep the empty-folder guard
  (disabled submit when no folder exists).

### P3.1 Client-side gate (in `upload.js`, reading the two data-caps)

- On file selection, for each file pick the applicable cap: `image/*` → `data-max-image-bytes`;
  everything else → `data-max-bytes` (transport). If `file.size > cap` → mark the row
  `is-error` with "Datei zu gross (max. N MB)" and EXCLUDE it from the queue (never uploaded).
- Show the human max in the row message.
- This is the first line; the server's `save()` size + `fitsMemory` guards remain the
  authoritative second line (a client can bypass JS). Note `fitsMemory` now guards only the
  variant source (image/poster), not the moved original (P0).

**Acceptance P2+P3 (manual, needs admin login):** open the modal, select several files incl.
one over the limit and one large video; confirm: over-limit file rejected client-side
without a request; each uploaded file shows a moving progress bar; a running file can be
cancelled and the next starts; a name-collision shows the inline overwrite prompt; on finish
the Drive list refreshes in place.

---

## P4. Video poster (canvas → variants)

### P4.1 Client — extract one poster frame (port the dev's existing code)

**File:** `packages/module-dms/res/assets/js/documents/upload.js` (helper) — or a small
sibling module if cleaner.

Contract:

```js
// Resolve to an image Blob (image/jpeg) or null if extraction fails / not a video.
function extractVideoPoster(file) : Promise<Blob|null>
```

The dev's proven approach lives in the old wdv-5.1.0 `service/scripts/Media.js`
(`_MEDIA.creator.video` + `getCanvas`). Port its logic, with ONE modernisation: source the
frame from the LOCAL file, not a server URL.

Concrete parameters taken from that code (keep them):
- **Frame time:** `video.currentTime = (video.duration > 15) ? 5 : 1` — 5 s in for clips
  longer than 15 s, else 1 s. Set it on `loadedmetadata`.
- **Wait for a decodable frame:** on `loadeddata`, poll until `video.readyState >= 2` before
  drawing (the old code's `resize()` retry loop) — otherwise the canvas is blank.
- **Poster size + quality:** draw to a canvas and export `image/jpeg` at quality `0.75`,
  longest edge ~1250 px (the old `getThumbnail` used 1250 px). Multi-step downscale for a
  smoother result is optional (old `getCanvas` did 3 steps when the delta > 500 px); a single
  `drawImage` at the target size is acceptable.
- **Undecodable fallback:** if the browser cannot decode the video (`readyState` never
  reaches 2, or `error`/timeout), resolve `null` — do NOT block the upload (the old code drew
  an "UNDISPLAYABLE" placeholder; here we simply skip the poster).

Modernisation vs. the old flow: the old code uploaded the video FIRST, then generated the
poster from the uploaded video URL and posted it in a SECOND request (`is_video_poster=1`).
The new flow generates the poster locally via `URL.createObjectURL(file)` and ships it in the
SAME request as the video — one round-trip. Prefer `canvas.toBlob(cb, 'image/jpeg', 0.75)`
over the old `toDataURL` + `dataURLtoFile` (a Blob is what `FormData` wants). Always
`URL.revokeObjectURL()` and drop the `<video>`/`<canvas>` when done (free memory).

- In the uploader (P2), before POSTing a video, `await extractVideoPoster(file)`; if it
  returns a Blob, append it to the `FormData` as `poster` (filename e.g. `poster.jpg`).
- Failure to extract MUST NOT block the video upload — just upload without a poster.

### P4.2 Server — poster → variants through the image pipeline

**Files:** `UploadService.php`, `SaveService.php`, (maybe) `DocumentKind.php`, `SaveRequest.php`.

- `UploadService::save(...)` gains an optional `?UploadedFile $poster = null`. Validate it
  like any image: it MUST sniff to an `image/*` MIME (`DocumentKind::fromMime`), else ignore
  it (never fail the video upload because of a bad poster). Read its bytes with the SAME
  memory discipline (small image — negligible, but still guard if huge).
- Pass poster bytes into the save path. Cleanest: add `?string $posterBytes = null` to
  `SaveRequest`, and in `SaveService::save`:
  - Store the video original as today (`kind = video`, `hasImageVariants() === false` → no
    variants from the video bytes).
  - **If `posterBytes !== null` AND the kind accepts a poster** (video; add
    `DocumentKind::acceptsPoster(): bool` → `Video, Audio? → true`, default false — decide
    video-only for now): run `generateVariants($id, 'jpg', $posterBytes, $req)` and set
    `variants`; set `width`/`height` from `getimagesizefromstring($posterBytes)` (the video
    resolution). This reuses `specsFor()` (admin `s/m/l/xl` + any root profile) unchanged —
    the poster is just the pixel source.
- MUST store poster-derived variant blobs under the SAME document `id` as the video
  (`0/<id>/s.jpg` next to `orig.mp4`) — layout B, id-addressed. No new document.
- MUST NOT change delivery: `/media/.../<video-slug>.s.jpg` resolves via the existing
  `resolve()` + `variants` map; `publicUrl($doc, 's')` works unchanged.
- Overwrite path (`SaveService::replace` / `UploadService::replaceExisting`): out of scope
  for the first cut — leave a `// TODO poster on overwrite` note; if a poster arrives on an
  overwrite, it MAY be applied but is not required.

### P4.3 Drive thumbnail rendering for video

**File:** `DriveControllerTrait.php` (`fileVm`, `previewVm`).

- `fileVm`: change `$hasThumb = $kind === 'image' && array_key_exists('s', $doc->getVariants());`
  to be **kind-agnostic on the variant presence**:
  `$hasThumb = array_key_exists('s', $doc->getVariants());` (a video with a poster now has
  an `s` variant → shows a thumbnail; a video without stays on its icon).
- `previewVm`: same for `$isImage`/`imageUrl` — show the poster image when a usable variant
  exists, regardless of `kind` (pick the largest available of `xl/l/m/s`, as it already
  does). Keep the icon fallback when no variant exists.
- The `<img>` markup in `_list.tpl.php` / `_preview.tpl.php` needs no change (it already
  branches on `thumbUrl`/`imageUrl !== null`).

**Acceptance P4 (manual):** upload an mp4; confirm a poster row is sent; the video document
lists with a thumbnail; the preview pane shows the poster; `/media/<root>/<folder>/<slug>.s.jpg`
returns the poster bytes; the video itself still downloads/plays via the original.

---

## P5. CSS

**Files:** `packages/module-dms/res/scss/**` (the `.dms-` component set — see
[`../topics/css-dms.md`](../topics/css-dms.md)). Add a `.dms-upload-list` / `.dms-upload-row`
component: row layout (name, progress track + fill, status, cancel), and state modifiers
(`--done`, `--error`, `--skipped`, `--cancelled`, `--conflict`). Follow the DMS token +
BEM conventions; rebuild the DMS CSS the usual way. Do NOT inline colors — use tokens.

---

## P6. Test plan (summary)

- **Unit (throwaway CLI):** P0 memory smoke (large dummy through `saveFromUpload`, flat
  peak memory); `effectiveMaxUploadBytes()` for ini combinations (unlimited memory, tight
  memory, tight transport). Keep the existing `serverMaxBytes/fitsMemory` smoke green.
- **HTTP (curl, admin session):** per-file envelope for success/duplicate/conflict/oversize;
  poster part accepted and variants produced (check `documents.json` variants + blob files).
- **Manual (admin browser):** the full P2/P3/P4 acceptance lists above — this is the
  standing open item in the topic doc (admin click-through), so fold it in.

## P7. Open input needed from the dev

- **Poster-extraction JS: DELIVERED** — old wdv-5.1.0 `service/scripts/Media.js`
  (`_MEDIA.creator.video`). Parameters extracted into P4.1 (frame 5 s/1 s, `readyState>=2`
  wait, 1250 px JPEG q0.75, skip on undecodable). No further input needed; just port it.
- Confirm `BASE_RESERVE` (default 64 MB) is acceptable, or tune after seeing real backend
  request memory.

## P8. File map (touched)

P0 (core, do first):
- `packages/module-dms/src/Blob/BlobStorage.php` + `LocalBlobStorage.php` — add `putFile()`
  (move-based writer)
- `packages/kernel/shared/src/ValueObjects/UploadedFile.php` — add `sha256()` (`hash_file`),
  `imageSize()` (`getimagesize`); stop reading the original via `bytes()`
- `packages/module-dms/src/Services/SaveService.php` — add `saveFromUpload()` (move original
  + streamed checksum; variants only for image / video-poster); poster bytes → variants
- `packages/module-dms/src/Services/UploadService.php` — use `sha256()`/`saveFromUpload()`,
  `save()` gains `?UploadedFile $poster`, `effectiveMaxUploadBytes()`, `fitsMemory` gates the
  variant source only

P1–P5:
- `packages/module-dms/src/Ui/DriveControllerTrait.php` — `uploadAction` (per-file envelope,
  no redirect, read `poster`), `addAction` (`data-max-bytes` + `data-max-image-bytes`),
  `fileVm`/`previewVm` (video thumb: variant-presence, not `kind==='image'`)
- `packages/module-dms/src/Services/SaveRequest.php` — optional `posterBytes`
- `packages/module-dms/src/Images/DocumentKind.php` — `acceptsPoster()` (optional helper)
- `packages/module-dms/res/assets/js/documents/upload.js` — full rewrite (XHR queue,
  progress, cancel, two-cap client gate, `extractVideoPoster` ported from `Media.js`)
- `packages/module-dms/res/view/templates/Documents/DriveController/_upload.tpl.php` —
  data-cap attributes, file-list container
- `packages/module-dms/res/scss/**` — `.dms-upload-list` component
- `packages/kernel/core/src/Http/Request.php` — no change (`getUploadedFile('poster')` already exists)

## see also

- [`../topics/documents.md`](../topics/documents.md) — upload rules, the PHP-config-driven
  size cap + memory guard this plan builds on
- [`../topics/css-dms.md`](../topics/css-dms.md) — `.dms-` component conventions for P5
- [`dms-umbauplan.md`](dms-umbauplan.md) — the R6c upload/pane-refresh mechanics reused here
