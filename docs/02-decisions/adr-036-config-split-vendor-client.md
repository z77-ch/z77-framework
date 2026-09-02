# ADR-036 — Config split: `config/vendor` (release-owned) vs `config/client` (installation-owned)

**Status:** `[APPROVED]`
**Date:** 2026-09-02

---

## Context

In the release layout, `config/` stood in `target.shared` — one directory,
symlinked into every release. But it mixed two kinds of state (the same
mixture ADR-035 untangled in `var/`):

- **Installer-GENERATED** files — `bootstrap.inc.php`, `fileFinder.inc.php`,
  `moduleManager.inc.php`. Pure functions of `composer.json` + `vendor/`,
  headed "DO NOT EDIT", regenerated on every `composer install`.
- **Hand-maintained machine/project facts** — `systemConfig.inc.php`
  (canonicalBaseUrl), `mail.inc.php` (transport), `geoip.inc.php` (licence
  key), `auth`, `backup`, `i18n`. Seed-once, deliberately excluded from git
  AND the deploy upload — they describe THIS machine.

Measured consequence (AXO3, first module deploy under the release structure,
2026-09-02): `moduleManager.inc.php` is walked on EVERY request
(`getReservedRoutes()` → `getModuleConfig()` → hard-throwing config load). A
newly registered module whose package is missing in the ANSWERING release
throws on every request — the site stands. Therefore a module deploy was not
testable on `next` (the shared config flips both doors at once), and every
deploy needed two hand-copied files (CHECKLIST 1).

The risk analysis sharpened the line: **everything a developer hand-edits
cannot kill the site** (worst: a dead language prefix, wrong mail links);
**everything that can kill the site is exactly the generated set** nobody
hand-edits.

## Decision

`config/` gains two subdirectories with distinct owners:

```text
config/
├── vendor/     GENERATED: bootstrap, fileFinder, moduleManager
│               → real directory, rides with the release upload (like vendor/)
└── client/     HAND-MAINTAINED: systemConfig, mail, geoip, auth, backup, i18n
                → in the release layout a symlink into shared/config
```

- Readers resolve `config/X` as `config/vendor/X` → `config/client/X` →
  `config/X` (legacy flat fallback; central helper `ConfigLocator`, plus the
  path-based `BackupService::fromProjectRoot`).
- The installer writes generated files to `config/vendor/`, seeds seed-once
  files into `config/client/`, and migrates a flat layout automatically
  (generated leftovers deleted and regenerated; seed-once files RENAMED so
  hand edits survive).
- Projects change `target.shared` from `config` to `config/client` and add
  `config` to `release_dirs`; the upload's shared-name exclusion then protects
  exactly the client tier. The deploy-time config compare (two hand copies)
  becomes obsolete under the split and only runs for the legacy layout.

## Reasoning

- The ownership line coincides with the danger line: release-owned =
  generated = crash-capable; installation-owned = hand-edited = degradation
  at worst. One symlink, self-explaining names.
- Server migration is nearly free: `shared/config` stays where and what it
  is; `config/client` points at it; the generated leftovers inside it simply
  stop being read.
- Local development is unchanged in behaviour: two real subdirectories, no
  symlink, same lookup.
- Naming mirrors the project's philosophy: `vendor` = generated, never
  touched; `client` = yours.

## Consequences

- A module deploy is testable on `next`: each release carries its own module
  register — no shared file can make the old release throw.
- CHECKLIST point 1 (hand-copying regenerated config) disappears under the
  new layout.
- `config/vendor` must be part of the release upload (`release_dirs`); the
  first release after the switch must ship it, and `target.shared` must say
  `config/client` — a mixed state (split locally, whole-`config` shared on
  the server) is covered by the legacy fallback + the deploy compare until
  the project flips its `target.json`.
- Seed-once semantics are unchanged; only the location moved.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Status quo + deploy discipline (switch both doors first, then copy) | Works, but is exactly the class of by-hand invariant the release structure exists to remove; not testable on `next`. |
| Generated files into `vendor/z77/config/` | vendor/ is the untouched framework by project philosophy; installation configs do not belong inside it. |
| Six per-file symlinks inside a release-real `config/` | Same effect, but six links instead of one and no self-explaining structure; more moving parts in deploy.php. |
| Whole `config/` release-local (drop the symlink entirely) | The six machine files must never travel with a release: a dev-machine `canonicalBaseUrl`/`transport` on production breaks mail and login links (SEC-005); and without them in the upload the release would not boot. |
