# Roadmap

**Last updated:** 2026-06-06 · milestones and status note 2026-11-20

> The sections from «Priorities of 2026-06» down are a snapshot of 2026-06 and are kept as
> history. What is open today: [pendenzen-2026-11-12.md](pendenzen-2026-11-12.md) and each
> topic's `## pending`.

## Milestones

| Milestone | Status | Description |
|---|---|---|
| M1 — Documentation Structure | `[DONE]` | Docs structure set up, lifecycle defined |
| M2 — Request Lifecycle & Routing | `[DONE]` | View Layer + Asset Pipeline + Cache + Auth + Navigation |
| M3 — Framework Core | `[DONE]` | PHP structure, autoloading, routing — code integrated and reviewed |
| M4 — First Production Use | `[DONE]` | Integration into a live project — client websites run on the web stack (1.x on Packagist since 2026-07-15) |
| Release 2.0.0 — Business Suite | `[DONE]` 2026-11-20 | First release with the business suite on Packagist: `persistence-doctrine`, `module-vat`, `-contact`, `-mandator`, `-financial`, `-article`, `-debtor`, `-order`, `-receipt`, `-mailing`, `-creditor`, `-member`, `-api` and `kibot` (14 new packages, 19 split targets); the web stack moves to 2.0.0 with them (breaking changes since `1.6.0`: [`upgrade-1.6-to-2.0.md`](upgrade-1.6-to-2.0.md), `CHANGELOG.md`). Precondition met 2026-11-19: the suite runs in production on the owner's own server. The manual explains only the Packagist installation now (D5). Plan, decisions D1–D5 and the after-tag steps (skeleton, clean-folder check, live projects): [`release-2.0.0-bauplan.md`](release-2.0.0-bauplan.md). |

## History

The git history of this repository starts at the public release (2026-07-15); the work before it
lived elsewhere and is recorded here so the start is not lost (owner 2026-11-20).

| Date | Event |
|---|---|
| 2025-10-16 | The start: the owner joins GitHub and takes the first steps with git — the bootstrap, nothing else yet. Successor of the Webdreams framework wdv-6.2.2. |
| 2025-10-22 | First commit on GitHub (owner's contribution graph). |
| 2026-04-06 | First recorded architecture decision ([ADR-001](../02-decisions/adr-001-bootstrap-minimal-dependencies.md), bootstrap with minimal dependencies). |
| 2026-07-09 | Monorepo and the first split repos created under `z77-ch`. |
| 2026-07-15 | 1.0.0 — public on GitHub and Packagist (kernel, web modules, docs, skeleton). |
| 2026-11-20 | 2.0.0 — the business suite published (19 packages). |
| 2026-11-20 | 2.1.0 — logo set-up window, backend session lifetime and «Auf diesem Gerät angemeldet bleiben», the pending state for every server action; `dev-main` aliased `2.x-dev`. |

## Open items — prioritized list

The swept, prioritized list of every topic's `## pending` (owner looks, owner decisions, ready to build, known limits): [pendenzen-2026-11-12.md](pendenzen-2026-11-12.md). The topic docs stay the source of truth.

## Priorities of 2026-06 (vor Publikation) — historic snapshot

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
- **ROUTE-DYN-001 — dynamic friendly detail routes (slug capture)** — a clean public URL such as `/referenzen/musterstrasse` resolving to one detail action with the slug. **Update 2026-11-15:** the core shipped with ADR-015 (NavigationAlias + content slugs, `getSlugs()`); the rest is deferred until the first real detail-page controller — current state in [`routing.md`](../topics/routing.md) ROUTE-DYN-001.
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
| Navigation-Service `getByTag()` — menu rendering (since replaced by slots, `getBySlot()`, ADR-022) | `[DONE 2026-05-01]` |
| MetaData — title/description/structured-data in templates | `[DONE 2026-05-02]` |
| Auth — LoginUser, AuthService, LoginController, module-backend | `[DONE 2026-05-02]` |
| Installer — rewritten (instance pattern, full error handling) | `[DONE 2026-05-03]` |
| Code Reviews — DI, Router, FileFinder | `[DONE 2026-05-03]` |
| Active-state detection | `[OPEN]` |
| CSS-Pipeline decision (DESIGN-V001) | `[OPEN]` |
| Backend Dashboard + Cache/Debug controls | `[OPEN]` |
