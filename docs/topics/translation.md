# translation

2026-07-12

## entry

1. `packages/kernel/core/src/Services/Translator.php` — UI-string translation: `t(key)` against per-language dictionaries
2. `packages/kernel/core/src/Services/SlugTranslator.php` — URL path segment ↔ canonical mapping (routing)
3. `packages/kernel/core/src/autoload/prod/php/Helper.php` — the `t()` and `localizedUrl()` template helpers
4. `packages/kernel/core/src/Services/TranslationCatalog.php` — read/write access to both i18n families for the backend editor (TRANS-TOOL-001)
5. `packages/module-backend/src/Ui/Controllers/Content/TranslationController.php` — the backend translation editor (`/backend/content/translation/*`)

## file map

SOURCE=/packages/kernel/core/src/Services/Translator.php
SOURCE=/packages/kernel/core/src/Services/SlugTranslator.php
SOURCE=/packages/kernel/core/src/Services/TranslationCatalog.php
SOURCE=/packages/kernel/core/src/Services/HtmlView.php
SOURCE=/packages/kernel/core/src/autoload/prod/php/Helper.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/TranslationController.php
SOURCE=/packages/module-backend/res/view/templates/Content/TranslationController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/TranslationController/edit.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Content/TranslationController/confirmDelete.tpl.php
SOURCE=/packages/kernel/core/src/Http/Request.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/Bootstrap.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/kernel/core/data/framework/i18n/de.default.json
SOURCE=/packages/kernel/core/data/framework/i18n/fr.default.json
SOURCE=/packages/kernel/core/data/framework/i18n/route-slugs.fr.default.json
SOURCE=/packages/module-frontend/res/view/templates/partials/header.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/partials/footer.tpl.php

RUNTIME=/skeleton/data/framework/i18n/de.json
RUNTIME=/skeleton/data/framework/i18n/fr.json
RUNTIME=/skeleton/data/framework/i18n/route-slugs.fr.json

## mental model

Two independent translation mechanisms, both reading per-language data under
`data/framework/i18n/` and both DI services reached via `DI::getTranslator()` /
`DI::getSlugTranslator()` (registered in `Bootstrap::pullUp()`). UI strings translate
*text*; slugs translate *URL segments*. Canonical = the default language (ADR-014).

- **UI strings** — `t(key, params, lang)` reads `{lang}.json` (flat `key → value`):
  current language → `defaultLanguage` → the key itself. Keys are namespaced
  (`nav.*`, `footer.*`, `common.*`). Template usage: `<?= e(t('footer.pages')) ?>` —
  `t()` returns the raw string, the caller escapes. Navigation display names use
  `t('nav.' . $entry->getAction())` so the entry stays slim (no per-language field).
- **URL slugs** — `SlugTranslator` maps single segments both ways. Only non-default
  languages have a table (`route-slugs.{lang}.json`, canonical → localized); the
  default has none (identity).
  - Inbound: `Request::translateSlugsToCanonical()` runs after `extractLanguage()`,
    before routing — localized → canonical, so the router/cache/convention chain only
    sees canonical segments. Non-translatable → unchanged (canonical resolves, garbage
    404s).
  - Outbound: the `localizedUrl(canonicalUrl, lang)` helper maps each segment
    canonical → localized and prepends the language prefix (default = no prefix). Used
    by nav/footer links and the `$languageSwitch` builder in `AbstractBaseController`.
  - Inbound 301 (SEO single-form): after a route matches, `Request::enforceLocalizedSlug()`
    re-localizes the matched canonical segments and compares them to what was requested;
    if they differ it throws `LocalizedRedirectException`, caught in `Bootstrap::pullUp()`
    → `RedirectResponse(301)`. Safe by construction — the target is built from segments
    that just matched, and the table is a validated 1:1 round-trip, so the 301 never lands
    on a 404. Read methods + non-default language only.
  - Head SEO links: `AbstractBaseController::buildSeoLinks()` provides `$seo` to the head —
    a self-referencing `<link rel="canonical">` (current language's localized URL) plus
    `<link rel="alternate" hreflang>` for each offered language and an `x-default`
    (absolute URLs via `url_origin()`). Rendered by `partials/head/seo.tpl.php`; alternates
    only when the environment offers ≥2 languages, canonical always.
- **JS-side strings** — `core.js` builds some markup at runtime (the close-button
  label on dynamically created flash/message elements, the connection-error and
  script-load-error messages). These read the same single-source dictionary: keys
  are either shared with server-rendered markup (`common.close`) or JS-only and
  namespaced `js.*` (`js.connectionError`, `js.scriptLoadError`). `Translator::clientDictionary($lang)`
  returns exactly the JS-facing subset (`js.*` + the explicit `SHARED_CLIENT_KEYS`),
  resolved with the default-language fallback; `AbstractBaseController::html()` injects
  it as context key `clientI18n` and `HtmlView::render()` inlines it into `<head>` as a
  `<script type="application/json" data-z77-i18n>` data island (framework chrome, every
  full page, `JSON_HEX_TAG` guards against a `</script>` breakout). The `_Z77.core.i18n`
  channel reads the island once at boot (`load()`), then `t(key, fallback)` resolves a
  key, degrading to the fallback literal (then the key) when the string is missing — so
  JS never breaks if the island is absent. The inlined block is, by construction, the
  exact list of what the client needs; grep `js\.` to discover the JS-only keys.
- **One indexed form per page** (SEO-301): in a non-default language the localized
  slug is the single canonical URL. Reaching a page via its canonical (or any non-
  localized) slug `301`s to the localized form — `/fr/privacy` → `/fr/confidentialite`
  (see "inbound 301" below). The default language is itself canonical (no redirect).
- **Table invariants** (validated in `DEBUG`, throws): localized values are 1:1
  unique; no localized value shadows a *different* canonical key (an identity mapping
  like `contact` → `contact` is allowed). NOT validated: a key colliding with a routing
  identifier (`module`/`group`/`controller`/`action`) — segment translation is global, so
  such a key leaks into 4-tuple paths; it is a naming convention (see rules).
- The `skeleton/data/framework/i18n/*.json` files are RUNTIME (installer-seeded from
  the `*.default.json` SOURCE); do not hand-edit.

## translation tool (backend)

A backend editor manages both runtime families directly, so strings and localized
slugs no longer need hand-editing the JSON (TRANS-TOOL-001). URL
`/backend/content/translation/{action}`, group `content`, ADMIN-gated; reachable as
"Übersetzungen" under the "Webseiten" backend section. One list screen, two tables
(UI-Texte + Routen-Slugs), a shared add/edit/delete modal driven by a `?kind=ui|slug`
discriminator.

- **`TranslationCatalog`** (core service, DI singleton) owns all read/write. It edits
  the RUNTIME files (`data/framework/i18n/{lang}.json` + `route-slugs.{lang}.json`),
  never the `*.default.json` seeds. Languages/columns come from `I18n::getLanguages()`;
  the default language is the master key set + fallback (UI), or canonical with no
  table (slugs). Writes go through `FileStorage` (same `LOCK_EX` + pretty-print as the
  entity layer) and then clear ONLY the page cache (`CacheManager::page()->clearAll()`)
  — both translation services lazy-load per request, so no APCu entry needs dropping.
  Keys/canonicals are stored `ksort`ed.
- **Full CRUD on keys** (developer decision 2026-06-09): the tool can add, rename, and
  delete keys/canonicals, not just edit values. Trade-off accepted: a key without a
  matching `t()` call is dead weight; a deleted key still referenced in code falls back
  to the key name (a visible miss). The tool does not scan code for key usage.
- **UI value semantics:** the default-language value is always written (master); a
  non-default value left empty drops that key from the language file so `t()` falls back
  to the default (vs. storing an empty string). "Missing" in the grid = absent in a
  non-default file.
- **Slug writes are validated** against the {@see SlugTranslator} invariants before any
  write (per language, all-or-nothing): localized targets 1:1, no localized value
  shadows a different canonical. Violations are returned to the modal as errors; nothing
  is persisted. An empty value drops the localization (segment stays canonical there).
- **Mutations are Fetch POSTs** → globally CSRF-gated by `AccessGuard` (the
  `X-CSRF-Token` header); edit/delete additionally carry a per-entry token (scope =
  `translationUi`/`translationSlug`, id = the key string). No `#[Entity]` is involved —
  these are plain JSON maps, so there is no EntityManager/repository/validator-entity.
- The DEBUG slug-table validator in `SlugTranslator::load()` still runs independently on
  read; the tool's pre-write checks mirror it so a bad edit never reaches that path.

## rules

- When outputting a static UI string in a template → MUST use `t('namespaced.key')` wrapped in `e()`; MUST NOT hard-wire the literal text. Add the key to every language dictionary (`de.json` is the default and the fallback).
- When rendering a navigation entry's display name → MUST use `t('nav.' . $entry->getAction())`; MUST NOT read `$entry->getName()` for display (that is the canonical/default identifier).
- When displaying a LANGUAGE NAME (not its bare code) → MUST use `t('lang.' . $code)` for the name in the current UI language, or `t('lang.' . $code, [], $code)` for the ENDONYM (each language in its own name — the standard for a language switcher, forced by the target-language 3rd arg); MUST add `lang.<code>` to each dictionary (the frontend switch uses this — `partials/header.tpl.php`, both the topbar and the mobile overlay). MUST NOT hard-wire the names in a template and MUST NOT inject a parallel `$langLabels` map (it duplicates `t()` and needs plumbing). A bare `strtoupper($code)` is acceptable ONLY for a deliberately compact code-only switcher.
- When rendering an internal link to a navigation entry → MUST take the canonical URL from `NavigationService::urlFor($entry)` (alias-aware, ADR-015) and pass it through `localizedUrl()` for the current language; MUST NOT emit `$entry->getUrl()` raw (that is the 4-tuple path, not the public URL). For the page's own canonical/hreflang use `AbstractBaseController::currentCanonicalPath()`.
- When adding a localized URL for a page → MUST add a `canonical → localized` entry in `route-slugs.{lang}.json`; the table MUST stay 1:1 and no localized value may shadow a *different* canonical key — identity (`contact`→`contact`) is fine (debug throws otherwise).
- When choosing a `route-slugs` key → MUST NOT reuse a segment name that also appears as a `module` / `group` / `controller` / `action` identifier of a routable entry. Slug translation is segment-level and global (`localizedUrl()` / `translateSlugsToCanonical()` map every segment by name, position-independent), so a colliding key leaks into the canonical 4-tuple path of an entry that has no friendly `url`: `/frontend/index/main/about` would localize to `/frontend/index/main/a-propos`. It still round-trips and routes (no 404), but yields a semantically wrong URL + 301. The DEBUG validator does NOT catch this cross-collision — it is a naming convention. The safe pattern: give a page a friendly `url` so its public address is a single localizable slug, not the 4-tuple.
- When resolving the request language in `SlugTranslator`/`Translator` consumers → MUST treat the default language as canonical (no table, no prefix); MUST NOT create a `route-slugs.{default}.json`.
- When adding a new public page → MUST add its `nav.<action>` dict keys (all languages); a localized URL additionally needs a `route-slugs` entry — without it the canonical URL still works.
- When a string is rendered by `core.js` (client-built markup) → MUST add it under the `js.*` namespace in every language dictionary and read it via `_Z77.core.i18n.t('js.<key>', '<fallback>')`; MUST NOT hard-wire the literal in JS. If the string is also rendered server-side (shared vocabulary) → reuse the existing key (e.g. `common.close`) and add it to `Translator::SHARED_CLIENT_KEYS` so it travels to the client. MUST NOT duplicate a shared string under a separate `js.*` key.
- When emitting SEO head links → MUST render them from the `$seo` context (`AbstractBaseController::buildSeoLinks()`) in `partials/head/seo.tpl.php`; MUST NOT build canonical/alternate URLs ad-hoc in a template. Canonical is always emitted (self-referencing); `hreflang` alternates only in a ≥2-language environment.
- When editing translation values / slugs at runtime → SHOULD use the backend translation tool (`/backend/content/translation`), which validates slug invariants and clears the page cache; hand-editing the runtime JSON works but skips both. All writes MUST go to the RUNTIME files, never the `*.default.json` seeds (installer-owned, regenerated on reinstall). New keys still originate in code via a `t('key')` call — the tool fills values, it does not discover usage.

## known issues

- **JS-I18N-001** — resolved 2026-06-06. JS-rendered strings in `core.js` (close-button
  aria-label on dynamically created flash/popup messages, connection-error, script-load-error)
  were hard-wired German. Now they read the same single-source dictionary via the
  `_Z77.core.i18n` channel: the server inlines the JS-facing subset (`Translator::clientDictionary()`)
  as a `data-z77-i18n` JSON island in `<head>` (see mental model "JS-side strings"). Chosen
  over a fetch endpoint — no extra round-trip, no race, no new route; the inline subset stays
  small (`js.*` + `common.close`). Migration to a fetch endpoint stays trivial later via the
  same `js.*` namespace if the client string count ever grows large.

- **SEO-301 / canonical+alternate** — resolved 2026-06-07. Replaced the Option-a duplicate-URL
  trade-off: a non-default-language page reached via a canonical/non-localized slug now `301`s to
  its localized form (`Request::enforceLocalizedSlug()` → `LocalizedRedirectException`
  → `Bootstrap` `RedirectResponse(301)`), fired only after a successful route match so the target
  cannot 404. The head additionally carries `<link rel="canonical">` (self-referencing) + `hreflang`
  alternates + `x-default` (`AbstractBaseController::buildSeoLinks()` → `partials/head/seo.tpl.php`).
  See mental model "inbound 301" / "head SEO links".

- **TRANS-TOOL-001** — resolved 2026-06-09. Added a backend editor for the i18n catalog so UI strings and route slugs no longer require hand-editing the JSON. New `TranslationCatalog` core service (read/write both families as a key × language matrix, atomic `FileStorage` write + page-cache clear, slug-invariant validation mirroring `SlugTranslator`) + `TranslationController` (`/backend/content/translation/*`, group `content`, ADMIN) with a two-table list and a shared `?kind=ui|slug` add/edit/delete modal. Full CRUD on keys (add/rename/delete), per developer decision — accepted trade-off: the tool does not cross-check `t()` usage in code. Empty non-default UI value ⇒ key dropped from that language (fallback); empty slug ⇒ stays canonical. Mutations are Fetch POSTs (global CSRF header + per-entry token); no `#[Entity]` (plain JSON maps). Reachable as "Übersetzungen" under the backend "Webseiten" section (navigation id 22, both nav data files). Verified: catalog logic 16/16 (matrix, rename, fallback-drop, slug 1:1 + shadow rejection, delete), template render 10/10, route resolves auth-gated (302), `php -l` all green, frontend regression-free, runtime JSON byte-identical after the test round-trip.

## pending

- **Content URL slug table** — only if content URLs ever localize their slug; today content is addressed by canonical slug + language, so no table is needed.

## see also

- [`i18n.md`](i18n.md) — language policy (default/available languages, session persistence, content fallback); the `$languageSwitch` context this layer's `localizedUrl()` feeds
- [`navigation.md`](navigation.md) — the public URL is a `NavigationAlias.path` resolved via `urlFor()` (ADR-015); `action` drives the `nav.<action>` display keys; both feed `localizedUrl()`
- [`routing.md`](routing.md) — `Request` parsing where inbound slug→canonical translation is injected before route matching
