# dms-zip-download-bauplan.md — download several DMS documents as one ZIP

**Status:** APPROVED (owner, 2026-11-02), in progress. Topic: [`../topics/documents.md`](../topics/documents.md), item DMS-ZIP-001.

## Problem

The Drive downloads one document at a time. Its multi-select offers delete and move only. The owner
wants a selection as one ZIP, with a mail when it is ready, ready in the DMS for a limited time, and
removed afterwards, because otherwise everything exists twice.

The limits on the host: an answer above about 500 MB gets a 413 (DMS-CHUNK-DL-001), a job has a
50-second `timeBudget` per pass (`jobs.md`), and selections can reach several GB.

## What the old framework did (wdv-510, read only)

`ce/cron/src/Controllers/ZipArchivController.php`: a cron job adds files with `ZipArchive` until an
estimate (12 MB/s, 300 s minus 60 s reserve) is used up, closes the archive, and the next run reopens
it and skips what is there. A second, browser-driven variant adds one file per request
(`ce/frontend/src/Controllers/ZipController.php`).

**Worth keeping:** work in slices across runs, tell the user when it is ready, delete after a period
(15 days there).

**Not worth keeping:**

- `ZipArchive::close()` rewrites the archive, as far as we know libzip, so a slice costs more the
  bigger the archive already is. The old screenshot fits: 272 MB took 16.9 s, later 91 MB took 27.5 s.
  Not measured on its own; it is enough to avoid building on it.
- One file bigger than the budget fits into no slice.
- The memory limit is not handled in this code, only read for display
  (`ce/frontend/src/Controllers/DocumentController.php`). The owner remembers a memory logic; it was
  not found here.
- It stores the bytes a second time.

## Approaches compared

| | A: real ZIP file, job | B: browser builds it | **C: virtual ZIP (recommended)** |
|---|---|---|---|
| How | a job copies all bytes into one file | the browser fetches each document and packs while saving | a job computes the checksums only; the server answers each byte range from the original blobs |
| Bytes stored twice | yes, until cleanup | no | **no**, only a small manifest |
| Disk full risk | yes, a selection can fail on it | no | no |
| Works with the browser closed, mail possible | yes | **no** | yes |
| Rights withdrawn after creation | the copy stays readable | checked per document | **checked on every piece** |
| Document changed after creation | silently the old bytes | current bytes | refused, clear message |
| New code | ZIP64 writer, resume mid-file | JS packer with CRC and ZIP64, in the service worker | ZIP layout, a ranged answer, the job |
| Client changes | none (`chunk-download.js`) | large | **none** (`chunk-download.js`) |

C is the owner's idea without its price: the job, the mail, the time limit and the cleanup stay, the
second copy goes. B is rejected because it cannot send a mail and needs the tab open for hours.

A remains the fallback if C fails on the host measurement below.

### Why C works

ZIP without compression (store) makes every position computable once names, sizes and CRC-32 are
known. Compression would save little anyway: images, videos and PDFs are compressed already, and it
is what makes an archive slow to build. Only the CRC needs reading every byte, and that is the job.

### Probe (2026-11-02, Windows, PHP 8.5, throwaway script, removed)

Six files, among them a 4.5 GB one placed before the last entry, an empty one and an umlaut name.
The result, 4.81 GB, was written in 144 explicit ranges of 32 MB, as `chunk-download.js` asks.

- libzip opens it with `CHECKCONS`, the 4.5 GB entry re-read through it has the right CRC.
- Windows `tar` (libarchive) lists it and extracts a 300 MB entry byte-identical.
- .NET `ZipFile` lists all entries and reads the entry behind the 4 GB boundary; the umlaut name
  arrives correctly (code points checked).
- The CRC computed in resumed slices equals `hash_file()`: `serialize(HashContext)` works for
  `crc32b`.

**Not proved:** Windows Explorer, macOS Archive Utility and 7-Zip (not installed here); anything on
the host. The large file was sparse, so the speed seen says nothing about a real disk: **the CRC
speed on the host must be measured** (phase Z6).

## Flow

```
Drive: tick documents, «Als ZIP …»
  → dialog: count, total size, name, [x] «E-Mail, sobald bereit» (only with an address on the account)
  → server: bulkDocs() gates `read` per document, writes the manifest (owner-bound random id)
       small selection (below zipInstantBytes): CRC in the request, ready at once, download starts
       otherwise: enqueue dms-zip-prepare, the dialog follows with the live listener
  → job: CRC per file, cursor in the manifest, JobResult::again() when the budget ends
  → state «ready»: mail (no document names, link to the Drive view, login required)
  → Drive view «ZIP-Downloads» (own bundles only, like the trash view): download / delete, expiry shown
  → «Download»: chunk-download.js fetches 32 MB ranges
       every piece: owner, expiry; each document it touches: `read`, not deleted, checksum and size
       unchanged. The first piece checks all documents.
  → expired: refused by the gate at once; manifest removed by the sweep or the job
```

## Decisions in this plan

- **Not a `Document`.** A ZIP stands in no folder. A real document would inherit the folder's ACL and
  show one person's selection to others, and would count as content. It is a personal bundle in its
  own Drive view. The owner accepted this over a document in a folder (2026-11-02).
- **Expiry is enforced by the gate, not by the job.** A job that is switched off cannot keep a bundle
  alive. Deleting only removes a small manifest, so no `defaultSchedule` and no exception to the
  jobs.md rule: `dms-zip-cleanup` is offered, and every new bundle sweeps, like
  `ChunkedUploadService`.
- **The job reads blobs without the owner's rights** and outputs nothing but CRCs. Rights are checked
  at creation and on every piece. It runs as `CRON_JOB`, no ACL is bypassed for delivery.
- **`originalName` in a flat archive, no folders** in v1. Names are cleaned (no path separators, no
  `..`, no control characters: zip-slip), duplicates become `name (2).ext`.
- **Original bytes only**, the same as the plain «Download». A document without them is skipped and
  named in the dialog.
- **Limits:** `BULK_MAX` (500) applies; at most 3 unfinished bundles per person (each one reads every
  byte once).
- **Mail through `EmailService`** directly, module-dms has no dependency on module-mailing. A failed
  mail is logged and shown on the bundle; the bundle stays usable. The origin comes from
  `CANONICAL_BASE_URL`, as a job has no request.

## Build phases

| Phase | Content | Where |
|---|---|---|
| Z1 | `ZipLayout`: pure class, files in, size and `emit(start, end)` out, ZIP64 where needed. Harness first, against libzip, on real and on sparse files | `module-dms/src/Zip/` |
| Z2 | Ranged answer: range parsing moves out of `FileResponse::byteRange()` for shared use, same 256 MiB cap, `FileResponse` behaviour unchanged (`tests/file-response-range.php` stays green). New typed response over a byte source, reached through a helper or service like `serve()` (Rule 3) | kernel |
| Z3 | `ZipBundleService`: create, state, gate per piece, delete, sweep; manifests under `data/dms/zips/<id>/` with a lock; `dms/zips` into `BackupService::DATA_EXCLUDES`; the hash context unserialized with `allowed_classes` limited to `HashContext`, a failure restarts that file | `module-dms` |
| Z4 | Jobs `dms-zip-prepare`, `dms-zip-cleanup`; config `zipKeepDays`, `zipInstantBytes` | `module-dms`, `dmsConfig` |
| Z5 | UI after a board on the developer canvas (`backend-screen.md`): bulkbar button, dialog, live state, Drive view; mail template | `module-dms` |
| Z6 | Host: CRC speed and a real multi-GB download on the real host; Explorer, macOS, 7-Zip; rights withdrawn, document replaced and document deleted mid-download | real installation |

Topic doc, `CHANGELOG.md` and `docs:check` go with each phase. A project's member page
can follow the recipe of DMS-CHUNK-DL-001; not part of v1.

## Risks

- **A document replaced during one piece** can deliver mixed bytes until the next check. A short body
  is caught by the client; other cases show as a CRC error when unpacking.
- **The CRC pass still reads everything**, so it costs I/O once per bundle. Bounded by the job runner
  (`maxParallel`, budget) and the bundle limit.
- **A plain request without `Range`** above the host cap gets the 413, as for any large file. The UI
  always uses the chunked download for bundles.
- **`chunk-download.js` needs a secure context and shows its own errors**: check in Z6 that a refused
  piece reads sensibly to the user.

## Owner decisions (2026-11-02)

1. **Approach C**, a personal bundle in a Drive view, not a real document in a folder.
2. **Keep time:** 7 days (`zipKeepDays`). It costs no disk, this is about links going stale.
3. **Mail:** optional, a checkbox «E-Mail, sobald bereit».
4. **Instant path:** below `zipInstantBytes` (256 MB, to be confirmed by Z6) ready at once, without the job.
