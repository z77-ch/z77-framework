# installer

2026-09-24

## entry

1. `packages/kernel/core/src/Installer/Install.php` — Composer post-install script; static `run()` is the entry point
2. `skeleton/composer.json` — project template, single source of truth for skeleton configuration
3. `packages/kernel/core/src/Config/bootstrap.default.inc.php` — default bootstrap config template

## file map

SOURCE=/packages/kernel/core/src/Installer/Install.php
SOURCE=/packages/kernel/core/res/CLAUDE.project.md
SOURCE=/packages/kernel/core/res/htaccess-deny
SOURCE=/packages/kernel/core/cron/run.php
SOURCE=/packages/kernel/core/src/Config/bootstrap.default.inc.php
SOURCE=/packages/kernel/core/src/Config/moduleManager.default.inc.php
SOURCE=/packages/kernel/core/src/Config/systemConfig.default.inc.php
SOURCE=/packages/kernel/core/src/Config/database.default.inc.php
SOURCE=/packages/kernel/core/data/framework/routing/navigation.default.json
SOURCE=/packages/kernel/core/data/framework/seo/metadata.default.json
SOURCE=/skeleton/composer.json
SOURCE=/tests/fresh-install-setup.php
SOURCE=/tests/installer-asset-publish.php
RUNTIME=/skeleton/var/state/published-assets.json

## mental model

Runs as a Composer post-install/post-update hook. Reads `extra` config from `composer.json`, scans all installed packages for those matching `frameworkPrefix` (Z77), processes only those. For each match: project directories are created, public assets copied, config files written (regenerated every install), and data files seeded (written once — never overwritten).

- Published public files carry a **publication record** (`var/state/published-assets.json`): the sha1 each file had when the installer wrote it. It is what separates «untouched since we published it» (refreshed silently on the next install) from «edited in this project» (never written without consent) — INST-ASSET-DIFF-001, [ADR-046](../02-decisions/adr-046-publication-record-for-public-files.md). It covers `res/assets` plus the entry files `index.php` and `.htaccess`, not the branding files.
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
| 4 | `copyFiles()` | `public/` entry files → project web root — **first install only** (`public/` absent; ADR-024). On update (`public/` present) instead: `loadPublishedAssets()` + `reportAssetDrift()` sort the shipped assets into refreshable / changed / new (ADR-025) |
| 4b | `reportEntryFileDrift()` | update only: the same classification for `public/index.php` and `public/.htaccess` (INST-ASSET-ENTRY-001) |
| 4c | `deployUndisputedAssets()` | update only: write every file still byte-identical to the publication record, plus every file that is absent AND unrecorded — **no prompt, interactive and non-interactive alike** (INST-ASSET-DIFF-001). Saves the record in a `finally`, so an abort mid-loop still records what was written |
| 5 | `createDirectories()` | override dirs, moduleTree, logs (always) + publicAssetTree asset copy (**first install only**; every copied file enters the publication record) |
| 6 | `seedCronEntry()` | seed `cron/run.php` from the kernel template — **seed-once**: the cron entry for hosts whose panel takes one command and no `cd` (the starter `chdir()`s into the project and hands over to `vendor/bin/z77-run`), see [`jobs.md`](jobs.md) |
| 6b | `migrateConfigSplit()` | one-time flat→split migration (ADR-036): flat generated files deleted (rewritten below), flat seed-once files RENAMED into `config/client/` so hand edits survive; no-op on a split layout |
| 7 | `writeBootstrapConfig()` | → `config/vendor/bootstrap.inc.php` (ADR-036: generated tier) |
| 8 | `writeModuleManagerConfig()` | → `config/vendor/moduleManager.inc.php` |
| 9 | `writeAuthConfig()` | → `config/client/auth.inc.php` — **seed-once**: skipped if it already exists (INST-CONFIG-001) |
| 10 | `writeI18nConfig()` | → `config/client/i18n.inc.php` — **seed-once**: skipped if it already exists (INST-CONFIG-001) |
| 11 | `writeBackupConfig()` | → `config/client/backup.inc.php` — **seed-once**: backup policy (retention, excludes, dump settings), see [`backup.md`](backup.md) |
| 12 | `writeMailConfig()` | → `config/client/mail.inc.php` — **seed-once**: mail transport + sender identity (`enabled=true`, `transport='mail'`, empty `fromAddress` to fill per project), see [`mail.md`](mail.md) |
| 13 | `writeSystemConfig()` | → `config/client/systemConfig.inc.php` — **seed-once**: installation identity (`canonicalBaseUrl`, `baseCurrency`), NOT fed from `composer.json` (ADR-030). `canonicalBaseUrl` is seeded EMPTY (no default is possible): the setup and the backend work without it, frontend pages and mail links answer 500 until it is set (INST-FRESH-001) |
| 13b | `writeDatabaseConfig()` | → `config/client/database.inc.php` — **seed-once**: the ONE database connection (host, port, name, user, password; empty `name` = no database), read by the Doctrine driver and the `db` backup; like systemConfig NOT fed from `composer.json` (ADR-039 decision 4), see [`persistence-doctrine.md`](persistence-doctrine.md). Seeded `host` = `localhost` (Unix socket on Linux; on Windows set `127.0.0.1` by hand — DOCTRINE-HOST-001) |
| 14 | `writeFileFinderConfig()` | → `config/vendor/fileFinder.inc.php` |
| 15 | `writeDataFiles()` | seed `data/*.json` from EVERY installed framework package's data roots (skip if already exist; INST-SEED-001) |
| 16 | `provisionAdmin()` | create admin (interactive) or write `SETUP_TOKEN` (non-interactive) — skip if `backendUsers.json` exists |
| 17 | `writeDebugFlag()` | create/remove `var/state/debug.flag` per `debug` (release-local, ADR-035; creates `var/state/` if missing) |
| 18 | `seedDenyFiles()` | seed a deny `.htaccess` (`Require all denied`, from `core/res/htaccess-deny`) into `data/`, `config/`, `logs/` — **seed-once**, existing files never touched, missing dirs skipped (INST-DENY-001) |
| 19 | `seedProjectClaudeMd()` | seed `CLAUDE.md` (project context for AI assistants) from the kernel template — **seed-once**, never overwritten |
| 19b | `renderAssetWriteNotice()` | name every file written in step 4c (plain lines — it needed no decision, but a deploy log must show it) |
| 20 | `renderAssetDriftNotice()` | the ONE list of the files that still need a DECISION, as a coloured notice (ADR-025) — with the reason per file, in BOTH run modes; non-interactively it carries the closing guidance line |
| 21 | `promptAssetDeploy()` | **interactive only**: per-file, default-No (ADR-026). Non-interactive: does nothing — the notice above was the report, so no file is named twice |
| 21b | `savePublishedAssets()` | write `var/state/published-assets.json` when anything was published this run |
| 22 | `offerDocsInstall()` | opt-in `z77/docs` require-dev (interactive: ask, default **Yes**; non-interactive: print the manual command) — **last output of the run** |

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

On a fresh project the installer creates the `publicAssetTree` subdirectories (`css/`, `js/`, `images/`, …) with `<*module*>` resolved to that name, then copies `vendor/{package}/res/assets/` into `public/{assetDir}/{module}/` — and writes the sha1 of every copied file into the publication record. Once `public/` exists this step is skipped entirely; from then on the update path (`reportAssetDrift()` + `deployUndisputedAssets()`) decides per file, and only a file that still matches the record, or that `public/` never had, is written.

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
| Config (regenerate) | `config/vendor/bootstrap.inc.php`, `config/vendor/moduleManager.inc.php`, `config/vendor/fileFinder.inc.php` | regenerated on every install; RELEASE-owned (ADR-036) — a function of `composer.json` + `vendor/`, rides with the release upload |
| Config (seed-once) | `config/client/i18n.inc.php`, `auth`, `backup`, `mail`, `systemConfig`, `database` (and hand-created `geoip`) | user-adjustable — written once, never overwritten (INST-CONFIG-001); INSTALLATION-owned (ADR-036) — in the release layout `config/client` is a symlink into `shared/` |
| Data | `data/framework/**/*.json` | written once — never overwritten |

> The blanket "config regenerated on every install" holds only for framework-controlled config (`bootstrap`, `moduleManager`, `fileFinder`) — those are fed from `composer.json`. Everything a developer or operator adjusts is seed-once. `systemConfig.inc.php` (ADR-030) is the strongest case: it holds what differs per INSTALLATION, so it is the one config file that is deliberately not fed from `composer.json` at all — that file is committed, and staging and production could then not differ.

Each generated config file carries a policy note in its header (`header()` + `NOTE_REGENERATE` / `NOTE_SEED_ONCE`): regenerate-always files warn "DO NOT EDIT — configure via composer.json"; seed-once files state they are safe to edit and never overwritten.

## admin provisioning

No credential is seeded — `backendUsers.default.json` does not exist (removed in
Phase 4; the framework is open source, so anything shipped is public). Instead
`provisionAdmin()` creates the first account at install time — with role
**`superUser`** (ADR-021: the installation/DMS governor; `admin` is a normal,
grant-managed role and is never provisioned; the username stays `admin`). It is
skipped whenever `data/framework/auth/backendUsers.json` already exists (re-install /
update never touch the user store).

| Context (`io->isInteractive()`) | Action |
|---|---|
| interactive | `provisionAdminInteractive()` — username `admin`, roles `['superUser']`, hidden password prompt (twice, must match), `PasswordPolicy::evaluate()` → store JSON with `password_weak` flag, bcrypt cost 12 |
| non-interactive | `provisionSetupToken()` — write a random 32-byte hex `SETUP_TOKEN` under `data/framework/auth/` (never `public/`); no account until the token-gated `/backend/system/setup/setup` runs |

The store is written as plain JSON matching the `BackendUser` shape (snake_case) —
no DI / EntityManager boot at install time. The non-interactive path is consumed
by `SetupController` (first-run setup), which validates the token, creates the
account (also role `superUser`), and deletes the token. See [`security.md`](security.md).

## overwrite behaviour — public/ is seed-once (ADR-024)

`public/` (and `override/`) belong to the developer. The installer seeds the framework baseline
**only on the first install** — when `public/` does not exist yet:

| Situation | Behaviour |
|---|---|
| `public/` absent (fresh project) | seed the full baseline: entry files (`index.php`, `.htaccess`, favicons) + `res/assets` → `public/assets/{module}` |
| `public/` exists (any re-install / update) | **not seeded wholesale.** Per file (assets + `index.php` / `.htaccess`): identical to the publication record, or absent and unrecorded → **written silently** (INST-ASSET-DIFF-001); differs from the record, or no record → kept, offered per file on an interactive run (ADR-026) and named on a non-interactive one; absent but recorded → reported as removed here, never re-created. The branding files (favicons, `site.webmanifest`) are never touched |

`copyFiles()` also skips any individual file that already exists (defensive). There is **no**
`debug`-driven overwrite and **no** unattended/"yes-to-all" force command (a blind force-copy is the
footgun that caused INST-ASSET-002). The installer writes into an existing `public/` on exactly two
paths: the automatic refresh of an asset that is **byte-identical to the copy the installer itself
published** (no developer work can be at stake — that is the whole point of the record), and the
interactive, per-file, default-No deploy prompt (ADR-026). Everything else stays. To refresh the
framework baseline wholesale the developer deletes the target file(s) — or `public/` — and
re-installs, or starts a new project and migrates old data in.

`config/*.inc.php` and `data/` keep their own policies (regenerate-always / seed-once) — this
section is only about `public/`.

## publication record for published public files (INST-ASSET-DIFF-001, ADR-046)

`var/state/published-assets.json` — project-relative path → the sha1 the file had **when the
installer wrote it**:

```json
{
  "public/.htaccess":                   "1d4e…",
  "public/index.php":                   "9b20…",
  "public/assets/backend/css/base.css": "6f1c…",
  "public/assets/shared/js/core.js":    "a03b…"
}
```

- **Covers** every `res/assets` file plus the two framework-owned entry files `index.php` and
  `.htaccess`. NOT the branding files that share that directory (favicons, `site.webmanifest`) —
  see INST-ASSET-ENTRY-001 under `## known issues`.
- **Written by every path that publishes a file**: the first install (`createPublicAssets()` →
  `copyFiles(..., record: true)`, plus `recordEntryFiles()`), the automatic write and the
  interactive deploy (both through `deployAsset()`), and the adoption below — all via
  `recordPublishedAsset()` / `savePublishedAssets()`.
- **Where it lives and why.** Release-local runtime state under `var/state/` (ADR-035), next to
  `debug.flag` / `noindex.flag`: a fixed path, not a config key, gitignored, never uploaded by a
  deploy. It describes THIS installation's `public/` against THIS release's `vendor/` — exactly the
  ADR-035 criterion for release-local. It is **not** in `shared/`: it is derived state, rebuildable,
  and must never outlive the tree it describes.
- **It is a record, not a manifest.** It does not describe what the framework ships (that is
  `vendor/` itself, read live) — only what WE last wrote into `public/`. Nothing to maintain by
  hand, nothing to ship, nothing to version.
- **Missing, malformed or stale → never a silent write.** No file, unreadable file, broken JSON, or
  no entry for a given file → it counts as `unrecorded`: kept, never written silently, reported with
  «no publication record — provenance unknown». The record only ever *adds* confidence.
- **In sync ⇒ ours: the adoption rule, and the self-healing.** Whenever `public/` and `vendor/`
  hold the SAME bytes and the record does not already say so, the record is corrected — a missing
  entry and a WRONG entry are the same case. Both sides agree right now; that is a statement about
  the present, not a guess about the past, and nothing is written into `public/`. Two things follow:
  an installation from before the record needs no manual step, and a wrong entry cannot freeze a
  file. Without the second half, one aborted run, one hand copy from `vendor/` (which the installer
  itself advises) or one interactive `y` would leave a hash that never matches again — the file
  would count as «edited» at every future framework change and never be refreshed.
- **Crash-safe and atomic.** Both write loops save the record in a `finally`, so a copy that throws
  mid-way still leaves the record describing what was already written; `savePublishedAssets()`
  writes a `.tmp` file and `rename()`s it, so an interrupted write cannot leave a truncated record
  (which would read back as «unknown» for every file below the cut).
- **It only grows.** An entry for a file the framework no longer ships stays. Pruning would mean
  trusting one run's view of `vendor/` to decide that a file is gone for good — a disabled module,
  a half-installed tree or a renamed package would each delete entries that are still true. A sha1
  per path is cheap; a wrong deletion is not.
- **Deleting it is safe**: in-sync files adopt a record again on the next install, and the
  currently-differing ones cost one round of prompts.

## file handling on update (ADR-025/026, INST-ASSET-DIFF-001, ADR-046)

Since `public/` is seed-once, a framework update that changes `core.js` / `base.css` would otherwise
be **invisible** — the `FileFinder` keeps serving the stale deployed copy, no error. On an update
(`public/` present) `classifyPublishedFile()` compares THREE values per file — the shipped file, the
deployed copy, and what the record says we last wrote — and sorts it into one of five outcomes:

| Outcome | Condition | What happens |
|---|---|---|
| in sync | deployed copy identical to `vendor` | nothing to write; the record adopts/corrects the hash if it does not already match (see the adoption rule above) |
| `↻ refreshed` | differs from `vendor`, but sha1 **equals the record** | written immediately, **no prompt, in every run mode**; named afterwards |
| `+ published` | absent in `public/` **and unrecorded** | genuinely new — nobody can have edited what never existed here, so it is written unattended and recorded |
| `− removed here` | absent in `public/` but **recorded** | WE published it and it is gone: someone deleted it in this project. Never re-created on its own; reported, and asked once on an interactive run |
| `~ kept` | present, differs from the record (`edited`) or has no record (`unrecorded`) | never written on its own. Interactive: warned + asked, default No. Non-interactive: named, with the reason |

A framework file that vanished from `vendor/` is still NOT reported: comparing new-vendor against
deployed-public cannot tell a dropped framework file from a developer-added one.

- `reportAssetDrift()` runs in the `else` branch of `execute()` (only when `public/` exists). It
  reuses `$this->publicAssetPaths` (built by `buildPaths()`) and `deriveAssetDirName()` to locate,
  per framework namespace, the vendor source `…/res/assets` and the deployed target
  `public/{assetDir}/{name}`; `reportEntryFileDrift()` does the same for the two entry files. Both
  only **collect** — they print nothing.
- `collectAssetDrift()` recurses the source tree and hands each file to `classifyPublishedFile()`;
  the entries carry `src` (vendor) + `dst` (public) alongside `display`, and a `~ kept` entry
  additionally carries `reason` (`edited` / `unrecorded`).
- `deployUndisputedAssets()` performs the `↻ refreshed` + `+ published` writes right there — before
  the rest of the install — and saves the record in a `finally`.
- `renderAssetWriteNotice()` prints those files as plain lines; they needed no decision, but a
  deploy log must still show what changed under `public/`.
- `renderAssetDriftNotice()` is called near the **end of `execute()`** (after "installation
  complete") and prints what still needs a decision, as ONE notice with a solid coloured background
  (`<bg=yellow;fg=black>` Symfony Console / Composer IO inline style, lines padded to a uniform
  width). It is the **only** list of those files, in both run modes — non-interactively it carries
  the closing guidance line, so nothing is printed twice. Prints nothing when there is nothing to
  decide.

### opt-in per-file deploy (ADR-026)

After the notice, `promptAssetDeploy()` offers to write the still-undecided files into `public/`.

- **Interactive only.** In a non-interactive run (CI / deploy / `--no-interaction`) it returns
  immediately: the notice above already named every file and why it stays. Silence was the defect —
  «Asset deploy: nothing written» named nothing, and a stale `base.css` survived hours of installs
  unnoticed.
- **Default No.** Each file is a separate `askConfirmation(…, false)`. Blind Enter / "yes to all"
  reflex writes nothing — an explicit per-file `y` is required.
- `~ kept` → may be the developer's own edit or a **compiled build artefact** (CSS/JS from
  `override/…/scss`); the reason line plus a loud warning precedes `Overwrite public/ file? [y/N]`.
  Overwriting the wrong one is the INST-ASSET-002 footgun — hence warning + default No.
- `− removed here` → **not** a risk-free copy: the file was published here and deleted since, so
  restoring it undoes that deletion. Said plainly, then `Restore into public/? [y/N]`.
- `deployAsset()` does the copy: creates missing parent dirs, overwrites the target, enters the file
  into the publication record, throws `\RuntimeException` on failure. No "yes-to-all" flag, no
  `debug` auto-copy.
- What it still **cannot** tell: whether a `~ kept` file the record does not know was customized or
  merely hand-deployed. That is the `unrecorded` case, and it is reported as such — until the file
  happens to be in sync once, at which point the adoption rule settles it.

## deny .htaccess seed (INST-DENY-001)

`seedDenyFiles()` seeds `core/res/htaccess-deny` (`Require all denied`) as `.htaccess` into
`data/`, `config/` and `logs/` — the stores that must never be web-reachable. In the correct
layout (document root = `public/`) Apache never reads these files; the day a panel
misconfiguration or a project unpacked straight into htdocs puts the project root into the
web, they turn `data/framework/auth/SETUP_TOKEN` (admin takeover), the password hashes and
the mail credentials from URLs into 403s. Same alarm-not-fault philosophy as
`.releases/htaccess-deny` (the release-layout twin, written into `shared/` stores by
`deploy.php`/`switch.php`) — keep the functional blocks of the two files identical.

- **Seed-once** — an existing `.htaccess` in any of the three stores is never touched.
- **Never into a store served through `public/`** — a deny file inside `public/media`
  (= `shared/media` in the release layout) would 403 every image. The fixed `DENY_DIRS`
  list contains no such store; keep it that way.
- Only effective where `.htaccess` is honoured (Apache + `AllowOverride`); on nginx or
  `AllowOverride None` the correct document root remains the only defence.
- A missing directory is skipped (`logs/` is config-driven); a missing template is a
  reported packaging defect, not a fatal error.

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

- When editing a runtime config in `config/*.inc.php` → MUST NOT edit manually (regenerated on every `composer install`) — EXCEPT the seed-once files in `config/client/` (`i18n`, `auth`, `backup`, `mail`, `systemConfig`, `database`), which MAY be edited by the developer (the installer never overwrites them once they exist)
- When changing a data file in `data/framework/**/*.json` → MUST be aware that the installer NEVER overwrites it after first install
- When touching public file deployment → MUST keep `public/` seed-once for everything the installer did not itself publish (ADR-024); MUST NOT add a `debug`-driven overwrite or an unattended / "yes-to-all" force command. Exactly TWO unattended writes into an existing `public/` are allowed (ADR-046), both from `deployUndisputedAssets()`: a file whose sha1 still equals the publication record, and a file that is absent AND unrecorded. Everything else MUST go through the interactive, per-file, default-No prompt (ADR-026, `promptAssetDeploy()`), which MUST stay interactive-only (`io->isInteractive()`).
- When a public file is written by the installer → MUST be entered into the publication record (`recordPublishedAsset()`), otherwise the next install treats it as a foreign edit and stops refreshing it.
- When `public/` and `vendor/` hold the same bytes → the record MUST be corrected whenever it says something else, missing entry and WRONG entry alike (`classifyPublishedFile()`); MUST NOT make the adoption conditional on the entry being absent — a stale hash would then never heal and would freeze the file as "edited" forever.
- When writing files into `public/` in a loop → MUST save the record in a `finally`; MUST NOT save only after the loop (an abort would leave files on disk that the record does not know).
- When writing the record → MUST write a temp file and `rename()` it; MUST NOT write in place (a truncated record reads back as "provenance unknown" for every file below the cut).
- When a record entry names a file the framework no longer ships → MUST leave it; MUST NOT prune the record from one run's view of `vendor/`.
- When a file is absent in `public/` → MUST consult the record before writing: unrecorded = genuinely new (write it), recorded = published here and deleted since (report as removed, MUST NOT re-create it on its own, and MUST NOT call restoring it risk-free).
- When a run is non-interactive → MUST NAME every file that keeps its current state, with the reason, in the drift notice; MUST NOT end with a bare "nothing written", and MUST NOT list the same file twice (INST-ASSET-DIFF-001).
- When choosing which entry files the record covers → MUST keep `RECORDED_ENTRY_FILES` to framework-owned code (`index.php`, `.htaccess`); MUST NOT add the branding files (favicons, `site.webmanifest`), which nearly every project replaces and which would then stand in every install log forever (INST-ASSET-ENTRY-001).
- When adding error handling in installer code → MUST throw `\RuntimeException`; MUST NOT silently swallow failures
- When changing skeleton configuration → MUST edit `skeleton/composer.json` (single source of truth)
- When seeding auth data → MUST NOT ship a working credential in any `*.default.json`; the admin is provisioned by `provisionAdmin()` (interactive prompt) or deferred via `SETUP_TOKEN` (non-interactive). MUST write the token under `data/`, never `public/`.
- When changing the project context template (`core/res/CLAUDE.project.md`) → MUST keep the seeded `CLAUDE.md` seed-once; existing projects are never overwritten (the file belongs to the developer).
- When touching the docs offer (`offerDocsInstall()`) → MUST keep the nested `composer require` interactive-only (non-interactive prints the manual command), MUST keep a failure non-fatal, and MUST keep `z77/docs` a require-dev dependency (docs never reach a `--no-dev` production deploy).
- When touching the deny seed (`seedDenyFiles()` / `DENY_DIRS`) → MUST keep it seed-once, MUST NOT add a store served through `public/` (a deny file in `public/media` 403s every image — the measured axo3 incident), and MUST keep `core/res/htaccess-deny` functionally identical to `.releases/htaccess-deny`.

## known issues

- **INST-FRESH-001** — resolved 2026-09-22 (P2 exit check, findings S1–S3). Don't assume a fresh install needs no hand edit before it is fully usable: two seed-once values are deliberately left for the installation. (1) `canonicalBaseUrl` is empty — until 2026-09-22 that took down even `/backend/system/setup/setup`, and with status 200 (fixed in the framework: [`bootstrap.md`](bootstrap.md) BOOT-SETUP-001, BOOT-ERR-001). Now the setup and the backend run; frontend pages and mail links answer 500 until it is set. Where it is named: the installer prints one line naming `config/client/systemConfig.inc.php` and `canonicalBaseUrl` at the end of every run while the value is empty (`reportMissingCanonicalBaseUrl()`, seed-once file only read; checked by `tests/fresh-install-setup.php` through the static `canonicalBaseUrlNotice()`), and the backend Störer names it — but only AFTER login: the setup page and `/login` show no banner. (2) The database `host` is `localhost` — right on Linux (socket), ~2 s per request on Windows against a MariaDB bound to `127.0.0.1` ([`persistence-doctrine.md`](persistence-doctrine.md) DOCTRINE-HOST-001). Both files are seed-once: any change to a seed reaches only NEW installations; an existing installation keeps its file.
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

- **INST-ASSET-DIFF-001** — added 2026-07-14 (ADR-025), **resolved 2026-09-24**. v1 was a read-only `vendor`↔`public` diff that stored nothing and therefore could not tell a framework change from a developer edit; it listed every differing file and asked per file (ADR-026), default No. Don't assume that was harmless: a **non-interactive `composer install` answers No to every prompt**, so a stale published copy survived every install and the run ended with «Asset deploy: nothing written» — naming nothing. Measured on z77.ch (P2 exit check): the packages are symlinked path repositories, so commit 29cda26's new backend CSS classes were live in `vendor/` at once while `public/assets/backend/css/base.css` stayed hours old — the one-line journal form rendered as a vertical stack, with no error anywhere. **Fix ([ADR-046](../02-decisions/adr-046-publication-record-for-public-files.md)):** a publication record (`var/state/published-assets.json`, see the section above) stores the sha1 of every file the installer writes into `public/`. An update then separates «still identical to what we published» and «never published here» → written **without a prompt, in every run mode** (`deployUndisputedAssets()`) from «edited here» / «no record» → kept, and named once with the reason. The protecting intent of ADR-024/026 is untouched: a file the project changed is still never overwritten without an explicit `y`. Verified by `tests/installer-asset-publish.php` (53 checks). Reviewed independently 2026-09-24; the review's findings are built in (self-healing adoption, `finally` + atomic record write, new vs. removed-here, one list per run, entry files). See [`../02-decisions/adr-025-asset-drift-report-on-update.md`](../02-decisions/adr-025-asset-drift-report-on-update.md).
- **INST-ASSET-DEPLOY-001** — added 2026-07-15 (ADR-026), amended 2026-09-23 by INST-ASSET-DIFF-001 (a file identical to the publication record is refreshed without a prompt; a non-interactive run now NAMES what it kept instead of saying nothing). Turns the ADR-025 drift report into an **opt-in, per-file deploy** on **interactive** updates: after the coloured notice, `promptAssetDeploy()` asks per file whether to copy it into `public/`, **default No**. `+ new` deploys plainly; `~ changed` is preceded by a footgun warning (may be the developer's own edit / a compiled build artefact). `deployAsset()` performs the copy (mkdir parents, overwrite target, throw on failure). **Non-interactive runs stay read-only** (CI / deploy — `public/` untouched), and there is **no** "yes-to-all" / `debug` auto-copy — an explicit per-file `y` is the only write path. Amends ADR-024 §3 / ADR-025's "never write into `public/`". Verified via a reflection harness (14 checks: colour banner, new-deployed, changed-not-overwritten-on-No, default-N writes nothing, non-interactive no prompts, changed=Yes overwrites). See [`../02-decisions/adr-026-opt-in-interactive-asset-deploy.md`](../02-decisions/adr-026-opt-in-interactive-asset-deploy.md).

- **INST-ASSET-ENTRY-001** — resolved 2026-09-24. Don't assume the entry files are outside the
  record: `public/index.php` and `public/.htaccess` are framework-owned code that used to be
  copied on the first install and never looked at again — not even reported — so a changed
  `index.php` reached no existing project and nobody was told. They now run through the same
  classifier as the assets (`RECORDED_ENTRY_FILES`, `reportEntryFileDrift()`,
  `recordEntryFiles()`). The branding files beside them (`favicon.ico`, `favicon.svg`,
  `favicon-96x96.png`, `apple-touch-icon.png`, `web-app-manifest-*.png`, `site.webmanifest`)
  are deliberately NOT covered: nearly every project replaces them, so they would differ from
  the shipped ones forever and stand in every single install log — permanent noise for files
  that change once a year. They stay first-install-only and are never touched again.
- **INST-ASSET-CRLF-001** — noted 2026-09-24, not built for. The record compares bytes, and
  today that is safe because the packages arrive as `path` repositories / dev junctions (the
  working tree itself). The day a project installs them from Packagist as dist archives, the
  shipped text files carry LF while a Windows checkout of `public/` may hold CRLF — every
  text asset would then differ from its published copy, count as `edited`, and never be
  refreshed again (the `~ kept` list would name every CSS and JS file on every install). Do
  not pre-build a normalisation: it would have to guess which files are text, and a normalising
  compare could call two genuinely different files equal. Revisit when the first project
  consumes `z77/*` from Packagist — the likely answer is a `.gitattributes` in the skeleton
  that keeps `public/` at LF, not a change in the installer.

## pending

- ARCH-C: split into separate classes (v1.1, low priority)
- Audited with INST-ASSET-DIFF-001 and deliberately left alone: `data/**/*.json` and the
  seed-once `config/client/*` files (seed-once BY DESIGN — they hold what the project entered;
  the record-and-refresh answer would be wrong there, the merge answer is the backend import,
  ADR-032), the generated `config/vendor/*` (regenerated every run — cannot go stale),
  `cron/run.php`, the deny `.htaccess` files and the project `CLAUDE.md` (seed-once,
  developer-owned, a framework change there is a manual migration note, not an install-time
  write).
- **INST-CONFIG-001** (partially resolved 2026-07-11): reassess the installer's overwrite policy before Packagist publication. Audit **every file the installer writes** (all `writeXxxConfig()` steps, `writeDataFiles()`, public asset copy, `copyFiles()`) and decide per file whether an install/update may overwrite it. Classify each target as: regenerate-always vs. seed-once (like data files) vs. merge. Blocks publication.
  - DONE: `config/client/i18n.inc.php` → seed-once (`writeI18nConfig()` skips if it exists). Defines the project's languages, which the developer adapts after install; an update must not clobber that.
  - DONE: `config/client/auth.inc.php` → seed-once (`writeAuthConfig()` skips if it exists). Holds installation-wide auth policy (e.g. `passwordTier`) the developer adapts after install.
  - DONE: `copyFiles()` (public entry files) + public asset copy → seed-once on first install only (ADR-024, INST-ASSET-002). `public/` is developer-owned; the installer never overwrites it.
  - TODO: classify the remaining framework-derived config targets — `bootstrap.inc.php`, `moduleManager.inc.php`, `fileFinder.inc.php` (regenerate-always is likely correct, but confirm each carries no developer-adjusted value before publication).
  - DECIDED 2026-08-08: `writeDataFiles()` stays **seed-once at file level** — the installer never merges records into an existing runtime file. The `merge` class is served by a separate, manual data import in the backend (ADR-032). Consistent with ADR-024/025: the installer reports, the developer decides.

- **INST-SEED-001** — resolved 2026-08-08. `writeDataFiles()` now walks **every installed framework package**: data roots are derived from each package's framework psr-4 paths via the same `stripSrc` logic `buildPaths()` uses (`core/src` → `{install}/core/data`, `src` → `{install}/data`; new helper `frameworkDataRoots()`, install paths from Composer's `InstallationManager`, deduped, metapackages skipped). Previously only the kernel's own `core/data` was scanned, so module seeds never reached a project — concretely `packages/module-dms/data/documents/folders.default.json` (the DMS Drive root). On a rel-path collision across packages the first wins; seed-once protects existing runtime data either way. Verified in the skeleton: removed `data/documents/folders.json` → `composer install` seeds it from module-dms (Drive root, `key: "drive"`, `system: true`); re-install skips everything (0 writes).

- **INST-IMPORT-001** — resolved 2026-08-08 (ADR-032 phases 1–6 built). The record-level `merge`
  answer is the backend data import: `/backend/service/import/list` (SUPER_USER), core in
  `Z77\Shared\Import`. Single source of truth from here: [`import.md`](import.md). Remaining
  v2 work (mysqldump reader, `ImportMapping`, upload form) is tracked THERE, not here.
