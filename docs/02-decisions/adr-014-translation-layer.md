# ADR-014 — Translation Layer: UI Strings + URL Slug Translation

**Status:** `APPROVED`
**Date:** 2026-06-05

---

## Context

ADR-013 established the language policy (default + available languages, session
persistence, content fallback). What remained: the page chrome was still single
language. Two kinds of text needed translating, with different owners:

1. **Static UI strings** hard-wired in templates — aria-labels, footer labels,
   message close buttons, navigation display names. Owner: developer.
2. **URL path segments** — `/privacy` should be reachable (and rendered in links) as
   `/fr/confidentialite`, while the router still resolves the canonical controller.

The predecessor framework (wdv-6.2.2) solved (2) with translation tables that the
router consulted to map a localized URL segment back to a canonical one before
resolving the controller. z77 adopts the same principle.

## Decision

A translation layer with two services, both reading per-language data under
`data/framework/i18n/`.

### UI strings — `Translator` + `t()`

`t(string $key, array $params = [], ?string $language = null): string` resolves a key
against `data/framework/i18n/{lang}.json`: current language → `defaultLanguage` → the
key itself (visible miss marker). Templates use `<?= e(t('footer.tagline')) ?>`.
Navigation display names resolve through `t('nav.' . $entry->getAction())` — the entry
stays slim (no per-language field); the action is the stable canonical key.

### URL slugs — `SlugTranslator` + `localizedUrl()`

> ⚠️ **Amendment 2026-09-17 — alias-first, translation on the alias part only.**
> The inbound rule below ("each path segment localized → canonical, so the
> router and the whole resolution chain only ever see canonical segments") and its
> outbound mirror ("each segment" canonical → localized) are **superseded**. Slug
> translation now applies to the **alias part** of a URL and to nothing else:
> inbound it runs only to look an alias up (never in Fetch mode, never on a technical
> `module/group/controller/action` path, never on the content-slug remainder behind a
> slug-accepting alias); outbound `localizedUrl()` localizes only what resolves to an
> alias and gives every other path the language prefix alone. Both directions go
> through one class, `Z77\Core\Routing\AliasPathResolver`.
>
> **Why:** a localized VALUE equal to a technical identifier. With the entry
> `kontakt → contact` the blanket inbound translation rewrote
> `/fr/frontend/main/contact/get-form` and `/fr/frontend/main/contact/danke` to
> `…/kontakt/…` → 404 — a live contact form's blur-check endpoint and its PRG target.
> The "Table invariants" below knew only the KEY side of that collision (a localized
> value shadowing a different canonical KEY) and claimed the rest "still round-trips
> and routes (no 404)". That claim was wrong for the value side.
>
> **Stands unchanged:** canonical = the default language; the table shape
> (`route-slugs.{lang}.json`, canonical → localized, non-default languages only);
> `SlugTranslator` as pure segment ↔ segment mapping; the two existing table
> invariants (now joined by a third, see below); the 301 to the single indexed
> localized form — narrowed to alias URLs; the whole UI-string half of this ADR
> (`Translator` / `t()`), which this amendment does not touch.
>
> See ADR-015 §"Amendment 2026-09-17", [`../topics/translation.md`](../topics/translation.md),
> and the build plan [`../03-development/plan-alias-first-routing.md`](../03-development/plan-alias-first-routing.md)
> (request matrix, deliberate behaviour changes).

**Canonical = the default language.** What is stored in navigation/code is the
canonical form; only non-default languages carry a table
(`route-slugs.{lang}.json`, canonical → localized). `SlugTranslator` does pure
segment ↔ segment mapping (`toCanonical` / `toLocalized`); the default language has
no table (identity).

- **Inbound** _(superseded 2026-09-17 — see the amendment above; `translateSlugsToCanonical()`
  no longer exists, the work moved into `AliasPathResolver::resolve()`, which translates only
  to look an alias up)_: "each path segment localized → canonical, so the router and the
  whole resolution chain only ever see canonical segments. A non-translatable segment
  stays unchanged — already-canonical resolves; genuine garbage 404s (no controller)."
- **Outbound** (`localizedUrl()` helper): a canonical URL → localized + language
  prefix, for rendering nav/footer/switch links. The default language keeps canonical,
  prefix-less URLs. Path+prefix logic lives in the helper; `SlugTranslator` stays
  segment-only. _(Amended 2026-09-17: "each segment" is superseded — only an alias path
  is localized, a technical path and the slug remainder are emitted as they resolve; the
  helper also carries `?query` / `#fragment` through unchanged.)_

### Canonical stays valid in any prefix (Option a)

`/fr/privacy` (canonical word under a non-default prefix) keeps resolving — the
canonical form is valid everywhere. So a page is reachable under both its canonical
and its localized URL in a non-default language. This is an accepted duplicate-URL
trade-off; an optional 301 canonical→localized for SEO is a later add-on, not core.

_(Superseded twice: by SEO-301 on 2026-06-07 — the 301 was built, so in a non-default
language the localized form is the single indexed URL — and narrowed on 2026-09-17 to
ALIAS URLs. A technical path (`/fr/frontend/main/index/lage`) has no localized form at
all now: it resolves as written, 200, without a 301, and the aliased duplicate is
consolidated by `rel=canonical`. The reverse, `/fr/frontend/main/index/situation`, is a
404 — it is not a URL this framework ever emits.)_

### Table invariants (validated, debug fail-fast)

`SlugTranslator` validates each table on load in `DEBUG`:

1. **1:1** — localized values are unique (else the reverse mapping is ambiguous).
2. **No shadowing** — no localized value equals a *different* canonical key (else
   `/lang/<localized>` would never reach the canonical page of that name). An identity
   mapping (`contact` → `contact`, same word in both languages) is allowed — it reaches
   its own page.
3. **No module key** _(added 2026-09-17)_ — no localized value equals a module key
   (`frontend`, `backend`, …). Since the alias question is asked first and the segments
   are translated to ask it, such a value would make `/{lang}/{module}/…` read as an
   alias path. Validated in `DEBUG` on load and by the backend translation tool.

With these invariants the translation is deterministic and collision-free.
_(Amended 2026-09-17: two invariants were not enough — they cover the key side of the
collision only. See the amendment block above.)_

### The wdv two-table model collapses to one in z77

wdv had separate "content" and "code" translation tables. In z77 content is keyed by
`(canonical-slug, language)` and addressed by canonical slug + request language
(content.about.fr.json), so content slugs need no URL table. Only **route slugs**
(the URL path segments that resolve to controller/group/action) get a table.

## Reasoning

**Why canonical = default language (not a neutral identifier)?**
Simplicity. Code and stored data are already the canonical form; only non-default
languages need a table. The default language never needs translation entries.

**Why translate inbound to canonical instead of teaching the router localized URLs?**
The router, navigation matching, page-cache identity, and convention resolution all
stay untouched — they only ever see canonical segments. One translation seam in
`Request`, zero changes downstream.
_(Amended 2026-09-17: the seam stayed single, but it moved INTO the alias question
(`AliasPathResolver`). Downstream still never sees a translated segment — because
nothing outside an alias path is translated at all. The price of the old blanket form
was that a localized word could collide with a controller name and 404 it.)_

**Why two invariants and not a full canonical registry check?**
The realistic collision (a localized slug shadowing a canonical page) is caught
table-internally. A system-wide canonical registry would be heavier for no practical
gain when vocabularies are disjoint (canonical English-ish, localized French).

**Why is the nav display name a `t()` key and not a per-language entry field?**
Keeps the navigation entry slim (developer constraint) and routes all UI-string
translation through one mechanism + one source file per language.

## Consequences

**Easier:**
- All chrome strings + nav names translate via one `t()` call + one dict per language
  ("translate the UI to X" = fill one file — the discoverable-single-source principle).
- Localized, SEO-friendly URLs without touching the router or cache.

**Harder / to keep in mind:**
- A new public page needs: a `nav.<action>` dict key (de + fr) and, for a localized
  URL, a `route-slugs.{lang}` entry. Missing dict key → visible `nav.<action>` marker;
  missing slug entry → canonical URL still works (no localized URL).
- The slug table must satisfy the two invariants (debug throws otherwise).
- Page cache keys per language (ADR-013) — localized and canonical URLs of the same
  page in the same language share the canonical identity + language, so one cache entry.

**Deferred:**
- **JS-side strings** (e.g. the close-button aria-label that `core.js` sets on
  dynamically created messages) are not covered by the PHP `t()`. Planned: after boot,
  JS fetches the current language's dictionary once and translates client-side.
- Optional 301 canonical→localized redirect for SEO.
- Per-language `content` URL slug table, only if content URLs ever localize their slug.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Per-language label/URL fields on the navigation entry | Overloads the entry; translations scatter across data. Central tables keep the entry slim. |
| Canonical = neutral identifier, every language localized (incl. default) | More table maintenance (default needs entries too) for no gain; default = canonical is simpler. |
| Teach the router to match localized URLs directly | Touches router, navigation matching, cache identity, convention resolution — many seams vs. one inbound translation step. |
| `/lang/<canonical>` returns 404 (only localized valid) | More logic to actively reject canonical; Option a (canonical always valid) matches "code = default lang, works everywhere". |
| Two tables (content + code) like wdv | z77 content is keyed by (canonical-slug, language); content slugs need no URL table. One route-slug table suffices. |

## see also

- ADR-013 → language policy (default/available languages, session persistence, content fallback) this builds on
- `docs/topics/translation.md` → the area topic, file map, rules
- `docs/topics/i18n.md` → language resolution + the `$languageSwitch` contract
