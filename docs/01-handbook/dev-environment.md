# Developer Environment — Requirements & Setup

**Status:** `[CURRENT]`
**Date:** 2026-11-19

Everything the framework needs to build and run that is **not** carried by the code
itself (tools are per-machine; `node_modules/` and `vendor/` are regenerated). Match or
exceed the minimums — they come from the code.

---

## 1. Required tools (known-good versions)

| Tool | Minimum | Known-good | Notes |
|---|---|---|---|
| PHP (CLI) | 8.2 (kernel + web modules) · 8.4 (business modules, Doctrine driver, the monorepo) | 8.4 / 8.5 | on PATH; `php.ini` values → [§2](#2-php-settings-phpini) |
| PHP extensions | `mbstring fileinfo zip openssl iconv simplexml gd` | + `pdo_mysql`, `curl`, `apcu` | the complete list, per package, is in [§2 → Extensions](#extensions) |
| MariaDB | 10.6 | tested with 10.6, 11.4 and 13.0 (11.4.14 dev since 2026-11-19, 10.6.28 dev before, 11.4.13 production, 13.0.2 install test 2026-11-19) | only for the business modules / `z77/persistence-doctrine` and their test harnesses; a newer major than tested → proceed, stop at the first SQL error |
| Composer | 2.x | 2.8 / 2.9 | — |
| Node.js | 20 | 22 / 24 | `docs-lint` requires ≥20 |
| npm | — | 11.x | ships with Node |
| Dart Sass | — | 1.99 | **local** dev-dependency (`npm install`), not global — runs via `npm run` |
| terser | 5.26.0 (pinned) | 5.26.0 | **local** dev-dependency (`npm install`) — the JS minifier of `npm run build:js`; the same version the VS Code extension «autominify» bundles |
| Git | — | 2.x | provides Git Bash + ssh on Windows |

Node/Sass are needed **only to develop styles** — the framework ships pre-compiled CSS, so
a project runs with just PHP + Composer. A project has **no JavaScript build step**: the
packages ship each script with its committed `.min.js`. Whoever changes a framework `*.js`
re-minifies it with `npm run build:js -- <file.js>` (terser from the local `node_modules`,
installed by `npm install`) and checks with `php tests/js-min-markers.php`
— [`../topics/js-build.md`](../topics/js-build.md). `sass` and `terser` are not global binaries — they live in
`node_modules/` and are invoked by the `npm run …` scripts. Do not install them globally.

---

## 2. PHP settings (php.ini)

Every value below comes from the code (the limit, the call or the guard named in «why»), not
from habit. **required** = something breaks without it · **recommended** = works without it,
but worse or slower · **production** = only on the server. Check every value at once with
`php -i` (CLI) — the web PHP has its own `php.ini` on most hosts: check it there with a
temporary `phpinfo()` page or the probe in [release-structure.md](release-structure.md).

| Setting | Value | Level | Why (source in the code) | Needed by | Check |
|---|---|---|---|---|---|
| `file_uploads` | `On` | required | every upload (Drive, imports, PDFs, vouchers) | kernel | `php -r "echo ini_get('file_uploads');"` → `1` |
| `upload_max_filesize` | ≥ `20M` (dev: `500M`) | required | largest fixed per-file cap: creditor PDF `creditorConfig['intake']['maxBytes']` = 20 MiB; then bank voucher `BankPostingService::VOUCHER_MAX_BYTES` and receipt photo `ReceiptCaptureService::MAX_PHOTO_BYTES` = 10 MiB, camt file / ZIP 5 MiB. Every upload is one request per file (`UploadPolicy`), and `UploadPolicy::transportMaxBytes()` caps every limit by this value — the screens show the lower one. DMS: a single-request upload is bounded by this value only; above it the chunked upload sends 8 MiB pieces (`dmsConfig['chunkBytes']`, clamped below it) | creditor, financial, receipt, debtor, dms | `php -r "echo ini_get('upload_max_filesize');"` |
| `post_max_size` | ≥ `upload_max_filesize`, a little above it | required | a request carries the file plus the form fields and multipart framing; the smaller of the two is the transport cap | as above | `php -r "echo ini_get('post_max_size');"` |
| `max_file_uploads` | default `20` | — | the upload component sends one file per request (`UploadPolicy`); no form sends more than a handful | — | `php -r "echo ini_get('max_file_uploads');"` |
| `max_input_vars` | default `1000` | — | the largest forms (bank match lists, `SHOW_LIMIT` 60 rows) stay far below it | — | `php -r "echo ini_get('max_input_vars');"` |
| `memory_limit` | ≥ `128M` · `256M` with the business suite · ≥ `384M` with DMS photos · dev `512M` | required / recommended | a camt ZIP holds up to 50 MiB of XML in memory (`CamtArchive::MAX_TOTAL_BYTES`, entries ≤ 5 MiB, estimate — not measured); DMS image variants: GD decodes the pixels, a 45-MP photo needs ≥ ~380M (`UploadService` / `GdImageProcessor` measure the remaining limit and skip variants that would not fit — DMS-MEM-001, [`documents.md`](../topics/documents.md)); videos and other files are moved, not loaded; DMS ZIP bundles are sliced by the job (tested at 48M) | dms, financial, debtor | `php -r "echo ini_get('memory_limit');"` |
| `max_execution_time` (web) | default `30` (dev: `300`) | recommended | long work runs in the job runner, not in a request (creditor AI recognition only in the `creditor-intake` job); the backup screen and the KI-Assistent stream lift their own limit (`set_time_limit(0)` in `BackupController`, `KibotEndpoint`) — the host's web-server timeout still applies | kernel, backend, kibot | `phpinfo()` of the web PHP |
| `max_execution_time` (CLI) | `0` (PHP's CLI default) | required | the cron runner bounds itself: `timeBudget` 50 s per pass ([jobs-and-cron.md](jobs-and-cron.md)) | kernel (jobs) | `php -r "echo ini_get('max_execution_time');"` → `0` |
| `date.timezone` | not needed (`Europe/Zurich` if set) | — | the kernel sets the zone from `timeZone` in `config/vendor/bootstrap.inc.php` (default `Europe/Zurich`, `Bootstrap`), as do `z77-backup` / `z77-restore`; only tools outside the boot (test run directories) fall back to `date.timezone` / UTC | kernel | `php -r "echo date_default_timezone_get();"` |
| `apc.enabled` | `1` | recommended | `DataCache` uses APCu only when loaded AND enabled (`DataCache::apcuOn()`); without it every request starts with an empty cache | kernel (`apcu`) | `php -r "var_dump(function_exists('apcu_enabled') && apcu_enabled());"` |
| `apc.enable_cli` | `1` | recommended (dev) | a CLI run has its own APCu pool that dies with the process; with `0` the CLI works from the per-request tier, without warnings (CACHE-APCU-CLI-001, [`cache.md`](../topics/cache.md)); the APCu harness passes `-d apc.enable_cli=1` itself | kernel | `php -i` → `apc.enable_cli => On` |
| `apc.shm_size` | default `32M` | — | the code sets no size; one pool per installation **and** release — raise it only on a host where many sites share APCu | kernel | `php -i` → `apc.shm_size` |
| `opcache.enable`, `opcache.validate_timestamps`, `opcache.revalidate_path` | the host's | production | the release switch and «Cache leeren» depend on them — see [release-structure.md](release-structure.md) (not repeated here) | kernel | probe in release-structure.md |
| `allow_url_fopen` | `On` on the developer's machine | recommended (dev) | only the deploy tools use PHP streams over HTTP (`.releases/lib.php`: `get_headers()` probes); the framework at runtime fetches with curl (kibot, GeoIP update) | `.releases/` | `php -r "echo ini_get('allow_url_fopen');"` |
| `disable_functions` | must not contain the functions named here, where the feature is used | required per feature | `proc_open` · `proc_get_status` · `proc_terminate` · `proc_close` → creditor recognition (`pdftotext`, `ToolRunner`) — **in the CLI PHP of the cron only**, the web PHP may forbid them; `exec` → database backup and restore (`MysqlDumper`, `MysqlRestorer`; backup screen AND jobs, so web and CLI); `set_time_limit` → backup screen, KI-Assistent; `passthru` → the installer's `z77/docs` offer (CLI); `exec` · `shell_exec` · `proc_open` → `.releases/` deploy scripts (developer machine) | creditor, kernel, backend, kibot | `php -r "echo ini_get('disable_functions');"` (CLI and web separately) |

### Extensions

The one list. Required ones are enforced by the packages' `composer.json` (`ext-*`) — Composer
refuses to install without them.

| Extension | Level | Needed by | What uses it |
|---|---|---|---|
| `mbstring` | required | kernel | strings, everywhere |
| `fileinfo` | required | kernel | upload MIME sniffing (`UploadedFile`) |
| `zip` | required | kernel | backups and restore (`ZipArchiver`), camt ZIP (`CamtArchive`), debtor's invoice ZIP |
| `openssl` | required | kernel | `TotpVault` (2FA secrets), HTTPS |
| `iconv` | required | kernel | `PdfDocument` |
| `simplexml` | required | kernel | `CamtParser` (camt.053 / .054) |
| `gd` | required | module-dms | image variants |
| `pdo_mysql` | required | persistence-doctrine (every business module) | MariaDB |
| `curl` | required | kibot | `CurlTransport` (Anthropic API); also the kernel's `geoip-update` job, which fails with «curl fehlt» without it |
| `json` | required | kibot | always built into PHP ≥ 8.0 |
| `apcu` | recommended | kernel (`suggest`) | shared data cache; settings above |
| `ctype` | (on by default) | many modules | `ctype_digit()` — not declared, part of every standard build |
| `intl` | optional | module-dms | `Normalizer` for ZIP entry names (guarded by `class_exists`) |

---

## 3. First run (a project)

```bash
composer install            # pulls the framework into vendor/, runs the installer
composer dev                # serves http://127.0.0.1:8080 from public/ (IPv4 — on Windows `localhost` binds IPv6 only)
```

The installer prompts for the admin password (interactive). If Composer's Windows
hidden-input helper errors with `hiddeninput.exe: Resource temporarily unavailable`,
delete `%TEMP%\hiddeninput.exe` and retry, or run `composer install --no-interaction`
(writes a `SETUP_TOKEN`; create the admin later via `/backend/system/setup/setup`).

---

## 4. CSS compilation (Dart Sass)

Source `res/scss/` → `res/assets/css/` per module. Requires `npm install` first.

| Command | Effect |
|---|---|
| `npm run watch:frontend` · `:backend` · `:dms` · `:member` | watch + recompile one module (dev) |
| `npm run watch` | watch all four at once |
| `npm run build` | one-off compressed build (all modules, no source map) |

CSS rules: [`css-conventions.md`](css-conventions.md); watch workflow →
[`../topics/css-watch.md`](../topics/css-watch.md).

---

## 5. Checkers

| Command | Purpose |
|---|---|
| `npm run docs:check` | deterministic docs linter (`docs/topics/` structure) — must be green |
| `npm run build` | compile all module CSS (compressed) — must succeed |
| `bash docs/01-handbook/verify-dev-env.sh` | monorepo checkout: PASS / WARN / FAIL for tools, PHP extensions, local Sass, GitHub access, git identity ([script](verify-dev-env.sh)) |
| `php tests/run.php <group\|harness …>` / `--changed` | the test runner: harnesses side by side, one summary table, watchdog, tree guard ([testing.md](../topics/testing.md)); the database harnesses need a local MariaDB |
| `php tests/<name>.php` | one plain-PHP harness directly (no table, no watchdog) |

---

## 6. Gotchas

- **`node_modules` / `vendor` are gitignored** — always `npm install` / `composer install`
  on a fresh checkout; nothing runs until you do.
- **APCu for the CLI** (`apc.enable_cli=1`) — recommended on a dev machine; without it a CLI
  run works from the per-request cache ([§2](#2-php-settings-phpini)).
- **A fresh PHP takes uploads only up to 2 MB** (`upload_max_filesize = 2M`,
  `post_max_size = 8M`) — set the values of [§2](#2-php-settings-phpini) before testing uploads.
- **`hiddeninput.exe` on Windows** — a leftover locked copy in `%TEMP%` breaks the admin
  password prompt (see §3).
- **`npm run … -- --flag` in PowerShell** — `npm.ps1` drops the `--`, so npm eats a flag
  such as `--check` and `build:js` runs without it (it then WRITES). Call the script
  directly: `node scripts/build-js.mjs --check`. File arguments pass through unharmed.

---

> **Framework maintainers:** the multi-machine workflow (repository access, directory
> layout for path-repo consumption, NAS sync, project-copy/deploy mechanics) is kept in a
> local runbook outside this repository — it is machine- and account-specific, not part of
> the framework.
