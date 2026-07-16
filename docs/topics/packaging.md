# packaging

2026-07-16

Public since 2026-07-15: the monorepo and all split repos are public on GitHub and every package (`z77/kernel`, the three modules, `z77/skeleton`, `z77/docs`) is registered on Packagist with release tags (`1.0.x`). `z77-ch/kernel` + `module-frontend/backend/dms` are the active split targets; the obsolete `z77-ch/core`, `/shared`, `/persistence` repos are archived (read-only), superseded by kernel.

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

## mental model

The monorepo `z77-ch/z77-framework` is the single development source. On every push to `main`, `split.yml` copies each source directory into its own read-only repo `z77-ch/<name>`. There are **5 split targets**: `kernel` (the foundation — Core/Shared/Persistence in one package), `module-frontend`, `module-backend`, `module-dms`, plus `docs` (the monorepo `docs/` published as `z77/docs`). Client projects consume those split repos via Composer. The split repos are never committed to directly — the monorepo is authoritative.

- The **kernel** package ships three PSR-4 roots in one `composer.json` (`Z77\Core` → `core/src/`, `Z77\Shared` → `shared/src/`, `Z77\Persistence` → `persistence/src/`). They were merged because they are functionally inseparable and mutually cyclic — see ADR-023.
- Module packages (`module-frontend`/`backend`/`dms`) each require only `z77/kernel` (`^1.0`).
- The split action (`danharrin/monorepo-split-github-action`, formerly `symplify/…`) is a **snapshot copy**, not a history-preserving subtree split — it copies the current package directory and makes one commit. It does **not** use `splitsh-lite`.
- Releases are **tagged** (`x.y.z`) in the monorepo; the tag push propagates to the split repos (see split flow) and Packagist picks it up. `extra.branch-alias` maps `dev-main` → `1.0.x-dev` so `^1.0` also resolves from `main` between releases.
- All split repos are **public** and on **Packagist** (since 2026-07-15). Client projects consume via plain `composer require z77/*` / `composer create-project z77/skeleton` — no `repositories` entry needed. Only the monorepo's own `skeleton/` uses local `path` repos, for development against the moving framework.
- `z77-ch/z77-skeleton` (Packagist `z77/skeleton`) is **not a split target** — a separate, hand-maintained repo (README + `composer.json` only, everything else is installer-generated). It is committed and tagged independently of the monorepo.
- `z77/docs` ships the documentation itself (`docs/composer.json` at the top of `docs/`, no autoload). Projects consume it as **require-dev** — offered by the installer (`offerDocsInstall()`, see [`installer.md`](installer.md)) so an AI coding assistant has the full framework context under `vendor/z77/docs`, version-matched to the code packages via the shared tags.
- Vendor namespace is `z77/*` (e.g. `z77/kernel`); GitHub org is `z77-ch`. The two intentionally differ — repo name need not equal package name for VCS repositories.

## split flow

1. Push to `main` → workflow matrix runs one job per split target (kernel + 3 modules + docs).
2. Each job clones the target repo, checks out `main`, replaces its contents with the current `packages/<pkg>/`, commits, pushes.
3. Tag push → same, plus the tag is propagated (consumable as `x.y.z` once tagging starts).

## consuming the framework

```bash
composer create-project z77/skeleton my-project   # new project (recommended entry)
composer require z77/module-frontend:^1.0         # add a package to an existing project
```

Everything resolves from Packagist; `composer require z77/module-frontend:^1.0` pulls the whole graph (`module-frontend` → `z77/kernel`).

## rules

- When creating a new `z77-ch/<pkg>` target repo → MUST push an initial commit to `main` before the first split; an empty target repo breaks the split action (`git push` of a commit-less branch fails with `src refspec main does not match any`).
- When adding a package that is required by, or requires, another `z77/*` package → MUST add `extra.branch-alias` (`dev-main` → `1.0.x-dev`) to its `composer.json`, or the `^1.0` constraints will not resolve from `dev-main`.
- When adding a namespace to the foundation → MUST add it as another PSR-4 root inside `packages/kernel/composer.json` (nested dir like `core/src/`), MUST NOT create a new base package that would re-introduce a cycle with kernel (ADR-023).
- When editing a split target repo directly → MUST NOT; the repo is read-only, changes MUST be made in `packages/<pkg>/` in the monorepo and pushed.
- When a client project consumes these packages → MUST use Packagist (public since 2026-07-15) and MUST NOT add `vcs`/`path` repository entries for `z77/*` — those were the pre-release consume paths.
- When releasing a change in `packages/` → MUST tag the monorepo (`x.y.z`) and push the tag; the workflow propagates it to the 4 split repos and Packagist picks it up. A push without a tag only updates `dev-main`.
- When changing the skeleton (`README.md` / `composer.json`) → MUST commit and tag in `z77-ch/z77-skeleton` itself (not a split target) AND mirror behaviour-relevant changes in the monorepo's `skeleton/composer.json` (dev parity).

## known issues

- **PKG-001**: don't assume the split preserves per-file history — the action is a snapshot copy, each monorepo push produces one commit in the target. The workflow uses a shallow checkout (no `splitsh-lite`, so full history is not needed).
- **ARCH-PKG-002** — resolved 2026-07-09 by ADR-023. The former circular triangle `core ↔ shared ↔ persistence` is gone: the three ship as one `z77/kernel` package, so their internal references are no longer a Composer dependency. Modules depend downward on the single foundation.
- **PKG-003** — resolved 2026-07-15 by the public release: the split repos are public, dist zipballs download without a token, the `404 from dist` warnings are gone.

## pending

- None documented.

## see also

- [`installer.md`](installer.md) — how the consumed packages are wired into a project at `composer install` time (`Z77\Core\Installer\Install`).
- [`../02-decisions/adr-023-kernel-package-core-shared-persistence.md`](../02-decisions/adr-023-kernel-package-core-shared-persistence.md) — why core/shared/persistence are one package.
