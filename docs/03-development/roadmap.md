# Roadmap

**Last updated:** 2026-06-06

## Milestones

| Milestone | Status | Description |
|---|---|---|
| M1 — Documentation Structure | `[DONE]` | Docs structure set up, lifecycle defined |
| M2 — Request Lifecycle & Routing | `[DONE]` | View Layer + Asset Pipeline + Cache + Auth + Navigation |
| M3 — Framework Core | `[DONE]` | PHP structure, autoloading, routing — code integrated and reviewed |
| M4 — First Production Use | `[OPEN]` | Integration into a live project |

## Current Priorities (vor Publikation)

1. **Backend Dashboard** — Dashboard-Controller als erster geschützter Bereich
2. **Backend Features** — Clear-Cache-Button, Debug-Toggle (gehören zum Dashboard)
3. **Active-State-Detection** — Navigation-Entry im Menü markieren
4. **DESIGN-V001** — CSS-Pipeline-Entscheid: `styles.tpl.php` Statics vs. Pipeline
5. **Code Reviews** — CacheManager, packages/kernel/shared, packages/kernel/persistence, module-frontend
6. **Niedrig-Cleanups** — `routingLog`, `SECRET_HASH_FILENAME`, Doctrine-Stubs, Driver.php Duplikat

7. **AUDIT — discoverable single source** — verify the config principle from
   [conventions.md](../01-handbook/conventions.md#configuration--semantically-discoverable-single-source)
   (introduced with ADR-013/i18n) is carried through the rest of the framework:
   no scattered duplicate config values, every global setting under a semantically
   named source. One-time framework-wide pass.

→ Detailed triage process and idea backlog: [triage.md](triage.md)

## i18n / Translation — Status (2026-06-05)

First production project needs `de` + `fr`. Built and verified live this session.
Per-area detail + rules live in the topic docs; this is the resume overview.

**Done:**
- **Language policy (ADR-013)** — central `config/i18n` (`defaultLanguage` + `languages`), `I18n` service, installer-generated config, scattered `DEFAULT_LANGUAGE` constants removed, URL language segment validated against the whitelist. → [`i18n.md`](../topics/i18n.md)
- **Frontend language switch** — per-module `languages` opt-in (frontend `['de','fr']`), session persistence (URL prefix sets + remembers, prefix-less reads session), content language fallback (`ContentService::find`), switch button (topbar + mobile overlay) with dedicated SCSS. `home`/`about` translated to `fr`.
- **Translation layer (ADR-014)** — UI strings via `t()` + per-language dictionaries (`data/framework/i18n/{lang}.json`); URL slug translation via `SlugTranslator` + `localizedUrl()` (canonical=default, inbound localized→canonical before routing, outbound canonical→localized for links), debug-validated slug tables. → [`translation.md`](../topics/translation.md)
- **Popup close hardening (POPUP-CLOSE-001)** — backdrop click no longer discards an edit. → [`fetch.md`](../topics/fetch.md)
- **Popup ESC (POPUP-ESC-001, 2026-06-07)** — kept ESC as a deliberate close shortcut (by decision, no code change); both `[data-popup-close]` and ESC are accepted discard paths, only the backdrop click is excluded. → [`fetch.md`](../topics/fetch.md)
- **JS-side i18n (JS-I18N-001, 2026-06-06)** — `core.js` runtime strings (close labels, connection/script-load errors) now read the single-source dictionary via the `_Z77.core.i18n` channel; the server inlines the JS-facing subset (`js.*` + `common.close`) as a `data-z77-i18n` JSON island in `<head>`. Inline chosen over a fetch endpoint (no round-trip/race/route). → [`translation.md`](../topics/translation.md)
- **Backend content editing-language mode (CONTENT-LANG-001, 2026-06-06)** — `/backend/content/content/list` filtered to one editing language (session-sticky, `?language=` only switches; key `backendContentLanguage`), prominent `be-lang-switch` banner + `be-lang-tag`; new documents inherit the mode language. Chosen over the frontend URL-prefix (which would drive the backend UI language). Reusable pattern for metadata. → [`content.md`](../topics/content.md)
- **Backend metadata per language (CONTENT-LANG-001, 2026-06-06)** — `MetaDataController` adopts the same editing-language mode: the "by navigation point" list is scoped to the active language (status per language), the `?env=` filter is preserved across switches, new records inherit the mode language. → [`metadata.md`](../topics/metadata.md)
- **SEO single-form 301 + canonical/alternate (2026-06-07)** — non-default-language pages reached via a canonical slug `301` to the localized form (`Request` → `LocalizedRedirectException` → `Bootstrap` `RedirectResponse`, fired only after a route match so it can't 404); head carries self-canonical + `hreflang` alternates + `x-default`. → [`translation.md`](../topics/translation.md)
- **Per-module subset validation (2026-06-07)** — `getModuleLanguages()` fails fast when a declared (non-empty) `languages` set omits the default language. → [`i18n.md`](../topics/i18n.md)

**Open (resume here):**
- **ROUTE-DYN-001 — dynamic friendly detail routes (slug capture)** — `/referenzen/austrasse` must resolve as a clean public URL to `frontend/referenzen/projekte/projekt` with `slug=austrasse`. Today impossible (exact-string nav match; `params` are query-only; friendly `url` + `params` mutually exclusive). Requirement fixed, **mechanism to be designed before implementing**. → [`routing.md`](../topics/routing.md) pending
- **AUDIT — discoverable single source** (priority 7 above) — framework-wide pass.

## M2 Detail — Request Lifecycle & Routing

| Task | Status |
|---|---|
| ADR-003 Response Objects | `[DONE]` |
| ADR-004 Dispatcher | `[DONE]` |
| Navigation entity moved to `packages/kernel/shared/src/Entities/` | `[DONE 2026-04-28]` |
| Navigation-Routing (JSON + CacheManager + Router + Request guard) | `[DONE]` |
| RequestMode — Page / Fetch (replaces IS_AJAX_HTTP_REQUEST) | `[DONE 2026-04-28]` |
| Language extraction before routing (`extractLanguage()`) | `[DONE 2026-04-28]` |
| Installer: data files (`data/framework/routing/`, `data/framework/auth/`) | `[DONE 2026-04-28]` |
| View Layer Bugs (BUG-V001–V008) | `[DONE 2026-04-29]` |
| Asset Versioning Refactor — `AssetVersionService`, MIN/VERSION removed | `[DONE 2026-04-29]` |
| Baukasten Layout — dynamic body sections in HtmlView | `[DONE 2026-04-29]` |
| Template helpers — `e()`, `raw()`, `replacePlaceholders()`, `partial()` | `[DONE 2026-04-29]` |
| Page Cache — ETag/304/atomic write/HEAD/RFC 7232 | `[DONE 2026-05-01]` |
| Navigation-Service `getByTag()` — menu rendering | `[DONE 2026-05-01]` |
| MetaData — title/description/structured-data in templates | `[DONE 2026-05-02]` |
| Auth — LoginUser, AuthService, LoginController, module-backend | `[DONE 2026-05-02]` |
| Installer — rewritten (instance pattern, full error handling) | `[DONE 2026-05-03]` |
| Code Reviews — DI, Router, FileFinder | `[DONE 2026-05-03]` |
| Active-state detection | `[OPEN]` |
| CSS-Pipeline decision (DESIGN-V001) | `[OPEN]` |
| Backend Dashboard + Cache/Debug controls | `[OPEN]` |
