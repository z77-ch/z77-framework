# DMS Images in a Project

**Status:** `[CURRENT]`
**Last updated:** 2026-07-13

Consumer guide for client projects: how to define **image sizes** for DMS uploads and how to
**display** DMS-managed images in frontend templates. Framework internals live in
`docs/topics/documents.md`; this page is what a project needs day-to-day.

The workflow in one line:

```text
define profiles (config, once)  →  assign a profile to a folder (Drive, click)
  →  upload images (Drive)      →  render them (mediaUrl / mediaImage in templates)
```

---

## a) Image-size config (`imageProfilesConfig`)

### Where

ONE file, in the **project's override tree** (the framework ships none):

```text
<project>/override/z77/module/dms/src/App/Config/imageProfilesConfig.inc.php
```

### Why there

- Image sizes are **project-specific content decisions**, not framework code — they belong to
  the project, and the override tree is the project's place to provide module config
  (FileFinder resolves `override/...` BEFORE `vendor/...`, first match wins, no merge).
- It lives under `module/dms` because the DMS consumes it (upload → resize), across all
  partitions — not under `module/frontend` or any other single module.

### Structure

Two levels: **partition → profile → sizes**. The partition key is the top-level Drive folder's
`key` — or its **slug** when the folder was created by hand in the Drive (no key needed).

```php
<?php
namespace Z77\Module\Dms\App;

return [
    'front' => [                              // partition (top folder under the Drive root)
        'default' => [                        // fallback for folders WITHOUT an assignment
            'w1600' => ['w' => 1600],
            'w800'  => ['w' => 800],
        ],
        'slider' => [                         // a named profile — assign it to a folder
            'mobile'  => ['w' => 768],
            'tablet'  => ['w' => 1280],
            'desktop' => ['w' => 1920],
        ],
    ],
];
```

Variant spec fields:

| Key | Required | Meaning |
|---|---|---|
| `w` | yes | target width in px (also the `srcset` width) |
| `h` | no | target height; without it the image scales proportionally |
| `fit` | no | `contain` (letterbox, default) or `cover` (crop to fill) — only meaningful with `w` + `h` |

Rules:

- Profile name **`default`** is reserved as the per-partition fallback.
- Profile name **`admin`** is forbidden — it is the framework-fixed tool profile.
- Variant names: `[a-z0-9_-]`. Avoid `s` and `m` (used by the framework tool sizes).
- Generation is **downscale-only** (never upscales), keeps the source format, JPEG q90.

### Assigning a profile to a folder

In the Drive (backend → Dokumente): edit the folder → field **"Bildprofil"** → pick the profile
→ save. The assignment **inherits to all subfolders** (like the delivery mode), so one
assignment on `front/slider` covers `slider/home/main` etc. Folders without an assignment fall
back to the partition's `default` profile; a partition without a config block yields only the
framework tool sizes.

### What an upload produces

For an image uploaded into a profiled folder:

```text
original  +  your profile variants (e.g. mobile/tablet/desktop)  +  s (160) + m (480)
```

`s`/`m` are the Drive's own list-thumbnail and preview sizes — always generated, framework-fixed.
Without any project profile the full framework ladder `s/m/l/xl` (160/480/1024/2048) is generated
instead.

**Not retroactive:** changing sizes or assignments affects FUTURE uploads only. To regenerate an
existing image, delete it in the Drive and upload it again (re-uploading without deleting is
skipped as a checksum-identical duplicate).

**After editing the config:** in dev with `debug: true` it applies immediately; otherwise clear
the cache (any DMS write does it, or the backend cache tool).

---

## b) Displaying DMS images in the frontend

### Prerequisite: the document must be public

Only documents whose **effective delivery mode is `public`** are served under `/media` (set the
mode on the folder once — e.g. `front` → `public` — and it inherits). The helpers below build
URLs blindly: a non-public document yields a URL that 404s.

### `mediaUrl()` — just the URL

```php
<img src="<?= e(mediaUrl('front/imgs/logo.png')) ?>" alt="Logo">
<img src="<?= e(mediaUrl('front/slider/home/main/hero.jpg', 'desktop')) ?>" alt="">
```

- Signature: `mediaUrl(string $path, ?string $variant = null): ?string`
- `$path` = the structural **slug path**: `partition/folder…/file.ext` — slugs as shown in the
  Drive, NOT the raw upload filename (umlauts etc. are slugified).
- `$variant` = a variant name of the document (`desktop`, `mobile`, `s`, …); `null` = original.
- Returns the public URL **with content-version token** (`/media/…?v=abc12345` — safe for
  long-lived browser caching; the token changes when the bytes change), or **`null`** when the
  path does not resolve. **Always null-guard and `e()`-escape.**

### `mediaImage()` — URL + alt/caption/dimensions

For real content images use `mediaImage()` — it also delivers the localized image texts
maintained in the Drive (edit modal → "Bildtexte", per language):

```php
<?php $img = mediaImage('front/slider/home/main/hero.jpg', 'desktop'); ?>
<?php if ($img): ?>
<figure>
    <img src="<?= e($img['url']) ?>"
         alt="<?= e($img['alt']) ?>"
         width="<?= e($img['width']) ?>" height="<?= e($img['height']) ?>"
         loading="lazy">
    <?php if ($img['caption'] !== ''): ?>
    <figcaption><?= e($img['caption']) ?></figcaption>
    <?php endif; ?>
</figure>
<?php endif; ?>
```

- Signature: `mediaImage(string $path, ?string $variant = null): ?array`
- Returns `{url, alt, caption, width, height}` or `null`. `alt`/`caption` are already resolved
  to the current request language (with default-language fallback). `width`/`height` match the
  **requested variant** — the variant's own pixel size (e.g. `desktop` → the desktop variant's
  `w`/`h`); for the original (`$variant === null`) they are the original dimensions. So the
  `<img width height>` always matches the delivered image (correct aspect ratio, no CLS).

### Responsive slider example (srcset)

```php
<?php $img = mediaImage('front/slider/home/main/hero.jpg'); ?>
<?php if ($img): ?>
<img src="<?= e(mediaUrl('front/slider/home/main/hero.jpg', 'desktop')) ?>"
     srcset="<?= e(mediaUrl('front/slider/home/main/hero.jpg', 'mobile')) ?> 768w,
             <?= e(mediaUrl('front/slider/home/main/hero.jpg', 'tablet')) ?> 1280w,
             <?= e(mediaUrl('front/slider/home/main/hero.jpg', 'desktop')) ?> 1920w"
     sizes="100vw"
     alt="<?= e($img['alt']) ?>"
     width="<?= e($img['width']) ?>" height="<?= e($img['height']) ?>">
<?php endif; ?>
```

To render a whole folder as a slider (all images of `front/slider/home/main`), list the folder's
documents via the DMS API in the controller — `DocumentService::create()->listByFolder($id)` —
and hand the slug paths to the template; the template stays on `mediaUrl`/`mediaImage`.

### Do NOT

- Do not assemble `/media/...` URLs by hand (no `?v=` token → stale browser caches after a
  file replace).
- Do not read `alt` maps or resolve languages in the template — `mediaImage()` does it.
- Do not call `DocumentService::resolve()`/`publicUrl()` directly in a template.
- Do not point `<img>` at the original of a huge photo — use a variant.

---

## See also (framework internals)

- `docs/topics/documents.md` — full DMS topic: rules, delivery model, ACL, profiles engine
- `docs/02-decisions/adr-020-…` (rev. 2026-07-13) — why profiles are partition-namespaced project config
- `docs/03-development/dms-folder-image-profiles-bauplan.md` — build plan + as-built details
