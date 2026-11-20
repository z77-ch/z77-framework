# New project — manual installation, from the empty folder to `next`

**Status:** `[CURRENT]` — written 2026-10-22 from the setup of the z77.ch site (2026-10-20) and the
first-deploy preparation on a shared host; reviewed 2026-11-15; stage 1 from Packagist since
release 2.0.0 (2026-11-20).
**For:** whoever sets up a project by hand — typically one with the business suite or the
optional modules (member, api, mailing, kibot), every package from Packagist. A web-stack-only
site can start with `composer create-project z77/skeleton` instead (root README → Quickstart)
and join at stage 2. Working on the framework itself (path repositories onto a monorepo
checkout): [dev-environment.md §7](dev-environment.md#7-working-on-the-framework-itself-path-repositories).
Six stages, each ends with a check.

| Stage | Result |
|---|---|
| 1. Create | project folder with `composer.json`, `.gitignore`, `.releases/`, git |
| 2. Install | framework in `vendor/`, local database migrated, admin account |
| 3. Test locally | the site answers on `localhost`, backend login works |
| 4. Deploy | the server prepared, the first release uploaded into `releases/<date>` |
| 5. Database | the server database created, connected, migrated |
| 6. Bend `next` | `next.<domain>` serves the release, tested in the browser |

Going live (`current`) is not part of this document — `.releases/CHECKLIST.md` step 5.

Placeholders used below (z77.ch values as the example):

| Placeholder | Meaning | Example |
|---|---|---|
| `<project>` | project folder | `~/work/z77/z77-1.0.0-z77.ch` |
| `<domain>` | production host | `z77.ch` |
| `<root>` | server directory of the project | `/home/<account>/public_html/example.ch` |
| `<ssh>` | SSH alias in `~/.ssh/config` | `host-example` |
| `<db>` | database name and user | local `example_local`, server `<account>_example` |
| `<rel>` | release name = deploy date | `2026-10-23` |
| `<port>` | local dev server port | `8079` |

---

## 0. Prerequisites (once per machine)

- PHP ≥ 8.4 CLI with the extensions and `php.ini` values of
  [dev-environment.md → PHP settings](dev-environment.md#2-php-settings-phpini) (upload
  limits, `memory_limit`, APCu, `disable_functions`), Composer 2, Git —
  [dev-environment.md](dev-environment.md).
- Local MariaDB on `127.0.0.1`.
- An SSH alias for the host in `~/.ssh/config` (key login, no password in any file).

---

## 1. Create the project

1. Create the folder `<project>`.
2. Copy the `composer.json` of the published skeleton
   ([z77-ch/z77-skeleton](https://github.com/z77-ch/z77-skeleton/blob/main/composer.json)) and
   adapt it:
   - `name`, `description`, `authors`; `"php": ">=8.4"` (the business modules need 8.4).
   - `require`, every z77 package with `^2.0`: the web stack (`z77/kernel`,
     `z77/module-frontend`, `z77/module-backend`, `z77/module-dms`) plus the modules the
     project uses (e.g. `z77/module-order`, `z77/module-receipt`); `require-dev`: `z77/docs`.
     Composer pulls in what they require (`persistence-doctrine`, `module-vat`, …) from
     Packagist — no `repositories` block.
   - `autoload.psr-4`: one override entry per namespace and module — for every module in
     `require` AND every module it requires
     (`"Z77\\Module\\Contact\\": ["override/z77/module/contact/src/"]`) — the installer creates
     the folders from it and registers only the modules listed there
     ([business-suite-setup.md §2](business-suite-setup.md#2-switch-the-modules-on)).
   - `scripts.dev`: `php -S 127.0.0.1:<port> -t public` — one port per project, so two
     projects can run side by side.
   Each business module's README names its override entry («Enabling it in a project»).
3. `.gitignore`: it must ignore `/vendor/`,
   `/config/`, `/data/`, `/backup/`, `/var/`, `/logs/`, `/public/assets/`, `/public/media/`,
   `/public/storage/`, `/.vscode/`, `/.claude/`, `/docs/temp/`, `/composer.lock`.
   `config/` and `data/` hold credentials — never commit them.
4. `.releases/`: copy the framework's `.releases/` verbatim (every file but `target.json`), then
   follow `.releases/HANDOFF.md` steps 2–4:
   - `target.json` from `target.example.json`: `host` = `<ssh>`, `root` = `<root>`,
     `hosts` = `next.<domain>` / `<domain>`, `link_target` = `public` (typical for a shared host).
   - `link_target: public` → copy `htaccess-deny` to `<project>/.htaccess`.
   - `.vscode/sftp.json` from `sftp.example.json`, `remotePath` = `<root>/releases/<rel>`.
5. `git init`, first commit (`composer.json`, `.gitignore`, `.releases/`, `.htaccess`).

**Check:** `git status` is clean; `config/`, `data/`, `vendor/` do not exist yet.

---

## 2. Install

1. **Local database** — in the MariaDB client as root:
   ```sql
   CREATE DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER '<db>'@'127.0.0.1' IDENTIFIED BY '<password>';
   GRANT ALL PRIVILEGES ON <db>.* TO '<db>'@'127.0.0.1';
   ```
   Keep the password in a password manager or a local folder outside the project — not in the repo.
2. **Install the framework** — in `<project>`:
   ```
   composer install
   ```
   Composer downloads the packages into `vendor/`; the installer writes `config/vendor/` (generated),
   seeds `config/client/` and `data/` (seed-once), and publishes `public/`.
   It asks for the admin password. Without a terminal prompt (`--no-interaction`, or the
   `hiddeninput.exe` error on Windows) it writes a one-time `SETUP_TOKEN` under
   `data/framework/auth/` instead — the admin is then created in stage 3.
3. **Connect the database** — `config/client/database.inc.php` (seed-once, gitignored):
   `host` = `127.0.0.1` (on Windows, NOT `localhost` — 2 s per request, DOCTRINE-HOST-001),
   `port`, `name`, `user`, `password`.
4. **System config** — `config/client/systemConfig.inc.php`: `installationName` (shown in the
   logo when the frontend has no `site.name`), `canonicalBaseUrl` = `http://localhost:<port>`.
5. **Create the schema:**
   ```
   php vendor/bin/z77-db status
   php vendor/bin/z77-db migrate
   ```
   `migrate` names the project root and the database first — read them. It asks for a
   «yes» at a terminal; in scripts and for AI assistants use `migrate --no-interaction`
   (without a terminal it hangs).

**Check:** `php vendor/bin/z77-db status` shows `New 0`.

---

## 3. Test locally

1. `composer dev` → `http://localhost:<port>` (or `php -S 127.0.0.1:<port> -t public`).
2. Without an admin from stage 2: open `/backend/system/setup/setup`, enter the token from
   `data/framework/auth/SETUP_TOKEN`, create the admin. The token is deleted afterwards. The
   login page links there while it is pending — [installer.md → First Login](installer.md#first-login).
3. Check in the browser:
   - `/` and the starter pages (`/about`, `/services`, `/contact`, `/legal`, `/privacy`) → 200,
     `/en/…` as well; the contact form leads to `/thanks`.
   - `/login` → backend login with the admin. After it the dashboard block «Ersteinrichtung» lists
     the next open setup steps as links — [business-suite-setup.md §4](business-suite-setup.md#4-first-steps-in-the-backend)
     and [backend.md](../topics/backend.md).
   - one backend screen per Doctrine module (e.g. Kontakte) opens without an error.
4. Commit what changed in the repo (overrides, docs).

**Check:** pages and backend work; `logs/` holds no new error.

---

## 4. Deploy to the server

### 4a. Hosting panel (once per project)

- Subdomain `next.<domain>` with document root `<root>/next` (`link_target: public` — the door
  link points at `releases/<rel>/public`).
- PHP ≥ 8.4 for the subdomain (shared hosts: the panel's PHP version selector).
- SSL certificate for `next.<domain>` (and `<domain>` for later).
- The panel creates `next/` as a real directory (with `cgi-bin`) — remove it in 4b;
  `switch.php` places the link.

### 4b. Server directories (once, over SSH)

```sh
ssh <ssh>
cd <root>
rm -rf next current                      # only panel-made empty dirs — look first (ls -la)
mkdir -p releases shared/{data,config,logs,backup,media,storage} shared/var/lib
chmod 700 shared/backup
```

- `shared/` FIRST: a symlink onto a missing target fails later with «File exists».
- No deny `.htaccess` in `<root>` when an old site lives under the same parent (e.g. a previous site in a sibling folder) — it would 403 the old site. `deploy.php` warns; accept the warning.

### 4c. Seed `shared/` (first deploy only)

From the second release on, `shared/` IS the state — never seed again.

- `shared/config/client/` ← the local `config/client/` only (`target.shared` names
  `config/client`, `release_dirs` names `config` — `config/vendor/` rides with every release,
  ADR-036). Leave `database.inc.php` for stage 5.
- `shared/data/` ← the local `data/` (admin account, navigation, seeds, content).
- In `shared/config/client/systemConfig.inc.php`: `canonicalBaseUrl` = `https://next.<domain>`
  (switched to `https://<domain>` at go-live).

Copy with `scp -r` or the SFTP extension — into `shared/`, never into a release.

### 4d. Upload the release (from `<project>`)

```
php .releases/check.php                  # read every line
php .releases/vendor-deploy.php          # production vendor/ (no dev dependencies), build stamp
php .releases/deploy.php <rel>           # tar over ssh into releases/<rel>, signposts into shared/
```

- `check.php` before `vendor-deploy.php` reports «no build.json» and the dev dependencies —
  normal; `vendor-deploy.php` resolves them. It stamps the build only from path repositories
  (framework development, [dev-environment.md §7](dev-environment.md#7-working-on-the-framework-itself-path-repositories)):
  on a project that takes the framework from Packagist it ends with «build.json missing» and the
  backend footer shows no version — open point of the 2.0.0 release
  ([`release-2.0.0-bauplan.md`](../03-development/release-2.0.0-bauplan.md) step 5.5).
- `deploy.php` refuses an existing or running release; a second release on the same day is
  `<date>-2`.
- If `deploy.php` reports a differing generated config (`fileFinder`, `moduleManager`,
  `bootstrap`), copy it up with the printed `scp` line BEFORE stage 6.

**Check:** `ssh <ssh> ls -la <root>/releases/<rel>` shows `vendor public override cron
composer.json` and the links `data config logs backup` into `shared/`.

---

## 5. Open the server database

1. **Create database and user** in the panel (the panel's database section, or the host's CLI/API for databases), collation
   `utf8mb4_unicode_ci`. The account prefix is part of both names (`<account>_…`). Keep the
   password in a password manager or a local folder outside the project.
2. **Connect** — `shared/config/client/database.inc.php` on the server: `host` = `localhost`
   (on Linux the socket — keep it), `name`, `user`, `password`; then `chmod 600` on the file.
3. **Migrate** — in the RELEASE directory, never through `current`/`next`:
   ```sh
   cd <root>/releases/<rel>
   /usr/bin/php8.4 vendor/bin/z77-db status     # read project root + database
   /usr/bin/php8.4 vendor/bin/z77-db migrate
   ```
   The host's CLI default may be older than 8.4 — call the 8.4+ binary of your host explicitly (e.g. `/usr/bin/php8.4`; `command -v php8.4`). A root other than
   `releases/<rel>` is the wrong directory: stop.
   With `opcache.validate_timestamps = 0` on the host: «Cache leeren» in the backend afterwards.

Order note: `.releases/CHECKLIST.md` migrates AFTER bending `next` (step 3b). On the first
deploy no release is running yet, so migrating before stage 6 is equivalent — and `next` shows
a working site from its first request. From the second release on, follow the CHECKLIST order.

**Check:** `z77-db status` on the server shows `New 0`.

---

## 6. Bend `next` onto the release

```
php .releases/switch.php <rel> next
```

- The script builds the link per `link_target`, waits until `X-Z77-Release` names `<rel>` (up to
  ~2 min — OPcache trampoline, no reset), then probes from outside (`/composer.json`,
  `/vendor/z77/build.json`, the shared stores → 403/404). On STOP or FAIL: stop, it prints the
  fix or the rollback line. Never bend by hand with `ln`.
- After a proven switch the script appends a line to `.releases/deploy-log.md`: time, release,
  door, project commit and framework commit (from the release's `vendor/z77/build.json` on the
  server), «dirty» where unsaved changes went out. Commit the file — `git log <commit>..HEAD`
  later shows what came since that deploy.
- Test on `https://next.<domain>` in the browser: the pages, the backend login (the admin from
  `shared/data`), one screen per Doctrine module. A static file proves nothing — check rendered
  pages.
- `next` serves the same pages as production later: give it `noindex` — the backend's noindex
  switch, logged in on `next` (writes `releases/<rel>/var/state/noindex.flag`, per door and per
  release — ADR-035, so set it again after every new release on `next`).
- Back to development:
  ```
  php .releases/vendor-dev.php           # vendor/ copies → links again
  ```
  Forgetting it fails silently: framework edits no longer reach the project.

**Check:** `curl -sI https://next.<domain>/ | grep X-Z77-Release` names `<rel>`; the site and
the backend work on `next`.

---

## After this

- **Go live:** `.releases/CHECKLIST.md` step 5 (`switch.php <rel> current`), `canonicalBaseUrl`
  to `https://<domain>`, the production domain's document root to `<root>/current`.
- **Cron** (only through `current`, never a release path): hosting panel
  `<php-8.4-binary> <root>/current/cron/run.php` — [release-structure.md](release-structure.md).
- **Every further release:** `.releases/CHECKLIST.md`, steps 1–6.

## See also

- [release-structure.md](release-structure.md) — the server layout and why it is built this way
- [installer.md](installer.md) — what `composer install` writes
- [dev-environment.md](dev-environment.md) — tools and the admin-password prompt
- `.releases/HANDOFF.md`, `.releases/RULES.md`, `.releases/CHECKLIST.md` — the deploy tooling
- [`../topics/persistence-doctrine.md`](../topics/persistence-doctrine.md) — `database.inc.php`,
  `z77-db`, DOCTRINE-HOST-001
