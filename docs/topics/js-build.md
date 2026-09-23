# js-build

2026-09-23

## entry

1. `scripts/build-js.mjs` — the build itself: every `*.js` under a package's `res/assets/js` becomes a `*.min.js`
2. `package.json` — the npm scripts (`build:js`, `check:js`) beside the SCSS pipeline
3. `packages/kernel/core/src/Services/JavascriptManager.php` — the consumer: it serves `.min.js` in production

## file map

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

The runtime picks the file by mode: `JavascriptManager` serves `name.min.js` in production and `name.js` in debug, then versions it as `name_at-{mtime}.min.js`. A missing `.min.js` does not take the page down — the manager falls back to the unminified source and writes a warning per request. That fallback is why nothing ever noticed that the `.min.js` files were not generated: until 2026-09-23 they were minified BY HAND, one by one, and they had drifted (see known issues).

`npm run build:js` closes that gap. It is the JS half of what `npm run build` is for SCSS ([`css-watch.md`](css-watch.md)) and follows the same shape: source and output live in the package, nothing is bundled, no module graph is resolved, each file is minified on its own.

- **One file in, one file beside it out.** `foo.js` → `foo.min.js`, in the same directory. No bundle, no import resolution, no source map — the framework loads plain scripts, and a map would have to be versioned into the `_at-{mtime}` name too.
- **terser, with its defaults.** Local scopes are mangled; top-level names (`_Z77`, `_z77CollectFormData`) and property names (`el.name`, `file.name`) are not. That is exactly what the templates and the DOM code depend on.
- **Only a file that actually changed is written.** The served name carries the mtime and the installer records the published sha1 (ADR-046) — a no-op rewrite would churn the cache-busting name and the publication record for nothing.
- **terser is a maintainer tool, not a dependency.** It is installed globally (`npm i -g terser`), never listed in `package.json`: a project consuming the framework never runs this build, it consumes the committed `.min.js`. The script looks for terser in the global root, then in a local `node_modules`, and exits with that hint if it finds neither.
- **`.min.js` files are committed.** They are build output, but a project installs from `vendor/` and has no Node toolchain — shipping them is the point.

## the vendor marker

A third-party bundle that already ships minified is **copied, not minified again**. Re-mangling a foreign build can break code that reads its own function names — `lottie.js` stringifies functions to start a web worker.

The marker sits in the file it is about, in the first 400 bytes:

```js
/* @z77-js: vendor — lottie-web, shipped minified; see docs/topics/js-build.md */
```

No list to maintain elsewhere, and it explains itself to whoever opens the file. The build then copies the source verbatim to `.min.js`, so the two stay byte-identical by construction instead of by accident.

## api

| Command | Effect |
|---|---|
| `npm run build:js` | writes every `.min.js` that is out of date, names each one with the size it saved |
| `npm run check:js` | writes nothing, exits 1 if any `.min.js` is out of date — the guard against the drift below |

Both print one line per file (`geschrieben` / `aktuell` / `vendor` / `VERALTET`). A terser error names the file and fails the run.

## rules

- When editing any `*.js` under `packages/*/res/assets/js` → MUST run `npm run build:js` before committing; MUST NOT hand-edit a `*.min.js` (the next build overwrites it, and a hand patch that drifts from its source is invisible — JS-BUILD-001)
- When adding a JavaScript file → MUST let the build produce its `.min.js` and MUST commit both; MUST NOT ship a `.js` without its `.min.js` (production then serves the unminified file and logs a warning per request)
- When vendoring a third-party script that is already minified → MUST put `@z77-js: vendor` in its first line with the library name; MUST NOT run terser over it, and MUST NOT copy it to `.min.js` by hand instead
- When the build needs a different minifier → MUST keep the one-file-in-one-file-out contract and the defaults that preserve top-level and property names; MUST NOT enable `mangle.toplevel` or property mangling (the templates call globals by name, the form code reads `el.name`)
- When a `.min.js` looks suspicious → MUST compare it against its source with a real parser, MUST NOT compare string literals with a regular expression (terser folds `'a' + 'b'` into `'ab'`, and a regex mis-pairs quotes — this produced two false "text is missing" reports while the build was being written)
- When deploying → MUST treat `.min.js` as committed source, not as something the target builds; MUST NOT expect Node on a project server

## known issues

- **JS-BUILD-001** — resolved 2026-09-23. Until this build existed, `.min.js` was hand work, and it had come apart in three ways: five files (`content/slot.js`, `content-edit.js`, `lottie-figure.js`, `login-wait.js`, `member/shell.js`) had a `.min.js` that was a **verbatim copy of the commented source** — production served the full source and nobody could see it; four (`partial-labels.js`, `dms/drive.js`, `dms/upload.js`, `public-form.js`) had **no `.min.js` at all** and ran on the fallback, logging a warning on every request; the twelve that were genuinely minified were **21 % larger than terser produces**. Checked before the first build with an acorn-based comparison of all string literals: no text was lost, and no old `.min.js` had been built from a different source version — so the hand patch in `core.min.js` (MODAL-SCROLL-001, [`backend.md`](backend.md)) was in sync and nothing was dropped when it was regenerated.
- **Don't assume a missing `.min.js` is loud.** `JavascriptManager` falls back to the source deliberately (a missing build must not take production down, ADR-024 reasoning) and only writes a log line. That is by design — `npm run check:js` is what makes it visible before a deploy, not the runtime.
- **Don't assume the byte counts are the transfer saving.** Servers gzip. Measured on `core.js`: 29 897 raw → 7 799 gzipped; 10 900 minified → 3 518 gzipped. Minifying still halves the compressed transfer, but the gain is less than the raw figures suggest.
- **The build is not wired into `composer install` or a git hook.** Nothing forces it. Forgetting it leaves the previous `.min.js` in place, which is stale but valid — the failure mode is silent, not broken.

## pending

- Run `npm run check:js` in whatever CI or pre-deploy step exists once there is one — the check is written for it and currently has no caller.
- Decide whether `.min.js` should carry a source map. Not today: the map would need its own versioned name beside `name_at-{mtime}.min.js` and nothing currently reads one.

## see also

- [`css-watch.md`](css-watch.md) — the SCSS half of the same pipeline; same source/output convention, same npm-script shape
- [`stylesheet.md`](stylesheet.md) — the runtime asset pipeline the output feeds into: FileFinder lookup, `_at-{mtime}` versioning, AssetCleaner
- [`installer.md`](installer.md) — how `res/assets` reaches a project's `public/`; a rebuilt `.min.js` only arrives there through the publication record (ADR-046)
- [`backend.md`](backend.md) — MODAL-SCROLL-001, the hand patch of `core.min.js` that this build replaced
- [`lottie.md`](lottie.md) — the vendored library that carries the `@z77-js: vendor` marker
