# Pattern: image slider (fanned, CSS-driven, DMS-fed)

**Status:** `[CURRENT]`
**Last updated:** 2026-07-13
**Graduation:** project code (reference: a live project)

## Purpose

A horizontal image slider with an **active slide left** and the upcoming slides **fanned out
beside it, partly peeking**, with an optional **fullscreen lightbox**. How wide the active tile
is and where the fan goes is DESIGN — the reference project uses ~50 % active width, fan to
the right, mobile full-width with the fan downward; your project picks its own values.
Navigation is **pure CSS** (radio buttons + labels — no JS to slide); a small JS layer adds
keyboard/swipe and crisp fullscreen. Images come from a **DMS folder** the client fills — add or
remove an image in the Drive and the slider adapts with **no code change**.

Use it for a marketing hero/gallery where the client owns the images and the look is a fanned card
stack. A plain one-image-at-a-time carousel does not need this machinery.

## Ingredients & build order

Every file the project creates, in the order to build them (§ = the section below):

| # | File (project `override/z77/module/frontend/…` unless noted) | § |
|---|---|---|
| 1 | `override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php` — profile + sizes | §1 |
| 2 | — (Drive, no file): create the folder, assign the profile, upload images | §1 |
| 3 | `src/Ui/Controllers/{Group}/{Controller}.php` — list images, `createCss` + `addJs` | §2 |
| 4 | `res/view/templates/{Group}/{Controller}/css/slider.css.tpl.php` — count-dependent CSS | §3a |
| 5 | `res/view/templates/partials/{page}/slider.tpl.php` — the markup (contract classes) | §4 |
| 6 | `res/scss/…` — fan geometry, arrows, fullscreen, responsive (the project's design) | §3b |
| 7 | `res/assets/js/slider.js` + npm `build:js` script — optional enhancement | §5 |
| 8 | project i18n: `slider.prev` / `slider.next` / `slider.fullscreen` | §4 |

## What is the framework mechanism vs. what is the project's

This page documents the **mechanism** — how the pieces are built and loaded so a slider adapts to
its images: the config, the controller wiring, the generated-CSS handling, the markup contract, the
JS handling. **The look is the project's**: the fan geometry, the tile width, aspect ratios,
the peek `--sl-step`, colours, and the responsive breakpoints all live in the project's SCSS and
are NOT prescribed here. When you build a slider in a new project, copy the mechanism; design the
layout fresh (the reference values below are the reference project's, shown only to make the mechanism concrete).

```text
MECHANISM (this doc, transferable)          PROJECT (own SCSS, per design)
────────────────────────────────           ─────────────────────────────
DMS image profile + folder                  fan geometry (translateX/Y, --sl-step)
controller: list → createCss → addJs        tile width (reference: ~50 %), aspect ratios
generated <head> CSS (count-dependent)      colours, radius, arrows/fullscreen styling
markup contract (classes + custom props)    which stylesheet loads at which breakpoint
JS build + load                             mobile fan direction
```

## The contract (the glue between the three layers)

Generated CSS ↔ template ↔ project SCSS talk through a fixed set of class names and two custom
properties. Keep these stable; everything else is free.

| Hook | Set by | Read by | Meaning |
|---|---|---|---|
| `.sl-slider__stage` | template | (JS, SCSS) | positioning root; holds the inputs + viewport as **siblings** |
| `.sl-slider__radio` (`#sl-slide-{i}`) | template | generated CSS | one hidden radio per slide; first `checked` = start slide |
| `.sl-slider__fs-toggle` (`#sl-slider-fs`) | template | SCSS, JS | hidden checkbox = fullscreen state |
| `.sl-slider__viewport` | template | generated CSS, SCSS | the clipping window; **must follow the inputs as a sibling** |
| `.sl-slider__track` / `.sl-slider__slide` | template | generated CSS, SCSS | the row + the tiles |
| `.sl-slider__nav` | template | generated CSS, SCSS | per-slide prev/next arrows (base-hidden) |
| `--sl-active` | **generated CSS** on `.sl-slider__viewport` | project SCSS | index of the active slide |
| `--sl-i` | **generated CSS** on each `.sl-slider__slide` | project SCSS | that tile's own index |

The generated CSS emits, per slide `i` (`nth-child` `k=i+1`):

```css
#sl-slide-i:checked ~ .sl-slider__viewport { --sl-active: i }
.sl-slider__slide:nth-child(k)             { --sl-i: i }
#sl-slide-i:checked ~ .sl-slider__viewport .sl-slider__slide:nth-child(k) .sl-slider__nav
                                           { opacity: 1; pointer-events: auto }
```

The project SCSS then offsets each tile from these two numbers (reference formula — the *values*
are the project's):

```scss
.sl-slider__slide {
    --sl-rel: calc(var(--sl-i,0) - var(--sl-active,0));
    z-index: calc(50 - max(var(--sl-rel), 0));           // passed tiles collapse behind active
    transform: translateX(calc(max(var(--sl-rel), 0) * var(--sl-step))); // Y on mobile
}
```

## 1. Config (DMS image profile + folder)

Images live in a DMS folder, e.g. `front/slider/home/main`. Set the partition (`front`) delivery
mode to **public** (inherits down) so `/media` serves them. Define the widths the `<img srcset>`
needs plus a large `full` for the fullscreen lightbox, in the project's
`override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php` (see `dms-images.md`):

```php
'slider' => [
    'mobile'  => ['w' => 768],
    'tablet'  => ['w' => 1280],
    'desktop' => ['w' => 1920],
    'full'    => ['w' => 2800],  // fullscreen lightbox
],
```

Assign the profile to the folder in the Drive (folder edit → "Bildprofil"). **Not retroactive** —
images uploaded before the profile change must be deleted + re-uploaded to get new variants.

## 2. Controller

Resolve the folder **read-only** through public repo APIs, list its live images, keep only the ones
that resolve to a public URL (so the list matches the markup 1:1 — see Pitfalls), then generate the
CSS and register the JS:

```php
private const SLIDER_FOLDER = ['front', 'slider', 'home', 'main'];

$folders = DI::getUnifiedEntityManager()->getRepository(Folder::class);
$folder  = $folders->findRootBySlug(self::SLIDER_FOLDER[0]);
foreach (array_slice(self::SLIDER_FOLDER, 1) as $slug) {
    $folder = $folder ? $folders->findOneBy(['parent_id' => $folder->getId(), 'slug' => $slug]) : null;
}
$docs = $folder ? DocumentService::create()->listByFolder($folder->getId()) : [];
usort($docs, fn(Document $a, Document $b) => strcmp($a->getSlug(), $b->getSlug())); // stable order

$base = implode('/', self::SLIDER_FOLDER);
$images = [];
foreach ($docs as $doc) {
    if (!str_starts_with($doc->getMimeType(), 'image/')) continue;
    $path = $base . '/' . $doc->getSlug() . '.' . $doc->getExt();
    if (mediaUrl($path) !== null) $images[] = $path;   // resolvable/public only
}

if ($images !== []) {
    $this->layoutManager->createCss(
        'home-slider', self::NAMESPACE,
        'Main/IndexController/css/slider', ['count' => count($images)], count($images),
    );
    $this->layoutManager->addJs('slider', self::NAMESPACE, 'footer', true);
}
return $this->html(['sliderImages' => $images]);
```

`$this->layoutManager` is inherited from `AbstractBaseController` (protected). Keep the controller
thin — it lists and registers; it emits **no** CSS/JS strings.
Ordering: `listByFolder()` is unordered (no per-document manual sort in the DMS yet), so sort by
slug — the client's filenames (`…01`, `…02`) carry the sequence.

## 3. CSS handling (the split)

Two kinds of CSS, loaded two different ways:

**(a) Count-dependent → generated → `<head>`.** The radio→active-index map and each tile's index
depend on the image **count** and nothing else. Do **not** inline a `<style>` in the body. Generate
it with `LayoutManager::createCss()` (documented use case: "CSS-only sliders where selectors and
values depend on DB entities") — it renders a PHP css-template into a versioned file under
`assets/css` and registers it as a `<head>` stylesheet. The css-template
(`res/view/templates/{Group}/{Controller}/css/slider.css.tpl.php`):

```php
<?php /** @var int $count */
for ($i = 0; $i < $count; $i++) { $nth = $i + 1;
    echo "#sl-slide-{$i}:checked ~ .sl-slider__viewport{--sl-active:{$i}}\n";
    echo ".sl-slider__slide:nth-child({$nth}){--sl-i:{$i}}\n";
    echo "#sl-slide-{$i}:checked ~ .sl-slider__viewport .sl-slider__slide:nth-child({$nth}) .sl-slider__nav{opacity:1;pointer-events:auto}\n";
} ?>
```

**`version = count`** (the 5th arg to `createCss`): the generated CSS is a pure function of the
count, so reordering or alt-text edits do not change it, and the file is reused until the count
changes. Do **not** version by `max(updatedAt)` — that regenerates needlessly and can go *backwards*
on delete (reusing a stale file).

**(b) Static layout/appearance → project SCSS → project stylesheet.** All the geometry, sizing,
z-index, arrows, fullscreen lightbox, responsive behaviour — **this is the project's**, compiled and
loaded however the project loads its CSS (in the reference project: mobile-first `common` + a media-scoped
`desktop` sheet). The framework mechanism only requires that this SCSS reads `--sl-active` / `--sl-i`
and styles the contract classes. **Do not put the fan values in the framework.**

## 4. Template (partial)

Markup only — **no inline styles** (the count-dependent bits come from the generated CSS). The
inputs and the viewport must be **siblings** so `#id:checked ~ .sl-slider__viewport` resolves.

```php
<?php /** @var list<string> $images */ $count = count($images); if ($count === 0) return; ?>
<section class="sl-slider"><div class="sl-container"><div class="sl-slider__stage">
    <?php for ($i = 0; $i < $count; $i++): ?>
    <input type="radio" name="sl-slider" id="sl-slide-<?= $i ?>" class="sl-slider__radio"<?= $i === 0 ? ' checked' : '' ?>>
    <?php endfor; ?>
    <input type="checkbox" id="sl-slider-fs" class="sl-slider__fs-toggle">

    <div class="sl-slider__viewport">
        <div class="sl-slider__track">
            <?php foreach ($images as $i => $path):
                // Controller already filtered to resolvable images; guard only a delete race.
                $img  = mediaImage($path) ?? ['alt' => '', 'caption' => '', 'width' => null, 'height' => null];
                $prev = ($i - 1 + $count) % $count; $next = ($i + 1) % $count;  // wrap-around ?>
            <figure class="sl-slider__slide" data-full="<?= e((string) mediaUrl($path, 'full')) ?>">
                <img class="sl-slider__img"
                     src="<?= e((string) mediaUrl($path, 'desktop')) ?>"
                     srcset="<?= e((string) mediaUrl($path,'mobile')) ?> 768w, <?= e((string) mediaUrl($path,'tablet')) ?> 1280w, <?= e((string) mediaUrl($path,'desktop')) ?> 1920w"
                     sizes="(min-width: 768px) 50vw, 100vw"
                     alt="<?= e($img['alt']) ?>"
                     <?= $img['width'] ? 'width="' . e((string) $img['width']) . '"' : '' ?>
                     <?= $img['height'] ? 'height="' . e((string) $img['height']) . '"' : '' ?>
                     loading="<?= $i === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                <div class="sl-slider__nav">
                    <label class="sl-slider__arrow sl-slider__arrow--prev" for="sl-slide-<?= $prev ?>" aria-label="<?= e(t('slider.prev')) ?>">…</label>
                    <label class="sl-slider__arrow sl-slider__arrow--next" for="sl-slide-<?= $next ?>" aria-label="<?= e(t('slider.next')) ?>">…</label>
                </div>
            </figure>
            <?php endforeach; ?>
        </div>
        <label class="sl-slider__fs" for="sl-slider-fs" aria-label="<?= e(t('slider.fullscreen')) ?>">…</label>
    </div>
</div></div></section>
```

`data-full` carries the 2800px URL for the JS fullscreen swap. Add the `slider.prev` / `slider.next`
/ `slider.fullscreen` aria labels to the project i18n. `.sl-container` is the project's content wrapper.
The `width`/`height` attributes (original dimensions from `mediaImage()`) let the browser reserve
the aspect ratio before the image loads — no layout shift; keep them. The `sizes` value is the
PROJECT's: it must match the tile width your SCSS actually gives the active slide (reference: 50vw).

## 5. JS handling (build + load) — optional enhancement

The slider works fully without JS. The JS only adds: keyboard ←/→ (+ Esc to leave fullscreen),
touch swipe, and — on fullscreen open — swapping the active `<img>.src` to its `data-full` (2800px)
for crispness (`srcset`/`sizes` cannot react to the fullscreen CSS state). It sets the matching
radio's `.checked`; no framework coupling.

**Build + load** — the same "how do assets get to `public/` and get referenced" question as CSS:

- **Build**: author the source in `override/z77/module/frontend/res/assets/js/slider.js`; a project
  build step copies it to `public/assets/frontend/js/` as `slider.js` (+ `slider.min.js` for prod).
  A project with only `build:css` has no JS build — add a dependency-free copy script (reference project:
  `scripts/build-js.mjs`, npm `build:js`) or a real minifier (esbuild/terser) if smaller prod files
  are wanted. `getVersionedJs` resolves `js/{name}.js` in debug, `js/{name}.min.js` in production.
- **Load**: register per page in the controller — `LayoutManager::addJs('slider', self::NAMESPACE,
  'footer', true)` (defer). Put it behind the same `$images !== []` guard as the CSS. (Global,
  always-on scripts go in the layout config's `javascripts` instead — the slider is page-scoped, so
  `addJs` is the right tool.)

## Adapting a copy (the fast path)

Copying the reference implementation instead of building from the sections above is fine — but
these MUST be adapted, or the copy silently misbehaves:

- [ ] `SLIDER_FOLDER` constant → the new project's DMS slug path
- [ ] profile name + sizes in the project's `imageProfilesConfig.inc.php` (+ assign in the Drive)
- [ ] class prefix — `sl-` is this example's prefix; rename to the project prefix EVERYWHERE
      (template + generated-CSS template + SCSS + JS query selectors — the contract must stay in sync)
- [ ] `sizes` attribute + breakpoints → match the new design's tile widths
- [ ] i18n keys `slider.*` → project language files
- [ ] `NAMESPACE` / template group paths → the new controller's location
- [ ] npm `build:js` script (`scripts/build-js.mjs`) → exists in the new project's `package.json`
- [ ] the SCSS fan geometry → design it fresh; the reference values are the reference project's look

## Reference

Living implementation — **the reference project**, `override/z77/module/frontend/`:
`src/Ui/Controllers/Main/IndexController.php`,
`res/view/templates/partials/home/slider.tpl.php`,
`res/view/templates/Main/IndexController/css/slider.css.tpl.php` (generated),
`res/scss/sections/_slider.scss` + `res/scss/layout/_desktop.scss` (**project layout**),
`res/assets/js/slider.js` + `scripts/build-js.mjs`.
Build plan + decisions + reference layout values: `work/docs/plans/02-slider.md` —
**project-internal working doc** (projects are not git repos; `work/` may be cleaned up).
Everything transferable is on THIS page; the plan is only extra background if it still exists.

## Pitfalls

- **Radio↔slide desync** — the generated CSS targets slides by `nth-child`; if the partial skips a
  slide the indices shift. Filter to renderable images **in the controller**; never skip in the template.
- **CSS in the body** — do not inline the count-dependent `<style>`; use `createCss` → `<head>`.
- **Version = count, not timestamp** — the generated CSS depends only on the count (see §3a).
- **Inputs/viewport must be siblings** — `#id:checked ~ .sl-slider__viewport` breaks otherwise.
- **Not retroactive** — new profile widths (e.g. `full`) only apply to future uploads; re-upload.
- **`abs()` / `max()`** — used for the collapse/hide math; widely supported (2024+). To support older
  engines, move the hide logic into the generated CSS (one rule per state).
- **Fullscreen crispness needs the swap** — without the JS `data-full` swap the lightbox shows the
  inline variant upscaled.
- **Lightbox backdrop = opaque** — use an opaque colour, not `rgba()` with alpha (a semi-transparent
  fixed backdrop is unreliable and hard to verify in headless screenshots; a viewer wants full focus).
- **Viewport radius** — with `overflow: hidden` the clipped fan edge squares off; give
  `.sl-slider__viewport` the same corner radius as the tiles (reset to 0 in fullscreen).
- **Contain the z-index space** — the fan uses per-tile `z-index` (~50) and the controls sit above
  it; on the global scale those leak over the sticky header / menu overlay. Put
  `isolation: isolate` on `.sl-slider__stage` so all internal z-indices stay local (below the
  header). Fullscreen must escape that context: `.sl-slider__stage:has(#sl-slider-fs:checked)
  { z-index: <modal-layer> }` lifts the whole slider above everything only while fullscreen is open.
  (Keep global layers — overlay/header/modal — on a shared z-index token scale so they stay ordered.)
