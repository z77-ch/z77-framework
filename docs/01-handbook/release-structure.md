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
  current -> releases/2026-08-28/public     production points here
  next    -> releases/2026-08-28/public     the test subdomain points here
```

Whether the doors point at `releases/<date>/public` (document root =
`<domain>/current`) or at `releases/<date>` (document root =
`<domain>/current/public`) is ONE convention per project, recorded in
`.releases/target.json` as `link_target` — `public` above, cyon's layout.
Never mixed: the door is bent with `php .releases/switch.php <date> <door>`,
which builds the link from that key, resets OPcache, and probes the door
from outside. The hand-typed `ln` that stops one level short turns the
release root — vendor/, composer.json, every signpost below — into the
document root; `<project>/.htaccess` (`Require all denied`) and the same file
in every `shared/` store are the alarm for that day.

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
- **…but OPcache freezes that resolution.** `__DIR__` is a COMPILE-time
  constant, and OPcache binds the UNRESOLVED path Apache hands over
  (`<domain>/next/index.php`) to the one compiled copy it made on the first
  request through that door. With `opcache.revalidate_path=0` (the default)
  a hit on that binding never calls `realpath()` again — the binding has NO
  TTL, and the timestamp check stats the file the copy was compiled FROM
  (the OLD release's `index.php`, unchanged), not through the link. Bending
  the door changes nothing OPcache looks at: every request through it runs
  `ABS_BASE_PATH` in the old release — while static files (served by
  Apache, which follows the link on every access) already come from the new
  one. Measured on cyon 2026-08-29 (`X-Z77-PageCache: MISS`, fresh render,
  old content; `md5_file()` of the controller identical to the new release);
  mechanism proven 2026-08-31: the door bent to another release for minutes,
  the worker's realpath cache long expired and resolving correctly, OPcache
  still serving the bound copy — zero hits on the linked release. Only the
  entry point is affected: everything it includes goes through the real path
  and is keyed per release.

  **What ends it.** A `touch` on the new release's `index.php` does NOT
  (reasoned 2026-08-29, disproved 2026-08-30: the door on release N served
  N-1 for an hour, hits climbing — the new file is never statted).
  `opcache_reset()` does — and since 2026-08-31 `index.php` no longer bakes
  the release in at all: it is a trampoline that resolves `ABS_BASE_PATH` at
  runtime from `realpath($_SERVER['DOCUMENT_ROOT'])`, so even a stale bound
  copy boots the CURRENT release within the realpath cache TTL (≤120 s).
  The trampoline is a contract: `index.php` stays minimal and unchanging,
  because the cached copy may outlive the release it came from.
  `.releases/switch.php` still resets — for the immediate, proven switch: a
  one-shot file dropped into the release's `public/`, called once over
  HTTPS, self-deleting; then it reads `X-Z77-Release` off `/` (every
  HtmlResponse carries it since 2026-08-30 — basename of `ABS_BASE_PATH`,
  i.e. the release name) and retries, because a worker whose realpath cache
  still resolves the door to N-1 can re-bind the whole pool to N-1 right
  after the reset. Why not a reset button in the backend: the button would
  run in the OLD release (the door is still stuck there). On shared hosting
  the account has ONE OPcache for ALL its sites (measured 2026-08-31), so
  the reset recompiles them all once — harmless. The realpath cache: keep
  `realpath_cache_ttl` at 120 s, do not set it to 0 for the account (every
  stat gets dearer, all day, for two minutes per deploy).
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

## The rules, per project: `.releases/`

The layout above is binding for every project. Each project carries a
`.releases/` directory (master copy in the framework root — copy it, fill
`target.json`, commit): `RULES.md` states the rules (outer boundary =
`target.root`, four root entries, upload only into `releases/<name>`, shared
names never uploaded, `uploadOnSave: false`), `check.php` verifies
`target.json` against the hand-maintained `.vscode/sftp.json` and exits
non-zero on any violation. Run `php .releases/check.php` before every deploy.
`HANDOFF.md` is the step list for setting a project up. Deviations only with
the explicit approval of the framework owner, recorded in the project's
`RULES.md`.

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
- **An OPcache reset after every switch** — regardless of
  `opcache.validate_timestamps`. `switch.php` does it with a one-shot file
  in the release's `public/`, called once over HTTP, self-deleting; a reset
  endpoint left behind is a cache flush for anyone, so the script stops if
  the file is still there. On shared hosting the account has ONE OPcache for
  ALL its sites, so the reset hits production and every other site too —
  harmless, one recompile each, but not an isolated test.
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
all domains and doors, record it as `link_target` in `.releases/target.json`,
and let `switch.php` build the link. The person switching under pressure
grabs the wrong one; measured 2026-08-30 on axo3.ch, harmless direction
(404). The other direction serves `shared/` as URLs.

## Deploying a release

```sh
BASE=~/public_html/example.ch
REL=2026-09-04

# 1 + 2. upload the new release and set its signposts — one command from
#    the developer machine. It packs release_dirs + composer.* + .htaccess
#    with every shared name excluded, streams the tar over ssh into
#    releases/$REL (refusing an existing or running release), links every
#    target.shared entry, and says whether shared/config/fileFinder.inc.php
#    differs from the local one.
php .releases/deploy.php $REL
#    (by hand: mkdir -p $BASE/releases/$REL, upload, then the signpost block
#     from the initial setup, step 3)

# 3. bend next, test on the subdomain — real server, real data.
#    From the developer machine, over ssh: link target per
#    target.link_target, the deny files in shared/, the OPcache reset
#    (without it the OLD release's index.php keeps running behind the new
#    link — a touch does not help, see the mechanism section), the proof
#    via X-Z77-Release, then the probes from outside.
php .releases/switch.php $REL next
#    (by hand: ln -sfn releases/$REL/public $BASE/next  [link_target=public],
#     then a one-shot <?php opcache_reset(); unlink(__FILE__); in public/,
#     called once, then curl -sI https://next.../ | grep X-Z77-Release)

# 4. bend current, clear the page cache — same script, same reason
php .releases/switch.php $REL current
rm -rf $BASE/shared/lib/cache/*        # or backend «Cache leeren»

# 5. prune old releases — keep at least the previous one (the rollback)
ls -dt $BASE/releases/*/ | tail -n +3 | xargs rm -rf

# Rollback, should step 4 turn out wrong — the same script, the old name.
# It resets OPcache again: the binding sticks to whatever it last compiled,
# in both directions.
php .releases/switch.php 2026-08-28 current
```

**Verify with PHP, not with a static file.** After each switch, request a
page that is demonstrably different in the new release — a changed template,
a new route. A static file (image, CSS, a marker file) only proves that
Apache follows the link, which it always does; the stuck entry point shows
exclusively in rendered output. A `MISS` in `X-Z77-PageCache` with old
content is the signature. Why the first migration did not show this: moving
from `apps/z77-1.0.0` to this layout changed the document root in the
panel — new path, new OPcache keys, fresh compilation. The problem appears
on the SECOND release, exactly when the structure is supposed to prove its
worth.

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
- **No door without `/public` when `link_target` is `public`.** The release
  root is then the document root, and it holds the signposts into `shared/`:
  `/.propbase/tenant-3.json`, `/data/…`, `/backup/…` answer as plain files.
  `<project>/.htaccess` and the deny file in every store turn that into a
  403 — if `AllowOverride` is on. `switch.php` builds the link right and
  probes; the probe is the only one of the three that measures.
- **No deny file in a store served through `public/`.** `public/media` is a
  link to `shared/media`, the link IS the directory, so
  `shared/media/.htaccess` is `public/media/.htaccess` — every image 403,
  on every door at once. Measured 2026-08-30 on axo3.ch, eleven minutes of
  broken images in production. `deploy.php`/`switch.php` write the deny
  file only into stores that are signposts in the release root.

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
