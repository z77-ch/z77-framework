# installer

2026-07-03

## entry

1. `packages/kernel/core/src/Installer/Install.php` — Composer post-install script; static `run()` is the entry point
2. `skeleton/composer.json` — project template, single source of truth for skeleton configuration
3. `packages/kernel/core/src/Config/bootstrap.default.inc.php` — default bootstrap config template

## file map

SOURCE=/packages/kernel/core/src/Installer/Install.php
SOURCE=/packages/kernel/core/res/CLAUDE.project.md
SOURCE=/packages/kernel/core/src/Config/bootstrap.default.inc.php
SOURCE=/packages/kernel/core/src/Config/moduleManager.default.inc.php
SOURCE=/packages/kernel/core/data/framework/routing/navigation.default.json
SOURCE=/packages/kernel/core/data/framework/seo/metadata.default.json
SOURCE=/skeleton/composer.json

## mental model

Runs as a Composer post-install/post-update hook. Reads `extra` config from `composer.json`, scans all installed packages for those matching `frameworkPrefix` (Z77), processes only those. For each match: project directories are created, public assets copied, config files written (regenerated every install), and data files seeded (written once — never overwritten).

- All failures throw `\RuntimeException` — no silent errors.
- `skeleton/composer.json` is the single source of truth for project configuration.
- `run()` is static (Composer requirement); creates `new self($event)` internally → instance pattern, no global state.

## entry point

```json
"scripts": {
    "post-install-cmd": ["Z77\\Core\\Installer\\Install::run"],
    "post-update-cmd":  ["Z77\\Core\\Installer\\Install::run"]
}
```

## execute() steps

| # | Method | Purpose |
|---|---|---|
| 1 | `loadConfig()` | merge `composer.json` extra with defaults |
| 2 | validate `frameworkPrefix` / `modulePrefix` | throw `\RuntimeException` if missing |
| 3 | `buildPaths()` | override paths first, vendor paths second (CE principle) |
| 4 | `copyFiles()` | `public/` entry files → project web root — **first install only** (`public/` absent; ADR-024). On update (`public/` present) instead: `reportAssetDrift()` collects the read-only changed/new asset list; rendered at the end (ADR-025) |
| 5 | `createDirectories()` | override dirs, moduleTree, logs (always) + publicAssetTree asset copy (**first install only**) |
| 6 | `writeBootstrapConfig()` | → `config/bootstrap.inc.php` |
| 7 | `writeModuleManagerConfig()` | → `config/moduleManager.inc.php` |
| 8 | `writeAuthConfig()` | → `config/auth.inc.php` — **seed-once**: skipped if it already exists (INST-CONFIG-001) |
| 9 | `writeI18nConfig()` | → `config/i18n.inc.php` — **seed-once**: skipped if it already exists (INST-CONFIG-001) |
| 10 | `writeFileFinderConfig()` | → `config/fileFinder.inc.php` |
| 11 | `writeDataFiles()` | seed `data/*.json` (skip if already exist) |
| 12 | `provisionAdmin()` | create admin (interactive) or write `SETUP_TOKEN` (non-interactive) — skip if `loginUsers.json` exists |
| 13 | `writeDebugFlag()` | create/remove `data/framework/debug.flag` per `debug` |
| 14 | `seedProjectClaudeMd()` | seed `CLAUDE.md` (project context for AI assistants) from the kernel template — **seed-once**, never overwritten |
| 15 | `renderAssetDriftNotice()` | print the collected asset drift (step 4) as ONE coloured notice (ADR-025) |
| 16 | `promptAssetDeploy()` | interactive-only, per-file, default-No deploy of drifted assets (ADR-026) |
| 17 | `offerDocsInstall()` | opt-in `z77/docs` require-dev (interactive: ask, default **Yes**; non-interactive: print the manual command) — **last output of the run** |

## frameworkPrefix filter

`getInstalledPackages()` iterates ALL installed packages (doctrine, symfony, etc.). Only namespaces starting with `frameworkPrefix` (`Z77`) are processed — everything else is silently skipped. Safe to add any third-party dependency.

## public asset installation

`createPublicAssets()` runs **only on the first install** (`public/` absent — see `## overwrite behaviour`) for **every** framework package whose vendor copy ships a `res/assets/` directory. Assets go into the **single, developer-owned tier** `public/{assetDir}/{module}` — there is no `public/assets/vendor/*` (ADR-024):

| Namespace | Vendor path (source) | Target |
|---|---|---|
| `Z77\Module\Frontend` | `vendor/z77/module-frontend/res/assets` | `public/{assetDir}/frontend` |
| `Z77\Module\Backend` | `vendor/z77/module-backend/res/assets` | `public/{assetDir}/backend` |
| `Z77\Shared` | `vendor/z77/kernel/shared/res/assets` | `public/{assetDir}/shared` |
| `Z77\Core` / `Z77\Persistence` | _no `res/assets/`_ | silently skipped |

The target dir name is derived by `deriveAssetDirName($namespace)`: 3-segment namespaces whose middle segment is `modulePrefix` (`Z77\Module\Frontend`) use the third segment; all others (`Z77\Shared`) use the second; always lowercased.

On a fresh project the installer creates the `publicAssetTree` subdirectories (`css/`, `js/`, `images/`, …) with `<*module*>` resolved to that name, then copies `vendor/{package}/res/assets/` into `public/{assetDir}/{module}/`. Once `public/` exists this step is skipped entirely — the developer owns those files and deploys new/changed framework assets themselves.

To add assets to a future framework package: create `res/assets/` in that package. No installer changes needed.

## error handling

All failures throw `\RuntimeException` — no silent errors:

| Method | Throws on |
|---|---|
| `mkDirs()` | `mkdir()` failure |
| `writeFile()` | `mkdir()` or `file_put_contents()` failure |
| `copyFiles()` | source dir missing or individual file copy failure |
| `writeDataFile()` | source file unreadable |

## data files vs config files

| Type | Path | Behaviour |
|---|---|---|
| Config (regenerate) | `config/*.inc.php` except `i18n.inc.php` / `auth.inc.php` | regenerated on every install |
| Config (seed-once) | `config/i18n.inc.php`, `config/auth.inc.php` | user-adjustable (project languages / auth policy) — written once, never overwritten (INST-CONFIG-001) |
| Data | `data/framework/**/*.json` | written once — never overwritten |

> The blanket "config regenerated on every install" holds only for framework-controlled config (`bootstrap`, `moduleManager`, `fileFinder`). `i18n.inc.php` and `auth.inc.php` are developer-adjustable, so they are seed-once — deliberately decoupled from the `debug` flag (a caching/dev switch, not an overwrite policy).

Each generated config file carries a policy note in its header (`header()` + `NOTE_REGENERATE` / `NOTE_SEED_ONCE`): regenerate-always files warn "DO NOT EDIT — configure via composer.json"; seed-once files state they are safe to edit and never overwritten.

## admin provisioning

No credential is seeded — `loginUsers.default.json` does not exist (removed in
Phase 4; the framework is open source, so anything shipped is public). Instead
`provisionAdmin()` creates the first account at install time — with role
**`superUser`** (ADR-021: the installation/DMS governor; `admin` is a normal,
grant-managed role and is never provisioned; the username stays `admin`). It is
skipped whenever `data/framework/auth/loginUsers.json` already exists (re-install /
update never touch the user store).

| Context (`io->isInteractive()`) | Action |
|---|---|
| interactive | `provisionAdminInteractive()` — username `admin`, roles `['superUser']`, hidden password prompt (twice, must match), `PasswordPolicy::evaluate()` → store JSON with `password_weak` flag, bcrypt cost 12 |
| non-interactive | `provisionSetupToken()` — write a random 32-byte hex `SETUP_TOKEN` under `data/framework/auth/` (never `public/`); no account until the token-gated `/backend/system/setup/setup` runs |

The store is written as plain JSON matching the `LoginUser` shape (snake_case) —
no DI / EntityManager boot at install time. The non-interactive path is consumed
by `SetupController` (first-run setup), which validates the token, creates the
account (also role `superUser`), and deletes the token. See [`security.md`](security.md).

## overwrite behaviour — public/ is seed-once (ADR-024)

`public/` (and `override/`) belong to the developer. The installer seeds the framework baseline
**only on the first install** — when `public/` does not exist yet:

| Situation | Behaviour |
|---|---|
| `public/` absent (fresh project) | seed the full baseline: entry files (`index.php`, `.htaccess`, favicons) + `res/assets` → `public/assets/{module}` |
| `public/` exists (any re-install / update) | **not seeded** — instead an asset-drift report (ADR-025). On an **interactive** run that report becomes an opt-in, per-file, default-No deploy prompt (ADR-026, see below); **non-interactive** runs stay read-only and touch nothing |

`copyFiles()` also skips any individual file that already exists (defensive). There is **no**
`debug`-driven overwrite and **no** unattended/"yes-to-all" force command (a blind force-copy is the
footgun that caused INST-ASSET-002). The **only** way the installer writes into an existing `public/`
is the interactive, per-file, default-No deploy prompt (ADR-026, below) — never automatically, never
in a non-interactive run. To refresh the framework baseline wholesale the developer deletes the target
file(s) — or `public/` — and re-installs, or starts a new project and migrates old data in.

`config/*.inc.php` and `data/` keep their own policies (regenerate-always / seed-once) — this
section is only about `public/`.

## asset-drift report on update (ADR-025, INST-ASSET-DIFF-001; deploy ADR-026, INST-ASSET-DEPLOY-001)

Since `public/` is seed-once, a framework update that changes `core.js` / `base.css` would
otherwise be **invisible** — the `FileFinder` keeps serving the stale deployed copy, no error. On an
update (`public/` present) the installer therefore prints a **read-only** list of framework assets
in `vendor` that differ from what is deployed in `public/`. It writes nothing — `public/` stays
developer-owned.

- `reportAssetDrift()` runs in the `else` branch of `execute()` (only when `public/` exists), after
  the "public untouched" message. It reuses `$this->publicAssetPaths` (built by `buildPaths()`) and
  `deriveAssetDirName()` to locate, per framework namespace, the vendor source `…/res/assets` and the
  deployed target `public/{assetDir}/{name}`. It only **collects** into `$assetDriftChanged` /
  `$assetDriftAdded` — it prints nothing itself.
- `collectAssetDrift()` recurses the source tree and compares each file to its public counterpart by
  `sha1`: absent in public → `+ new`; present but different hash → `~ changed`. `removed` is NOT
  reported (comparing new-vendor vs deployed-public cannot tell a dropped framework file from a
  developer-added one).
- `renderAssetDriftNotice()` is called as the **last step of `execute()`** (after "installation
  complete") and prints the collected entries as ONE notice with a solid coloured background
  (`<bg=yellow;fg=black>` Symfony Console / Composer IO inline style, lines padded to a uniform
  width). Prints nothing when there is no drift.

### opt-in per-file deploy (ADR-026)

After the notice, `promptAssetDeploy()` offers to deploy the drifted files into `public/` — this is
the **only** path by which the installer ever writes into an existing `public/`, and it amends
ADR-024/025's "never write into `public/`".

- **Interactive-only.** Runs solely when `io->isInteractive()`. Non-interactive (CI / deploy /
  `--no-interaction`) → the drift stays the read-only ADR-025 report, `public/` untouched. The notice
  itself adapts its action line ("you'll be asked per file below" vs "deploy yourself").
- **Default No.** Each file is a separate `askConfirmation(…, false)`. Blind Enter / "yes to all"
  reflex deploys nothing — an explicit per-file `y` is required.
- `+ new` → risk-free (absent in `public/`): `Deploy into public/? [y/N]`.
- `~ changed` → may be the developer's own edit or a **compiled build artefact** (CSS/JS from
  `override/…/scss`); a loud warning precedes `Overwrite public/ file? [y/N]`. Overwriting the wrong
  one is the INST-ASSET-002 footgun — hence warning + default No.
- `deployAsset()` does the copy: creates missing parent dirs, overwrites the target (intended for
  `~ changed`), throws `\RuntimeException` on failure. No "yes-to-all" flag, no `debug` auto-copy.
- The drift entries carry `src` (vendor) + `dst` (public) alongside `display`, so the deploy copies
  without re-walking the trees.
- It **cannot** distinguish a framework change from a developer edit — it lists every file whose
  deployed copy differs from the shipped one. The developer knows which they customized; the list is
  "review these", not "the framework changed these". Precision beyond that needs the manifest/version
  scheme rejected in ADR-025 (deferred).
- No manifest, nothing stored — the two on-disk trees (`vendor/…/res/assets` vs `public/assets/{name}`)
  are the whole truth. Zero maintenance.

## AI docs + project CLAUDE.md

Two pieces make a fresh project immediately workable with an AI coding assistant:

- **`seedProjectClaudeMd()`** — seeds `CLAUDE.md` into the project root from the kernel
  template `core/res/CLAUDE.project.md`. Content: docs pointer (`vendor/z77/docs`), CE
  override rules in short form, deployment note. **Seed-once**: as soon as the file
  exists it belongs to the developer and is never touched again. A missing template is
  reported (packaging defect) but does not break the install.
- **`offerDocsInstall()`** — offers the AI-optimized documentation package `z77/docs`
  (the monorepo `docs/` published as its own split package, version-matched to the
  framework) as **require-dev**, so `composer install --no-dev` deploys never carry it.
  Skipped entirely when the package is already installed or required. Interactive:
  one question, default **Yes** (deliberately inverted vs. the overwrite prompts —
  a yes only adds a dev dependency, nothing existing is touched), then a nested
  `composer require --dev z77/docs:^1.0` (same php + composer binary, rebuilt by
  `composerCommand()`). A failure is **non-fatal**: the install is already complete,
  the manual command is printed. Non-interactive: no question, no require — one hint
  line with the manual command.

## placeholders in directories config

| Placeholder | Type | Source |
|---|---|---|
| `<htmlRoot>` | static | `core-bootstrap.htmlRoot` |
| `<moduleDir>` | static | `core-bootstrap.moduleDir` |
| `<assetDir>` | static | `core-bootstrap.assetDir` |
| `<tplDir>` | static | `core-bootstrap.tplDir` |
| `<*overrideDir*>` | dynamic | `overrideDir + '/' + strtolower(frameworkPrefix)` |
| `<*module*>` | dynamic | per-package name derived from `autoload.psr-4` namespace via `deriveAssetDirName()` — covers modules AND non-module packages like `shared` |

## adding a module

Add to `autoload.psr-4` in `composer.json`, then `composer install`:

```json
"Z77\\Module\\Blog\\": ["override/z77/module/blog/src/"]
```

Installer creates the override dirs, registers the module in `moduleManager.inc.php`, and adds paths to `fileFinder.inc.php` automatically. Note (ADR-024): on an existing project (`public/` present) the new module's **public assets are NOT seeded** — they stay in `vendor` and the developer deploys them into `public/assets/{module}` (delete `public/` + re-install for a full re-seed, or copy them in).

## rules

- When editing a runtime config in `config/*.inc.php` → MUST NOT edit manually (regenerated on every `composer install`) — EXCEPT `config/i18n.inc.php` and `config/auth.inc.php`, which are seed-once and MAY be edited by the developer (the installer never overwrites them once they exist)
- When changing a data file in `data/framework/**/*.json` → MUST be aware that the installer NEVER overwrites it after first install
- When touching public asset / entry-file deployment → MUST keep `public/` seed-once (first install only, `public/` absent — ADR-024); MUST NOT add a `debug`-driven overwrite or an unattended / "yes-to-all" force command that writes into an existing `public/`. The ONE allowed write into an existing `public/` is the interactive, per-file, default-No deploy prompt (ADR-026, `promptAssetDeploy()`), which MUST stay interactive-only (`io->isInteractive()`) — non-interactive runs MUST remain read-only.
- When adding error handling in installer code → MUST throw `\RuntimeException`; MUST NOT silently swallow failures
- When changing skeleton configuration → MUST edit `skeleton/composer.json` (single source of truth)
- When seeding auth data → MUST NOT ship a working credential in any `*.default.json`; the admin is provisioned by `provisionAdmin()` (interactive prompt) or deferred via `SETUP_TOKEN` (non-interactive). MUST write the token under `data/`, never `public/`.
- When changing the project context template (`core/res/CLAUDE.project.md`) → MUST keep the seeded `CLAUDE.md` seed-once; existing projects are never overwritten (the file belongs to the developer).
- When touching the docs offer (`offerDocsInstall()`) → MUST keep the nested `composer require` interactive-only (non-interactive prints the manual command), MUST keep a failure non-fatal, and MUST keep `z77/docs` a require-dev dependency (docs never reach a `--no-dev` production deploy).

## known issues

- `Install.php` is a single large class (ARCH-C) — planned split for v1.1 (low priority).
- **INST-ASSET-001** — resolved 2026-05-17. Asset installation no longer module-only: `createPublicAssets()` now installs `res/assets/` from every framework package (modules + shared + any future non-module package). Previously the `Z77\Module\` filter silently dropped shared assets, so e.g. `packages/kernel/shared/res/assets/js/core.js` never reached `public/assets/shared/js/` via Composer install.
- **INST-ASSET-002** — resolved 2026-07-14 (ADR-024). `composer install` clobbered
  developer-owned public files: `createPublicAssets()` copied `vendor/{package}/res/assets/`
  into `public/assets/{module}/` on every install, and entry files (favicon etc.) were
  overwritten too (`debug=true` → always) — overwriting a project's compiled CSS/JS and its
  favicon (live incident 2026-07-13 on the reference project: header/mobile styling disappeared;
  `override/.../scss` untouched, a rebuild restored it). **Fix:** `public/` is seed-once —
  the installer deploys the framework baseline (entry files + `res/assets` →
  `public/assets/{module}`) **only when `public/` is absent** (first install); afterwards it
  never touches `public/`. The `debug`-driven overwrite (`shouldOverwrite()`) was removed and
  the dead `public/assets/vendor/*` tier dropped from `fileFinder` `assetPaths` (single tier).
  No force/publish command. Verified in skeleton (first install seeds; re-install leaves
  `public/` untouched; app serves). To refresh the baseline: delete `public/` + re-install, or
  new project + migrate.

- **INST-ASSET-DIFF-001** — added 2026-07-14 (ADR-025). Complements ADR-024's seed-once: on an update (`public/` present) the installer prints a **read-only** list of framework assets in `vendor` that differ from the deployed `public/` copies (`~ changed` / `+ new`, by `sha1`), so a changed `core.js` / `base.css` after `composer update` is no longer invisible. Writes nothing — `public/` stays developer-owned. On-the-fly `vendor`↔`public` diff, no manifest, nothing stored. Cannot distinguish a framework change from a developer edit (lists every differing file); precision beyond that (per-file manifest / git-tag changelog) was deliberately deferred. Methods `reportAssetDrift()` + `collectAssetDrift()`. See [`../02-decisions/adr-025-asset-drift-report-on-update.md`](../02-decisions/adr-025-asset-drift-report-on-update.md).
- **INST-ASSET-DEPLOY-001** — added 2026-07-15 (ADR-026). Turns the ADR-025 drift report into an **opt-in, per-file deploy** on **interactive** updates: after the coloured notice, `promptAssetDeploy()` asks per file whether to copy it into `public/`, **default No**. `+ new` deploys plainly; `~ changed` is preceded by a footgun warning (may be the developer's own edit / a compiled build artefact). `deployAsset()` performs the copy (mkdir parents, overwrite target, throw on failure). **Non-interactive runs stay read-only** (CI / deploy — `public/` untouched), and there is **no** "yes-to-all" / `debug` auto-copy — an explicit per-file `y` is the only write path. Amends ADR-024 §3 / ADR-025's "never write into `public/`". Verified via a reflection harness (14 checks: colour banner, new-deployed, changed-not-overwritten-on-No, default-N writes nothing, non-interactive no prompts, changed=Yes overwrites). See [`../02-decisions/adr-026-opt-in-interactive-asset-deploy.md`](../02-decisions/adr-026-opt-in-interactive-asset-deploy.md).

## pending

- ARCH-C: split into separate classes (v1.1, low priority)
- **INST-CONFIG-001** (partially resolved 2026-07-11): reassess the installer's overwrite policy before Packagist publication. Audit **every file the installer writes** (all `writeXxxConfig()` steps, `writeDataFiles()`, public asset copy, `copyFiles()`) and decide per file whether an install/update may overwrite it. Classify each target as: regenerate-always vs. seed-once (like data files) vs. merge. Blocks publication.
  - DONE: `config/i18n.inc.php` → seed-once (`writeI18nConfig()` skips if it exists). Defines the project's languages, which the developer adapts after install; an update must not clobber that.
  - DONE: `config/auth.inc.php` → seed-once (`writeAuthConfig()` skips if it exists). Holds installation-wide auth policy (e.g. `passwordTier`) the developer adapts after install.
  - DONE: `copyFiles()` (public entry files) + public asset copy → seed-once on first install only (ADR-024, INST-ASSET-002). `public/` is developer-owned; the installer never overwrites it.
  - TODO: classify the remaining framework-derived config targets — `bootstrap.inc.php`, `moduleManager.inc.php`, `fileFinder.inc.php` (regenerate-always is likely correct, but confirm each carries no developer-adjusted value before publication), plus `writeDataFiles()`.
