# js-build

2026-11-12

## entry

1. ONE minifier, two ways to run it (owner decision 2026-11-11, JS-BUILD-003): VS Code «autominify» on save, or `npm run build:js -- <file.js>` — the same terser with the same options `{ mangle: false }`, identical bytes. The assistant minifies with `build:js` after every change to a `*.js`
2. `tests/js-min-markers.php` — the offline check that the save happened: fails when a `.min.js` lacks a marker of its source
3. `packages/kernel/core/src/Services/JavascriptManager.php` — the consumer: it serves `.min.js` in production

## file map

SOURCE=/tests/js-min-markers.php
SOURCE=/scripts/build-js.mjs
SOURCE=/package.json
SOURCE=/packages/kernel/core/src/Services/JavascriptManager.php
SOURCE=/packages/kernel/core/src/Services/LayoutManager.php
SOURCE=/packages/kernel/shared/res/assets/js
SOURCE=/packages/module-backend/res/assets/js
SOURCE=/packages/module-dms/res/assets/js
SOURCE=/packages/module-frontend/res/assets/js
SOURCE=/packages/module-member/res/assets/js

## mental model

The runtime picks the file by mode: `JavascriptManager` serves `name.min.js` in production and `name.js` in debug, then versions it as `name_at-{mtime}.min.js`. A missing `.min.js` does not take the page down — the manager falls back to the unminified source and writes a warning per request. A STALE `.min.js` is worse: it is silent everywhere — debug shows the new behaviour, production runs the old one (review 2026-10-13, K1: `core.min.js` lacked five days of changes).

**One minifier, two ways to run it** (owner decision 2026-11-11, JS-BUILD-003, revising JS-BUILD-002): the owner's VS Code extension «autominify» (`caffeineonice.autominify`, minify on save) runs terser with `{ mangle: false }`; `scripts/build-js.mjs` loads the terser the extension bundles and passes the same options, so a save in VS Code and `npm run build:js -- <file.js>` write the same bytes (verified 2026-11-11 against the eight `.min.js` last saved in VS Code: identical). The assistant runs `build:js` for the files it changed; the owner may still save in VS Code. `php tests/js-min-markers.php` is the check either way.

- **One file in, one file beside it out.** `foo.js` → `foo.min.js`, in the same directory. No bundle, no import resolution, no source map — the framework loads plain scripts, and a map would have to be versioned into the `_at-{mtime}` name too.
- **Nothing is mangled.** The extension's default is `mangle: false`, and `build:js` keeps it — that is what makes both ways identical. `_Z77`, `_z77CollectFormData` (called by templates) and `el.name`, `file.name` survive in any case; the harness checks `_z77CollectFormData`. `.min.js` files from the earlier terser runs (`--mangle`) are mangled until their source is next changed and rebuilt — both are valid builds.
- **The marker harness, not a byte comparison.** `tests/js-min-markers.php` holds a small list of strings per source that a minifier keeps (string literals, property names, top-level names — e.g. `data-submit-navigate`, `_z77Busy`) and fails when one is in the source but not in the `.min.js` («re-save X in VS Code»). A local function name (`_wiredFragment`) is never a marker: a correct build mangles it away. It also fails when a vendor build's `.min.js` is not a byte copy, and warns for a source without a `.min.js` and for a `.min.js` older than its source (mtime — a hint only, a checkout sets mtimes at will).
- **`.min.js` files are committed.** They are build output, but a project installs from `vendor/` and has no Node toolchain — shipping them is the point.
- **`build:js` finds terser in the extension first**, then a local `node_modules`, then the global npm root. Without arguments it builds every source; `--check` writes nothing and lists every `.min.js` that differs from this build (an older mangled one differs too — not a staleness proof, the marker harness is). `check:js` stays retired.

## the vendor marker

A third-party bundle that already ships minified is **copied, not minified again**. Re-mangling a foreign build can break code that reads its own function names — `lottie.js` stringifies functions to start a web worker.

The marker sits in the file it is about, in the first 400 bytes:

```js
/* @z77-js: vendor — lottie-web, shipped minified; see docs/topics/js-build.md */
```

Such a file is NOT re-saved through the minify-on-save extension: its `.min.js` is a byte copy of the source (`tests/js-min-markers.php` fails otherwise; `build:js` copies it too).

## api

| Command | Effect |
|---|---|
| `npm run build:js -- x.js …` | writes `x.min.js` for the named sources — the assistant's way |
| VS Code: open `x.js`, save | the extension writes the same `x.min.js` — the owner's way |
| `php tests/js-min-markers.php` | offline, no dependency: markers (fail), vendor copies (fail), missing / older `.min.js` (warn); exit 1 on a failure |
| `npm run build:js` | every source; `-- --check` writes nothing and lists the differing ones |

## rules

- When editing any `*.js` under `packages/*/res/assets/js` → MUST rebuild its `.min.js` before committing (`npm run build:js -- <file.js>`, or a save in VS Code) and MUST run `php tests/js-min-markers.php`; MUST NOT minify with other options or another tool (two builds of one file would churn it); MUST NOT hand-edit a `*.min.js` (a hand patch that drifts from its source is invisible — JS-BUILD-001)
- When a change adds behaviour whose absence in production would be silent (a new data attribute, a guard, a command) → MUST add one marker for it to `tests/js-min-markers.php`; MUST NOT pick a local function name as the marker (mangled in every correct build)
- When adding a JavaScript file → MUST commit its `.min.js` with it; MUST NOT ship a `.js` without its `.min.js` (production then serves the unminified file and logs a warning per request)
- When vendoring a third-party script that is already minified → MUST put `@z77-js: vendor` in its first line with the library name and copy it to `.min.js` byte for byte; MUST NOT minify it again
- When configuring the minifier (the VS Code extension, or terser for `build:js`) → MUST keep the one-file-in-one-file-out contract and top-level and property names; MUST NOT enable top-level or property mangling (the templates call globals by name, the form code reads `el.name`)
- When a `.min.js` looks suspicious → MUST compare it against its source with a real parser or the marker harness, MUST NOT compare string literals with a regular expression (a minifier folds `'a' + 'b'` into `'ab'`, and a regex mis-pairs quotes — this produced two false "text is missing" reports in 2026-09)
- When deploying → MUST treat `.min.js` as committed source, not as something the target builds; MUST NOT expect Node on a project server

## known issues

- **JS-BUILD-003** — decided 2026-11-11 (owner): the assistant minifies itself. `build:js` now uses the extension's terser and options (`{ mangle: false }`) instead of a global terser with `--mangle`, takes file arguments, and is the assistant's way; VS Code stays the owner's. Built for `core.js`, `documents/media-field.js`, `content/editor.js` the same day (markers 94/0, `tests/core-js.mjs` 176/0, `tests/core-js-windows-browser.mjs` 18/0) — this also replaced the hand patch in `core.min.js` (MENU-001).
- **JS-BUILD-002** — decided 2026-10-13 (owner). The review of the day found `core.min.js` five days stale (K1) because terser was missing on PC2 and `build:js` could not run. The owner minifies with a VS Code extension on save; terser is not installed. Consequences: `check:js` removed from `package.json` (it measured against terser's bytes), `build:js` kept as an optional tool, `tests/js-min-markers.php` added as the offline check (no terser, no npm). State 2026-10-13: `core.min.js` stale (the review rework changed `core.js`), `upload.js` and dms `upload-poster.js` without `.min.js` — the harness fails / warns until the owner re-saves them.
- **JS-BUILD-001** — resolved 2026-09-23. Until a build existed, `.min.js` was hand work, and it had come apart in three ways: five files (`content/slot.js`, `content-edit.js`, `lottie-figure.js`, `login-wait.js`, `member/shell.js`) had a `.min.js` that was a **verbatim copy of the commented source** — production served the full source and nobody could see it; four (`partial-labels.js`, `dms/drive.js`, `dms/upload.js`, `public-form.js`) had **no `.min.js` at all** and ran on the fallback, logging a warning on every request; the twelve that were genuinely minified were **21 % larger than terser produces**. Checked before the first build with an acorn-based comparison of all string literals: no text was lost, and no old `.min.js` had been built from a different source version — so the hand patch in `core.min.js` (MODAL-SCROLL-001, [`backend.md`](backend.md)) was in sync and nothing was dropped when it was regenerated.
- **Don't assume a missing `.min.js` is loud.** `JavascriptManager` falls back to the source deliberately (a missing build must not take production down, ADR-024 reasoning) and only writes a log line. `tests/js-min-markers.php` is what makes it visible before a deploy, not the runtime.
- **Don't assume the marker harness proves a `.min.js` complete.** It proves the listed markers are there; a change without a marker is invisible to it. Keep the list current (rules).
- **Don't assume the byte counts are the transfer saving.** Servers gzip. Measured on `core.js` (2026-09): 29 897 raw → 7 799 gzipped; 10 900 minified → 3 518 gzipped. Minifying still halves the compressed transfer, but the gain is less than the raw figures suggest.
- **Nothing forces the save.** No git hook, no `composer install` step. Forgetting it leaves the previous `.min.js` in place — stale but valid, the failure mode is silent, not broken. The harness is the guard.

## pending

- Run `php tests/js-min-markers.php` before a deploy: `.releases/check.php` is the pre-deploy step now but does not call it, and it runs in a project, where the framework's `tests/` is not present — decide where the call belongs (e.g. the framework's own release/tag step).
- Decide whether `.min.js` should carry a source map. Not today: the map would need its own versioned name beside `name_at-{mtime}.min.js` and nothing currently reads one.

## see also

- [`css-watch.md`](css-watch.md) — the SCSS half: `npm run build` compiles the CSS; same source/output convention
- [`stylesheet.md`](stylesheet.md) — the runtime asset pipeline the output feeds into: FileFinder lookup, `_at-{mtime}` versioning, AssetCleaner
- [`installer.md`](installer.md) — how `res/assets` reaches a project's `public/`; a new `.min.js` only arrives there through the publication record (ADR-046)
- [`fetch.md`](fetch.md) — `core.js`, the file whose stale `.min.js` started JS-BUILD-002
- [`backend.md`](backend.md) — MODAL-SCROLL-001, the hand patch of `core.min.js` that the first build replaced
- [`lottie.md`](lottie.md) — the vendored library that carries the `@z77-js: vendor` marker
