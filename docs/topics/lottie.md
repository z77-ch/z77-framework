# lottie

2026-09-22

## entry

1. `packages/module-frontend/res/view/templates/partials/lottie.tpl.php` — the one way a template renders a Lottie (`partials/lottie` with `src`, `ratio`, `poster`, …)
2. `packages/module-frontend/res/assets/js/lottie-figure.js` — the `<lottie-figure>` web component (poster, reduced motion, lazy load, hover trigger, hidden layers)
3. `packages/module-frontend/res/assets/js/lottie.js` — the player (lottie-web 5.12.2, MIT, `lottie.js.LICENSE.txt`)

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
- **Opt-in scripts:** the player weighs ~300 KB, so the framework layoutConfig does NOT load it. A project that uses Lottie adds both scripts to its layoutConfig `javascripts` (`lottie`, `lottie-figure`, namespace `Z77\Module\Frontend`, `defer`). Load order is free — the component checks for the player at play time.
- **Assets reach `public/` like every framework asset:** seeded on the first `composer install`, afterwards only reported as drift (ADR-024/025). A project with its own stylesheets (not the framework `base` sheet) carries the two pre-upgrade rules of `_lottie.scss` itself.

## rules

- When a template shows a Lottie → MUST render it through `partials/lottie`; MUST NOT write `<lottie-figure>` by hand (the poster reservation and attribute escaping live in the partial).
- When a Lottie carries meaning (text, a link target) → MUST provide that meaning in HTML next to it; the component is `aria-hidden`. Text inside the animation that must be translated → overlay it as HTML and drop the layer with `hideLayers`.
- When a project uses Lottie → MUST list `lottie` + `lottie-figure` in its layoutConfig `javascripts`; MUST NOT load the player from a CDN (the page must work from its own origin).
- When updating the player → MUST replace `lottie.js` AND `lottie.min.js` together, update the version line in `lottie.js.LICENSE.txt`, and deploy the drift into each project's `public/`.

## see also

- [`view-layer.md`](view-layer.md) — partials and layoutConfig `javascripts`
- [`installer.md`](installer.md) — how framework assets reach `public/` (seed once, drift report)
- [`documents.md`](documents.md) — `mediaUrl()` for Lottie JSON and posters kept in the DMS

## known issues

- None documented.

## pending

- The `.min.js` twins are copies, not minified builds (the player is already minified; the component is ~10 KB).
