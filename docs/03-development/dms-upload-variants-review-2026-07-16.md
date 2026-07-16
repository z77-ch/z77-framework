# DMS upload — missing image variants review (2026-07-16)

> **Temporary working review.** Analysis of a live incident in the zihlundsee reference
> project; delete or fold into the topic doc once the fix decision is made.
> Topic: [`../topics/documents.md`](../topics/documents.md) (known issue DMS-MEM-001).

## Incident

Batch upload of 9 photos (8256×5504 = 45.4 MP, 22–43 MB JPEG each) into
`drive/front/slider/wohnen/main` (profile `slider`, inherited from `drive/front/slider`).
Result: 6 documents got their full variant set (`s, m, mobile, tablet, desktop, full`),
**3 documents (ids 35, 37, 39) got NO variants at all** — no Drive thumbnail, no
derivatives, only the original. No error was shown; every file reported "Hochgeladen".

| id | file | size | variants |
|---:|------|-----:|----------|
| 33 | …-0081.jpg | 27.9 MB | full set |
| 34 | …-0188.jpg | 33.8 MB | full set |
| 35 | …-0224.jpg | 43.1 MB | **none** |
| 36 | …-0225.jpg | 33.9 MB | full set |
| 37 | …-0283.jpg | 22.7 MB | **none** |
| 38 | …-0330.jpg | 28.2 MB | full set |
| 39 | …-0371.jpg | 29.5 MB | **none** |
| 40 | …-0388.jpg | 37.1 MB | full set |
| 42 | …-0421.jpg | 29.5 MB | full set |

Environment: dev, PHP 8.4, `memory_limit = 512M`, `upload_max_filesize = post_max_size = 500M`.

## Root cause — `fitsMemory` false negative, not an actual OOM

`GdImageProcessor::generate()` runs the pixel-based memory guard `fitsMemory()` BEFORE
decoding; when it says "does not fit" it returns `[]` and the document is stored with the
original only (the deliberate graceful path, see `documents.md` known-issue note on GD
memory). The guard computes:

```
needed    = (srcPixels + 2 × largestDestPixels) × 5      // ≈ 266.5 MB for 45.4 MP + full@2800
available = memory_limit − memory_get_usage(true)
fits      = needed ≤ available × 0.8
```

For these images the guard fails as soon as `memory_get_usage(true)` exceeds ~179 MB at
check time. That happened — **not because the memory was actually consumed, but because
`memory_get_usage(true)` reports RESERVED memory including ZendMM's retained/cached
chunks, which are freed (by `imagedestroy`) and fully REUSABLE for the next allocation.**

### Evidence (reproduced 2026-07-16)

1. **Each image fits comfortably alone.** CLI repro on the three failed originals: decode
   + resample + convolution + encode all succeed in ~2 s with a real peak of **264 MB**
   (all 6 slider variants, references properly released — note PHP 8's `imagedestroy()`
   is a no-op; the bitmap frees when the last variable reference drops). None of the
   images is defective.
2. **Reserved ≠ used, and the cache IS reused (proven 2026-07-16, `gc-test2.php`).**
   After one full pass with all references released, `memory_get_usage(false)` = 0 MB
   while `memory_get_usage(true)` = **200 MB** — pure ZendMM chunk cache. Under a **400M**
   limit, two further 45.4-MP passes then succeed with peak 264 MB: the cached chunks are
   fully reused, `reserved` does not grow. The `(true)`-based guard would have refused
   both (available 400−200=200 × 0.8 = 160 MB < 266 MB needed) — the exact false
   negative. A `(false)`-based guard stays safe: a real OOM requires truly-used + needed
   to exceed the limit, which is precisely what it checks (plus the 20 % margin).
3. **The same retention exists across web requests.** `php -S` keeps one worker process;
   after each variant-generating request the NEXT request starts with an elevated
   `memory_get_usage(true)` (measured: 2 → 52 → 64 → 78 MB, accumulating with every heavy
   request). Add the framework baseline plus the original read into RAM by
   `SaveService::variantsFromStoredOriginal()` (`file_get_contents`, 22–43 MB — the 43 MB
   file failed first) and individual upload requests cross the ~179 MB threshold →
   variants silently skipped. A request that skips GD does not add retention, so the
   following upload tends to pass again — matching the observed alternating pattern.

### What is NOT the cause

- **The batch design is sound.** `upload.js` uploads strictly sequentially, ONE file per
  XHR request; the server never holds more than one image per request. The user's
  assumption "memory is only ever needed for 1 image" is correct — the leak-like effect
  is allocator chunk retention in the persistent PHP worker, not files accumulating.
- **PHP parameters are not too low.** 512 M holds a 45.4 MP image incl. all 8 variants
  (real peak ≈ 264 MB). Raising `memory_limit` would only mask the guard's pessimism.
- **Not the transport caps** (files ≤ 43 MB ≪ 500 M) and not `UploadService::fitsMemory`
  (file-size based, passed correctly).

## Why no error message (question b)

By design, twice over:

1. `GdImageProcessor::generate()` returns `[]` for "does not fit" — the SAME silent path
   as an unsupported format. `SaveService` then simply skips the variants flush. Nothing
   is logged anywhere; the information WHY is discarded.
2. `DriveControllerTrait::uploadAction` sees a successfully saved document → envelope
   `status: success` → the row shows "Hochgeladen".

The graceful degradation (store the original rather than fatal mid-save, ARCH-A003) is
right, but for a slider image "no variants" is a de-facto failure the editor must learn
about — currently invisible until the frontend shows nothing.

## Recommendations

1. **Fix the guard (root cause) — DONE 2026-07-16 (uncommitted), dev live-confirmed same day:**
   a fresh skeleton browser batch upload of 11 × 45.4-MP photos produced variants/thumbnails
   for ALL files (the pre-fix batch dropped 3 of 9). Change: measure `available`
   from `memory_limit − memory_get_usage(false)` (actually-used bytes) instead of
   `(true)` (reserved incl. reusable cached chunks) — changed in
   `GdImageProcessor::fitsMemory()` and `UploadService::fitsMemory()`. Safety proven
   under limit pressure (evidence 2): ZendMM reuses its cached chunks, so future real
   usage ≈ used + needed; the existing 20 % margin stays. Verified with the real
   `GdImageProcessor`: the three failed originals processed back-to-back in ONE process
   under a **400M** limit all yield the full 6-variant set (reserved plateaued at
   ~206 MB — the old guard would have refused passes 2+). `memory_get_usage()` is core
   PHP, SAPI-independent — works unchanged on cyon/LSAPI, where long-lived workers make
   the `(true)`-based degradation WORSE than in dev (one big image would poison a
   worker's guard for its whole lifetime).
2. **Surface the outcome:** when an image document is saved WITHOUT variants, return the
   upload envelope as a warning (e.g. `status: success` + note "Original gespeichert,
   Web-Varianten fehlen") and/or `error_log()` the skip reason (dims, needed, available).
   Today the reason is unrecoverable.
3. **PHP parameters: leave as is on dev.** 512M/500M are adequate; only if >60 MP sources
   become normal would 768M be warranted. **cyon caveat:** the fix does not add capacity —
   the production `memory_limit` must still hold the real peak. For 45.4 MP + the slider
   profile the guard needs `memory_limit ≥ ~380M` (266 MB / 0.8 + baseline); check the
   value in my.cyon (PHP settings / `.user.ini`) and set 512M. Below that, big images
   would (correctly) store original-only again — then either raise the limit or downscale
   sources before upload (8256 px for a 2800-px `full` variant is generous anyway; the
   profile comment still says "originals are 4000px wide", which these no longer are).
4. **Repair the 3 documents:** variants are generated at save time only → delete + re-upload
   ids 35/37/39 (identical re-upload of a deleted-then-purged doc regenerates; a plain
   re-upload over a live doc is checksum-skipped). A `reprocess` command remains a
   later-phase item.

## Repro artifacts

Scratch scripts (session scratchpad, not committed): `gd-repro.php` (CLI guard-vs-real
comparison on the live blobs), `srv2.php` (php -S cross-request retention measurement),
`gc-test2.php` (chunk-reuse proof under a 400M limit — the safety case for the
`memory_get_usage(false)` guard).
