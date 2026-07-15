# ADR-015 — Navigation / URL Architecture: NavigationAlias + Content Slugs

**Status:** `APPROVED`
**Date:** 2026-06-08

---

## Context

z77 needs dynamic, friendly, multi-language detail URLs — a clean public address like
`/schweiz/stadt/basel` (de) / `/fr/suisse/village/bale` (fr) that resolves to **one**
controller action (`frontend/main/city/show`) which then resolves the entity itself.
This was tracked as **ROUTE-DYN-001** (`docs/topics/routing.md`, pending).

A first design converged on 2026-06-07: **`UrlAlias`** — a plain URL→target mapping
explicitly **not** bound to a navigation node, fronted by a lazy localized cache.

The developer chose a **different direction** — rebuild the
navigation / URL layer so that

- **Navigation becomes purely functional** — structure and routing target only, no URL,
  no slugs, no language, no SEO;
- a **navigationId-bound `NavigationAlias`** owns the canonical entry URL of a navigation;
- dynamic path segments become **runtime Content Slugs**, captured after the alias and
  passed to the action.

This **supersedes the `UrlAlias` direction of ROUTE-DYN-001** and reverses several shipped
decisions: friendly `url` on Navigation (NAV-URL-001), `params` (NAV-PARAMS-001),
canonical-derived-from-4-tuple, and the unique-4-tuple rule (NAV-DUP-001).

Starting state to migrate from:

- `Navigation` owns friendly `url` + `params`; `findByPath()` matches the canonical URL
  (built from the 4-tuple), then the friendly `url`, then a `params` subset.
- `SlugTranslator` + `route-slugs.{lang}.json` normalize **structural** segments
  inbound/outbound (ADR-014).
- `PageCache` keys rendered HTML by the 5-tuple `PageIdentity`
  `(language, module, group, controller, action)`.
- `MetaData` is navId-keyed (`findMetaData(navId, lang)`).

Four design forks (D1–D4) were decided before writing this ADR; they are the body below.

## Decision

A layered separation of responsibilities:

```text
Navigation       → structure only: module/group/controller/action, parentId, sortKey,
                   navigationGroupId, ref, active/visibleInMenu. NO url/slug/language/SEO.
NavigationAlias  → id, navigationId (FK), path, isCanonical, active. Canonical entry URL.
Content Slugs    → runtime path remainder after the alias, passed to the action.
SlugTranslator   → normalizes EVERY path segment localized ↔ canonical.
Cache            → keyed by the localized, as-requested URL; lazy self-populating.
Resolver         → orchestrates inbound (URL → navigation + slugs) and outbound.
```

The naming is deliberate: ROUTE-DYN-001 argued the mapping is *not* a navigation node and
must not be called `aliasNavigation`. This decision goes the other way — the alias **is** a
property of a navigation ("this entry URL leads to this navigation"), bound by a
`navigationId` FK, and named **`NavigationAlias`**.

### D1 — Disambiguation via Content Slugs, not `params`

- The `params` field is **removed** from Navigation. Dynamic discrimination is carried by
  **Content Slugs** — the path remainder after the longest-matching alias — positional,
  passed to the action as the contract `{ navigation: Navigation, slugs: list<string> }`.
- **One** Navigation node + **one** canonical alias base path per detail *type* — never a
  node/alias per detail *page*. (`/schweiz/stadt` → one navigation; `basel`, `bern`, … are
  content slugs, not nodes.)
- Inbound matching is **longest-prefix** over alias paths; the remainder is content slugs
  (unbounded, positional). The action resolves the entity itself.
- The **uniqueness invariant moves from the 4-tuple to the alias `path`**. Multiple
  Navigation nodes may share a 4-tuple (e.g. `city/show` once per country). This supersedes
  NAV-DUP-001.
- The "same action, statically different pages" case (the old `?subject=home|about`) belongs
  in the **content layer** (a `Page` entity keyed by `navigationId`), not in routing. It is
  unused in real data today (all `params: []`).

### D2 — Cache key = localized URL; identity = canonical

- **Identity** (resolver / NavigationAlias) = the **canonical default-language path**
  (`schweiz/stadt/basel` → navid). The resolver and the action work in canonical.
- **Cache key** (both the APCu resolution cache **and** the rendered-HTML `PageCache`) = the
  **localized, as-requested URL**: `pages/{lang}/{localized-path}.html`, e.g.
  `pages/fr/suisse/village/bale.html`.
- The **hot path is translation-free**: HIT → serve the file, no translation, no resolver.
  MISS → `SlugTranslator` normalizes all segments to canonical → resolve → render → store
  under the localized key. Lazy, self-populating.
- **Collision-free**: per language exactly one indexed (localized) URL per page (a 301
  enforces the single form, ADR-014 / SEO-301).
- **Invalidation**: per entity = canonical → localize per language → unlink the localized
  keys; bulk = `clearAll()` on deploy. The structured
  `invalidateController/Group/Module` subtree API is dropped (the localized cache tree no
  longer mirrors the routing target).

### D3 — SlugTranslator normalizes ALL segments; split backing

- `SlugTranslator` normalizes **every** path segment localized → canonical, uniformly —
  structural **and** content. After translation the path is fully canonical and the action
  sees **only canonical** slugs (it is language-agnostic).
- The backing is **split by scale**: structural segments from the small flat
  `route-slugs.{lang}.json` (load + invert is fine at finite size); content/entity slug
  translations from an **indexed store** that scales (fed by entities on save). The store
  *mechanism* is an implementation detail (build Phase 5); the *requirement* is fixed here.
- A lazy APCu cache (`route:{lang}:{localizedPath}` → target) keeps translate + resolve on
  the **cold path only**.

### D4 — Metadata: navId for static, entity override for detail

- navId-keyed `MetaData` (`findMetaData(navId, lang)`) stays for **static / landing** pages.
- **Detail** pages: the **action** sets metadata at runtime from the entity via an
  **override hook**, taking precedence over the navId default
  (`AbstractBaseController::html()` resolves the navId default once).
- Canonical / hreflang links use the existing `buildSeoLinks()` path (alias path + content
  slugs); no special path is needed.

## Reasoning

**Why Navigation purely functional?** Parameter / slug variants on a node explode the
structure — one node per filter or value. Keeping the node as a stable structural routing
target and pushing variability into runtime content slugs keeps the tree finite and stable.

**Why bind the alias to a navigation (vs the non-bound `UrlAlias`)?** The developer wants
the alias to be the canonical entry point *of* a navigation, and to keep nav-UI active
state, menu building, and SEO-landing semantics anchored on the structural node. This is the
conscious reversal of ROUTE-DYN-001's "not a nav node".

**Why identity canonical but cache key localized?** Identity must be language-independent and
stable, so the resolver/translator have one target and the entity one canonical slug. The
cache, by contrast, must be cheap on the hot path — keying by the exact incoming localized
URL makes a hit a single file lookup with zero translation. Identity ≠ cache key, on purpose.

**Why translate content slugs in the SlugTranslator (not per-language in the action)?** It
keeps the action language-agnostic (always canonical), centralizes all language handling in
one seam, and matches ADR-014's principle "translate inbound to canonical; downstream sees
only canonical".

**Why metadata from the entity for detail pages?** navId is 1:N to detail pages;
title / description / canonical are per-entity, so the action is the only place that knows
them.

## Consequences

**Easier:**
- Finite, stable navigation tree — detail pages never inflate it.
- URL identity lives in one place (the alias path); the cache hot path is a direct file hit.
- One language seam (the SlugTranslator) for both structural and content slugs.

**Harder / migration work:**
- Remove `url` + `params` from `Navigation` (entity, accessors, validator, edit form, JSON).
- Backend CRUD splits into `Navigation` (structure) + `NavigationAlias` (URL) management.
- `PageCache` re-keyed from the 5-tuple `PageIdentity` to the localized URL; the
  `invalidate*` subtree API is replaced by per-URL invalidation + deploy `clearAll()`.
- Data migration: every current `url`/`params` route → a `NavigationAlias` row.
- New **indexed** backing for content-slug translation; `SlugTranslator` extended to content
  slugs.
- Metadata **override hook** on the controller base.

**Reversed prior decisions:** NAV-URL-001 (friendly `url` on Navigation), NAV-PARAMS-001
(`params`), NAV-DUP-001 (unique 4-tuple → now unique alias `path`), the 5-tuple
`PageIdentity`, and ROUTE-DYN-001's `UrlAlias` direction.

**Language stability addendum (2026-06-08, Variante B — supersedes ADR-013 §"Language
resolution & persistence"):** a prefix-less resolved URL ALWAYS renders `defaultLanguage`;
the session NEVER overrides the rendered language of a resolved page. The remembered
preference only redirects the bare site root (`/` → `/{lang}`). This is required for the
canonical URL to be language-stable (the alias path `/home` must be one language, not
session-dependent), and for canonical/hreflang to come from the canonical alias path
(`AbstractBaseController::currentCanonicalPath()`), not the as-requested path. Recorded in
`i18n.md` (I18N-LANG-STABLE-001).

**Deferred:** the content-slug translation **store mechanism** (Phase 5); the content-layer
`Page` entity for the "same action, statically different pages" case.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| `UrlAlias`, **not** navigation-bound (ROUTE-DYN-001) | Developer chose the alias as a property *of* a navigation (entry URL leads to a navigation), to anchor nav-UI / menu / SEO-landing semantics on the structural node. |
| Keep `params` on Navigation | Overloads the structural node; dynamic discrimination belongs in runtime content slugs. Unused in real data anyway. |
| One Navigation node + alias **per detail page** | Tree / alias explosion; defeats "navigation = finite structure". |
| Cache key = canonical default-language URL | Forces localized→canonical translation (incl. entity slugs) on every hot-path hit; the localized key makes a hit translation-free. |
| Cache key = 5-tuple (status quo) + slug `variant` segment | Keeps the old "URL = 5-tuple" identity alive in the cache, parallel to the new URL-as-identity model; a bolt-on, less consistent. |
| Entity owns per-language slugs, action resolves per language (`findBySlug(slug, lang)`) | Splits language handling across actions; normalizing to canonical in the SlugTranslator keeps the action language-agnostic and centralizes the seam. |
| Keep all slug translations in flat `route-slugs.json` | Does not scale to thousands of entity slugs (load + invert per miss). |

## see also

- ROUTE-DYN-001 (`docs/topics/routing.md`, pending) → the requirement + the superseded
  `UrlAlias` design this replaces
- ADR-005 → the 4-segment URL schema (still the routing-target form behind the alias)
- ADR-007 / ADR-008 / ADR-009 → the navigation tree model that structural `Navigation` keeps
- ADR-014 → the translation layer this extends to content slugs
- ADR-013 → page-cache-per-language this re-keys
- `docs/topics/navigation.md`, `routing.md`, `translation.md`, `metadata.md` → area topics to
  update when implementing
