# New project — manual installation, from the empty folder to `next`

**Status:** `[CURRENT]` — written 2026-10-22 from the z77.ch-initial setup (2026-10-20) and the
first-deploy preparation on cyon.
**For:** the owner, doing every step by hand. Six stages, each ends with a check.

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
| `<project>` | project folder | `Z:\z77\z77-1.0.0-z77.ch-initial` |
| `<domain>` | production host | `z77.ch` |
| `<root>` | server directory of the project | `/home/<account>/public_html/example.ch` |
| `<ssh>` | SSH alias in `~/.ssh/config` | `host-example` |
| `<db>` | database name and user | local `example_local`, server `<account>_example` |
| `<rel>` | release name = deploy date | `2026-10-23` |
| `<port>` | local dev server port | `8079` |

---

## 0. Prerequisites (once per machine)

- PHP ≥ 8.4 CLI with `mbstring fileinfo gd apcu` (`apc.enable_cli=1`), Composer 2, Git —
  [dev-environment.md](dev-environment.md).
- Local MariaDB on `127.0.0.1`.
- The framework monorepo at `Z:\z77\z77-ch-framework-1.0.0`. The project lives NEXT to it —
  the path repositories in `composer.json` are relative (`../z77-ch-framework-1.0.0/…`).
- An SSH alias for the host in `~/.ssh/config` (key login, no password in any file).

---

## 1. Create the project

1. Create the folder `<project>` next to the framework.
2. Copy `skeleton/composer.json` from the framework and adapt it:
   - `name`, `description`, `authors`; `"php": ">=8.4"`.
   - `require`: the modules the project uses (`z77/kernel`, `z77/module-frontend`,
     `z77/module-backend`, `z77/module-dms`, plus e.g. `module-contact`, `module-debtor`,
     `module-financial`, `module-vat`, `module-article`, `module-order`); `require-dev`:
     `z77/docs`.
   - `repositories`: one path repository per package, symlinked:
     ```json
     {"type": "path", "url": "../z77-ch-framework-1.0.0/packages/kernel", "options": {"symlink": true}}
     ```
     `module-mandator` belongs in the list as soon as `module-financial`, `-debtor`, `-article`
     or `-order` is required — they depend on it.
   - `autoload.psr-4`: one override entry per namespace and module
     (`"Z77\\Module\\Contact\\": ["override/z77/module/contact/src/"]`) — the installer creates
     the folders from it.
   - `scripts.dev`: `php -S localhost:<port> -t public` — one port per project, so two
     projects can run side by side.
   Worked example: `composer.json` of z77.ch-initial.
3. `.gitignore`: copy it from an existing project (z77.ch-initial). It must ignore `/vendor/`,
   `/config/`, `/data/`, `/backup/`, `/var/`, `/logs/`, `/public/assets/`, `/public/media/`,
   `/public/storage/`, `/.vscode/`, `/.claude/`, `/docs/temp/`, `/composer.lock`.
   `config/` and `data/` hold credentials — never commit them.
4. `.releases/`: copy the framework's `.releases/` verbatim (every file but `target.json`), then
   follow `.releases/HANDOFF.md` steps 2–4:
   - `target.json` from `target.example.json`: `host` = `<ssh>`, `root` = `<root>`,
     `hosts` = `next.<domain>` / `<domain>`, `link_target` = `public` (cyon).
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
   Keep the password in `%USERPROFILE%\.z77\<db>-db.txt` — not in the repo.
2. **Install the framework** — in `<project>`:
   ```
   composer install
   ```
   The installer links the packages into `vendor/z77/`, writes `config/vendor/` (generated),
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
   The command names the project root and the database first — read them.

**Check:** `php vendor/bin/z77-db status` shows `New 0`.

---

## 3. Test locally

1. `composer dev` → `http://localhost:<port>` (or `php -S localhost:<port> -t public`).
2. Without an admin from stage 2: open `/backend/system/setup/setup`, enter the token from
   `data/framework/auth/SETUP_TOKEN`, create the admin. The token is deleted afterwards.
3. Check in the browser:
   - `/` and the starter pages (`/about`, `/services`, `/contact`, `/legal`, `/privacy`) → 200,
     `/en/…` as well; the contact form leads to `/thanks`.
   - `/login` → backend login with the admin.
   - one backend screen per Doctrine module (e.g. Kontakte) opens without an error.
4. Commit what changed in the repo (overrides, docs).

**Check:** pages and backend work; `logs/` holds no new error.

---

## 4. Deploy to the server

### 4a. Hosting panel (once per project)

- Subdomain `next.<domain>` with document root `<root>/next` (`link_target: public` — the door
  link points at `releases/<rel>/public`).
- PHP ≥ 8.4 for the subdomain (cyon: MultiPHP).
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
- No deny `.htaccess` in `<root>` when an old site lives under the same parent (z77.ch:
  `apps/wdv-622`) — it would 403 the old site. `deploy.php` warns; accept the warning.

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
php .releases/vendor-deploy.php          # vendor/ links → real copies, build stamp
php .releases/deploy.php <rel>           # tar over ssh into releases/<rel>, signposts into shared/
```

- `check.php` before `vendor-deploy.php` reports «symlinked vendor», «no build.json» and the dev
  dependencies — normal; `vendor-deploy.php` resolves them.
- `deploy.php` refuses an existing or running release; a second release on the same day is
  `<date>-2`.
- If `deploy.php` reports a differing generated config (`fileFinder`, `moduleManager`,
  `bootstrap`), copy it up with the printed `scp` line BEFORE stage 6.

**Check:** `ssh <ssh> ls -la <root>/releases/<rel>` shows `vendor public override cron
composer.json` and the links `data config logs backup` into `shared/`.

---

## 5. Open the server database

1. **Create database and user** in the panel (cyon: MySQL databases, or
   `uapi Mysql create_database` / `create_user` / `set_privileges_on_database`), collation
   `utf8mb4_unicode_ci`. The account prefix is part of both names (`<account>_…`). Keep the
   password in `%USERPROFILE%\.z77\<domain>-db.txt`.
2. **Connect** — `shared/config/client/database.inc.php` on the server: `host` = `localhost`
   (on Linux the socket — keep it), `name`, `user`, `password`; then `chmod 600` on the file.
3. **Migrate** — in the RELEASE directory, never through `current`/`next`:
   ```sh
   cd <root>/releases/<rel>
   /opt/alt/php85/usr/bin/php vendor/bin/z77-db status     # read project root + database
   /opt/alt/php85/usr/bin/php vendor/bin/z77-db migrate
   ```
   cyon's CLI default is PHP 8.3 — call the 8.4/8.5 binary explicitly. A root other than
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
- **Cron** (only through `current`, never a release path): cyon panel
  `php84 <root>/current/cron/run.php` — [release-structure.md](release-structure.md).
- **Every further release:** `.releases/CHECKLIST.md`, steps 1–6.

## See also

- [release-structure.md](release-structure.md) — the server layout and why it is built this way
- [installer.md](installer.md) — what `composer install` writes
- [dev-environment.md](dev-environment.md) — tools and the admin-password prompt
- `.releases/HANDOFF.md`, `.releases/RULES.md`, `.releases/CHECKLIST.md` — the deploy tooling
- [`../topics/persistence-doctrine.md`](../topics/persistence-doctrine.md) — `database.inc.php`,
  `z77-db`, DOCTRINE-HOST-001
