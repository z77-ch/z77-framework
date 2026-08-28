# backup

2026-07-16

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
SOURCE=/packages/kernel/shared/src/Backup/DbDumperInterface.php
SOURCE=/packages/kernel/shared/src/Backup/MysqlDumper.php
SOURCE=/packages/kernel/core/src/Config/backup.default.inc.php
SOURCE=/packages/kernel/bin/z77-backup
SOURCE=/packages/kernel/shared/src/Jobs/BackupJob.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/BackupController.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/actions.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/BackupController/confirmDelete.tpl.php

## mental model

One HTTP-free kernel service (`Z77\Shared\Backup\BackupService`) with two thin
frontends: the backend screen `/backend/service/backup/list` (new group
`service`, topbar section «Service») and the CLI `php vendor/bin/z77-backup
{data|db|full}` for cron. Three backup types:

| Type | Source | Notes |
|---|---|---|
| `data` | the whole `data/` tree | includes `backendUsers.json` — hence the SUPER_USER gate |
| `db` | SQL dump (v1: `mysqldump` via {@see MysqlDumper}) | only when the `database` block in `config/backup.inc.php` is set; otherwise UI shows "not configured", CLI no-ops with exit 0 |
| `full` | project root minus `fullExcludes` | `vendor/`/`node_modules/` are regenerable from the lock files; `lib/` is scratch space the installation rebuilds by itself; the backup root itself is ALWAYS excluded (recursion guard) |

`lib/` is excluded as a WHOLE TREE, not member by member. It is the
installation's scratch space — the page cache (`lib/cache/pages`), the throttle
counters (`lib/throttle/*`) — and the rule is «everything below `lib/` may be
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
- Retention runs after every successful backup: keep the newest N per type
  (config `retention`, defaults 10/10/5, `0` = unlimited).
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
- When adding a directory of disposable runtime state → MUST follow ADR-034: put it under `lib/` (page cache, throttle counters live there) and MUST NOT add it to `fullExcludes` — the whole `lib` tree is already named, and a second entry would only start the maintained-list problem again (BACKUP-LIB-001). The test is «may this be deleted while the installation is serving requests?»; if no, it does not belong under `lib/` and the decision is its location, not its exclude.

## known issues

- **BACKUP-LIB-001**: don't assume a changed default reaches an existing installation. `fullExcludes` used to name `lib/cache` instead of `lib`, so when the throttle counters moved to `lib/throttle` (2026-08-25) they were back inside every full archive. `config/backup.inc.php` is seed-once — the installer writes it once and NEVER overwrites it — so changing `DEFAULT_EXCLUDES` and `backup.default.inc.php` only fixes installations that do not exist yet. Every existing installation carries its own copy and needs the line edited by hand; axo3 and zihlundsee are done — working copies AND servers, 2026-08-25, nothing open. This is the general shape, not a one-off: any seed-once default that changes needs a per-installation pass, and the change is silent until someone opens an archive and finds what should not be in it.

- **BACKUP-JOBS-001**: don't assume a data backup may contain `data/framework/jobs` — it must not, for two independent reasons. It is transient runtime state, so a restore would resurrect a queue of work from whenever the archive was taken (same argument that keeps a running job out of systemConfig, [`bootstrap.md`](bootstrap.md)). And it MOVES mid-archive: `ZipArchive` reads file contents at `close()`, not at `addFile()`, so `queue.json` being rename-replaced by the very backup job that is running fails the whole archive with `ZipArchive::close(): Read error`. Found 2026-08-07 when the backup types became jobs; fixed via `DATA_EXCLUDES`, applied to `data` and appended unconditionally to `fullExcludes` (the configured list is the operator's, this entry is not).

## pending

- **Decide what «Gesamtprojekt» does in the release layout** (found 2026-08-28 on cyon, see [`../01-handbook/release-structure.md`](../01-handbook/release-structure.md)): `ZipArchiver::zipDirectory()` iterates with `RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)` and no `FOLLOW_SYMLINKS` — a symlinked directory inside the tree answers `hasChildren() === false` and `isFile() === false`, so the iterator never descends and packs nothing. In the release structure, where `data/` inside a release is a symlink into `shared/`, a full backup therefore archives NO data — without an error, without a hint, which is the most dangerous kind of backup. «Daten» is unaffected (the symlink is the SOURCE argument and is followed on open); «Datenbank» is unaffected. Options: add `FOLLOW_SYMLINKS` (then decide how to avoid archiving `shared/` several times over via multiple links), or declare «Gesamtprojekt» unsupported in that layout and say so on the surface. Until decided, release-layout installations schedule «Daten» + «Datenbank» only.
- Existing (pre-1.1.0) projects do not get the «Service» navigation section automatically (navigation data is seed-once). The fix is BUILT (ADR-032): the backend data import proposes the missing shipped entries — see [`import.md`](import.md). Manual creation via the navigation UI remains the fallback on installations whose framework predates the import screen.

## see also

- [`../02-decisions/adr-028-cli-entry-point.md`](../02-decisions/adr-028-cli-entry-point.md) — why a dedicated Composer-bin binary per CLI task
- [`../02-decisions/adr-031-job-queue-and-cron-runner.md`](../02-decisions/adr-031-job-queue-and-cron-runner.md) — the job queue the three backup types are scheduled through
- [`../02-decisions/adr-034-disposable-runtime-state-under-lib.md`](../02-decisions/adr-034-disposable-runtime-state-under-lib.md) — why `lib` is excluded as a whole tree, and the three-category test that decides what may live there
- [`security.md`](security.md) — role gate + storage placement of the archives
- [`installer.md`](installer.md) — `writeBackupConfig()` seed-once config
- [`backend.md`](backend.md) — group/controller conventions the backup surface follows
