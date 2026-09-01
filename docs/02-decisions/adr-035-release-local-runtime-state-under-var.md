# ADR-035 — Release-local runtime state, and `lib/` becomes `var/`

Date: 2026-09-01 · Status: accepted · Amends: [ADR-034](adr-034-disposable-runtime-state-under-lib.md)

## Context

ADR-034 gave disposable runtime state a home and a hard test: *may this be
deleted while the installation is serving requests?* Yes → `lib/`. It named
one directory and answered one question — **what** may be thrown away.

The release layout (`docs/01-handbook/release-structure.md`) asks a second
question that ADR-034 never had to: **whose** is it? A production door
(`current`) and a test door (`next`) run different code against one
`shared/` store. `lib/` was listed in `target.shared`, so both doors wrote
into the same `lib/cache`.

`PageCache` keys its files by `PageIdentity` = *(language, module, group,
controller, action)*. There is no release dimension in that key. Two doors,
one file:

```
shared/lib/cache/pages/de/frontend/main/index/legal.html
    written by  next     (release under test)
    read by     current  (production)   for up to ttl = 86400 s
```

A page rendered on the staging door is served by production for a day.
Nothing in the code notices; the entry is a cache HIT like any other. The
reverse costs less but happens more often: `next` serves production's cached
HTML, so a release is tested against the previous release's output.

The existing safeguards do not cover it, and both fail for the same reason —
they were designed against a single installation, not against two doors:

- **DEBUG → BYPASS** cannot be armed per door. `debug.flag` lived in
  `data/framework/`, and `data` is shared: switching the staging door into
  debug mode switched production with it (`display_errors` included).
  `SEO_NOINDEX` had the identical defect from the other side — the door that
  needs `noindex` is exactly the one that could not carry it alone.
- **The admin bypass (CACHE-ADMIN-001)** does hold, because the doors are
  separate hosts with separate sessions. But it only covers the logged-in
  developer. Any anonymous request to the staging door — a private window, a
  crawler, a preview link sent to the client — writes into the shared cache.

Found by review on 2026-09-01, before it produced an incident. The layout
document already carried «clear the page cache after bending `current`» as a
manual step; that covers the switch, not the hours of testing before it.

## Decision

**`var/` replaces `lib/`, and its second level says whether the state
survives a release switch.**

```
releases/<name>/
    var/
        cache/      RELEASE-LOCAL   rendered pages, apcu.stamp
        state/      RELEASE-LOCAL   debug.flag, noindex.flag
        lib/    ->  ../../../shared/var/lib      throttle counters
```

ADR-034's test is unchanged and still decides what may live under `var/` at
all. The new question is decided by the second level:

| | may be deleted mid-flight | survives a release switch | location |
|---|---|---|---|
| Rendered pages, APCu stamp | yes | **no** — describes this release's code | `var/cache` |
| Release switches (DEBUG, noindex) | yes | **no** — belongs to the door | `var/state` |
| Throttle / lock-out windows | yes | **yes** — a switch must not free a locked account | `var/lib` |

Three consequences, all binding:

1. **`var/cache` and `var/state` are fixed paths, not configuration.**
   `Bootstrap` publishes `ABS_VAR_PATH` and `ABS_STATE_PATH`; `cacheDir` is
   gone from `bootstrap.default.inc.php` and a leftover key in an installed
   config is ignored. A configurable path can be pointed back into a shared
   store, which is precisely the defect above — and it would keep doing so
   silently in every installation whose seed-once config nobody edited.
   `CacheManager::setCacheDir()` therefore takes an absolute path and
   **throws** on a relative one, so a pre-ADR-035 caller fails loudly instead
   of resuming into `shared/`.
2. **`var/` is excluded from the `full` backup AS A TREE** — ADR-034's rule 1,
   carried over verbatim to the new name. Never a member of it.
3. **`logs/` does NOT move under `var/`.** It carries the form log
   (`FormLog` — submitted enquiries), which is a record and belongs in the
   archive. Moving it under a tree defined as «may be deleted at any moment»
   would have excluded it from every backup, silently. FHS would put it at
   `var/log`; that is correct only for a log that is genuinely disposable, and
   this one is not (see Rejected Alternatives).

### Why `var` and not `lib`

ADR-034 rejected `var/` on one ground: *«`lib/cache` is the established path
in `bootstrap.inc.php` of every installation, and renaming it buys a word at
the price of touching every installed config.»*

That price is at its lowest today — two installations exist, one of them in
production — and the paths have to be touched anyway for the release-local
split. The word bought is not cosmetic: `lib` means «library, so code» in
every other PHP project, and this framework is written to be handed to a
successor who has read Symfony or Laravel before it. ADR-034 recorded the
name as «a deliberate local redefinition»; the redefinition is now retired.

## Reasoning

**The cache key must carry every dimension the content depends on.** This is
the third time the same class of defect has appeared: CACHE-ADMIN-001 (the
key lacked the user dimension, so an admin's overlay was served to visitors),
CACHE-CLI-001 (the pool lacked a cross-process dimension, so a CLI write
never reached the web pool), and now the release dimension. Adding
`release` to `PageIdentity` would have worked too and was rejected —
see below.

**A switch belongs to the door it switches.** DEBUG and `noindex` are not
data; they say how *this* release should behave. Filing them in `data/` made
them a property of the installation, which is why they could not be set on
one door without the other. `var/state` states the ownership in the path.

**A guarantee that a config can revoke is not a guarantee.** The release
layout promises that a request runs start-to-finish in one release. The page
cache broke that promise, and it broke it through a setting nobody had
consciously chosen — `cacheDir` pointed at `lib/cache` because that is where
it happened to point in 2019. Structure that the layout depends on is not a
project preference.

## Consequences

- **Existing installations need a migration**, and it is not automatic —
  `shared/lib/` and the two flags must be moved by hand, per installation and
  per server. The step list is in
  [`release-structure.md`](../01-handbook/release-structure.md).
- **`config/backup.inc.php` is seed-once**, so every existing installation
  still excludes `lib` — a directory that no longer exists — and would
  archive all of `var/`. The same cost ADR-034 recorded, paid a second time,
  and for the same reason: a changed default does not reach an installation.
  `.releases/check.php` now warns on this and on a leftover `cacheDir`.
- **`target.shared` loses `lib` and gains `var/lib`.** It is the first shared
  entry that is not a top-level name and not under `public/`, so
  `releases_sharedStoreName()` now strips only the `public/` prefix and
  `deploy.php` creates the link's parent directory before `ln -s`.
  `check.php` refuses `var`, `var/cache` and `var/state` as shared entries
  and warns when `var/lib` is missing from them.
- **The APCu stamp becomes release-local, and that is an improvement.** Web
  and cron resolve the same `ABS_BASE_PATH` (both reach the release through
  a symlink, both end at `releases/<name>`), so CACHE-CLI-001 keeps working
  — while a cron running on the staging release stops wiping production's
  pool on every tick. It rests on the cron entry going through `current`,
  which release-structure.md already makes binding.
- **A release starts with a cold page cache.** Intended: the first request
  per page after a switch renders fresh, which is what «this release's
  output» means. It also removes the manual «clear the cache after bending
  `current`» step from the deploy checklist.
- **`DataCache` (APCu) needed no change.** Its pool prefix is
  `md5(ABS_BASE_PATH)`, which was already the real release path — the one
  layer that had the release dimension from the start.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Add a release dimension to `PageIdentity` | Fixes the page cache and nothing else — the DEBUG and `noindex` flags stay shared, and the next piece of release-dependent state under `lib/` reopens the hole. It also grows the cache directory by one level per release and leaves every previous release's pages on disk until someone prunes them. Treats the instance, not the class. |
| Keep `lib/` shared, clear the cache after every switch | The manual step the layout already documented — and the one that does not cover the hours of testing on `next` BEFORE the switch, which is where the staging render enters production's cache. A rule that has to be remembered at exactly the wrong moment. |
| Keep the name `lib`, only split release-local vs shared | Cheaper by one rename, and it leaves `lib` meaning «library, so code» for the next reader. Since the paths have to be touched anyway, the rename costs one line per migration step. |
| Move `logs/` to `var/log` (full FHS) | The form log lives there (`FormLog::DIR`) and holds submitted enquiries — records. Under `var/` it would be excluded from the full backup as part of the tree, silently. Doing it properly requires moving `FormLog` into `data/` first and migrating the existing `form-*.jsonl` in two installations; that is a separate change with its own risk, tracked in `topics/forms.md`. |
| Exclude `var/log` individually from the backup instead | Breaks ADR-034's rule 1: the exclude list would name three members instead of one tree, and a `var/tmp` added tomorrow would be in every archive until someone remembers the list exists. That is the exact failure BACKUP-LIB-001 was. |
| Make `cacheDir` configurable but validate it at boot | The framework cannot see `shared/` — it would have to compare `realpath()` against the release root on every request and then decide what to do with a violation. A log line per request is noise; an exception takes the site down. Removing the setting removes the question. |
