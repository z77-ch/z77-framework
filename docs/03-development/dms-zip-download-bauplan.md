# dms-zip-download-bauplan.md — download several DMS documents as one ZIP

**Status:** APPROVED (owner, 2026-11-02), Z1 to Z4 and the host-page hooks built 2026-11-02, Z5 and Z6 open (see the phase table). Topic: [`../topics/documents.md`](../topics/documents.md), item DMS-ZIP-001.

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
  alive. Removing the manifest is another matter, it holds file names and possibly an address:
  `dms-zip-cleanup` ships a daily `defaultSchedule` (corrected 2026-11-02, the third exception of the
  jobs.md rule, JOBS-SCHED-001), and every new bundle sweeps as well.
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

| Phase | Content | Where | Status |
|---|---|---|---|
| Z1 | `ZipLayout`: pure class, files in, size and `emit(start, end)` out, ZIP64 where needed. Harness first, against libzip, on real and on sparse files | `module-dms/src/Zip/` | built, `tests/dms-zip-layout.php` |
| Z2 | Ranged answer: range parsing moves out of `FileResponse::byteRange()` for shared use, same 256 MiB cap, `FileResponse` behaviour unchanged (`tests/file-response-range.php` stays green). New typed response over a byte source, reached through a helper or service like `serve()` (Rule 3) | kernel | built: `ByteRange`, `ByteSource`, `ByteSourceResponse` |
| Z3 | `ZipBundleService`: create, state, gate per piece, delete, sweep; manifests under `data/dms/zips/<id>/` with a lock; `dms/zips` into `BackupService::DATA_EXCLUDES`; the hash context unserialized with `allowed_classes` limited to `HashContext`, a failure restarts that file | `module-dms` | built, `tests/dms-zip-bundle.php` |
| Z4 | Jobs `dms-zip-prepare`, `dms-zip-cleanup`; config `zipKeepDays`, `zipInstantBytes`; the mail with its template (moved here from Z5: it needs no screen) | `module-dms`, `dmsConfig` | built, same harness |
| B | Host pages, module-neutral (owner, 2026-11-02): config hooks for address, extra gate and download-started, `deleteForDocuments` / `deleteForOwner` / `ownerKey`, mail replaceable per installation or host, the actions usable in a project's own controller, `list()`; `ByteSourceResponse` through `DeliveryPolicy`; the failed mail; the client shows the server's reason | `module-dms`, kernel, client | built, recipe in `documents.md` |
| Z5 | UI after a board on the developer canvas (`backend-screen.md`): bulkbar button, dialog, live state, Drive view | `module-dms` | open; the JSON endpoints are mounted (`ZipBundleTrait`), no screen |
| Z6 | Host: CRC speed and a real multi-GB download on the real host; Explorer, macOS, 7-Zip; rights withdrawn, document replaced and document deleted mid-download | real installation | open |

Topic doc and `docs:check` go with each phase (this repo keeps no `CHANGELOG.md`). A project's
member page can follow the recipe of DMS-CHUNK-DL-001; not part of v1.

## As built: where Z1 to Z4 went further than this plan

- **The central directory counts as touching every document.** The plan's gate named «each
  document the range touches». The central directory carries every name, so a piece that reaches
  it checks them all; a piece that only clips a local header counts for that document too.
- **A request without `Range` and a HEAD check all documents,** like the first piece.
- **A document changed during the count ends the bundle as `failed`,** with a German reason; the
  plan only spoke of pieces. The first piece would refuse it anyway, this says so earlier.
- **The expiry starts again when a bundle gets ready.** It runs from creation first, so a bundle
  nobody ever counts (no cron) goes away as well. The keep time is about links, and a link exists
  from ready on.
- **More than 500 documents are refused, not cut:** a ZIP that silently misses documents is worse
  than a message. The bulk rule's cap, its own consequence.
- **A new bundle queues the job unless an entry is still WAITING,** not «unless one is open»: a
  running entry may have scanned before the manifest existed and finish without it.

## After the review of 2026-11-02

An independent review found no authorization or format blocker and real defects in concurrency and
limits. What changed, all with harness section (P) of `tests/dms-zip-bundle.php`:

- **One owner for a preparation:** a lease in the manifest, and every write of its holder re-reads
  under the lock and gives up unless the bundle is still its own. Before, a job pass with an old
  scan could rewrite an instant bundle that had got ready: back to `preparing`, a second mail, a
  running download refused. A lease that runs out is adopted by the job.
- **Caps on all live bundles,** per person and per installation, under one lock with the creation.
- **A manifest has a head line,** so a pass, a sweep, a list and a purge do not hold all manifests.
  The file is replaced by temp file and rename, under a `lock` file of its own.
- **An instant bundle sends no mail,** whatever the flag says: it is ready or failed in the answer.
  The earlier decision «a failed bundle sends a mail» stands for the job path.
- **The started hook fires on the first piece answered,** wherever it starts.
- **The trash purges bundles per batch of 100, inside its loop;** a single `purge()` keeps doing it by
  itself.

A second review of recovery and clean-up, the same day, section (Q) of the harness:

- **Leftovers count for nobody and go:** a directory without a readable bundle is no slot, `open()` and
  the sweep remove it from 10 minutes on, the purges also take a lock-less manifest.
- **A bundle with no live holder is stranded, lease or not,** and every look at it queues the job, a
  refused `open()` too: three bundles without a queue entry had locked their owner out for days.
- **A started claim that cannot be saved breaks no download;** the hook does not fire then.
- **`dms-zip-cleanup` ships `daily@03:50`** (owner): see the decision above.
- **A lease further ahead than a lease can be counts as run out.**

## Owner decisions after Z4 (2026-11-02)

- **The client does change, by one function.** A refused piece shows the server's German reason from
  the JSON, as text (Rule 7). The comparison table's «no client change» held for the piece protocol
  and still does.
- **A failed bundle sends a mail** when one was requested: whoever asked for it has no screen open.
- **`DocumentService::purge()` calls `deleteForDocuments()`:** not named in the order, but its reason
  was «purges must remove all traces», and a framework purge that leaves a name for up to seven days
  would be the first counter-example.
- **An account removed takes its bundles with it** (owner, later that day): the kernel's
  `accountRemovedListeners` registry, the pattern of `authBridges`; `module-dms` declares a listener,
  backend user removal and `AccountDeletion` announce, none of them knows the others and an
  installation without the DMS declares nothing. A failing clean-up is logged and stops no removal.

## Open questions for Z5

- **`DRIVE_VIEW_ROUTE`** points at the Drive itself until the view exists; the mail's link must
  land there after a login.
- **The checkbox on the instant path:** the server ignores it, the dialog can hide it below
  `zipInstantBytes` so that it promises nothing.
- **Deleting and opening again:** the caps do not bound an instant bundle's reading for a person who
  does that in a loop. A per-person rate limit stays out for now and is needed when a member page mounts
  the trait (`documents.md`, pending).
- **No cron:** a bundle stays `preparing` until it expires. The dialog could say so (JOBS-003).

## Risks

- **A document replaced during one piece** can deliver mixed bytes until the next check. A short body
  is caught by the client; other cases show as a CRC error when unpacking.
- **The CRC pass still reads everything**, so it costs I/O once per bundle. Bounded by the job runner
  (`maxParallel`, budget) and the bundle limit.
- **A plain request without `Range`** above the host cap gets the 413, as for any large file. The UI
  always uses the chunked download for bundles.
- **`chunk-download.js` needs a secure context**: a refused piece now shows the server's reason, check
  in Z6 how it reads in a real browser.

## Owner decisions (2026-11-02)

For Z5, decided the same day: a failed bundle sends a mail; the mail checkbox is hidden below `zipInstantBytes`; `chunk-download.js` gets one line that shows a readable message for a refused piece (the one client change); the dialog warns only when the job heartbeat is stale, cron runs on the known installations; a member's mail is handed to the project that owns the member page.

1. **Approach C**, a personal bundle in a Drive view, not a real document in a folder.
2. **Keep time:** 7 days (`zipKeepDays`). It costs no disk, this is about links going stale.
3. **Mail:** optional, a checkbox «E-Mail, sobald bereit».
4. **Instant path:** below `zipInstantBytes` (256 MB, to be confirmed by Z6) ready at once, without the job.
