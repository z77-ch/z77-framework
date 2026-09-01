# backup

2026-09-01

## entry

1. `packages/kernel/shared/src/Backup/BackupService.php` — orchestration: run one backup, write the meta sidecar, apply retention
2. `packages/module-backend/src/Ui/Controllers/Service/BackupController.php` — backend surface (group `service`), thin glue over the service
3. `packages/kernel/bin/z77-backup` — CLI/cron entry (ADR-028), same service underneath
4. `packages/kernel/core/src/Config/backup.default.inc.php` — seed-once policy defaults (retention, excludes, database block)

## file map

SOURCE=/packages/kernel/shared/src/Backup/BackupService.php
SOURCE=/packages/kernel/shared/src/Backup/BackupType.php
SOURCE=/packages/kernel/shared/src/Backup/BackupEntry.php
SOURCE=/packages/kernel/shared/src/Backup/BackupHistory.php
SOURCE=/packages/kernel/shared/src/Backup/ZipArchiver.php
SOURCE=/packages/kernel/shared/src/Backup/RetentionPolicy.php
SOURCE=/packages/kernel/shared/src/Backup/DbDumperInterface.php
SOURCE=/packages/kernel/shared/src/Backup/MysqlDumper.php
SOURCE=/packages/kernel/core/src/Config/backup.default.inc.php
SOURCE=/packages/kernel/bin/z77-backup
SOURCE=/packages/kernel/shared/src/Jobs/BackupJob.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/BackupController.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/actions.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/confirmDelete.tpl.php
SOURCE=/tests/zip-archiver-symlinks.php
SOURCE=/tests/backup-retention.php

## mental model

One HTTP-free kernel service (`Z77\Shared\Backup\BackupService`) with two thin
frontends: the backend screen `/backend/service/backup/list` (new group
`service`, topbar section «Service») and the CLI `php vendor/bin/z77-backup
{data|db|full}` for cron. Three backup types:

| Type | Source | Notes |
|---|---|---|
| `data` | the whole `data/` tree | includes `backendUsers.json` — hence the SUPER_USER gate |
| `db` | SQL dump (v1: `mysqldump` via {@see MysqlDumper}) | only when the `database` block in `config/backup.inc.php` is set; otherwise UI shows "not configured", CLI no-ops with exit 0 |
| `full` | project root minus `fullExcludes` | `vendor/`/`node_modules/` are regenerable from the lock files; `var/` is scratch space the installation rebuilds by itself; the backup root itself is ALWAYS excluded (recursion guard). `logs/` stays IN — it carries the form log, which is a record |

`lib/` is excluded as a WHOLE TREE, not member by member. It is the
installation's scratch space — the page cache (`var/cache/pages`), the throttle
counters (`var/lib/throttle/*`) — and the rule is «everything below `var/` may be
deleted at any moment without losing information». Naming the tree means a
future `lib/something` is covered the day it appears; the list that named
`lib/cache` alone did not cover `lib/throttle` and put every counter into the
full archive (BACKUP-LIB-001).

Storage: `{project}/backup/{type}/YYYY-MM-DD_HHMMSS_{type}.zip` + a
`*.meta.json` sidecar (trigger manual|cron, duration, status, file count).
The backup root lives in the project root, NEVER under `htmlRoot` — archives
are not web-reachable. **The file system is the single source of truth**
(ADR-025 philosophy): the history view is a directory scan
(`BackupHistory::scan()`), size and time come from the archive file, only run
details from the sidecar. There is no central history file that could drift.

- `ZipArchiver` writes to `*.zip.tmp` and renames on success — an aborted run
  never leaves a file the scan would list (the name pattern also filters it out).
- Directory links are FOLLOWED, with a realpath visited set (since 2026-08-28,
  BACKUP-SYMLINK-001): in the release layout
  ([`release-structure.md`](../01-handbook/release-structure.md)) `data/`,
  `config/` and `public/media` inside a release are links into `shared/`, and
  a full backup archives what is behind them, under the link-side names. Two
  names for one tree pack it once, a cycle terminates, a dangling link is
  skipped. A flat installation has no links and behaves exactly as before.
- Retention runs after every successful backup, decided by `RetentionPolicy`
  (pure, harness-tested). Two forms per type: an INTEGER keeps the newest N
  (`0` = unlimited; code default 10/10/5), an ARRAY is TIERED — e.g.
  `['last' => 2, 'daily' => 7, 'weekly' => 4, 'monthly' => 12]`: all of the
  last days, one per ISO week, one per month, thinning with age. The tiered
  form exists for LATE DISCOVERY: change something today, notice it ten days
  later — under «newest N» every kept archive already carries the mistake,
  under tiers a clean weekly/monthly state survives. `last` protects the
  manual backup taken right before a risky change from the same-day
  scheduled run; a misspelled tier name THROWS (silently losing a tier of
  history is the failure mode this topic avoids); timestamps come from the
  archive NAME, never mtime (a copy resets mtime — the GEOIP-002 lesson).
  The seeded default is tiered since 2026-08-28; existing installations
  carry their integer config until edited (seed-once — the BACKUP-LIB-001
  shape).
- `BackupHistory::FILE_PATTERN` doubles as the traversal guard: download and
  delete resolve ONLY file names matching the archive contract, and the type
  token in the name must match the requested type.
- `config/backup.inc.php` is **seed-once** (INST-CONFIG-001 class, like
  auth/i18n): `writeBackupConfig()` writes it on first install, never again.
- The CLI resolves the project root from the working directory (or
  `--project=`), deliberately not from its own path — in monorepo development
  `vendor/z77/kernel` is a path-repo symlink (ADR-028).

## backend surface

`/backend/service/backup/{action}` — group `service` (new, `groupDefaults:
service → backup`), section «Service» in the topbar (navigation seed ids
25/26). All actions `AuthRole::SUPER_USER`.

| Action | Kind | Purpose |
|---|---|---|
| `listAction` | HTML | three type sections, history rows, per-type "Jetzt sichern" (inline `data-fetch-post` form) |
| `runAction` | Fetch POST | run one backup synchronously (`set_time_limit(0)`); service errors become flash errors |
| `downloadAction` | GET | archive as `FileResponse` (`application/zip`, delivery=php — cyon has no X-Sendfile) |
| `actionsAction` | Fetch GET | ⋮ hub: download link + delete (LIST-ACTIONS-HUB-001) |
| `confirmDeleteAction` / `removeAction` | Fetch | modal + per-archive entity CSRF token (scope `backup`) |

## cron

```text
# cyon control panel → cron job (daily data backup):
cd /home/USER/public_html/project && php vendor/bin/z77-backup data
```

One line of output per run (cron-mail friendly), exit 0 = ok / 1 = error.
`db` without a configured database prints a note and exits 0, so the same
cron line works on every installation. Authorization model: shell access =
permission — no token, no HTTP (ADR-028).

Since ADR-031 the same three types are also available as JOBS (`backup-data`,
`backup-db`, `backup-full`, declared in `backendConfig`, all served by
`Z77\Shared\Jobs\BackupJob` with the type in the payload). A schedule set in the
backend then runs them through the single `z77-run` cron line, with history and
failures visible on the job screen. `z77-backup` stays for the manual call —
both go through `BackupService`. No backup job ships a `defaultSchedule`: how
often an installation is backed up, and how much disk that costs, is the
operator's call.

A backup job cannot be sliced (`ZipArchive` writes one archive in one call, no
resume point), so a large `full` run overruns the runner's time budget by
design — the job lock stops a second copy, `maxParallel` keeps other jobs
moving.

## rules

- When adding backup behaviour → MUST go into `Z77\Shared\Backup\*` (kernel, HTTP-free) so UI and CLI stay two thin frontends over one implementation; MUST NOT put backup logic into the controller or the bin script
- When changing where archives are stored → MUST keep the backup root outside `htmlRoot` (archives contain `data/framework/auth/backendUsers.json`) and MUST keep the recursion guard (backup root excluded from `full`)
- When resolving a submitted archive name (download/delete) → MUST go through `BackupHistory::resolvePath()` (pattern + type check); MUST NOT concatenate request input into a path
- When touching the run flow → MUST keep every failure a thrown `\RuntimeException` (installer error-model) and MUST keep the `.tmp`-then-rename write so aborted runs leave no listable archive
- When adding a database engine → MUST implement `DbDumperInterface`; credentials MUST NOT appear on the command line (process list) — use a defaults file or environment, like `MysqlDumper`
- When exposing backup actions in the backend → MUST keep every action `AuthRole::SUPER_USER` (the archive IS the user store) and mutations Fetch-POST (global CSRF) + per-archive entity token
- When adding another CLI task → MUST follow ADR-028 (own `bin/` script in the owning package, Composer `bin`, boot only what it needs)
- When changing what a `data` or `full` archive contains → MUST keep `data/framework/jobs` excluded (`BackupService::DATA_EXCLUDES`, applied to both types and NOT configurable); it is transient runtime state and it changes while the archive is being written (BACKUP-JOBS-001)
- When changing what retention keeps → MUST go through `RetentionPolicy` (pure names-in/names-out, so `tests/backup-retention.php` can replay timelines) and MUST preserve the late-discovery property: some kept archive predates a mistake that is N days old; MUST NOT let any retention config delete the just-written archive (the newest name always survives — asserted in the harness)
- When touching the archive walk → the descent MUST stay PATH-BASED (`scandir` + `is_dir`) and MUST keep the realpath visited set next to it — the pair in `ZipArchiver::addTree()`. MUST NOT swap it back to `RecursiveDirectoryIterator`: without `FOLLOW_SYMLINKS` a linked directory is a silent leaf, and WITH the flag a Windows junction still is (its directory entry reports type «unknown» — measured, BACKUP-SYMLINK-001). Following without the set recurses forever on a cycle; the set without following changes nothing
- When adding a directory of disposable runtime state → MUST follow ADR-034: put it under `var/` (page cache, release switches, throttle counters live there) and MUST NOT add it to `fullExcludes` — the whole `var` tree is already named, and a second entry would only start the maintained-list problem again (BACKUP-LIB-001). The test is «may this be deleted while the installation is serving requests?»; if no, it does not belong under `var/` and the decision is its location, not its exclude.
- When choosing the level under `var/` → state that describes THIS release's code or THIS door's behaviour MUST be release-local (`var/cache`, `var/state`); state that must survive a release switch MUST go under `var/lib` (a signpost into `shared/var/lib`) — ADR-035. Both stay inside the excluded tree either way.

## known issues

- **BACKUP-LIB-001**: don't assume a changed default reaches an existing installation. `fullExcludes` used to name `lib/cache` instead of `lib`, so when the throttle counters moved to `lib/throttle` (2026-08-25) they were back inside every full archive. `config/backup.inc.php` is seed-once — the installer writes it once and NEVER overwrites it — so changing `DEFAULT_EXCLUDES` and `backup.default.inc.php` only fixes installations that do not exist yet. Every existing installation carries its own copy and needs the line edited by hand; axo3 and zihlundsee are done — working copies AND servers, 2026-08-25, nothing open. This is the general shape, not a one-off: any seed-once default that changes needs a per-installation pass, and the change is silent until someone opens an archive and finds what should not be in it. **It happened again on 2026-09-01 (ADR-035):** the tree was renamed `lib` → `var`, so every installation whose seed-once `config/backup.inc.php` still says `lib` now excludes a directory that does not exist and archives all of `var/` instead. Same manual pass, working copies AND servers; `.releases/check.php` warns about it since. Two occurrences make the shape clear: a seed-once default is a copy, and a copy does not follow.

- **BACKUP-SYMLINK-001** — resolved 2026-08-28. Don't assume a directory walk sees what `is_dir()` sees. The old `RecursiveDirectoryIterator` walk treated a linked directory as a silent leaf — `hasChildren()` answered from the LINK view (no descend), `isFile()` from the TARGET view (not a file) — so a full backup of a release layout archived the code and dropped everything behind `data/`, `config/`, `logs/`, `public/media` and `public/storage`: no error, no hint, `status: ok` in the sidecar. Found on cyon while measuring the release structure; the «Daten» type was never affected (there the link is the SOURCE argument, which path resolution follows on open). Fixed by replacing the iterator with the explicit path-based descent + realpath visited set (see rules). `FOLLOW_SYMLINKS` alone was tried first and is NOT enough: a Windows junction reports directory-entry type «unknown», and the flag consults exactly that. Verified: `tests/zip-archiver-symlinks.php`, 9 checks — flat tree and linked tree produce the identical name set, excludes apply behind links, a twice-linked tree packs once, a cycle terminates, a dangling link is skipped.
- **BACKUP-JOBS-001**: don't assume a data backup may contain `data/framework/jobs` — it must not, for two independent reasons. It is transient runtime state, so a restore would resurrect a queue of work from whenever the archive was taken (same argument that keeps a running job out of systemConfig, [`bootstrap.md`](bootstrap.md)). And it MOVES mid-archive: `ZipArchive` reads file contents at `close()`, not at `addFile()`, so `queue.json` being rename-replaced by the very backup job that is running fails the whole archive with `ZipArchive::close(): Read error`. Found 2026-08-07 when the backup types became jobs; fixed via `DATA_EXCLUDES`, applied to `data` and appended unconditionally to `fullExcludes` (the configured list is the operator's, this entry is not).

## pending

- Existing (pre-1.1.0) projects do not get the «Service» navigation section automatically (navigation data is seed-once). The fix is BUILT (ADR-032): the backend data import proposes the missing shipped entries — see [`import.md`](import.md). Manual creation via the navigation UI remains the fallback on installations whose framework predates the import screen.

## see also

- [`../02-decisions/adr-028-cli-entry-point.md`](../02-decisions/adr-028-cli-entry-point.md) — why a dedicated Composer-bin binary per CLI task
- [`../02-decisions/adr-031-job-queue-and-cron-runner.md`](../02-decisions/adr-031-job-queue-and-cron-runner.md) — the job queue the three backup types are scheduled through
- [`../02-decisions/adr-034-disposable-runtime-state-under-lib.md`](../02-decisions/adr-034-disposable-runtime-state-under-lib.md) — why the tree is excluded as a whole, and the three-category test that decides what may live there (the directory itself is `var/` since ADR-035)
- [`../02-decisions/adr-035-release-local-runtime-state-under-var.md`](../02-decisions/adr-035-release-local-runtime-state-under-var.md) — the `lib/` → `var/` rename and the release-local split; the seed-once problem below applies to it a second time
- [`security.md`](security.md) — role gate + storage placement of the archives
- [`installer.md`](installer.md) — `writeBackupConfig()` seed-once config
- [`backend.md`](backend.md) — group/controller conventions the backup surface follows
