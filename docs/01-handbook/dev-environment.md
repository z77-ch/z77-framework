# Developer Environment — Requirements & Setup

Everything the framework needs to build and run that is **not** carried by the code
itself (tools are per-machine; `node_modules/` and `vendor/` are regenerated). Match or
exceed the minimums — they come from the code.

---

## 1. Required tools (known-good versions)

| Tool | Minimum | Known-good | Notes |
|---|---|---|---|
| PHP (CLI) | 8.2 | 8.3 / 8.4 | on PATH |
| PHP extensions | — | `curl apcu mbstring json openssl dom xml fileinfo gd` | all enabled in `php.ini`; APCu also for CLI (`apc.enable_cli=1`) |
| Composer | 2.x | 2.8 / 2.9 | — |
| Node.js | 20 | 22 / 24 | `docs-lint` requires ≥20 |
| npm | — | 11.x | ships with Node |
| Dart Sass | — | 1.99 | **local** dev-dependency (`npm install`), not global — runs via `npm run` |
| Git | — | 2.x | provides Git Bash + ssh on Windows |

Node/Sass are needed **only to develop styles** — the framework ships pre-compiled CSS, so
a project runs with just PHP + Composer. There is **no JavaScript build step**; JS ships
as-is. `sass` is not a global binary — it lives in `node_modules/.bin` and is invoked by
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
| `npm run watch:frontend` · `:backend` · `:dms` | watch + recompile one module (dev) |
| `npm run watch` | watch all three at once |
| `npm run build` | one-off compressed build (all modules, no source map) |

CSS rules: [`css-conventions.md`](css-conventions.md); watch workflow →
[`../topics/css-watch.md`](../topics/css-watch.md).

---

## 4. Checkers

| Command | Purpose |
|---|---|
| `npm run docs:check` | deterministic docs linter (`docs/topics/` structure) — must be green |
| `npm run build` | compile all module CSS (compressed) — must succeed |

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
