# translation

2026-09-17

## entry

1. `packages/kernel/core/src/Services/Translator.php` — UI-string translation: `t(key)` against per-language dictionaries
2. `packages/kernel/core/src/Routing/AliasPathResolver.php` — the ONE place where slug translation meets the alias layer, both directions (inbound `resolve()`, outbound `toLocalized()`)
3. `packages/kernel/core/src/autoload/prod/php/Helper.php` — the `t()` and `localizedUrl()` template helpers
4. `packages/kernel/core/src/Services/TranslationCatalog.php` — read/write access to both i18n families for the backend editor (TRANS-TOOL-001)
5. `packages/module-backend/src/Ui/Controllers/Content/TranslationController.php` — the backend translation editor (`/backend/content/translation/*`)

## file map

SOURCE=/packages/kernel/core/src/Services/Translator.php
SOURCE=/packages/kernel/core/src/Services/SlugTranslator.php
SOURCE=/packages/kernel/core/src/Routing/AliasPathResolver.php
SOURCE=/packages/kernel/core/src/Routing/Router.php
SOURCE=/packages/kernel/core/src/Services/NavigationUrlResolver.php
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
`DI::getSlugTranslator()` (registered in `Bootstrap::pullUpServices()`). UI strings
translate *text*; slugs translate *URL segments*. Canonical = the default language
(ADR-014). Since 2026-09-17 `NavigationUrlResolver`, `NavigationService`, `Router` and
`AliasPathResolver` are registered there too — `localizedUrl()` must answer in a job or a
mail template, where there is no request; only the language argument has to be passed
explicitly then (it defaults to the current request's).

- **UI strings** — `t(key, params, lang)` reads `{lang}.json` (flat `key → value`):
  current language → `defaultLanguage` → the key itself. Keys are namespaced
  (`nav.*`, `footer.*`, `common.*`). Template usage: `<?= e(t('footer.pages')) ?>` —
  `t()` returns the raw string, the caller escapes. Navigation display names use
  `t('nav.' . $entry->getAction())` so the entry stays slim (no per-language field).
- **URL slugs** — `SlugTranslator` maps single segments both ways. Only non-default
  languages have a table (`route-slugs.{lang}.json`, canonical → localized); the
  default has none (identity). **The table translates the ALIAS PART of a URL and
  nothing else** (amendment 2026-09-17 to ADR-014 + ADR-015): its keys are alias path
  segments. Its only caller is `AliasPathResolver`, which owns both directions.
  - Inbound: `Request::runParsing()` asks FIRST "is this an alias?" —
    `AliasPathResolver::resolve(segments, language)`. In a non-default language the
    segments are translated to canonical, the alias is looked up by that path (the raw
    spelling is tried after the translated one), and only a HIT adopts the translated
    form. A MISS resolves static navigation → convention **on the segments as
    requested**, untranslated. Fetch mode and reserved routes never reach the
    translator at all.
  - Outbound: `localizedUrl(canonicalUrl, lang)` → `AliasPathResolver::toLocalized()`.
    Only a path that resolves to an alias is localized; a technical path
    (`/frontend/main/x/y`) and the slug remainder behind an alias get the language
    prefix and nothing else. `?query` / `#fragment` are split off first and carried
    over verbatim. Used by nav/footer links and the `$languageSwitch` builder in
    `AbstractBaseController`.
  - Inbound 301 (SEO single-form): on an alias HIT, `Request::enforceLocalizedForm()`
    compares the requested spelling with the single localized form the resolver
    returned and throws `LocalizedRedirectException` when they differ; caught in
    `Bootstrap::pullUp()` → `RedirectResponse(301)`. Only the ALIAS PART is localized —
    the slugs are carried over as requested (each segment `rawurlencode`d, original
    query string appended). Safe by construction: the target comes from the same class
    that just matched, so the 301 never lands on a 404. Read methods + non-default
    language only. A technical path has NO localized form and is never redirected.
  - Content slugs are never translated: the structural table is not a content store,
    and an entity slug that happens to equal a table value (`/fr/references/contact`)
    would otherwise be rewritten. The action owns the entity lookup, language included.
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
  ALIAS path is the single indexed URL. Reaching an aliased page via its canonical (or
  any non-localized) spelling `301`s to the localized form — `/fr/privacy` →
  `/fr/confidentialite` (see "inbound 301" above). The default language is itself
  canonical (no redirect). Since 2026-09-17 this holds for alias URLs only: the
  technical URL of the same page (`/fr/frontend/main/index/lage`) answers 200 as
  written and is consolidated by `rel=canonical`, not by a redirect.
- **Table invariants** (validated in `DEBUG` on load, and mirrored by the backend
  translation tool before a write): (1) localized values are 1:1 unique; (2) no
  localized value shadows a *different* canonical key (an identity mapping like
  `contact` → `contact` is allowed); (3) **no localized value equals a module key**
  (`frontend`, `backend`, …) — the alias lookup translates before it asks, so such a
  value would make `/{lang}/{module}/…` read as an alias path. The old "a KEY colliding
  with a routing identifier leaks into 4-tuple paths" worry is gone with the amendment:
  technical paths are not translated any more, in either direction.
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
- When choosing a `route-slugs` KEY → MUST use a segment of a `NavigationAlias` path (that is the only thing the table translates since the 2026-09-17 amendment). An entry for a technical segment (`module` / `group` / `controller` / `action`) is dead weight — technical paths are never translated, in either direction — and MUST NOT be added. A page that wants a localized URL MUST have an alias; without one its address is the 4-tuple and stays as written in every language.
- When choosing a localized VALUE → MUST NOT use a word that equals a module key (`frontend`, `backend`, …): the alias lookup translates the segments before it asks, so `/{lang}/{module}/…` would be read as an alias path. `SlugTranslator::validate()` throws on this in `DEBUG` and the backend translation tool refuses the write. MUST also keep the older two invariants (1:1, no shadowing of a different canonical key).
- When a localized value happens to equal the path of ANOTHER alias that has no table entry of its own → MUST check that alias first: the translated lookup wins, so the other alias is shadowed in that language. Not machine-validated (it is a data-level collision across two files).
- When resolving the request language in `SlugTranslator`/`Translator` consumers → MUST treat the default language as canonical (no table, no prefix); MUST NOT create a `route-slugs.{default}.json`.
- When adding a new public page → MUST add its `nav.<action>` dict keys (all languages); a localized URL additionally needs a `route-slugs` entry — without it the canonical URL still works.
- When a string is rendered by `core.js` (client-built markup) → MUST add it under the `js.*` namespace in every language dictionary and read it via `_Z77.core.i18n.t('js.<key>', '<fallback>')`; MUST NOT hard-wire the literal in JS. If the string is also rendered server-side (shared vocabulary) → reuse the existing key (e.g. `common.close`) and add it to `Translator::SHARED_CLIENT_KEYS` so it travels to the client. MUST NOT duplicate a shared string under a separate `js.*` key.
- When emitting SEO head links → MUST render them from the `$seo` context (`AbstractBaseController::buildSeoLinks()`) in `partials/head/seo.tpl.php`; MUST NOT build canonical/alternate URLs ad-hoc in a template. Canonical is always emitted (self-referencing); `hreflang` alternates only in a ≥2-language environment.
- When editing translation values / slugs at runtime → SHOULD use the backend translation tool (`/backend/content/translation`), which validates slug invariants and clears the page cache; hand-editing the runtime JSON works but skips both. All writes MUST go to the RUNTIME files, never the `*.default.json` seeds (installer-owned, regenerated on reinstall). New keys still originate in code via a `t('key')` call — the tool fills values, it does not discover usage.

## known issues

- **TRANS-ALIAS-001** — resolved 2026-09-17 (amendment to ADR-014 + ADR-015; build plan
  `docs/03-development/plan-alias-first-routing.md`). Don't assume slug translation is
  global any more: it applies to the ALIAS PART of a URL and to nothing else. Before,
  `Request::translateSlugsToCanonical()` rewrote EVERY segment before anything was
  matched, so a localized VALUE equal to a technical identifier broke technical URLs —
  with `kontakt → contact` (fr) the live endpoints `/fr/frontend/main/contact/get-form`
  (the public-form blur check) and `/fr/frontend/main/contact/danke` (the PRG target)
  became `…/kontakt/…` and 404'd. The old text in this file knew only the KEY side of the
  collision and claimed "no 404". Fixed by asking the alias question FIRST
  (`Routing/AliasPathResolver`, one class for both directions): translate → look the alias
  up (raw spelling tried after the translated one) → a HIT adopts the translated form and
  301s to the single localized form; a MISS resolves static navigation → convention on the
  segments as requested. `translateSlugsToCanonical()` and `enforceLocalizedSlug()` are
  gone (the latter replaced by `enforceLocalizedForm()`); a third table invariant
  (localized value ≠ module key) closes the hole in the validator. Deliberate behaviour
  changes: `/fr/frontend/main/index/lage` = 200 without a 301 (was a 301 to
  `…/situation`), `/fr/frontend/main/index/situation` = 404 (was 200), a content-slug
  remainder is no longer rewritten by the table. Verified: `php tests/routing-alias-first.php`
  (59 checks) plus an end-to-end run against a real installation with `kontakt → contact`.

- **TRANS-CHECK-URL-001** — open. Don't assume a public form's blur check answers in the
  page's language: `PublicFormHandler::checkUrl()` builds the endpoint as
  `/{module}/{group}/{controller}/check` from the current `Request` getters, with NO
  language prefix. On `/fr/contact` the check request therefore arrives prefix-less, the
  default language is rendered (a prefix-less URL always renders `defaultLanguage`,
  I18N-LANG-STABLE-001), and the field message comes back German on a French page. Fix is
  a `localizedUrl()` around the assembled path — untouched here because it is a behaviour
  change on every project that ships a public form.

- **TRANS-SLUG-PATTERN-001** — open. Don't assume every live `route-slugs` table can be
  edited in the backend tool: `TranslationCatalog::SLUG_PATTERN`
  (`/^[a-z0-9]+(-[a-z0-9]+)*$/`) allows lowercase, digits and dashes only, while real
  tables carry underscores — zihlundsee's `route-slugs.fr.json` has
  `"gut_zu_wissen": "bon_a_savoir"`. Such a row reads and translates fine (the runtime
  reads the JSON directly), but saving it through `/backend/content/translation` is
  rejected as an invalid slug. Either the pattern admits `_` or the aliases are renamed to
  dashes — a decision, not a bug fix.

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
- **TRANS-SEED-001 — missing-key adoption in the backend editor (ADR-032 scope decision, 2026-08-08)** — runtime catalogs are file-level seed-once, so UI-string keys added to the shipped `*.default.json` after installation never reach an existing project. Decided: this is NOT the ADR-032 entity import (catalogs are flat `key → value` maps, no entities) — instead `TranslationCatalog` gets a small compare-and-adopt feature: list keys present in the vendor default but absent in the runtime catalog, adopt selected ones (never overwrite an existing value). See [`../03-development/review-import-adr-032.md`](../03-development/review-import-adr-032.md) IMP-R012.

## see also

- [`i18n.md`](i18n.md) — language policy (default/available languages, session persistence, content fallback); the `$languageSwitch` context this layer's `localizedUrl()` feeds
- [`navigation.md`](navigation.md) — the public URL is a `NavigationAlias.path` resolved via `urlFor()` (ADR-015); `action` drives the `nav.<action>` display keys; both feed `localizedUrl()`
- [`routing.md`](routing.md) — `Request` parsing where inbound slug→canonical translation is injected before route matching
