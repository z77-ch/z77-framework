# lottie

2026-11-12

## entry

1. `packages/module-frontend/res/view/templates/partials/lottie.tpl.php` — the one way a template renders a Lottie (`partials/lottie` with `src`, `ratio`, `poster`, …)
2. `packages/module-frontend/res/assets/js/lottie-figure.js` — the `<lottie-figure>` web component (poster, reduced motion, lazy load, hover trigger, hidden layers)
3. `packages/module-frontend/res/assets/js/lottie.js` — the player (lottie-web 5.12.2, its light build: SVG only, no expressions, MIT, `lottie.js.LICENSE.txt`)

## file map

SOURCE=/packages/module-frontend/res/view/templates/partials/lottie.tpl.php
SOURCE=/packages/module-frontend/res/assets/js/lottie-figure.js
SOURCE=/packages/module-frontend/res/assets/js/lottie-figure.min.js
SOURCE=/packages/module-frontend/res/assets/js/lottie.js
SOURCE=/packages/module-frontend/res/assets/js/lottie.min.js
SOURCE=/packages/module-frontend/res/assets/js/lottie.js.LICENSE.txt
SOURCE=/packages/module-frontend/res/scss/components/_lottie.scss
SOURCE=/packages/module-frontend/src/Ui/Config/layoutConfig.inc.php

## mental model

A Lottie on a page is **decoration with a static fallback**: the poster image is what the page shows first, without JS, on reduced motion and whenever loading fails; the animation cross-fades over it once it is ready. Built in zihlundsee.ch (cards, intros, the map, the closing band — every page), moved into the framework 2026-09-22.

- **Template side:** `$this->partial('partials/lottie', [...])` renders `<lottie-figure>` with a light-DOM `<img>` poster. Parameters: `src` (Lottie JSON URL, required — `''` renders nothing, so `mediaUrl(...)` can be passed straight through), `ratio` (`"949/650"`, default `"1/1"`), `poster`, `loop` (default true), `maxLoops`, `trigger` (`'hover'`), `class`, `hideLayers` (top-level layer names dropped before rendering — e.g. text the page overlays as translatable HTML).
- **No layout jump:** the poster `<img>` carries `width`/`height` = the ratio's terms, and `components/_lottie.scss` makes the not-yet-upgraded element a block, so the box is reserved at HTML parse time. After upgrade the component pins the img to its frame by inline style.
- **Component behaviour:** fetches the JSON only near the viewport (IntersectionObserver, 200px margin) and pauses off-screen; `prefers-reduced-motion` → never loads, the poster stays; `trigger="hover"` plays on hover, pauses on leave, falls back to autoplay on devices without hover; any failure (no player, 404, bad JSON) keeps the poster; `aria-hidden="true"` (decorative).
- **Opt-in scripts:** the player weighs ~170 KB, so the framework layoutConfig does NOT load it. A project that uses Lottie adds both scripts to its layoutConfig `javascripts` (`lottie`, `lottie-figure`, namespace `Z77\Module\Frontend`, `defer`). Load order is free — the component checks for the player at play time.
- **Assets reach `public/` like every framework asset:** seeded on the first `composer install`, afterwards only reported as drift (ADR-024/025). A project with its own stylesheets (not the framework `base` sheet) carries the two pre-upgrade rules of `_lottie.scss` itself.

## rules

- When a template shows a Lottie → MUST render it through `partials/lottie`; MUST NOT write `<lottie-figure>` by hand (the poster reservation and attribute escaping live in the partial).
- When a Lottie carries meaning (text, a link target) → MUST provide that meaning in HTML next to it; the component is `aria-hidden`. Text inside the animation that must be translated → overlay it as HTML and drop the layer with `hideLayers`.
- When a project uses Lottie → MUST list `lottie` + `lottie-figure` in its layoutConfig `javascripts`; MUST NOT load the player from a CDN (the page must work from its own origin).
- When a Lottie file has an expression (a string under the key `x` of a property, `grep '"x":"'`) → MUST bake it into keyframes before it goes live: the player ignores it silently and the property stands still. An expression in `time` is the comp's frame / `fr`; a keyframe's `t` is in comp frames too (the layer's `st` cancels out), so `value = N * time` is two keys, `{"t": ip, "s": [N * ip / fr], "i": {"x": [1], "y": [1]}, "o": {"x": [0], "y": [0]}}` and `{"t": op, "s": [N * op / fr]}`, with `"a": 1`.
- When setting `renderer` on the component → MUST leave it at `svg`, the only renderer in this build; with `canvas` the load fails and the poster stays.
- When updating the player → MUST take the light build of the release (`build/player/lottie_light.min.js`), never the full one: it evaluates a JSON's expressions with `eval` (LOTTIE-NOEVAL-001). MUST replace `lottie.js` AND `lottie.min.js` together, with the vendor marker line on top, update the version line in `lottie.js.LICENSE.txt`, and deploy the drift into each project's `public/`.

## see also

- [`view-layer.md`](view-layer.md) — partials and layoutConfig `javascripts`
- [`installer.md`](installer.md) — how framework assets reach `public/` (seed once, drift report)
- [`documents.md`](documents.md) — `mediaUrl()` for Lottie JSON and posters kept in the DMS

## known issues

- **LOTTIE-NOEVAL-001** — resolved 2026-11-02 (owner, hardening H4). The full player evaluated expressions inside the JSON with `eval`, so whoever could write to a folder of public documents could run script on a page that plays such a file. The player is now the light build of the same version: no `eval`, no `new Function`, and `<lottie-figure>` uses nothing it lacks (`loadAnimation` with `container`, `renderer: 'svg'`, `loop`, `autoplay`, `animationData`; `play`, `pause`, `destroy`; the events `DOMLoaded`, `loopComplete`). Its `Function(` hit in a plain grep is `createRenderFunction`. It keeps the blob worker the full player has too (js-build.md), unused with `animationData`. Verified in headless Edge on real project files: pixel-identical to the full player at 25 fixed frames per file for every file without an expression, also those carrying After Effects effects the full player does not draw either (Wave Warp, Layer Control); a baked file on the light player against the original with its expressions on the full player: 204 of 204 frames pixel-identical, integer frames and subframes, the same `stroke-dashoffset` on each, also across the loop point; the real component plays, loops, stops after `max-loops`, follows hover, drops `hide-layers`, destroys on disconnect, under an ENFORCED `script-src 'self'`. Under that same policy the full player with an expression never gets ready.
  **The order of a deploy, when a project has a file with an expression: the baked file goes into the DMS FIRST, then the framework.** A baked file looks the same on the old player, so nothing changes in between; the other way round the property stands still until the file arrives. Then the assets must reach `public/` (`composer install`, `vendor-deploy`), followed by «Cache leeren»: a page in the page cache still names the old player's versioned URL. Don't assume a file with an expression fails visibly: it plays, only the property stands still; look for `"x":"` in every project's files before updating.
  **Not seen on a real installation.** Frame order alone can change a few antialiased pixels (a full-against-full control with a sparser sequence differed by at most 146 of 616,850 pixels on one file, gone with the same sequence), so comparisons must run the same sequence.

## pending

- The comments in `partials/lottie.tpl.php` and the frontend `layoutConfig` still say ~300 KB, and `lottie-figure.js` still offers `canvas` (checked 2026-11-12): comments only, left for the next edit of those files (owner's limit of H4: assets and docs).
