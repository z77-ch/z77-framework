# packaging

2026-07-10

The kernel rollout is live: `z77-ch/kernel` + `module-frontend/backend/dms` are the active split targets; the obsolete `z77-ch/core`, `/shared`, `/persistence` repos are archived (read-only), superseded by kernel.

## entry

1. `.github/workflows/split.yml` — GitHub Actions workflow; splits each `packages/<pkg>` into its own read-only repo `z77-ch/<pkg>`
2. `packages/kernel/composer.json` — the foundation package manifest; one package, three PSR-4 namespaces (Core/Shared/Persistence), branch-alias pattern

## file map

SOURCE=/.github/workflows/split.yml
SOURCE=/packages/kernel/composer.json
SOURCE=/packages/module-frontend/composer.json
SOURCE=/packages/module-backend/composer.json
SOURCE=/packages/module-dms/composer.json
SOURCE=/skeleton/composer.json

## mental model

The monorepo `z77-ch/z77-framework` is the single development source. On every push to `main`, `split.yml` copies each `packages/<pkg>` directory into its own read-only repo `z77-ch/<pkg>`. There are **4 split targets**: `kernel` (the foundation — Core/Shared/Persistence in one package) plus `module-frontend`, `module-backend`, `module-dms`. Client projects consume those split repos via Composer. The split repos are never committed to directly — the monorepo is authoritative.

- The **kernel** package ships three PSR-4 roots in one `composer.json` (`Z77\Core` → `core/src/`, `Z77\Shared` → `shared/src/`, `Z77\Persistence` → `persistence/src/`). They were merged because they are functionally inseparable and mutually cyclic — see ADR-023.
- Module packages (`module-frontend`/`backend`/`dms`) each require only `z77/kernel` (`^1.0`).
- The split action (`danharrin/monorepo-split-github-action`, formerly `symplify/…`) is a **snapshot copy**, not a history-preserving subtree split — it copies the current package directory and makes one commit. It does **not** use `splitsh-lite`.
- Packages are kept in `dev` (no release tags). `extra.branch-alias` maps `dev-main` → `1.0.x-dev` so the `^1.0` requires resolve from the `main` branch without a tag.
- No package is on Packagist and all split repos are **private** — deliberately, until `docs/01-handbook/` is complete (publish philosophy). Consumption today is via `repositories: [{type: vcs, url: git@github.com:z77-ch/<pkg>.git}]`; the skeleton uses local `path` repos for development.
- Vendor namespace is `z77/*` (e.g. `z77/kernel`); GitHub org is `z77-ch`. The two intentionally differ — repo name need not equal package name for VCS repositories.

## split flow

1. Push to `main` → workflow matrix runs one job per package (kernel + 3 modules).
2. Each job clones the target repo, checks out `main`, replaces its contents with the current `packages/<pkg>/`, commits, pushes.
3. Tag push → same, plus the tag is propagated (consumable as `x.y.z` once tagging starts).

## consuming the framework

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        {"type": "vcs", "url": "git@github.com:z77-ch/kernel.git"},
        {"type": "vcs", "url": "git@github.com:z77-ch/module-frontend.git"}
    ]
}
```

`composer require z77/module-frontend:^1.0` then resolves the whole graph (`module-frontend` → `z77/kernel`) from `dev-main`.

## rules

- When creating a new `z77-ch/<pkg>` target repo → MUST push an initial commit to `main` before the first split; an empty target repo breaks the split action (`git push` of a commit-less branch fails with `src refspec main does not match any`).
- When adding a package that is required by, or requires, another `z77/*` package → MUST add `extra.branch-alias` (`dev-main` → `1.0.x-dev`) to its `composer.json`, or the `^1.0` constraints will not resolve from `dev-main`.
- When adding a namespace to the foundation → MUST add it as another PSR-4 root inside `packages/kernel/composer.json` (nested dir like `core/src/`), MUST NOT create a new base package that would re-introduce a cycle with kernel (ADR-023).
- When editing a split target repo directly → MUST NOT; the repo is read-only, changes MUST be made in `packages/<pkg>/` in the monorepo and pushed.
- When a client project consumes these packages → MUST declare them as `vcs` repositories and MUST NOT expect Packagist; dist zipball download 404s on private repos without a GitHub token, Composer falls back to `git clone` (source).
- When tagging a release → MUST NOT tag before `docs/01-handbook/` is complete (publish philosophy); until then packages stay on `dev-main`.

## known issues

- **PKG-001**: don't assume the split preserves per-file history — the action is a snapshot copy, each monorepo push produces one commit in the target. The workflow uses a shallow checkout (no `splitsh-lite`, so full history is not needed).
- **ARCH-PKG-002** — resolved 2026-07-09 by ADR-023. The former circular triangle `core ↔ shared ↔ persistence` is gone: the three ship as one `z77/kernel` package, so their internal references are no longer a Composer dependency. Modules depend downward on the single foundation.
- **PKG-003**: don't consume from private split repos without a GitHub OAuth token or `prefer-source` in the client project — otherwise every install prints `404 from dist … trying from source` warnings (harmless, but noisy). Confirmed in the 2026-07-10 consume test: install still succeeds via the `git clone` (source) fallback.

## pending

- Packagist registration + making repos public — deferred until `docs/01-handbook/` is complete and the publish decision is taken.

## see also

- [`installer.md`](installer.md) — how the consumed packages are wired into a project at `composer install` time (`Z77\Core\Installer\Install`).
- [`../02-decisions/adr-023-kernel-package-core-shared-persistence.md`](../02-decisions/adr-023-kernel-package-core-shared-persistence.md) — why core/shared/persistence are one package.
