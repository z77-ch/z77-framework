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
| `data` | the whole `data/` tree | includes `loginUsers.json` — hence the SUPER_USER gate |
| `db` | SQL dump (v1: `mysqldump` via {@see MysqlDumper}) | only when the `database` block in `config/backup.inc.php` is set; otherwise UI shows "not configured", CLI no-ops with exit 0 |
| `full` | project root minus `fullExcludes` | `vendor/`/`node_modules/` are regenerable from the lock files; the backup root itself is ALWAYS excluded (recursion guard) |

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

## rules

- When adding backup behaviour → MUST go into `Z77\Shared\Backup\*` (kernel, HTTP-free) so UI and CLI stay two thin frontends over one implementation; MUST NOT put backup logic into the controller or the bin script
- When changing where archives are stored → MUST keep the backup root outside `htmlRoot` (archives contain `data/framework/auth/loginUsers.json`) and MUST keep the recursion guard (backup root excluded from `full`)
- When resolving a submitted archive name (download/delete) → MUST go through `BackupHistory::resolvePath()` (pattern + type check); MUST NOT concatenate request input into a path
- When touching the run flow → MUST keep every failure a thrown `\RuntimeException` (installer error-model) and MUST keep the `.tmp`-then-rename write so aborted runs leave no listable archive
- When adding a database engine → MUST implement `DbDumperInterface`; credentials MUST NOT appear on the command line (process list) — use a defaults file or environment, like `MysqlDumper`
- When exposing backup actions in the backend → MUST keep every action `AuthRole::SUPER_USER` (the archive IS the user store) and mutations Fetch-POST (global CSRF) + per-archive entity token
- When adding another CLI task → MUST follow ADR-028 (own `bin/` script in the owning package, Composer `bin`, boot only what it needs)

## known issues

- None documented.

## pending

- Existing (pre-1.1.0) projects do not get the «Service» navigation section automatically (navigation data is seed-once) — the two entries are created via the backend navigation UI; documented in the 1.1.0 release notes.

## see also

- [`../02-decisions/adr-028-cli-entry-point.md`](../02-decisions/adr-028-cli-entry-point.md) — why a dedicated Composer-bin binary per CLI task
- [`security.md`](security.md) — role gate + storage placement of the archives
- [`installer.md`](installer.md) — `writeBackupConfig()` seed-once config
- [`backend.md`](backend.md) — group/controller conventions the backup surface follows
