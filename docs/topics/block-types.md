# block-types

2026-06-04

## entry

1. `packages/kernel/shared/src/Content/BlockRenderer.php` — the interface a new type implements: `type()`, `schema()`, `render()`.
2. `packages/module-frontend/src/App/Config/frontendConfig.inc.php` — where a module registers its renderer FQCNs (`contentBlocks`).
3. `packages/kernel/shared/src/Content/BlockRegistry.php` — `assemble()` folds every module's `contentBlocks` into the registry on demand.

## file map

SOURCE=/packages/kernel/shared/src/Content/BlockRenderer.php
SOURCE=/packages/kernel/shared/src/Content/BlockRegistry.php
SOURCE=/packages/kernel/shared/src/Content/DefaultBlockRegistry.php
SOURCE=/packages/kernel/shared/src/Content/InlineMarkdown.php
SOURCE=/packages/kernel/shared/src/Content/ContentRenderer.php
SOURCE=/packages/kernel/shared/src/Content/BlockView.php
SOURCE=/packages/kernel/shared/src/Content/Renderer/HeadingRenderer.php
SOURCE=/packages/module-frontend/src/Content/Renderer/HeroRenderer.php
SOURCE=/packages/module-frontend/src/App/Config/frontendConfig.inc.php

## mental model

A block type is **one `BlockRenderer` class** (`type()` + `schema()` + `render()`) plus **one line** registering its FQCN under a module's `contentBlocks`. `BlockRegistry::assemble()` walks the modules and folds it in. Everything else is derived: the backend editor offers the type and builds its form from `schema()`, `ContentValidator` accepts it via `types()`, stream pages render it via `render()`, and bespoke templates read it via `BlockView` (`Content::block($type)`). Adding a type touches **nothing** central.

- Core types (`heading`, `text`, `list`, `image`) live in `Z77\Shared\Content\Renderer` and are seeded by `DefaultBlockRegistry::create()`. Design-specific types live in a module (e.g. `Z77\Module\Frontend\Content\Renderer`) and are added via that module's `contentBlocks`.
- Renderers are **stateless** (no constructor) — `InlineMarkdown` is passed into `render()` per call, so `assemble()` can `new` them with no args.
- `schema()` is the authoring contract (one descriptor per field: `key`, `kind`, `label`, optional `options`/`item`/`default`); see [`../02-decisions/adr-011-block-field-schema.md`](../02-decisions/adr-011-block-field-schema.md).
- The block **storage** shape is just `{type, ...fields}` in the document's `blocks` array — a new type needs **no** change to `Content`.
- Deploy: PHP (renderer + config) is symlinked → effective immediately. New SCSS classes need `npm run build:frontend` + `composer install -d skeleton` (copies compiled CSS to `public`).

## recipe

Example: a `quote` block (text + source).

Step 1 — the renderer (`packages/module-frontend/src/Content/Renderer/QuoteRenderer.php`):

```php
<?php
namespace Z77\Module\Frontend\Content\Renderer;

use Z77\Shared\Content\BlockRenderer;
use Z77\Shared\Content\InlineMarkdown;

final class QuoteRenderer implements BlockRenderer
{
    public function type(): string { return 'quote'; }

    public function schema(): array            // → backend editor form
    {
        return [
            ['key' => 'text',   'kind' => 'textarea', 'label' => 'Zitat'],
            ['key' => 'author', 'kind' => 'text',     'label' => 'Quelle'],
        ];
    }

    public function render(array $block, InlineMarkdown $inline): string   // → stream HTML
    {
        $text   = $inline->toHtml((string)($block['text'] ?? ''));
        $author = htmlspecialchars((string)($block['author'] ?? ''), ENT_QUOTES, 'UTF-8');
        return '<figure class="fe-quote"><blockquote>'.$text.'</blockquote>'
            . ($author !== '' ? '<figcaption>'.$author.'</figcaption>' : '')
            . '</figure>';
    }
}
```

Step 2 — register the FQCN under `contentBlocks` in `frontendConfig.inc.php`:

```php
use Z77\Module\Frontend\Content\Renderer\QuoteRenderer;
// ...
'contentBlocks' => [
    HeroRenderer::class,
    FeatureGridRenderer::class,
    ProseSectionRenderer::class,
    QuoteRenderer::class,        // ← new
],
```

Step 3 (optional) — style `.fe-quote` in a frontend SCSS partial, then `npm run build:frontend`.

What you get for free: editor type + form, validation, stream rendering, and bespoke access `Content::block('quote')->html('text')` / `->text('author')`.

## rules

- When adding a new block type → MUST implement `BlockRenderer` (`type()`, `schema()`, `render()`) AND MUST register its FQCN under `contentBlocks` in the owning module's `<module>Config.inc.php`; MUST NOT edit `Content`, `BlockRegistry`, `ContentController`, `ContentValidator`, or `core/Bootstrap` to add a type.
- When writing `render()` → MUST escape every author value (`htmlspecialchars` and/or `InlineMarkdown::toHtml`) and MUST choose tag(s) and CSS class(es) from fixed code; MUST NOT echo raw block data and MUST NOT derive a tag or class from arbitrary block input (map a `variant`/`style` field through a fixed whitelist instead).
- When the type is design-specific → MUST place the renderer in the module namespace (e.g. `Z77\Module\Frontend\Content\Renderer`) and register via `contentBlocks`; MUST NOT add it to core's `DefaultBlockRegistry` (keeps core free of module markup).
- When declaring `schema()` → MUST list exactly the fields `render()` reads (same keys); MUST NOT let schema and render drift (the editor builds the form from `schema()`).
- When the change is PHP only (renderer + config) → no asset deploy is needed (packages are symlinked); when new SCSS classes are added → MUST run `npm run build:frontend`, then get the compiled CSS into `public/assets` — `public/` is seed-once (ADR-024), so `composer install` won't update an existing file: delete it (or `skeleton/public`) + `composer install -d skeleton` to re-seed, or copy it in by hand.
- When a repeated type is read in bespoke mode → MUST use `Content::blocks($type)` (list) or an index; MUST NOT assume a unique named slot for a type that can appear more than once.

## see also

- [`content.md`](content.md) — the content subsystem (storage, editor, stream vs. bespoke presentation, `BlockView`)
- [`../02-decisions/adr-011-block-field-schema.md`](../02-decisions/adr-011-block-field-schema.md) — why `schema()` lives on the renderer
- [`../02-decisions/adr-012-content-services-not-in-di.md`](../02-decisions/adr-012-content-services-not-in-di.md) — why `BlockRegistry::assemble()` is a factory, not a DI service

## known issues

- None documented.

## pending

- None documented.
