# Release structure — zero-downtime deploys on shared hosting

**Status:** measured in production, not just designed — first on one shared
host (cyon, LiteSpeed, 2026-08-28), see [the cyon example](#example-cyon-shared-hosting-litespeed).
Origin: `z77-client-a` — the first project running this layout; its widget
renders on THIRD-PARTY customer websites, so a broken minute during upload is
visible on someone else's site.

Set every new project up this way from the start. Retrofitting an existing
installation is the same structure plus one data move — the client-a migration is
the worked example.

**How to read this page.** Everything up to «What must NOT be done» is
**general**: it holds on any host that meets the [requirements](#requirements).
What differs from host to host — how you get in, how PHP runs, where the
document root is set, how cron is called — is collected in
[«Before you use this on another host»](#before-you-use-this-on-another-host);
one host fully worked through is the [cyon example](#example-cyon-shared-hosting-litespeed).
The workstation side (Windows vs Linux/macOS) is in
[«The workstation»](#the-workstation).

---

## Why

Uploading into the live directory serves visitors a mix of old and new code
for as long as the upload takes. The previous workaround — maintenance window,
copy to a second directory, re-point the domain — created two data stores, and
everything that happened during the rebuild (a registration, a login, an
import) was lost on the switch.

The fix is the classic release layout: **code is immutable per release, data
lives once in `shared/`, and "which release is live" is a single symlink.**

## What is general, what depends on the host

| general — the same on every host | host-specific — answer once per host |
|---|---|
| the layout: `releases/<name>`, `current`, `next`, `shared/` | how you get in: SSH with a shell, or SFTP only |
| relative link targets; the link rules (`link_target`, never mixed) | whether the filesystem allows symlinks and the web server follows them |
| what lives in `shared/` and what stays release-local (`var/cache`, `var/state`) | where and how the document root of a (sub)domain is set |
| the order: `shared/` targets first, then the links | whether `.htaccess` is read (the deny files depend on it) |
| the switch semantics: a request runs in ONE release; `switch.php` proves the switch via `X-Z77-Release` | how PHP runs: handler (PHP-FPM, LiteSpeed LSAPI, …), OPcache scope and settings, `realpath_cache_ttl`, `open_basedir` |
| schema migrations only from `releases/<name>`, never from `current` | the PHP CLI binary and its version (cron, `z77-db`) |
| `php .releases/check.php` before every deploy | cron: a real crontab or a panel field |
| rollback = bend the door back | file owner of SSH uploads vs the user PHP runs as |
| never touch anything above `target.root` | `exec`/`proc_open` availability (`disable_functions`) |

## Requirements

Stated as requirements, each with what breaks when it is missing. Verify them
with [«Before you use this on another host»](#before-you-use-this-on-another-host).

**On the server**

- **Symlinks can be created, and the web server follows them** — in and below
  the document root, onto directories outside it (`shared/`). *Missing:* 403 or
  404 on every page (the door itself is a link), or broken images
  (`public/media` is a link). Without symlinks at all the layout does not exist.
- **The document root of every (sub)domain can be set to an arbitrary path**
  inside the account: the door (`<root>/current` or `<root>/current/public`,
  per `link_target`). *Missing:* the switch cannot be a link — back to
  uploading into the live directory.
- **`$_SERVER['DOCUMENT_ROOT']` names that door path** (or its resolved path).
  The trampoline `index.php` resolves the release from it on every request.
  *Missing:* the site boots from the wrong release or not at all; `switch.php`
  never sees `X-Z77-Release` name the new release.
- **`shared/` and the release-local `var/` are writable by the user PHP runs
  as.** `shared/` is created over SSH, `releases/<name>/var/{cache,state}` by
  `deploy.php` — both as the SSH user. *Missing:* a form reports success and
  stores nothing, logs stay empty, the page cache never fills, the throttle
  forgets.
- **`open_basedir`, if set, covers the whole `<root>`** (releases AND
  `shared/`). PHP checks the resolved path. *Missing:* «open_basedir
  restriction in effect» on the first include from `vendor/` or the first
  read from `data/`.
- **`.htaccess` is read** (Apache, or an Apache-compatible server such as
  LiteSpeed, with `AllowOverride` covering `Require` and the rewrite rules).
  *Missing:* `public/.htaccess` does not route, and the deny files in the
  project root and in every `shared/` store protect nothing — `switch.php`'s
  outside probes then FAIL. On a server without `.htaccess` (nginx) the same
  rules must live in the server configuration.
- **Both doors answer over HTTPS with a valid certificate**, including the
  test subdomain. `switch.php` proves and probes over `https://` with peer
  verification. *Missing:* the proof never succeeds; the switch is unproven.
- **`realpath_cache_ttl` at most ~120 s** (the PHP default). `switch.php`
  watches for `X-Z77-Release` for 150 s. *Longer:* the switch still happens,
  later — `switch.php` reports a STOP it should not; re-run it.
- **SSH with a POSIX shell** (`bash`, `tar`, `readlink`, `find`, `ln`, `grep`,
  `cut`; `md5sum` for the legacy config compare) — `deploy.php` and
  `switch.php` send a bash script over `ssh`. *SFTP only:* the scripts cannot
  run; upload with an SFTP client into `releases/<name>`, set the signposts and
  bend the doors by hand (OpenSSH `sftp` knows `ln -s`), and prove the switch
  by hand with `curl -sI` — every safeguard of the scripts becomes your
  discipline.
- **A PHP CLI ≥ 8.4** (the framework's minimum, `composer.json`) for cron,
  `z77-db` and `z77-run`. *Older:* cron and migrations die with a parse error
  while the web side runs fine.

**In the framework**

- **The trampoline `index.php`** (every release since 2026-08-31) and the
  `X-Z77-Release` header (since 2026-08-30). Without them a switch does not
  take effect reliably — see the mechanism section.
- **The config split (ADR-036):** `config/vendor` (installer-generated) rides
  with the release, `config/client` (hand-maintained) is shared. In
  `target.json`: `config/client` in `shared`, `config` in `release_dirs`.
  Older installations: migration at the bottom of this page.
- Historical, for very old installations: framework state of 2026-08-28 or
  later, so the generated `fileFinder.inc.php` anchors on `ABS_BASE_PATH`
  instead of `dirname(__DIR__)` — behind a `config` symlink that location is
  `shared/`, and the map pointed at a `shared/vendor/` that does not exist
  (HTTP 500, «Namespace 'Z77\Core\' has no registered sourcePaths» — the
  error that triggered this document).

**On the workstation:** see [«The workstation»](#the-workstation).

**Not required, deliberately:** an OPcache reset. On shared hosting the
account often shares ONE OPcache across ALL its sites — a reset would flush
strangers. The trampoline makes the reset unnecessary.

## The layout

```
<domain>/
  shared/                     everything that comes into being at RUNTIME — stays put
      data/  config/client/  logs/  backup/  media/  storage/  var/lib/
      (project-specific stores too, e.g. a key store such as .credentials/)
  releases/
      2026-08-28/             pure code. One upload = one directory.
      2026-08-27/             the previous state — the way back
  current -> releases/2026-08-28/public     production points here
  next    -> releases/2026-08-28/public     the test subdomain points here
```

Whether the doors point at `releases/<date>/public` (document root =
`<domain>/current`) or at `releases/<date>` (document root =
`<domain>/current/public`) is ONE convention per project, recorded in
`.releases/target.json` as `link_target` — `public` above. Which one fits is
host-specific (what the panel lets you set); the rule that it is ONE is not.
Never mixed: the door is bent with `php .releases/switch.php <date> <door>`,
which builds the link from that key, proves the switch via `X-Z77-Release`,
and probes the door from outside. The hand-typed `ln` that stops one level short turns the
release root — vendor/, composer.json, every signpost below — into the
document root; `<project>/.htaccess` (`Require all denied`) and the same file
in every `shared/` store are the alarm for that day.

Inside a release, the data locations are nothing but signposts:

```
releases/<date>/data           -> ../../shared/data
releases/<date>/config/client  -> ../../../shared/config/client   (config/vendor is real, ADR-036)
releases/<date>/logs           -> ../../shared/logs
releases/<date>/backup         -> ../../shared/backup
releases/<date>/public/media   -> ../../../shared/media
releases/<date>/public/storage -> ../../../shared/storage
releases/<date>/var/lib        -> ../../../shared/var/lib
```

And what is NOT a signpost, deliberately — the release-local runtime state
(ADR-035). These are real directories inside the release, created by
`deploy.php` and by the framework on first write:

```
releases/<date>/var/cache      rendered pages, apcu.stamp
releases/<date>/var/state      debug.flag, noindex.flag
```

**The rule of thumb for `shared/`:** is it in git? Then it is code and lives
in the release. Does it come into being on the server? Then `shared/` — unless
it describes THIS release's code or THIS door's behaviour, which is the
release-local `var/` above.

Why the split is not optional: `PageCache` keys a file by
*(language, module, group, controller, action)* and by nothing else. Behind a
shared `var/cache` a page rendered on `next` is a cache HIT for `current` —
production serves the release under test for up to the TTL (86 400 s by
default), and the reverse makes every test on `next` read the previous
release's HTML. The DEBUG and `noindex` flags had the same defect from the
other side: in `data/` they could not be set on one door without setting the
other. Full reasoning in ADR-035.

Relative link targets (`../../shared/...`, `releases/<date>`), deliberately:
the whole tree survives an account move or a path rename without touching a
single link.

## How the `current` / `next` mechanism works

Two symlinks, two doors into the same pool of releases:

- **`current`** is what production serves. The domain's document root is the
  door (`<domain>/current` with `link_target: public`, `<domain>/current/public`
  with `release`) — the web server resolves the link on every file access, so
  re-pointing `current` re-points the whole site.
- **`next`** is the same mechanism as a second door: a test subdomain
  (`next.<domain>`, document root on `<domain>/next` the same way) that is
  permanently wired to it. A deploy lands in a new release directory, `next`
  is bent onto it, and the candidate is tested **on the real server, against
  the real shared data** — same code, same stores, different door. Only when
  it holds is `current` bent onto the same release.

Why this is actually uninterrupted, and not just fast:

- **A request runs start-to-finish in ONE release.** PHP's `__DIR__` is the
  symlink-RESOLVED real path, so `public/index.php` computes `ABS_BASE_PATH`
  as `…/releases/2026-08-28` — not as `…/current`. A request that entered
  through the old release finishes on the old release's files even if the
  switch happens mid-request. Visitors never see half-new code; the switch
  only decides where NEW requests enter.
- **…but OPcache freezes that resolution.** `__DIR__` is a COMPILE-time
  constant, and OPcache binds the UNRESOLVED path the web server hands over
  (`<domain>/next/index.php`) to the one compiled copy it made on the first
  request through that door. With `opcache.revalidate_path=0` (the default)
  a hit on that binding never calls `realpath()` again — the binding has NO
  TTL, and the timestamp check stats the file the copy was compiled FROM
  (the OLD release's `index.php`, unchanged), not through the link. Bending
  the door changes nothing OPcache looks at: every request through it runs
  `ABS_BASE_PATH` in the old release — while static files (served by the
  web server, which follows the link on every access) already come from the
  new one. Measured on cyon 2026-08-29 (`X-Z77-PageCache: MISS`, fresh render,
  old content; `md5_file()` of the controller identical to the new release);
  mechanism proven 2026-08-31: the door bent to another release for minutes,
  the worker's realpath cache long expired and resolving correctly, OPcache
  still serving the bound copy — zero hits on the linked release. Only the
  entry point is affected: everything it includes goes through the real path
  and is keyed per release. This is PHP's OPcache behaviour, not the host's —
  expect it everywhere `opcache.revalidate_path` is `0`.

  **What ends it.** A `touch` on the new release's `index.php` does NOT
  (reasoned 2026-08-29, disproved 2026-08-30: the door on release N served
  N-1 for an hour, hits climbing — the new file is never statted). Since
  2026-08-31 `index.php` no longer bakes the release in at all: it is a
  trampoline that resolves `ABS_BASE_PATH` at runtime from
  `realpath($_SERVER['DOCUMENT_ROOT'])`, so even a stale bound copy boots
  the CURRENT release within the realpath cache TTL (≤120 s with the
  default). The trampoline is a contract: `index.php` stays minimal and
  unchanging, because the cached copy may outlive the release it came from.
  Deliberately NO `opcache_reset()` on a switch: on shared hosting an
  account often has ONE OPcache for ALL its sites (measured on cyon
  2026-08-31) — a reset would flush every one of them. `.releases/switch.php`
  instead reads `X-Z77-Release` off `/` (every HtmlResponse carries it since
  2026-08-30 — basename of `ABS_BASE_PATH`, i.e. the release name) and
  retries across the TTL until it names the release. One-time migration
  per installation: a release deployed BEFORE the trampoline still has the
  baked-in copy bound — copy the trampoline `index.php` into that RUNNING
  release once (the mtime change recompiles the binding in ~2 s), or reset
  OPcache once by hand. The realpath cache: keep `realpath_cache_ttl` at
  120 s, do not set it to 0 for the account (every stat gets dearer, all
  day, for two minutes per deploy).
- **The data cache cannot go stale across the switch.** `DataCache` (APCu)
  namespaces its pool with a hash of `ABS_BASE_PATH` — and since that is the
  real release path, every release automatically has its own pool. The
  resolved `FileFinder` path map of release A is invisible to release B; no
  flush choreography needed.
- **The file-based page cache does NOT span releases** — since ADR-035. It
  lives at `releases/<date>/var/cache/pages`, a real directory inside the
  release, so a new release starts with a cold cache and nothing has to be
  cleared after a switch. Until 2026-09-01 it sat in `shared/lib/cache` and
  DID span them, in both directions: a page rendered on `next` was served by
  `current` for up to the TTL, and every test on `next` could read
  production's HTML. If you are reading this on an installation that still
  has `shared/lib/`, do the migration at the bottom of this document before
  the next deploy. Everything under `var/` stays disposable by contract
  (ADR-034), so wiping it is always safe.
- **DEBUG and `noindex` are per door**, for the same reason: the flags live
  in `releases/<date>/var/state/`. The staging door can run in debug mode or
  carry `noindex` while production does neither. Before ADR-035 both flags
  sat in the shared `data/framework/` and switching one door switched both.
- **Cron follows the switch on its own** — as long as the cron entry goes
  THROUGH `current` and never names a release directory. The link is resolved
  when the process starts: a job already running finishes on the old code
  (fine — same guarantee as a web request), the next tick runs the new one.
  Two call forms, depending on what the host allows (the PHP CLI binary name
  is host-specific — `php`, `php8.4`, `php84`, …):

  ```sh
  # a real crontab (shell available):
  * * * * * cd <domain>/current && php vendor/bin/z77-run

  # a panel that takes ONE command and no `cd`:
  php <domain>/current/cron/run.php
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
  `php .releases/switch.php <previous> current`. Nothing is copied, nothing
  restored; the previous release never stopped being complete. Keep at least
  one old release around for exactly this.

About a hand-typed `ln -sfn` (the scripts use it; you only type it where SSH
scripts are unavailable): **both switches are mandatory.** Without `-n`, `ln`
dereferences the existing link and plants the new one INSIDE the old target.
Strictly speaking `-sfn` replaces the link in two steps (unlink, then create)
— a gap of microseconds. Where even that is too much, use the truly atomic
form (`<link>` = `releases/<name>/public` with `link_target: public`,
`releases/<name>` with `release`):

```sh
ln -sfn <link> current.tmp && mv -T current.tmp current
```

`mv -T` (GNU coreutils) is a rename(2), which is atomic. For a shared host,
plain `ln -sfn` is in practice indistinguishable.

## The rules, per project: `.releases/`

The layout above is binding for every project. Each project carries a
`.releases/` directory (master copy in the framework root — copy it, fill
`target.json`, commit): [`RULES.md`](../../.releases/RULES.md) states the rules
(outer boundary = `target.root`, four root entries, upload only into
`releases/<name>` — never over `current`, `next` or `shared/`, shared names
never uploaded, `uploadOnSave: false`), `check.php` verifies `target.json`
against the hand-maintained `.vscode/sftp.json` and exits non-zero on any
violation. Run `php .releases/check.php` before every deploy.
[`HANDOFF.md`](../../.releases/HANDOFF.md) is the step list for setting a
project up, [`CHECKLIST.md`](../../.releases/CHECKLIST.md) the run on every
release. Deviations only with the explicit approval of the framework owner,
recorded in the project's `RULES.md`.

## The workstation

The `.releases/` scripts run on the developer machine and talk to the server
over `ssh`. What they need, read off the scripts:

| need | used by | Windows | Linux / macOS |
|---|---|---|---|
| PHP CLI ≥ 8.4 with `phar` (PharData builds the tar), `openssl` and `allow_url_fopen` (the HTTPS proof and probes use PHP streams) | every script | PHP for Windows on `PATH` | distribution package / Homebrew |
| OpenSSH client `ssh` on `PATH`, the host as an alias in the SSH config, key login without a prompt | `deploy.php`, `switch.php` | Windows OpenSSH client (`ssh.exe`), config in `%USERPROFILE%\.ssh\config` | system OpenSSH, `~/.ssh/config` |
| `git` and `composer` on `PATH` | `vendor-deploy.php`, `vendor-dev.php`, the deploy log of `switch.php` | Git for Windows, Composer | distribution package / Homebrew |
| `scp` | only the hand fixes the scripts print | comes with Windows OpenSSH | comes with OpenSSH |
| double-click wrappers | `vendor-deploy.bat`, `vendor-dev.bat` | `cmd` only | not needed — call `php .releases/vendor-deploy.php` directly |

**Not needed:** `ext-ssh2`, a local `tar`, `curl`, `rsync` or an `sftp`
binary — the tar is built in PHP and streamed through `ssh`; on the server
`tar` unpacks it. Every command in this page and in `CHECKLIST.md` is the
same on all three systems (`php .releases/…`); run it from PowerShell, Git
Bash or any POSIX shell. The development `vendor/` links are junctions on
Windows and symlinks elsewhere — `check.php` and `deploy.php` recognise both.

**Fallback without SSH scripts** (rule: `php .releases/check.php` prints `OK`
first, the upload goes into `releases/<name>` only): the VS Code SFTP
extension driven by `.vscode/sftp.json` works on every system and is the only
client `check.php` verifies. A GUI client (WinSCP on Windows) or `rsync` on
Linux/macOS work too, but nothing checks their exclusions — they must leave
out every `target.shared` name and `var/`. An `rsync` equivalent of
`deploy.php`'s packing (default `shared` list, split config — `config/client`
shared, `config` in `release_dirs`):

```sh
rsync -a --exclude=/config/client --exclude=/public/media --exclude=/public/storage \
      --exclude=.git --exclude=node_modules \
      vendor public override cron config composer.json composer.lock .htaccess \
      <alias>:<root>/releases/<name>/
```

Then the signposts by hand (initial setup, step 4) and the switch by hand,
proven with `curl -sI https://<host>/ | grep X-Z77-Release`.

## Before you use this on another host

Answer every question once per host, before the first deploy. Most checks
run over SSH; the web-side checks use a **throwaway probe release** that is
also your first real door, so nothing lands outside `<root>` (rule 1) or
beside the four root entries (rule 2):

```sh
# on the server, <root> is target.root
cd <root> && mkdir -p releases/z77-probe/public/deny shared
echo ok > releases/z77-probe/public/t.txt
echo 'Require all denied' > releases/z77-probe/public/deny/.htaccess
echo secret > releases/z77-probe/public/deny/t.txt
cat > releases/z77-probe/public/probe.php <<'EOF'
<?php
header('Content-Type: text/plain');
echo PHP_VERSION, ' ', PHP_SAPI, "\n";
echo 'DOCUMENT_ROOT=', $_SERVER['DOCUMENT_ROOT'] ?? '-', ' -> ', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '-', "\n";
foreach (['opcache.enable', 'opcache.revalidate_path', 'opcache.validate_timestamps',
          'realpath_cache_ttl', 'open_basedir', 'disable_functions'] as $k) {
    echo $k, '=', ini_get($k), "\n";
}
var_dump(file_put_contents(dirname(__DIR__) . '/written-by-php', 'x'));
EOF
ln -sfn releases/z77-probe/public next     # link_target public; with release: releases/z77-probe
```

Point the test subdomain's document root at the door (panel), then request
`https://next.<domain>/t.txt`, `/deny/t.txt` and `/probe.php`. **Remove the
probe afterwards** (`rm -rf releases/z77-probe`, `rm next`) — a page that
prints ini values is an information leak for anyone.

| question | how to verify | if the answer is no |
|---|---|---|
| SSH with a shell? | `ssh <alias> 'bash --version \| head -1; command -v tar readlink find grep cut'` | SFTP-only fallback ([workstation](#the-workstation)) |
| Symlinks allowed and followed? | `/t.txt` answers `ok` through the `next` link | the layout cannot run on this host |
| Document root settable per (sub)domain? | the panel; `/t.txt` answers on `next.<domain>` | the layout cannot run on this host |
| Which `link_target`? | what the panel accepts: `<root>/next` → `public`, only `<root>/next/public` → `release` | — record it in `target.json` |
| `DOCUMENT_ROOT` names the door? | `probe.php` line 2: the door path, resolving into `releases/z77-probe/public` | the trampoline cannot find the release — ask the host |
| `.htaccess` read? | `/deny/t.txt` gives 403 | the deny rules must go into the server config; `switch.php`'s probes will FAIL until they do |
| HTTPS with a valid certificate on both doors? | `curl -sI https://next.<domain>/t.txt` without `-k` | `switch.php` cannot prove the switch |
| PHP user can write where SSH wrote? | `probe.php` prints `int(1)`; `ls -ln releases/z77-probe` — the owner of `written-by-php` vs `t.txt` | different owners: make `shared/` and `releases/*/var` group-writable for PHP's group, or ask the host to run PHP as the account |
| `open_basedir` covers `<root>`? | `probe.php`: empty, or a path that contains `<root>` | the first include from a release fails |
| OPcache: `revalidate_path`, `validate_timestamps`, `realpath_cache_ttl`? | `probe.php` | `validate_timestamps=0`: «Cache leeren» after a migration ([DOCTRINE-CACHE-001](../topics/persistence-doctrine.md)); TTL > 120 s: `switch.php` reports a premature STOP, re-run it |
| OPcache shared across the account's sites? | ask the host; assume yes on shared hosting | either way: no `opcache_reset()` |
| `exec`/`proc_open` disabled? | `probe.php` (`disable_functions`) and `ssh <alias> 'php -r "echo ini_get(\"disable_functions\");"'` | the core needs neither; a module that calls external tools does — check that module's topic |
| PHP CLI ≥ 8.4, and its name? | `ssh <alias> 'command -v php; php -v \| head -1'`; look for `php8.4`, `php84`, … | use the versioned binary in cron and for `z77-db` |
| Cron: crontab or panel, `cd` allowed? | `ssh <alias> 'crontab -l'`; the panel's cron form | panel without `cd`: the `cron/run.php` form |

## Initial setup over SSH

The order is the rule: **`shared/` targets first, then the links.** A symlink
onto a missing target reads as `is_dir()=false`, and code that then tries
`mkdir()` fails with «File exists» — the name is taken by the dangling link.
(Bit client-a's CredentialStore, which creates its store on demand.)

```sh
# ── once per project ────────────────────────────────────────────────
BASE=/home/<user>/public_html/example.ch   # = target.root, the <domain> directory
REL=2026-08-28                             # today's release name

# 1. shared/ FIRST, with every target directory the links will point at.
mkdir -p $BASE/shared/{data,config/client,logs,backup,media,storage} $BASE/shared/var/lib
chmod 700 $BASE/shared/backup          # and every secret store, e.g. .credentials
#    NOTE: only var/lib is shared (throttle windows must survive a switch).
#    var/cache and var/state belong to the release — step 4 makes them there.

# 2. Seed shared/ on the very first install: config/client from the local
#    config/client/ (the machine facts edited for THIS server), data from the
#    installer run (or the old installation, when migrating).
#    From the second release on, this step does not exist — shared/ IS the state.

# 3. Upload the release — pure code: vendor/, override/, public/, cron/,
#    config/vendor/ (ADR-036), composer.json + composer.lock. The last two are
#    not read by the web application, but z77-run and z77-backup recognise the
#    project root by vendor/autoload.php AND composer.json side by side — an
#    upload without them leaves both CLI tools unable to find home.
#    ⚠️ The upload must NOT contain the linked names (data/, config/client/,
#    logs/, backup/, public/media, public/storage, var/) — a local directory of
#    that name would overwrite the signpost with a real folder. var/ is
#    excluded as a whole: var/lib is a signpost, var/cache and var/state are
#    runtime dirs that have no business travelling from a developer machine.
mkdir -p $BASE/releases/$REL
# … unpack the build into $BASE/releases/$REL …

# 4. Signposts inside the release.
cd $BASE/releases/$REL
ln -s ../../shared/data    data
mkdir -p config                                  # arrives with config/vendor anyway
ln -s ../../../shared/config/client config/client
ln -s ../../shared/logs    logs
ln -s ../../shared/backup  backup
ln -s ../../../shared/media   public/media
ln -s ../../../shared/storage public/storage
mkdir -p var                                     # the signpost needs a parent
ln -s ../../../shared/var/lib var/lib
mkdir -p var/cache var/state                     # release-local (ADR-035)

# 5. The two doors (link_target public; with release drop the /public).
cd $BASE
ln -sfn releases/$REL/public current
ln -sfn releases/$REL/public next
```

With SSH, steps 3 and 4 are one command from the developer machine —
`php .releases/deploy.php $REL` — once steps 1 and 2 are done: it refuses
while `shared/` is missing or `shared/config/client/` holds no `*.inc.php`
(rule 10: the first-install layout is manual).

Then, in the hoster's panel:

- document root of the production domain → the `current` door
  (`<domain>/current` with `link_target: public`, `<domain>/current/public`
  with `release`)
- a test subdomain (e.g. `next.example.ch`) → the `next` door the same way,
  and give it `noindex` (it serves the same pages as production)
- cron → through `current`, never a release path: a real crontab uses
  `cd <domain>/current && php vendor/bin/z77-run`, a panel without `cd` uses
  `php <domain>/current/cron/run.php` (see the mechanism section — and never
  a `..` across the switch)

**Build every switch the same way.** Whether document roots point at
`current/public` or `current` points at a `public` — pick ONE convention for
all domains and doors, record it as `link_target` in `.releases/target.json`,
and let `switch.php` build the link. The person switching under pressure
grabs the wrong one; measured 2026-08-30 on client-a.example.ch, harmless direction
(404). The other direction serves `shared/` as URLs.

## Deploying a release

The full run with what each step prints and what you decide is
[`CHECKLIST.md`](../../.releases/CHECKLIST.md); this is the shape.

```sh
BASE=/home/<user>/public_html/example.ch
REL=2026-09-04

# 0. local: deployable vendor/, then the check — it must print OK.
php .releases/vendor-deploy.php
php .releases/check.php

# 1 + 2. upload the new release and set its signposts — one command from
#    the developer machine. It packs release_dirs + composer.* + .htaccess
#    with every shared name excluded, streams the tar over ssh into
#    releases/$REL (refusing an existing or running release), and links every
#    target.shared entry. On the legacy whole-`config` layout only, it also
#    says whether the shared installer-generated configs (fileFinder,
#    moduleManager, bootstrap) differ from the local ones.
php .releases/deploy.php $REL
#    (by hand: mkdir -p $BASE/releases/$REL, upload, then the signpost block
#     from the initial setup, step 4)

# 3. bend next. From the developer machine, over ssh: link target per
#    target.link_target, the deny files in shared/, the proof via
#    X-Z77-Release (the trampoline index.php propagates the switch within
#    ~2 min — see the mechanism section; no OPcache reset), then the probes
#    from outside.
php .releases/switch.php $REL next
#    (by hand: ln -sfn releases/$REL/public $BASE/next  [link_target=public],
#     then curl -sI https://next.../ | grep X-Z77-Release until it names $REL)

# 3b. pending schema migrations (a module with Doctrine tables — module-contact
#    is the first): AFTER next is bent (3), BEFORE testing on it (3c) and
#    before current is bent (4). Run in the new release's directory, never
#    from current — the command prints the project root and the database
#    first, read them; `status` shows what is pending. One database for both
#    releases (ADR-035), so this runs exactly once; expand/contract (ADR-039
#    decision 14) is what keeps `current` working on the migrated schema.
cd $BASE/releases/$REL && php vendor/bin/z77-db status
cd $BASE/releases/$REL && php vendor/bin/z77-db migrate
#    (with `opcache.validate_timestamps = 0` on the host, «Cache leeren» in the
#     backend afterwards — persistence-doctrine.md DOCTRINE-CACHE-001)

# 3c. test on the subdomain — real server, real data, the migrated schema:
#    the pages of the new release AND a page of the old one on production,
#    which is the proof that the migration left `current` working.

# 4. bend current — same script, same reason. No cache to clear since
#    ADR-035: var/cache belongs to the release and is cold anyway.
php .releases/switch.php $REL current

# 5. local: back to the development vendor/.
php .releases/vendor-dev.php

# 6. prune old releases on the server — keep the two newest, and never the
#    target of current or next (rule 9; after a rollback current is OLDER).
cd $BASE && keep=" $(readlink current | cut -d/ -f2) $(readlink next | cut -d/ -f2) "
ls -1dt releases/*/ | tail -n +3 | while read -r d; do
  n=$(basename "$d"); case "$keep" in *" $n "*) ;; *) rm -rf "releases/$n" ;; esac
done

# Rollback, should step 4 turn out wrong — the same script, the old name.
# The trampoline propagates it the same way, within ~2 min.
php .releases/switch.php 2026-08-28 current
```

**Verify with PHP, not with a static file.** After each switch, request a
page that is demonstrably different in the new release — a changed template,
a new route. A static file (image, CSS, a marker file) only proves that
the web server follows the link, which it always does; the stuck entry point
shows exclusively in rendered output. A `MISS` in `X-Z77-PageCache` with old
content is the signature. Why the first migration did not show this: moving
from `apps/z77-1.0.0` to this layout changed the document root in the
panel — new path, new OPcache keys, fresh compilation. The problem appears
on the SECOND release, exactly when the structure is supposed to prove its
worth.

**Generated config rides with the release** (ADR-036): `config/vendor/` is
in the upload, so a `fileFinder.inc.php` or `moduleManager.inc.php`
regenerated on the dev machine (new module, new override path) reaches the
server with the next release and is testable on `next`. Only `config/client/`
is shared — a hand edit there is a data handgrip on the server, written into
the deploy protocol. On the legacy whole-`config` layout (`config` in
`target.shared`) the generated files are runtime state instead: copy a
changed one into `shared/config/` by hand before any switch — `deploy.php`
prints the `scp` line.

## What must NOT be done

- **Nothing above or beside `target.root`.** Not listed, copied, written or
  deleted — the only permitted operation above it is the path resolution that
  verifies the boundary itself (RULES.md rule 1). Uploads go into
  `releases/<name>` only, never over `current`, `next` or `shared/`.
- **No PHP code in `shared/`.** `__DIR__` is only dangerous when the file sits
  behind a symlink — which is exactly what everything in `shared/` does. Code
  that interprets its own location belongs in the release; `shared/` holds
  state. (The generated `fileFinder.inc.php` is the audited exception on the
  legacy layout: since 2026-08-28 it anchors on `ABS_BASE_PATH` and computes
  nothing from itself.)
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
- **No migration from `current`.** `z77-db migrate` runs in
  `releases/<name>` of the new release, after `next` is bent — never through
  a door, never twice.
- **No crontab or document root on a `releases/<date>` path.** Anything wired
  past `current`/`next` keeps running the old code after a switch, silently.
- **No `opcache_reset()` as part of a switch.** On shared hosting it flushes
  every site of the account. The one-shot hand reset (`<?php opcache_reset();
  unlink(__FILE__);` in the running release's `public/`, called once) remains
  the escape hatch for a release that predates the trampoline — never leave
  such a file behind, a reset endpoint is a cache flush for anyone.
- **No door without `/public` when `link_target` is `public`.** The release
  root is then the document root, and it holds the signposts into `shared/`:
  `/.credentials/tenant-3.json`, `/data/…`, `/backup/…` answer as plain files.
  `<project>/.htaccess` and the deny file in every store turn that into a
  403 — if `AllowOverride` is on. `switch.php` builds the link right and
  probes; the probe is the only one of the three that measures.
- **No deny file in a store served through `public/`.** `public/media` is a
  link to `shared/media`, the link IS the directory, so
  `shared/media/.htaccess` is `public/media/.htaccess` — every image 403,
  on every door at once. Measured 2026-08-30 on client-a.example.ch, eleven minutes of
  broken images in production. `deploy.php`/`switch.php` write the deny
  file only into stores that are signposts in the release root.

## Example: cyon (shared hosting, LiteSpeed)

The host this layout was built and measured on. Everything in this section is
cyon's answer to [«Before you use this on another host»](#before-you-use-this-on-another-host)
— on another host, measure instead of copying.

| question | cyon |
|---|---|
| access | SSH with `bash`; the alias lives in the SSH config (`cyon-z77` in `target.example.json`) |
| web server | LiteSpeed — Apache-compatible, reads `.htaccess` |
| symlinks | followed in and below the document root, no 403 anywhere (2026-08-28) |
| document root | set per (sub)domain in the panel to an arbitrary path inside the account; `link_target: public` (document root = `<domain>/current`) |
| account layout | `/home/<user>/public_html/<domain>` = `target.root` |
| PHP user | PHP writes into the directories created over SSH without extra permissions — throttle counters and page cache in the verified list below |
| OPcache | ONE OPcache for ALL sites of the account (2026-08-31); `opcache.revalidate_path=0` — the stale binding of the mechanism section was measured here |
| realpath cache | `realpath_cache_ttl` 120 s — the switch propagates within ~2 min |
| PHP CLI | versioned binary, `php84` |
| cron | panel field that takes ONE command and no `cd`: `php84 /home/<user>/public_html/<domain>/current/cron/run.php` |

Verified 2026-08-28, structure as above, test subdomain on the release:

```
front page                  200   X-Z77-PageCache: MISS → HIT
/media/…/iso-1-04.webp      200   image/webp — through the symlink
/assets/frontend/….css      200
/backend/system/login       200
/anmeldung                  200
widget fragment (CORS)      200
page cache lands in         releases/<date>/var/cache/pages/…   (release-local since ADR-035)
throttle counters in        shared/var/lib/throttle/…
```

## Migrating an installation from `lib/` to `var/` (ADR-035)

One-time, per installation and per server. An installation deployed before
2026-09-01 has `shared/lib/` and both flags in `shared/data/framework/`.
Nothing breaks if you skip it — the framework simply starts with empty
caches and DEBUG off — but the old directories stay behind as a decoy, and
the backup config keeps excluding a name that no longer exists.

Order matters: do it while the release you are about to deploy is NOT yet
live, or accept one cold cache.

```sh
BASE=/home/<user>/public_html/example.ch

# 1. the shared branch: throttle counters move, they must survive the switch
mkdir -p $BASE/shared/var
[ -d $BASE/shared/lib/throttle ] && mv $BASE/shared/lib/throttle $BASE/shared/var/lib

# 2. the rest of shared/lib was the page cache — it belongs to no release now
rm -rf $BASE/shared/lib

# 3. the two switches, per DOOR. Read the old shared flags first, then set
#    them in the release each door points at — that is the whole point of the
#    move, so decide per door rather than copying blindly.
ls -la $BASE/shared/data/framework/debug.flag 2>/dev/null
ls -la $BASE/shared/data/framework/seo/noindex.flag 2>/dev/null

#    ⚠️ Name the RELEASE, never reach through the door with `..`:
#    `$BASE/next/..` resolves the link first and names releases/, not the
#    release (the same trap the mechanism section describes). Ask the door
#    which release it points at, then work on that path.
NEXT=$(basename "$(dirname "$(readlink -f $BASE/next)")")   # link_target=public
CURR=$(basename "$(dirname "$(readlink -f $BASE/current)")")
#    (with link_target=release drop the inner dirname:
#     NEXT=$(basename "$(readlink -f $BASE/next)") )
echo "next -> $NEXT   current -> $CURR"

#    e.g. staging in debug + noindex, production in neither:
mkdir -p $BASE/releases/$NEXT/var/state $BASE/releases/$CURR/var/state
touch $BASE/releases/$NEXT/var/state/debug.flag
touch $BASE/releases/$NEXT/var/state/noindex.flag

# 4. drop the old shared flags — they are read by nothing after this
rm -f $BASE/shared/data/framework/debug.flag
rm -f $BASE/shared/data/framework/seo/noindex.flag
```

Then, on the developer machine, in the project:

- `.releases/target.json`: drop `"lib"` from `shared`, add `"var/lib"`
- `.vscode/sftp.json`: drop `"lib/**"` from `ignore`, add `"var/**"`
- `config/client/backup.inc.php` **and** `shared/config/backup.inc.php` on the
  server: `fullExcludes` — replace `lib` with `var` (seed-once, so the new
  default never reaches an existing installation on its own)
- `config/vendor/bootstrap.inc.php` **and** the server copy: remove the `cacheDir`
  key — it is ignored since ADR-035, and leaving it reads as the truth
- `php .releases/check.php` — it warns about every one of the above

The next `deploy.php` creates `var/cache`, `var/state` and the `var/lib`
signpost by itself.

## Migrating `shared/config` to `shared/config/client` (ADR-036, 2026-10-22)

Until 2026-10-22 `deploy.php` linked `config/client` onto the flat
`shared/config`. Since then it links onto `shared/config/client` — the same
tree as locally — and refuses to upload while that directory holds no
`*.inc.php`. A server in the old layout (client-a.example.ch, client-b.example.ch) is migrated
once, by hand, before the first deploy with the new `.releases/`:

```sh
cd $BASE/shared/config
mkdir client
cp -p *.inc.php client/        # COPY, not move: current, next and every rollback
cp -p .htaccess client/        # release still read the flat files
ls -la client/                 # systemConfig, mail, database, … all there
```

Then deploy and switch as usual. The flat copies stay until no release under
`releases/` links onto `shared/config` any more
(`readlink releases/*/config/client`); only then remove them. Until then a
hand edit of a client config goes into BOTH places.

A server seeded with the split layout (`shared/config/client/` already there,
z77.ch) needs nothing.

## See also

- [`installer.md`](installer.md) — the generated `config/vendor/fileFinder.inc.php`
  and why it anchors on `ABS_BASE_PATH`
- [`../topics/backup.md`](../topics/backup.md) — how the archive walk follows
  the layout's links (BACKUP-SYMLINK-001), and why all three types work here
- [`../topics/persistence-file.md`](../topics/persistence-file.md) — what
  lives under `data/` and why it must exist exactly once
- [`../topics/cache.md`](../topics/cache.md) — CACHE-RELEASE-001, the defect
  that forced the split, and what `PageIdentity` does and does not key on
- [`../topics/persistence-doctrine.md`](../topics/persistence-doctrine.md) —
  `z77-db`, DOCTRINE-CACHE-001
- [`../topics/jobs.md`](../topics/jobs.md) — `z77-run` and the cron pass
- [ADR-034](../02-decisions/adr-034-disposable-runtime-state-under-lib.md) —
  why everything under `var/` may be deleted at any time
- [ADR-035](../02-decisions/adr-035-release-local-runtime-state-under-var.md) —
  why `var/cache` and `var/state` are release-local and `var/lib` is not
- [ADR-036](../02-decisions/adr-036-config-split-vendor-client.md) —
  `config/vendor` rides with the release, `config/client` is shared
- [`.releases/RULES.md`](../../.releases/RULES.md),
  [`CHECKLIST.md`](../../.releases/CHECKLIST.md),
  [`HANDOFF.md`](../../.releases/HANDOFF.md) — the binding rules, the run, the setup
