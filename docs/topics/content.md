# content

2026-07-02

## entry

1. `packages/kernel/shared/src/Services/ContentService.php` — frontend entry: `ContentService::create()` factory → `find()` (entity, bespoke) / `render()` (HTML, stream) by slug (active-gated)
2. `packages/kernel/shared/src/Entities/Content.php` — the slug-addressed content document (slug, language, title, active, blocks)
3. `packages/kernel/shared/src/Repositories/ContentRepository.php` — `findBySlug($slug, $language)` (O(1) document-store load)

## file map

SOURCE=/packages/kernel/shared/src/Services/ContentService.php
SOURCE=/packages/kernel/shared/src/Entities/Content.php
SOURCE=/packages/kernel/shared/src/Repositories/ContentRepository.php
SOURCE=/packages/kernel/shared/src/Content/InlineMarkdown.php
SOURCE=/packages/kernel/shared/src/Content/BlockRenderer.php
SOURCE=/packages/kernel/shared/src/Content/BlockRegistry.php
SOURCE=/packages/kernel/shared/src/Content/ContentRenderer.php
SOURCE=/packages/kernel/shared/src/Content/BlockView.php
SOURCE=/packages/kernel/shared/src/Content/DefaultBlockRegistry.php
SOURCE=/packages/kernel/shared/src/Content/Renderer/HeadingRenderer.php
SOURCE=/packages/kernel/shared/src/Content/Renderer/TextRenderer.php
SOURCE=/packages/kernel/shared/src/Content/Renderer/ListRenderer.php
SOURCE=/packages/kernel/shared/src/Content/Renderer/ImageRenderer.php
SOURCE=/packages/module-frontend/src/Content/Renderer/HeroRenderer.php
SOURCE=/packages/module-frontend/src/Content/Renderer/FeatureGridRenderer.php
SOURCE=/packages/module-frontend/src/Content/Renderer/ProseSectionRenderer.php
SOURCE=/packages/module-frontend/src/App/Config/frontendConfig.inc.php
SOURCE=/packages/kernel/core/data/content/home.de.default.json
SOURCE=/packages/kernel/core/data/content/about.de.default.json
SOURCE=/packages/kernel/core/data/content/home.fr.default.json
SOURCE=/packages/kernel/core/data/content/about.fr.default.json
SOURCE=/packages/module-frontend/src/Ui/Controllers/Main/IndexController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/ContentController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/BackendAbstractController.php
SOURCE=/packages/kernel/shared/src/Validators/ContentValidator.php
SOURCE=/packages/module-backend/res/scss/components/_lang-switch.scss
SOURCE=/packages/module-backend/res/view/templates/Content/ContentController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/ContentController/edit.tpl.php
SOURCE=/packages/module-backend/res/assets/js/content/editor.js
SOURCE=/packages/module-backend/res/assets/css/content/editor.css
SOURCE=/packages/module-backend/res/view/templates/Content/ContentController/confirmDelete.tpl.php
RUNTIME=/skeleton/data/content/home.de.json
RUNTIME=/skeleton/data/content/about.de.json
RUNTIME=/skeleton/data/content/home.fr.json
RUNTIME=/skeleton/data/content/about.fr.json

## mental model

Content is a **slug-addressed, self-contained document**, not page-bound. Identity is `(slug, language)`; it is stored one file per record in document mode (`data/content/<slug>.<language>.json`, see [`persistence-file.md`](persistence-file.md)). The body is an ordered array of heterogeneous blocks (`[{type, ...fields}, ...]`). A controller composes a page by loading one or more documents by slug and placing them; reuse across pages is free (the same slug read by several controllers).

- Block order is the array order (authoritative) — blocks are never queried individually nor routed, so there is no `sortKey`.
- Rendering is **server-controlled**: `BlockRegistry` maps a block `type` to a `BlockRenderer`. The renderer picks the HTML tag(s) and escapes all author text — there is no path for raw author HTML to reach the page.
- `BlockRenderer`s are **stateless** (no constructor): `render(array $block, InlineMarkdown $inline): string`. `InlineMarkdown` is passed in per call, so a renderer can be declared by class name and `new`-ed with no args.
- **Each renderer also declares its `schema()`** — the authoring field descriptors for that type (key, kind, label, options/item/default), co-located with `render()` so fields and rendering never drift (see [`../02-decisions/adr-011-block-field-schema.md`](../02-decisions/adr-011-block-field-schema.md)). Field kinds: `text`, `textarea`, `select` (`options` value⇒label), `url`, `bool`, `list` (`item` = a kind string for scalars, or a descriptor list for object repeaters like `features.items`). `BlockRegistry::schemas()` / `schema($type)` expose them; the visual block editor builds its per-type forms from these. The block STORAGE shape is unchanged — schema describes authoring only.
- Inline formatting lives only inside a block's text value, via `InlineMarkdown` (whitelist `**bold**`, `*italic*`, `[label](url)`). Escape-first, then introduce whitelisted tags.
- Unknown block types render to an empty string (safe default).
- **The registry is assembled on demand via `BlockRegistry::assemble()`** (NOT a DI service — see [`../02-decisions/adr-012-content-services-not-in-di.md`](../02-decisions/adr-012-content-services-not-in-di.md)) from `DefaultBlockRegistry::create()` (the four core types) **plus every module's `contentBlocks` config** (renderer FQCNs, instantiated no-arg; walked via the DI `ModuleManager`). This is the extension point: a module ships design-specific block types without touching core. The frontend ships `hero` / `features` / `prose-section` (the `fe-*` markup) — declared in `frontendConfig.inc.php`. `BlockRegistry::types()` lists all known types (for the backend editor). A consumer assembles once and holds it locally — not per block/slug.
- **Two presentation modes over the same blocks** (one storage, one editor — only the page template differs):
    - **Stream** (author composes): `ContentRenderer` renders all blocks in order into one HTML string; the page template is generic (`<?= $contentHtml ?>`); the visual editor's reorder/add drives the page.
    - **Bespoke** (designer composes): the page template owns the markup (free `<section>`/`<div>`/`<hN>` + project CSS) and reads blocks as data via `Content::block($type)` / `blocks($type)` / `has($type)` → `BlockView` (`get()` raw, `text()` escaped, `html()` inline-formatted, `list()` repeater — object items wrapped as `BlockView`, scalars raw). A missing block is an empty `BlockView` null-object (`exists()===false`, `text()===''`, `list()===[]`) — no null checks; gate the wrapper with `has()`.
    - Not exclusive: a template can hand-place a hero, then dump a stream region below. The `ContentRenderer` is one presentation adapter, not the heart — `BlockView` is the parallel read path.
    - **Reference consumers (live):** `IndexController::homeAction` = stream (`render()` → `contentHtml`); `IndexController::aboutAction` = bespoke (`find()` → entity → `aboutAction.tpl.php` reads via `BlockView`). Both edit through the same backend block editor. Note: bespoke access is by-type — a repeated type (two `prose-section`s on about) is reached via `blocks($type)` (list) or index, not a unique name; distinct roles want distinct types or a future keyed-block concept.
- v1 has **no `section` field**. Zones on a page = multiple slugs composed by the controller (or a future container block). Reintroducing a page/section column would re-couple content to a page.
- **`active` (bool, default true)** gates public visibility: an inactive document is editable in the backend but not rendered on the frontend. The gate lives in `ContentService` (not in the renderer, not in the entity) — `find()` / `render()` return null / `''` for inactive documents; the repository returns them regardless so the backend editor still sees them.
- **Language fallback (ADR-013):** `ContentService::find()` falls back to the `defaultLanguage` document when the requested-language document does **not exist** (e.g. `/fr/about` with no `about.fr.json` → `about.de`). An existing-but-**inactive** document is a deliberate state and does NOT fall back. See [`i18n.md`](i18n.md).
- **Frontend wiring (live):** `ContentService` (in `Z77\Shared\Services`) is **consumer-built, not a DI service** — `ContentService::create()` composes `ContentRepository` (from the DI `UnifiedEntityManager`) + a `ContentRenderer` over `BlockRegistry::assemble()` (see [`../02-decisions/adr-012-content-services-not-in-di.md`](../02-decisions/adr-012-content-services-not-in-di.md)). A controller calls `ContentService::create()->render($slug, $language)` and passes the HTML to its template. `IndexController::homeAction` is the reference consumer — the **whole home page** is content-driven: `homeAction.tpl.php` is just `<?= $contentHtml ?>`, and `home.de.json` holds the hero/features/prose-section blocks. Runtime documents live at `data/content/<slug>.<language>.json`.
- **Backend editor (`ContentController`, group `content`, `/backend/content/content/*`):** document-mode CRUD following the clean→hydrate→validate pattern ([`entity-data-handling.md`](entity-data-handling.md)). Identity is `(slug, language)` — there is no int id, so edit/delete address documents by `?slug=&language=` and the entity-CSRF key is `"<slug>.<language>"` (`CsrfService` entity-token id widened to `int|string`). `slug`+`language` are **immutable on edit** (disabled inputs + forced server-side) so a rename can't orphan the file. v1 edits metadata (title, active) + blocks as a **validated JSON textarea**; `ContentValidator` checks slug/language/title and that every block's `type` is in `BlockRegistry::types()`. Reachable via navigation entry id 19 «Inhalte» under «Webseiten». The visual per-type block editor is the next step (2b).
- **Editing-language mode (CONTENT-LANG-001, 2026-06-06):** the backend UI chrome stays single-language (German), but the *content* it edits has the `(slug, language)` dimension. The editor scopes to **one editing language at a time** — session-sticky, mirroring the frontend language-session model (ADR-013): a `?language=<code>` on the list is only the **switch trigger**, the active language lives in the session (`BackendAbstractController::contentEditLanguage()` / `setContentEditLanguage()`, key `backendContentLanguage` — deliberately distinct from the frontend request-language session key so the two never interfere). `listAction` filters `findAll()` to that language so de/fr rows never mix; a prominent `be-lang-switch` banner (and the `be-lang-tag` in the editor modal header) keeps the active language unmistakable so content is never entered under the wrong language. **The mode is the single source of a new document's language**: `addAction` seeds `new Content()` with the mode language, the edit form shows `language` read-only, and the add POST path forces it from the session mode (a crafted body cannot place a document under another language). To author another language, switch the mode. This is the reusable pattern for [`metadata.md`](metadata.md) "Backend metadata per language".
- **Seeding:** starter content ships as `core/data/content/<slug>.<lang>.default.json` and is deployed by the Installer to `data/content/<slug>.<lang>.json` on first install (never overwritten) — same generic `*.default.json` mechanism as navigation/seo/auth (see [`persistence-file.md`](persistence-file.md)). Hand-placing a runtime file is wrong: it does not survive a clean reinstall.

## block types

Core (in `DefaultBlockRegistry`, available everywhere):

| type | fields | renders |
|---|---|---|
| `heading` | `level` (1–6, clamped, default 2), `text` | `<hN>` (inline-formatted) |
| `text` | `content` | `<p>` (inline-formatted) |
| `list` | `style` (`bullet`\|`number`), `items[]` | `<ul>`/`<ol>` with inline `<li>` |
| `image` | `src`, `alt`, `caption`? | `<img>` (scheme-gated src), `<figure>`+`<figcaption>` when caption set |

Module-contributed (frontend, via `frontendConfig.contentBlocks` — example of the extension point):

| type | fields | renders |
|---|---|---|
| `hero` | `eyebrow`, `title`, `subline` | `<section class="fe-hero">…` |
| `features` | `eyebrow`, `title`, `items[]` ({`number`, `title`, `text`}) | `<section class="fe-section">` + `<div class="fe-grid">` of `fe-item` |
| `prose-section` | `variant` (`dark`?), `eyebrow`, `title`, `lead` | `<section class="fe-section [fe-section--dark]">…` |

## flow

```text
IndexController::homeAction()
→ ContentService::create()->render('home', $language)     // factory, not DI (adr-012)
     → ContentRepository::findBySlug('home', $language)   // document store, O(1)
     → active gate (null/'' if inactive)
     → ContentRenderer::render($content)
          → per block: BlockRegistry → BlockRenderer → escaped + inline → safe HTML
→ html(['contentHtml' => $html])
template: <?= $contentHtml ?>   // already-safe HTML, no re-escape
```

## rules

- When loading content for the **frontend** → MUST go through `ContentService::create()->find()` / `render()` (applies the `active` gate); MUST NOT call the repository directly on the frontend (would render inactive, work-in-progress content). `ContentService`/`BlockRegistry` are consumer-built factories, NOT DI services — MUST NOT register them in `core/Bootstrap` and MUST NOT call `DI::set()` from a controller (adr-012)
- When loading content in the **backend** editor → MUST use `ContentRepository::findBySlug($slug, $language)` directly (the editor must see inactive documents); content is keyed by `(slug, language)`, MUST NOT assume one document per page
- When rendering blocks in **stream** mode → MUST go through `ContentRenderer` / `BlockRegistry`; MUST NOT build block HTML directly in a template (the registry's tag whitelist + escaping is the only safe path)
- When rendering content in **bespoke** mode (designer-owned layout) → MUST read blocks via `Content::block()/blocks()/has()` + `BlockView`; MUST use `text()` for plain and `html()` for inline-formatted fields, and MUST `e()` any raw `get()` value; MUST NOT echo raw block data unescaped (the entity field discipline of every other template applies)
- When adding a new block type → MUST implement `BlockRenderer` (stateless, no constructor; signatures `render(array $block, InlineMarkdown $inline)` AND `schema(): array`), escape all author text (`htmlspecialchars` and/or `InlineMarkdown`), and choose the tag from a fixed set — MUST NOT derive the tag from block data. `schema()` MUST describe exactly the fields `render()` reads (keys/kinds), so the visual editor and the renderer stay in lock-step (adr-011)
- When a module contributes block types → MUST declare the renderer FQCNs under `contentBlocks` in its `<module>Config.inc.php`; they are folded in by `BlockRegistry::assemble()` (which walks the modules). MUST NOT register module renderers in core's `DefaultBlockRegistry` (keeps core free of module knowledge)
- When editing a content document in the backend → MUST load it via `ContentRepository::findBySlug($slug, $language)` and address it by `?slug=&language=`; `slug`+`language` are the identity and MUST stay immutable on edit (force them server-side from the loaded record — a changed slug would orphan the old file); the entity-CSRF id MUST be `"<slug>.<language>"`
- When scoping a backend content view to a language → MUST read the active editing language from `BackendAbstractController::contentEditLanguage()` (session-sticky) and treat a `?language=<code>` request param only as the switch trigger (persist via `setContentEditLanguage()`); MUST NOT use the URL language prefix (`/fr/backend/...`) — that drives the backend's request/UI language, a separate concern. A new document MUST inherit the mode language (force it server-side on the add POST); MUST NOT expose a free language picker that could create a document under the wrong language. This is the same pattern metadata-per-language MUST follow.
- When validating posted blocks → MUST reject any block whose `type` is not in `BlockRegistry::types()` and MUST report invalid JSON (the entity silently turns bad JSON into `[]`); `ContentValidator` receives the raw blocks string for this
- When a block carries a URL (link target, image `src`) → MUST scheme-gate it (site-relative `/`/`#`, or `http`/`https`/`mailto`); MUST reject `javascript:`, `data:`, protocol-relative
- When storing inline formatting in block text → MUST use the `InlineMarkdown` whitelist (`**`, `*`, `[](url)`); MUST NOT store raw HTML in block text
- When a page needs several content zones → MUST compose via multiple slugs at the controller level (or a future container block); MUST NOT add a page/section field to `Content` (re-couples content to a page)

## see also

- [`persistence-file.md`](persistence-file.md) — document mode (`perRecord` + `keyBy`) that `Content` uses; `ContentRepository` extends `FileRepository`
- [`../02-decisions/adr-010-file-per-record-storage.md`](../02-decisions/adr-010-file-per-record-storage.md) — why content is one file per record
- [`block-types.md`](block-types.md) — recipe: how to add a new block type (renderer + `contentBlocks`)
- [`i18n.md`](i18n.md) — language policy + the default-language fallback `ContentService::find()` applies; the (slug, language) language dimension
- [`entity-data-handling.md`](entity-data-handling.md) — clean→hydrate→validate pipeline for the Phase 3 backend editor
- [`../02-decisions/adr-011-block-field-schema.md`](../02-decisions/adr-011-block-field-schema.md) — why the block field schema lives on the `BlockRenderer` (`schema()`)
- [`../02-decisions/adr-012-content-services-not-in-di.md`](../02-decisions/adr-012-content-services-not-in-di.md) — why `ContentService`/`BlockRegistry` are consumer-built factories, not DI registrations
- [`documents.md`](documents.md) — the DMS is the asset store a future DMS-referencing block (gallery/slider) links into by document `id` via `DocumentService::publicUrl()`; see the pending item below

## known issues

- None documented.

## pending

- **RESUME (2026-06-04)** — Done through **Phase 2 + backend editor + visual block editor (3b)**: file-per-record persistence (adr-010), `Content` entity + `ContentRepository`, block registry (module-extensible) + `InlineMarkdown` + core/frontend renderers, `ContentService` (active-gated), home page fully content-driven, backend `ContentController` CRUD. **Phase 3b DONE:** `schema()` on every `BlockRenderer` (adr-011) → `BlockRegistry::schemas()` → the modal renders a per-type visual form per block (add/remove/reorder, list repeaters for scalar + object items), `content/editor.js` serialises to the same `blocks` JSON the server validates. Verified live end-to-end (login → edit modal renders all types → save modified blocks → frontend home renders them). **Also done (2026-06-04): bespoke presentation** — `BlockView` + `Content::block()/blocks()/has()`; `/home` stays stream (`render()`), `/about` is the live bespoke reference (`find()` → entity → template reads blocks as data). Adding a type is documented in [`block-types.md`](block-types.md). **Next: Phase 4 (full-text search)**, navigation→slug routing, or keyed blocks (see below).
- **Navigation → slug routing** — `home` currently hardcodes slug `'home'` in `IndexController`. Decide how a navigation entry maps to a content slug (e.g. via the `?subject=` param already designed in `navigation.md`, or a dedicated content route) so pages declare their slug in navigation rather than in controller code.
- **Phase 3b — visual block editor — DONE (2026-06-04).** The edit modal renders a per-type visual form per block from `BlockRegistry::schemas()`: add (type picker → cloned `<template data-ce-tpl>`), remove, reorder (↑/↓; block order = DOM order), and list repeaters for scalar (`list.items`) and object (`features.items`) items. `content/editor.js` (`_Z77.scriptInit['content-editor']`, loaded via `load-script`) keeps a hidden `name="blocks"` in sync as the same JSON the server already validates — block inputs carry NO `name` (the shared form collector ignores them); single source = the visual editor. Unknown-type blocks are preserved verbatim via `data-ce-raw`. The old raw JSON textarea was **replaced** by the visual editor + a read-only `<details>` JSON preview (a raw-edit toggle was intentionally dropped to avoid a dual `name="blocks"` source). Verified live end-to-end. Minor follow-ups (not blocking): (a) interactive DOM ops (add/remove/reorder/repeater clone) were contract-verified + the serialised JSON round-trip was tested, but a human browser click-through is still worth one pass; (b) `select`/number fields round-trip as strings (e.g. `level` "2") — renderers cast, so harmless; tighten only if a type needs real ints.
- **Slug rename** — currently sidestepped: `slug`+`language` are immutable in the editor (no rename → no orphan). If renaming is wanted later, the editor must remove the old record before persisting the renamed one (see adr-010).
- **Keyed blocks (named bespoke slots)** — bespoke access is by-type (`Content::block($type)`); a repeated type (e.g. two `prose-section`s on `/about`) can only be reached via `blocks($type)` (list) or index, not a unique name. If named sections are wanted (`block('mission')` vs `block('standort')`), design a keyed-block concept (an optional `key`/`slot` field on a block + a `Content::keyed($key)` accessor) or use distinct types per role. Not blocking — surfaced by the `/about` bespoke build.
- **Phase 4 — full-text search** — SQLite FTS5 derived index, indexed on save; result list reads title/excerpt from the index without touching content files.
- **DMS-referencing block + `document` field kind (design agreed 2026-07-02, not built).** Content stays in `ContentService`/entity; the DMS ([`documents.md`](documents.md)) stays the asset store — a page **references** DMS documents, it does NOT store content JSON as a blob (that would lose slug-lookup, the block editor, validation, the `active`/i18n gates, and the safe render pipeline). Concrete first consumer: a **`gallery`/`slider`** block (module-contributed, like `hero`/`features` — MUST live in a module, NEVER in core `shared`: `shared → module-dms` is a forbidden coupling / near-cycle). Storage shape references documents by **`id`**, not a `/media` URL string: `{ "type": "gallery", "items": [ { "documentId": 42, "alt": "…", "caption": "…" } ] }`. The renderer resolves each id at render time via `DocumentService::publicUrl($doc, $variant)` (id-addressed → rename/move-safe; adds the `?v=` version token; correct per-variant extension) and builds `<img srcset>` over `s/m/l/xl`; a missing/unreadable doc → skip that slide (graceful, like an unknown block type → `''`). The new authoring piece is a **`document` (media) field kind** in the block schema/editor: an "Auswählen" button that opens the DMS **Drive in pick-mode** and returns doc id(s) (thumbnail via `document/preview?id=&variant=s`) — reusable for any block (incl. the core `image` `src`), the shared basis with the (a) content-Drive-look UI. Watch-outs: (1) a public page needs the referenced image at `deliveryMode: public` or `/media` 404s → the picker must show/enforce this; (2) module boundary as above; (3) dangling reference after a hard purge → skip silently. Editor/UX layout to be finalised first (see the DMS Drive pick-mode dependency).
