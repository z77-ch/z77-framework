# Developer Environment — Requirements & Setup

**Status:** `[CURRENT]`
**Date:** 2026-11-19

Everything the framework needs to build and run that is **not** carried by the code
itself (tools are per-machine; `node_modules/` and `vendor/` are regenerated). Match or
exceed the minimums — they come from the code.

---

## 1. Required tools (known-good versions)

| Tool | Minimum | Known-good | Notes |
|---|---|---|---|
| PHP (CLI) | 8.2 (kernel + web modules) · 8.4 (business modules, Doctrine driver, the monorepo) | 8.4 / 8.5 | on PATH |
| PHP extensions | `mbstring fileinfo zip gd` | + `apcu` | required set is enforced by the packages' `composer.json` (`mbstring`/`fileinfo`/`zip` → kernel, `gd` → module-dms, `pdo_mysql` → persistence-doctrine, `curl` → kibot); `apcu` is optional — shared data cache with a guarded per-request fallback, enable it also for CLI (`apc.enable_cli=1`) |
| MariaDB | 10.6 | — | only for the business modules / `z77/persistence-doctrine` and their test harnesses |
| Composer | 2.x | 2.8 / 2.9 | — |
| Node.js | 20 | 22 / 24 | `docs-lint` requires ≥20 |
| npm | — | 11.x | ships with Node |
| Dart Sass | — | 1.99 | **local** dev-dependency (`npm install`), not global — runs via `npm run` |
| Git | — | 2.x | provides Git Bash + ssh on Windows |

Node/Sass are needed **only to develop styles** — the framework ships pre-compiled CSS, so
a project runs with just PHP + Composer. A project has **no JavaScript build step**: the
packages ship each script with its committed `.min.js`. Whoever changes a framework `*.js`
re-minifies it with `npm run build:js -- <file.js>` (terser, found in the VS Code «autominify»
extension, a local or the global `node_modules`) and checks with `php tests/js-min-markers.php`
— [`../topics/js-build.md`](../topics/js-build.md). `sass` is not a global binary — it lives in `node_modules/.bin` and is invoked by
the `npm run watch*/build*` scripts. Do not install it globally.

---

## 2. First run (a project)

```bash
composer install            # pulls the framework into vendor/, runs the installer
composer dev                # serves http://localhost:8080 from public/
```

The installer prompts for the admin password (interactive). If Composer's Windows
hidden-input helper errors with `hiddeninput.exe: Resource temporarily unavailable`,
delete `%TEMP%\hiddeninput.exe` and retry, or run `composer install --no-interaction`
(writes a `SETUP_TOKEN`; create the admin later via `/backend/system/setup/setup`).

---

## 3. CSS compilation (Dart Sass)

Source `res/scss/` → `res/assets/css/` per module. Requires `npm install` first.

| Command | Effect |
|---|---|
| `npm run watch:frontend` · `:backend` · `:dms` · `:member` | watch + recompile one module (dev) |
| `npm run watch` | watch all four at once |
| `npm run build` | one-off compressed build (all modules, no source map) |

CSS rules: [`css-conventions.md`](css-conventions.md); watch workflow →
[`../topics/css-watch.md`](../topics/css-watch.md).

---

## 4. Checkers

| Command | Purpose |
|---|---|
| `npm run docs:check` | deterministic docs linter (`docs/topics/` structure) — must be green |
| `npm run build` | compile all module CSS (compressed) — must succeed |
| `bash docs/01-handbook/verify-dev-env.sh` | monorepo checkout: PASS / WARN / FAIL for tools, PHP extensions, local Sass, GitHub access, git identity ([script](verify-dev-env.sh)) |
| `php tests/run.php <group\|harness …>` / `--changed` | the test runner: harnesses side by side, one summary table, watchdog, tree guard ([testing.md](../topics/testing.md)); the database harnesses need a local MariaDB |
| `php tests/<name>.php` | one plain-PHP harness directly (no table, no watchdog) |

---

## 5. Gotchas

- **`node_modules` / `vendor` are gitignored** — always `npm install` / `composer install`
  on a fresh checkout; nothing runs until you do.
- **APCu must be enabled for CLI too** (`apc.enable_cli=1`) — the dev cache path uses it.
- **`hiddeninput.exe` on Windows** — a leftover locked copy in `%TEMP%` breaks the admin
  password prompt (see §2).

---

> **Framework maintainers:** the multi-machine workflow (repository access, directory
> layout for path-repo consumption, NAS sync, project-copy/deploy mechanics) is kept in a
> local runbook outside this repository — it is machine- and account-specific, not part of
> the framework.
