# Release structure — zero-downtime deploys on shared hosting

**Status:** measured on cyon (2026-08-28), not just designed. Origin:
`z77-axo3.ch` — the first project running this layout; its widget renders on
THIRD-PARTY customer websites, so a broken minute during upload is visible on
someone else's site.

Set every new project up this way from the start. Retrofitting an existing
installation is the same structure plus one data move — the axo3 migration is
the worked example.

---

## Why

Uploading into the live directory serves visitors a mix of old and new code
for as long as the upload takes. The previous workaround — maintenance window,
copy to a second directory, re-point the domain — created two data stores, and
everything that happened during the rebuild (a registration, a login, an
import) was lost on the switch.

The fix is the classic release layout: **code is immutable per release, data
lives once in `shared/`, and "which release is live" is a single symlink.**

## The layout

```
<domain>/
  shared/                     everything that comes into being at RUNTIME — stays put
      data/  config/  logs/  lib/  backup/  media/  storage/
      (project-specific stores too, e.g. axo3's .propbase/)
  releases/
      2026-08-28/             pure code. One upload = one directory.
      2026-08-27/             the previous state — the way back
  current -> releases/2026-08-28     production points here
  next    -> releases/2026-08-28     the test subdomain points here
```

Inside a release, the data locations are nothing but signposts:

```
releases/<date>/data           -> ../../shared/data
releases/<date>/config         -> ../../shared/config
releases/<date>/logs           -> ../../shared/logs
releases/<date>/lib            -> ../../shared/lib
releases/<date>/backup         -> ../../shared/backup
releases/<date>/public/media   -> ../../../shared/media
releases/<date>/public/storage -> ../../../shared/storage
```

**The rule of thumb for `shared/`:** is it in git? Then it is code and lives
in the release. Does it come into being on the server? Then `shared/`.

Relative link targets (`../../shared/...`, `releases/<date>`), deliberately:
the whole tree survives an account move or a path rename without touching a
single link.

## How the `current` / `next` mechanism works

Two symlinks, two doors into the same pool of releases:

- **`current`** is what production serves. The domain's document root is
  `<domain>/current/public` — the web server resolves the link on every file
  access, so re-pointing `current` re-points the whole site.
- **`next`** is the same mechanism as a second door: a test subdomain
  (`next.<domain>`, document root `<domain>/next/public`) that is permanently
  wired to it. A deploy lands in a new release directory, `next` is bent onto
  it, and the candidate is tested **on the real server, against the real
  shared data** — same code, same stores, different door. Only when it holds
  is `current` bent onto the same release.

Why this is actually uninterrupted, and not just fast:

- **A request runs start-to-finish in ONE release.** PHP's `__DIR__` is the
  symlink-RESOLVED real path, so `public/index.php` computes `ABS_BASE_PATH`
  as `…/releases/2026-08-28` — not as `…/current`. A request that entered
  through the old release finishes on the old release's files even if the
  switch happens mid-request. Visitors never see half-new code; the switch
  only decides where NEW requests enter.
- **The data cache cannot go stale across the switch.** `DataCache` (APCu)
  namespaces its pool with a hash of `ABS_BASE_PATH` — and since that is the
  real release path, every release automatically has its own pool. The
  resolved `FileFinder` path map of release A is invisible to release B; no
  flush choreography needed.
- **The file-based page cache DOES span releases.** It lives under
  `shared/lib/cache/pages` and holds rendered markup — after bending
  `current`, clear it (backend «Cache leeren», or `rm -rf` under
  `shared/lib/cache`), or visitors get the old release's HTML from the new
  one. Everything under `lib/` is disposable by contract (ADR-034), so this
  is always safe.
- **Cron follows the switch on its own** — as long as the cron entry goes
  THROUGH `current` and never names a release directory. The link is resolved
  when the process starts: a job already running finishes on the old code
  (fine — same guarantee as a web request), the next tick runs the new one.
  Two call forms, depending on what the host allows:

  ```sh
  # a real crontab (shell available):
  * * * * * cd <domain>/current && php vendor/bin/z77-run

  # a panel that takes ONE command and no `cd` (cyon):
  php84 <domain>/current/cron/run.php
  ```

  `cron/run.php` is seeded by the installer: `z77-run` finds the project by
  walking UP from the working directory, and a panel cron starts in the home
  directory, where that walk finds nothing. The starter sits physically in
  the release, so `__DIR__` names the real project once PHP has resolved the
  call path — it `chdir()`s and hands over.

  ⚠️ **Never reach the project with `..` across the switch** — neither in the
  cron path nor as `--project=<domain>/current/..`. POSIX resolves the link
  component FIRST, so `current/..` names `releases/`, not the domain
  directory: reads miss, and recursive `mkdir` (the job lock) fails with a
  «File exists» that points nowhere near the cause. This is the same
  symlink-resolved-realpath mechanic as everywhere else in this document —
  here it works against the intuition instead of for it.
- **Rollback is the same move in the other direction:**
  `ln -sfn releases/<previous> current`. Nothing is copied, nothing restored;
  the previous release never stopped being complete. Keep at least one old
  release around for exactly this.

About `ln -sfn`: **both switches are mandatory.** Without `-n`, `ln`
dereferences the existing link and plants the new one INSIDE the old target
(`current/public/public`). Strictly speaking `-sfn` replaces the link in two
steps (unlink, then create) — a gap of microseconds. Where even that is too
much, use the truly atomic form:

```sh
ln -sfn releases/2026-08-28 current.tmp && mv -T current.tmp current
```

`mv -T` is a rename(2), which is atomic. For a shared host, plain `ln -sfn`
is in practice indistinguishable.

## Prerequisites

- **Framework state of 2026-08-28 or later.** The installer must generate
  `config/fileFinder.inc.php` anchored on `ABS_BASE_PATH` (not
  `dirname(__DIR__)`). This is the ONE generated file that used to compute
  paths from its own physical location — behind the `config` symlink that
  location is `shared/`, and the map pointed at a `shared/vendor/` that does
  not exist (HTTP 500, «Namespace 'Z77\Core\' has no registered
  sourcePaths» — the error that triggered this document). Run
  `composer install` once with the current framework before the first deploy
  into this layout; the regenerated file is location-independent, so ONE copy
  in `shared/config/` serves every release.
- **The web server follows symlinks.** Verified on cyon: Apache follows them
  in and below the document root, no 403 anywhere. Check once per new host.
- **The panel lets you set the document root per (sub)domain** to an
  arbitrary path (`<domain>/current/public`).

## Initial setup over SSH

```sh
# ── once per project ────────────────────────────────────────────────
BASE=~/public_html/example.ch          # the <domain> directory
REL=2026-08-28                         # today's release name

# 1. shared/ FIRST, with every target directory the links will point at.
#    ⚠️ Order matters: a symlink onto a missing target reads as is_dir()=false,
#    and code that then tries mkdir() fails with «File exists» — the name is
#    taken by the dangling link. (Bit axo3's CredentialStore, which creates
#    its store on demand.)
mkdir -p $BASE/shared/{data,config,logs,lib,backup,media,storage}
chmod 700 $BASE/shared/backup          # and every secret store, e.g. .propbase

# 2. Upload the release — pure code: vendor/, override/, public/, cron/,
#    composer.json + composer.lock. The last two are not read by the web
#    application, but z77-run and z77-backup recognise the project root by
#    vendor/autoload.php AND composer.json side by side — an upload without
#    them leaves both CLI tools unable to find home.
#    ⚠️ The upload must NOT contain the linked names (data/, config/, logs/,
#    lib/, backup/, public/media, public/storage) — a local directory of that
#    name would overwrite the signpost with a real folder.
mkdir -p $BASE/releases/$REL
# … rsync/unzip the build into $BASE/releases/$REL …

# 3. Signposts inside the release.
cd $BASE/releases/$REL
ln -s ../../shared/data    data
ln -s ../../shared/config  config
ln -s ../../shared/logs    logs
ln -s ../../shared/lib     lib
ln -s ../../shared/backup  backup
ln -s ../../../shared/media   public/media
ln -s ../../../shared/storage public/storage

# 4. Seed shared/ on the very first install: config from the build,
#    data from the installer run (or the old installation, when migrating).
#    From the second release on, this step does not exist — shared/ IS the state.

# 5. The two doors.
cd $BASE
ln -sfn releases/$REL current
ln -sfn releases/$REL next
```

Then, in the hoster's panel:

- document root of the production domain → `<domain>/current/public`
- a test subdomain (e.g. `next.example.ch`) → `<domain>/next/public`, and give
  it `noindex` (it serves the same pages as production)
- cron → through `current`, never a release path: a real crontab uses
  `cd <domain>/current && php vendor/bin/z77-run`, a panel without `cd` uses
  `php <domain>/current/cron/run.php` (see the mechanism section — and never
  a `..` across the switch)

**Build every switch the same way.** Whether document roots point at
`current/public` or `current` points at a `public` — pick ONE convention for
all domains and doors, or the person switching under pressure grabs the wrong
one.

## Deploying a release

```sh
BASE=~/public_html/example.ch
REL=2026-09-04

# 1. upload the new release (same exclusions as above)
mkdir -p $BASE/releases/$REL
# … upload …

# 2. signposts (same block as in the initial setup, step 3)

# 3. bend next, test on the subdomain — real server, real data
ln -sfn releases/$REL $BASE/next

# 4. bend current, clear the page cache
ln -sfn releases/$REL $BASE/current
rm -rf $BASE/shared/lib/cache/*        # or backend «Cache leeren»

# 5. prune old releases — keep at least the previous one (the rollback)
ls -dt $BASE/releases/*/ | tail -n +3 | xargs rm -rf

# Rollback, should step 4 turn out wrong:
ln -sfn releases/2026-08-28 $BASE/current
```

One deliberate wrinkle: **generated config is runtime state here.** `config/`
lives in `shared/` and is NOT part of the upload, so a `fileFinder.inc.php`
regenerated on the dev machine (new module, new override path) reaches the
server only as an explicit deploy step — copy the changed file into
`shared/config/` and write it into the deploy protocol, like any other data
handgrip.

## What must NOT be done

- **No PHP code in `shared/`.** `__DIR__` is only dangerous when the file sits
  behind a symlink — which is exactly what everything in `shared/` does. Code
  that interprets its own location belongs in the release; `shared/` holds
  state. (The generated `fileFinder.inc.php` is the audited exception: since
  2026-08-28 it anchors on `ABS_BASE_PATH` and computes nothing from itself.)
- **No backup type «Gesamtprojekt» on a framework OLDER than 2026-08-28.**
  Before BACKUP-SYMLINK-001 the archive walk treated a linked directory as a
  silent leaf: the full backup of a release contained the code and NOTHING
  behind `data/`, `config/`, `public/media` — no error, no hint. Since the
  fix the walk is path-based with a realpath visited set and all three types
  work in this layout (see `topics/backup.md`). On an older framework,
  schedule «Daten» + «Datenbank» only.
- **No upload over the signposts.** The deploy artifact must exclude every
  linked name; one stray local `data/` folder in the upload replaces the link
  and forks the state.
- **No crontab or document root on a `releases/<date>` path.** Anything wired
  past `current`/`next` keeps running the old code after a switch, silently.

## Verified on cyon (2026-08-28)

Structure as above, test subdomain on the release:

```
front page                  200   X-Z77-PageCache: MISS → HIT
/media/…/iso-1-04.webp      200   image/webp — through the symlink
/assets/frontend/….css      200
/backend/system/login       200
/anmeldung                  200
widget fragment (CORS)      200
page cache lands in         shared/lib/cache/pages/…
throttle counters in        shared/lib/throttle/…
```

## See also

- [`installer.md`](installer.md) — the generated `config/fileFinder.inc.php`
  and why it anchors on `ABS_BASE_PATH`
- [`../topics/backup.md`](../topics/backup.md) — how the archive walk follows
  the layout's links (BACKUP-SYMLINK-001), and why all three types work here
- [`../topics/persistence-file.md`](../topics/persistence-file.md) — what
  lives under `data/` and why it must exist exactly once
- ADR-034 — why everything under `lib/` may be deleted at any time (what
  makes the cache-clear step always safe)
