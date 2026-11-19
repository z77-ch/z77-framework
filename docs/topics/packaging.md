# packaging

2026-11-14

Public since 2026-07-15: the monorepo and all split repos are public on GitHub, and the split packages (`z77/kernel`, the three modules `z77/module-frontend` / `-backend` / `-dms`, `z77/docs`) plus `z77/skeleton` are registered on Packagist with release tags (`1.0.0` … `1.6.0`, the last 2026-09-11). `z77-ch/kernel` + `module-frontend/backend/dms` + `docs` are the active split targets; the obsolete `z77-ch/core`, `/shared`, `/persistence` repos are archived (read-only), superseded by kernel. The other twelve packages under `packages/` are NOT split and NOT on Packagist (see `## packages`).

## entry

1. `.github/workflows/split.yml` — GitHub Actions workflow; splits each `packages/<pkg>` into its own read-only repo `z77-ch/<pkg>`
2. `packages/kernel/composer.json` — the foundation package manifest; one package, three PSR-4 namespaces (Core/Shared/Persistence), branch-alias pattern

## file map

SOURCE=/.github/workflows/split.yml
SOURCE=/packages/kernel/composer.json
SOURCE=/docs/composer.json
SOURCE=/packages/module-frontend/composer.json
SOURCE=/packages/module-backend/composer.json
SOURCE=/packages/module-dms/composer.json
SOURCE=/skeleton/composer.json
SOURCE=/tools/build-stamp.php
SOURCE=/.releases/check.php
SOURCE=/.releases/lib.php
SOURCE=/.releases/vendor-deploy.php
SOURCE=/.releases/vendor-dev.php
SOURCE=/composer.json
SOURCE=/tests/releases-override-check.php
SOURCE=/packages/kernel/shared/src/Build/BuildInfo.php
SOURCE=/packages/module-backend/res/view/templates/partials/shell/topbar.tpl.php

## mental model

The monorepo `z77-ch/z77-framework` is the single development source. On every push to `main`, `split.yml` copies each source directory into its own read-only repo `z77-ch/<name>`. There are **5 split targets**: `kernel` (the foundation — Core/Shared/Persistence in one package), `module-frontend`, `module-backend`, `module-dms`, plus `docs` (the monorepo `docs/` published as `z77/docs`). Client projects consume those split repos via Composer. The split repos are never committed to directly — the monorepo is authoritative.

- The **kernel** package ships three PSR-4 roots in one `composer.json` (`Z77\Core` → `core/src/`, `Z77\Shared` → `shared/src/`, `Z77\Persistence` → `persistence/src/`). They were merged because they are functionally inseparable and mutually cyclic — see ADR-023.
- The split module packages (`module-frontend`/`backend`/`dms`) each require only `z77/kernel` (`^1.0`). The business modules require each other and `z77/persistence-doctrine` — none of which is split (`## packages`).
- The split action (`symplify/monorepo-split-github-action@v2.4.5`) is a **snapshot copy**, not a history-preserving subtree split — it copies the current package directory and makes one commit. It does **not** use `splitsh-lite`.
- Releases are **tagged** (`x.y.z`) in the monorepo; the tag push propagates to the split repos (see split flow) and Packagist picks it up. `extra.branch-alias` maps `dev-main` → `1.0.x-dev` so `^1.0` also resolves from `main` between releases.
- All split repos are **public** and on **Packagist** (since 2026-07-15). A project that needs only the split packages consumes them via plain `composer require z77/*` / `composer create-project z77/skeleton` — no `repositories` entry needed. A package that is not split (every business module, `persistence-doctrine`, `kibot`, `module-api`) exists only in the monorepo: a project that uses one consumes the framework through `path` repositories at a monorepo checkout (as the monorepo's own `skeleton/` does) and deploys real copies built by `.releases/vendor-deploy.php` (`## build stamp`).
- `z77-ch/z77-skeleton` (Packagist `z77/skeleton`) is **not a split target** — a separate, hand-maintained repo (README + `composer.json` only, everything else is installer-generated). It is committed and tagged independently of the monorepo.
- `z77/docs` ships the documentation itself (`docs/composer.json` at the top of `docs/`, no autoload). Projects consume it as **require-dev** — offered by the installer (`offerDocsInstall()`, see [`installer.md`](installer.md)) so an AI coding assistant has the full framework context under `vendor/z77/docs`, version-matched to the code packages via the shared tags.
- Vendor namespace is `z77/*` (e.g. `z77/kernel`); GitHub org is `z77-ch`. The two intentionally differ — repo name need not equal package name for VCS repositories.

## packages

What `packages/*` holds, as `split.yml` and each `composer.json` show it (checked 2026-11-14). Every `composer.json` carries the `dev-main` → `1.0.x-dev` branch alias.

| Package | Split repo / Packagist | Requires (z77) |
|---|---|---|
| `z77/kernel` | `z77-ch/kernel`, Packagist | — (suggests `z77/kibot`) |
| `z77/module-frontend` | `z77-ch/module-frontend`, Packagist | kernel (suggests `z77/kibot`) |
| `z77/module-backend` | `z77-ch/module-backend`, Packagist | kernel |
| `z77/module-dms` | `z77-ch/module-dms`, Packagist | kernel |
| `z77/docs` (`docs/`) | `z77-ch/docs`, Packagist | — |
| `z77/persistence-doctrine` | not split | kernel + Doctrine ORM / DBAL / Migrations, Symfony cache / console |
| `z77/kibot` | not split | — (ext-curl) |
| `z77/module-api` | not split (api.md API-006) | kernel |
| `z77/module-member` | not split | kernel |
| `z77/module-vat` | not split | kernel |
| `z77/module-contact` | not split | kernel, persistence-doctrine |
| `z77/module-mandator` | not split | kernel, persistence-doctrine |
| `z77/module-mailing` | not split | kernel, persistence-doctrine |
| `z77/module-article` | not split | kernel, persistence-doctrine, vat, mandator |
| `z77/module-financial` | not split | kernel, persistence-doctrine, vat, mandator |
| `z77/module-debtor` | not split | kernel, persistence-doctrine, vat, contact, mandator |
| `z77/module-order` | not split | kernel, persistence-doctrine, vat, contact, mandator, article, debtor |
| `z77/module-receipt` | not split | kernel, persistence-doctrine, vat, mandator, financial, dms |
| `z77/module-creditor` | not split | kernel, persistence-doctrine, contact, vat, mandator, financial, receipt, dms |

`z77/skeleton` is no package of `packages/` — a hand-maintained repo `z77-ch/z77-skeleton` (see above).

## build stamp

A deploy build copies WORKING TREES into `vendor/z77/*`. Nothing else records which state that was: for a `path` repo `composer.lock` stores a content hash, not a commit. `tools/build-stamp.php` writes that fact into **`vendor/z77/build.json`**, and `Z77\Shared\Build\BuildInfo` reads it back; the backend's service-panel footer is the one consumer today (it used to print a hard-wired `z77 · Version 1.0.0`, unmaintained for months).

- The stamp lives INSIDE `vendor/` on purpose — `vendor/` is uploaded as a whole, so the statement can never travel separately from the thing it describes. An `override/`-only upload correctly leaves it untouched.
- Shape: `built_at` (unix) plus one object per source tree — `commit` (full hash or `null`), `branch`, `dirty`, `committed_at`. Unix timestamps, so no locale or timezone parsing anywhere in the chain.
- **`dirty`** says the build holds more than the commit names; `BuildInfo::label()` renders it as a trailing `+`. Since 2026-11-01 `vendor-deploy.php` builds from the last commit (PKG-EXPORT-001) and calls the stamp with `--committed`, so it is false there. It stays load-bearing for a project still on the older script, which copies its working tree.
- **No stamp = development checkout.** There the junctions point at a tree that can differ between two requests, so there is nothing true to state: `BuildInfo::current()` returns `null` and the panel says «Entwicklung», never a stale deploy date. `vendor-dev.bat` deletes the file for exactly that reason.
- Failure is loud but never fatal: no `git`, no repository, unwritable target → `commit: null`, the writer exits 0 with a warning, the panel says «unbekannt». A missing stamp must not block a deploy, and an empty string would read as a statement.
- The stamped sources come from the project's `composer.json` path repositories (`releases_pathRepos()` → `releases_stampSources()`): every package under `<fw>/packages/*` collapses into one `framework` entry, any other path repo is its own entry — nothing hard-coded, a new path repo is stamped automatically.
- This is provenance, NOT a release number. Tags exist (`1.0.0` … `1.6.0`) but stopped on 2026-09-11; while packages ship as `dev-main` from `path` repos, the commit is the only truthful answer.
- Verified by a since-retired harness, `tests/build-info.php` (37 checks — every broken-file shape, dirty vs clean, BOM, and the writer driven against a throwaway git repository).

## split flow

1. Push to `main` → workflow matrix runs one job per split target (kernel + 3 modules + docs).
2. Each job clones the target repo, checks out `main`, replaces its contents with the current `packages/<pkg>/` (`docs/` for `docs`), commits, pushes.
3. Tag push → same, plus the tag is propagated (consumable as `x.y.z`).
4. Runs are serialised by the workflow's `concurrency` group `packages-split` (`cancel-in-progress: false`) — a branch push and a tag push queue instead of racing (PKG-004).

## consuming the framework

```bash
composer create-project z77/skeleton my-project   # new project (recommended entry)
composer require z77/module-frontend:^1.0         # add a package to an existing project
```

The split packages resolve from Packagist; `composer require z77/module-frontend:^1.0` pulls the whole graph (`module-frontend` → `z77/kernel`). A package that is not split (`## packages`) does not resolve from Packagist — it needs a `path` repository at a monorepo checkout.

### a package's public assets need an install to reach the project

A package ships its CSS/JS/images under `res/assets/`; the project SERVES them from
`public/{assetDir}/{module}`, a copy the installer publishes ([`installer.md`](installer.md)).
Changing the package alone changes nothing a browser sees.

- **In the monorepo (and any setup with `path` repositories / dev junctions)** this is the trap:
  `packages/*` is symlinked into `vendor/`, so a rebuilt `base.css` is live in `vendor/` the second
  `npm run build:backend` finishes — while `public/assets/backend/css/base.css` is still the copy
  from the last install. Nothing errors; the browser keeps serving the old file. **Run
  `composer install` in the project after changing a package's CSS or JS.**
- **Since 2026-09-23 that install republishes without asking**, as long as the deployed copy is
  still byte-identical to the one the installer wrote (publication record,
  [`installer.md`](installer.md) INST-ASSET-DIFF-001). It works in a non-interactive run too, and
  the run names what it refreshed.
- **A file the project changed itself** (a hand edit, or CSS compiled from `override/…/scss` into
  `public/`) is still never overwritten silently — it is offered per file interactively, and named
  as kept stale otherwise. That is deliberate (ADR-024/026); a project that compiles its own CSS
  into `public/` keeps owning that file and republishes it itself.

## rules

- When creating a new `z77-ch/<pkg>` target repo → MUST push an initial commit to `main` before the first split; an empty target repo breaks the split action (`git push` of a commit-less branch fails with `src refspec main does not match any`).
- When adding a package that is required by, or requires, another `z77/*` package → MUST add `extra.branch-alias` (`dev-main` → `1.0.x-dev`) to its `composer.json`, or the `^1.0` constraints will not resolve from `dev-main`.
- When adding a namespace to the foundation → MUST add it as another PSR-4 root inside `packages/kernel/composer.json` (nested dir like `core/src/`), MUST NOT create a new base package that would re-introduce a cycle with kernel (ADR-023).
- When editing a split target repo directly → MUST NOT; the repo is read-only, changes MUST be made in `packages/<pkg>/` in the monorepo and pushed.
- When a client project consumes only SPLIT packages → MUST use Packagist (public since 2026-07-15) and MUST NOT add `vcs` repository entries for them — those were the pre-release consume paths. A project that needs a package that is not split MUST take it through a `path` repository at a monorepo checkout and deploy with `.releases/vendor-deploy.php` (real copies of the last commit, `## build stamp`).
- When releasing a change in `packages/` → MUST tag the monorepo (`x.y.z`) and push the tag; the workflow propagates it to the 5 split repos and Packagist picks it up. A push without a tag only updates `dev-main`.
- When changing the skeleton (`README.md` / `composer.json`) → MUST commit and tag in `z77-ch/z77-skeleton` itself (not a split target) AND mirror behaviour-relevant changes in the monorepo's `skeleton/composer.json` (dev parity).
- When a project gains a new path-repo package → MUST deploy with the current `.releases/vendor-deploy.php`, which stamps every path repo of `composer.json` by itself; a project still on an older script with a hard-coded `tools/build-stamp.php` call MUST add it there as another `name=path` pair, or the panel stays silent about that package's state.
- When building a deployable `vendor/` → MUST run `tools/build-stamp.php` AFTER the real copies are in place, and MUST NOT hand-edit `vendor/z77/build.json` (it describes a build; an edited one describes nothing).
- When building a deployable `vendor/` → MUST export each git source's last commit (`releases_exportCommit`) and MUST publish `public/` assets again from those copies (`composer run-script post-install-cmd`); MUST NOT copy a working tree: it carries gitignored files and uncommitted changes to the server (PKG-EXPORT-001). So a change that should ship MUST be committed first.
- When restoring dev junctions → MUST delete `vendor/z77/build.json` (`vendor-dev.bat` does), or the local backend reports the last deploy's date for a working tree that has moved on.
- When displaying the stamp → MUST show the `dirty` marker with the commit and MUST NOT render an empty string for an unknown commit; `BuildInfo::label()` returns `unbekannt` for that case.
- When adding a CLI binary to a package's `bin` list → MUST run `composer update z77/<pkg>` in every consuming project; `composer install` and `vendor-deploy.bat` do NOT create the new `vendor/bin` entry (PKG-005).
- When adding a key to a framework config, a mail form to `emailConfig` or a job to a module config → a project overriding that file whole does not get it; `php .releases/check.php` names what its override lacks (PKG-OVERRIDE-001), and the project decides. MUST NOT fix an override by script: leaving a key out may be its decision, and the framework code's default then applies.
- When changing a package's `res/assets/` (CSS, JS, images) → MUST run `composer install` in every consuming project afterwards; the served copy lives in `public/{assetDir}/{module}` and a `path`-repo/junction setup makes `vendor/` look current while `public/` is stale. A hand copy from `vendor/` (what the installer itself suggests for a file it kept) is fine — the next install sees both sides in sync and adopts it into the publication record (INST-ASSET-DIFF-001).

## known issues

- **PKG-OVERRIDE-001** — built 2026-11-02 (owner, hardening H6). A config under `override/` replaces the framework's file whole, so a key, a `forms` entry or a `jobs` entry the framework gained later is silently absent there: mail recipients no form lists, a job that does not exist. `.releases/check.php` compares `override/z77/**/Config/*.inc.php` with the framework's file of that path (project `vendor/z77/`, else a path repository of its `composer.json`): top-level keys, `forms` entries of `emailConfig`, `jobs` entries of a module config under `App/Config`. Only warns, on STDOUT, before anything else, and never changes the exit code; never writes, never reaches a server (`.releases/RULES.md`). Neither file is executed: a project's config may run anything and names classes no autoloader knows there, so `releases_configKeys()` reads the tokens. Don't assume: a `return array_merge(...)` or `[...] + $x` is not compared, and says so, and the same goes for a computed `forms` or `jobs` (`$fw['forms'] + […]`, a spread): its entries are unknown, not missing; a missing key is not always a fault, the code's default applies to most of them; a config in a subdirectory of `Config` (`Ui/Config/Main/*`) is a controller's, not compared; a project's own package has no counterpart and is skipped. Verified: `tests/releases-override-check.php` (temp project, a trap that would show execution, the script end to end with exit 0, the parser against `require` on every framework config). **The master copy only:** each project keeps its own `.releases/` (see pending).
- **PKG-EXPORT-001** — resolved in the script 2026-11-01. `vendor-deploy.php` copied the working tree, so the gitignored `docs/_local` (maintainer notes, no credentials found by a pattern scan) went to the server with every release of a project that requires `z77/docs`, outside the docroot; uncommitted changes went along too, and the installer published uncommitted CSS into `public/` through the links. Now it exports the last commit, runs the installer again from the copies, and names what it left out. Verified: `tests/releases-export.php`, and a full run in a throwaway project with changed files in the framework's working tree: the build, its `public/` assets and its stamp held the commit; first real deploy the same day, stamp not dirty, nothing gitignored in `vendor/z77`. Don't assume the bytes match a Windows working tree: the export writes the stored LF while files published earlier may hold CRLF, so a byte-for-byte fence between `vendor/` and `public/` can fail without a fault, compare with line endings normalised. The copies already on the servers were removed by the owner's decision the same day (one installation had them, in every kept release). Don't assume every project has it: each project keeps its own copy of `.releases/`, see pending. A source that is no git tree is still copied as it stands, with a warning.
- **PKG-001**: don't assume the split preserves per-file history — the action is a snapshot copy, each monorepo push produces one commit in the target. The workflow uses a shallow checkout (no `splitsh-lite`, so full history is not needed).
- **ARCH-PKG-002** — resolved 2026-07-09 by ADR-023. The former circular triangle `core ↔ shared ↔ persistence` is gone: the three ship as one `z77/kernel` package, so their internal references are no longer a Composer dependency. Modules depend downward on the single foundation.
- **PKG-003** — resolved 2026-07-15 by the public release: the split repos are public, dist zipballs download without a token, the `404 from dist` warnings are gone.
- **PKG-004** — resolved in `split.yml` (first hit at release 1.2.0, measured again 2026-09-02): `git push && git push origin <tag>` started TWO split workflow runs (branch push + tag push) that raced each other into the same split repos — the loser got `cannot lock ref … but expected …`. The workflow now has a `concurrency` group (`packages-split`, `cancel-in-progress: false`), so the runs queue and each completes its propagation. Don't assume a failed run from before the fix healed itself: re-run it (`gh run rerun <id> --failed`) and check each split repo carries the tag (`gh api repos/z77-ch/{repo}/tags`).

- **PKG-005**: don't assume a new `bin` entry reaches `vendor/bin` through `composer install` or `vendor-deploy.bat` — it does not. Composer builds `vendor/bin` from the bin list recorded in `composer.lock` / `vendor/composer/installed.json`, which `install` reads instead of re-resolving. Adding `bin/z77-run` to `packages/kernel/composer.json` left a client project with the package file present (`vendor/z77/kernel/bin/z77-run`) but no proxy in `vendor/bin` — the lock still said `bin/z77-backup` only. Fix: `composer update z77/kernel` in the project, then re-run the deploy. The direct path (`php vendor/z77/kernel/bin/z77-run`) works regardless; `vendor/bin` exists so a cron line survives a package rename.
- **PKG-ANON-001** — decided 2026-11-19 (owner, release 2.0.0 decision D4): no fresh public repository, no history rewrite. The old pending (2026-11-15) asked for a FRESH repository with one initial commit because commit titles name client projects. Reason against: the monorepo is already public (since 2026-07-15) and feeds the split repos and Packagist — a rewrite or a new repository would break both and cannot be undone. Client names stay in OLD commit titles; the tree (code, docs, tests) holds none since `204c415`. Don't assume a title is clean: commits before 2026-11-19 may name clients.

## pending

- **PKG-OVERRIDE-001 / PKG-EXPORT-001: every project's `.releases/`** — owner copies `check.php`, `lib.php`, `RULES.md` and `vendor-deploy.php` into each project; until then a project's check says nothing about its overrides and it builds from the working tree, not the commit. Compared 2026-11-12 (line endings ignored): current in z77.ch and one client project; differs in z77.ch-initial (all four) and in three client projects (one `lib.php`; two `check.php`, `lib.php`, `RULES.md`).
- Owner decision: which further packages become split targets (and get a Packagist entry). `split.yml` has 5 (kernel, module-frontend, module-backend, module-dms, docs; checked 2026-11-14); not split: module-api (api.md API-006), kibot, persistence-doctrine (each with its own pending) and module-article, -contact, -debtor, -financial, -mailing, -mandator, -member, -order, -receipt, -creditor, -vat.
- **Before the public GitHub release: anonymize the tree** — code, docs and tests are clean since commit `204c415` (2026-11-19); `npm run docs:check` guards the handbook against client names. The history question is decided, see known issues PKG-ANON-001.

## see also

- [`installer.md`](installer.md) — how the consumed packages are wired into a project at `composer install` time (`Z77\Core\Installer\Install`).
- [`../02-decisions/adr-023-kernel-package-core-shared-persistence.md`](../02-decisions/adr-023-kernel-package-core-shared-persistence.md) — why core/shared/persistence are one package.
