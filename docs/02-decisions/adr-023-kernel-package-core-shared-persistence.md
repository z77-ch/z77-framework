# ADR-023 — Kernel package: core / shared / persistence as one Composer package, three namespaces

**Status:** `[APPROVED]`
**Date:** 2026-07-09

---

## Context

Splitting the monorepo into per-package repos (see [`../topics/packaging.md`](../topics/packaging.md))
surfaced that `core`, `shared` and `persistence` cannot be installed as an ordered
dependency graph: they form a fully circular triangle. Measured distinct
cross-package symbol references (2026-07-09):

```
FROM \ TO      core    shared    persistence
core            —       19          3
shared          7        —          3
persistence     2        3          —
```

Every pair is cyclic, not just the triangle as a whole:

- `core ↔ shared` (19 / 7)
- `core ↔ persistence` (3 / 2)
- `shared ↔ persistence` (3 / 3)

This is not a broken layering to straighten out — the three are **functionally
inseparable**:

- **Login** — `AuthService` / `CurrentUserService` / `PasswordPolicy` live in `shared`,
  but need `SessionManager` / `DI` / `ControllerHandler` from `core` and
  `LoginUserRepository` from `persistence`.
- **Save** — `UnifiedEntityManager` / `EntityValidator` live in `persistence`, but
  need `Attributes\Entity` / `Naming` from `shared` and `DI` / `CacheManager` from `core`.

The three are one foundation artificially cut into three Composer packages. A
partial merge does not help: merging any two leaves the pair `(merged) ↔ third`
still cyclic.

The three names describe **aspects of one kernel**, not layers of a stack:

- **`core` = boot** — the runtime that starts and wires the framework.
- **`shared` = platform for all** — the common base every module builds on.
- **`persistence` = data storage** — reading and writing data.

Boot starts the platform; the platform stores through persistence; persistence
uses platform primitives — the cycle is inherent to the kernel's nature.

## Decision

Ship `core`, `shared` and `persistence` as **one Composer package** (`z77/kernel`)
that exposes **three unchanged PSR-4 namespaces** `Z77\Core`, `Z77\Shared`,
`Z77\Persistence`. The conceptual separation (boot / platform / storage) is kept as
namespace + directory structure **inside** the package; the internal references
between them are no longer a Composer concern, so the cycle disappears by definition.

Target monorepo structure:

```text
packages/kernel/
  composer.json            # name z77/kernel, three psr-4 roots
  core/        src/ …      # Z77\Core        (boot)
  shared/      src/ res/ … # Z77\Shared      (platform)
  persistence/ src/ …      # Z77\Persistence (storage)
```

```json
"autoload": {
    "psr-4": {
        "Z77\\Core\\":        "core/src/",
        "Z77\\Shared\\":      "shared/src/",
        "Z77\\Persistence\\": "persistence/src/"
    }
}
```

The split target set becomes 4 repos: `z77-ch/kernel`, `z77-ch/module-frontend`,
`z77-ch/module-backend`, `z77-ch/module-dms`. The three old repos
(`z77-ch/core`, `/shared`, `/persistence`) are archived, not deleted.

## Reasoning

The measured data and the domain reality agree: there is no real seam between the
three. A DAG could only be forced by dependency inversion plus code moves that
invent interfaces nobody needs (a DI/cache contracts layer, pulling `Auth` out of
`shared`, sinking `Attributes`/`Naming` below `persistence`) — high effort to model
a layering that does not exist. A single package models the truth, keeps every
namespace and folder, and removes the cycle at zero architectural cost. Modules
still depend downward on exactly one foundation, which is what ADR-019's
dependency-direction rule actually wants.

## Consequences

- Module packages (`module-frontend`, `module-backend`, `module-dms`) replace their
  three `z77/core|shared|persistence` requires with a single `z77/kernel`.
- `.github/workflows/split.yml` matrix goes from 6 to 4 packages.
- The installer (`Z77\Core\Installer\Install`) keys on **PSR-4 namespaces**, not
  package names, so `Z77\Core|Shared|Persistence` discovery and `shared/res/assets`
  installation must keep working after the move — to be verified during rollout
  (see [`../topics/installer.md`](../topics/installer.md)).
- `docs/topics/packaging.md` (ARCH-PKG-002) and `installer.md` are updated; ADR-019's
  package list is amended (core/shared/persistence = one kernel package, separated by
  namespace only — ADR-019's domain-by-vertical-slice rule is otherwise unchanged).
- The branch-alias / dev-main consumption model is unchanged.
- Harder: a future genuine need to consume only `persistence` standalone would
  require re-splitting — accepted, since no such consumer exists or is planned.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| **A — Untangle to a DAG** (dependency inversion + move `Auth` out of `shared`, sink `DI`/`Cache`/`Attributes` into a contracts layer) | High effort to enforce a layering that does not exist in the domain; all three pairs are cyclic, so it touches 4–5 namespaces and adds interfaces with no consumer other than the cycle break. |
| **Status quo — three packages, keep the cycle** | Composer installs it, but it is a false package boundary: it advertises independence the code does not have, and it breaks the "modules depend downward on the foundation" intent. |
| **Partial merge** (e.g. core+shared, keep persistence) | Does not remove the cycle — `(core+shared) ↔ persistence` stays cyclic. Either merge all three or fully untangle. |
