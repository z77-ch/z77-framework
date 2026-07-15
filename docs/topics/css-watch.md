# css-watch

2026-05-17

## entry

1. `package.json` — npm scripts (`watch:*`, `build:*`) that drive the SCSS compile pipeline
2. `docs/topics/css-backend.md` — backend SCSS source/output layout (consumer of this workflow)
3. `docs/topics/css-frontend.md` — frontend SCSS source/output layout (consumer of this workflow)

## file map

SOURCE=/package.json
SOURCE=/packages/module-backend/res/scss
SOURCE=/packages/module-backend/res/assets/css
SOURCE=/packages/module-frontend/res/scss
SOURCE=/packages/module-frontend/res/assets/css

## mental model

One uniform pipeline per module: SCSS source under `packages/<module>/res/scss/` is compiled by `sass` to `packages/<module>/res/assets/css/`. npm exposes one `watch:<module>` and one `build:<module>` script per module; aggregate `watch` / `build` scripts fan out to all modules. The watcher does NOT run automatically — without it, SCSS edits silently never reach the browser.

- One script pair per module — `watch:<module>` (dev), `build:<module>` (deploy, compressed, no source maps).
- All modules follow the same source→output convention; adding a module means adding two `package.json` lines, no new mechanism.
- When the user opens a CSS session for any module → ask whether to start the matching `watch:<module>` before writing SCSS.
- **The watcher alone does NOT update the running app.** It only writes `packages/<module>/res/assets/css/`. The app loads from `skeleton/public/assets/<module>/css/` — a deployed **copy** (single tier, no public vendor tier — see [`stylesheet.md`](stylesheet.md) asset path resolution). Since `public/` is **seed-once** (the installer only writes it when `public/` is absent — ADR-024), a plain `composer install` will **not** update an already-deployed file. To push a changed framework asset into `skeleton/public`: delete the file (or `skeleton/public` entirely) and `composer install` to re-seed, or copy `res/assets/...` → `public/assets/<module>/...` by hand — then hard-reload the browser (versioned `*_at-{stamp}.css`). Do not claim "no reinstall needed" just because `res/assets` is current — the app serves from the `public/assets` copy.

## per-module scripts

| Module | Watch | Build (deploy) |
|---|---|---|
| backend | `npm run watch:backend` | `npm run build:backend` |
| frontend | `npm run watch:frontend` | `npm run build:frontend` |
| all (aggregate) | `npm run watch` | `npm run build` |

Each `watch:<module>` is a single `sass --watch <src>:<out>` invocation; `build:<module>` adds `--style=compressed --no-source-map`. The aggregate `watch` / `build` scripts pass multiple `<src>:<out>` pairs to one `sass` call.

## workflow on session start

1. User signals CSS work for a module (e.g. "wir arbeiten am Backend CSS", "SCSS frontend").
2. Read the module's topic doc first (e.g. [`css-backend.md`](css-backend.md)).
3. Ask the user whether to start `npm run watch:<module>` in the background.
4. On confirmation → launch with `run_in_background`.
5. Then edit SCSS; the watcher compiles on save.

## adding a new module to the pipeline

1. Create SCSS source dir `packages/module-<name>/res/scss/` with at least one top-level `*.scss` entry.
2. Create asset output dir `packages/module-<name>/res/assets/css/` (sass creates it on first compile).
3. Add two lines to `package.json` scripts:
   ```json
   "watch:<name>":  "sass --watch packages/module-<name>/res/scss:packages/module-<name>/res/assets/css",
   "build:<name>":  "sass packages/module-<name>/res/scss:packages/module-<name>/res/assets/css --style=compressed --no-source-map"
   ```
4. Append the new `<src>:<out>` pair to the aggregate `watch` and `build` scripts.
5. Add a topic doc `docs/topics/css-<name>.md` documenting the module's SCSS layout (see [`css-backend.md`](css-backend.md) as template).

## rules

- When the user signals start of a CSS session for module `<m>` → MUST read the corresponding topic doc, then MUST ask the user whether to start `npm run watch:<m>` in the background; MUST NOT begin editing SCSS without a running watcher unless the user opts out
- When starting the watcher → MUST run it as a background process (`run_in_background: true`); MUST NOT block the session
- When adding a new module's CSS → MUST follow the source/output convention `packages/module-<name>/res/{scss,assets/css}`; MUST NOT invent a new layout
- When updating npm scripts for a new module → MUST add the entry to both `watch:<m>` / `build:<m>` AND the aggregate `watch` / `build` pairs; MUST NOT leave the aggregate out of sync
- When deploying / building for production → MUST use `build:<module>` (compressed, no source maps); MUST NOT ship watch-mode artefacts

## see also

- [`css-backend.md`](css-backend.md) — backend SCSS layout (tokens, base, components, layout) and what goes where
- [`css-frontend.md`](css-frontend.md) — frontend SCSS layout and per-breakpoint entry files
- [`stylesheet.md`](stylesheet.md) — runtime asset pipeline: how compiled CSS is loaded into pages, FileFinder lookup, versioning, AssetCleaner

## known issues

- **CSS-FRONTEND-LEGACY-001** — resolved 2026-05-17. Legacy `packages/module-frontend/src/scss/` tree deleted. The only SCSS source for the frontend module is now `packages/module-frontend/res/scss/` and `npm run build:frontend` covers all of it.

## pending

_(none)_
