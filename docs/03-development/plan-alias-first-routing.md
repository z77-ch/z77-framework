# Plan — alias-first routing, slug translation only on the alias path

**Status:** BUILT 2026-09-17 (reviewed twice, see "Review outcome"); not committed,
not deployed. Amends ADR-014 (inbound /
outbound translation) and ADR-015 (D3 "normalizes every path segment", alias
remainder). Trigger: zihlundsee.ch wants `/fr/contact` for the alias `/kontakt`.

## Problem (measured)

`Request::runParsing()` translates EVERY segment localized → canonical before anything
is matched, and the translated segments are used by all branches — alias, static
navigation, convention, Fetch. With the slug entry `kontakt → contact` the technical
URLs `/fr/frontend/main/contact/get-form` (Fetch) and `/fr/frontend/main/contact/danke`
become `…/kontakt/…` and 404: a localized VALUE equal to a technical identifier.
`translation.md` covers only the key side of this collision and claims "no 404".

Second, independent defect: an alias matches as a PREFIX, always. `/kontakt/anything`
renders the contact page (unlimited URLs for one page), the remainder is run through
the structural slug table, and `PageIdentity` has no slug dimension — two remainder
URLs of one alias would share one page-cache entry. Nobody consumes an alias remainder
today (`getSlugs()` callers: reserved routes only; zihlundsee + axo3: none).

## The model (owner, 2026-09-17)

1. Fetch never meets this: it always addresses controller/action directly.
2. An alias is a stand-in for a navigation entry. FIRST decide: alias or not.
3. Non-default language: translate, then look the alias up. The alias must exist
   exactly as looked up.
4. No alias → resolve module/group/controller/action as written. No translation.
5. A remainder (`/referenzen/mein_name`) is allowed only where the alias says so.

## Design

**`NavigationAlias.accepts_slugs`** (bool, default `false`). `false` = the alias
matches only its exact path. `true` = it also matches as a prefix; the remainder is
handed to the action as content slugs (`Request::getSlugs()`), RAW — never translated
(the structural table is not a content store; entity slugs are the action's business).

**Inbound (`Request::runParsing()`)**

```
raw = segments after the language prefix
reserved route            → as today (raw)
Fetch                     → parsePathSegments(raw)            no translation
raw empty                 → parsePathSegments()                defaults (home)
alias lookup: for i = n … 1
    prefix = translate(raw[0..i])          (default language: identity)
    alias  = exact alias for prefix
    hit when  i == n                       (exact)
          or  alias.accepts_slugs          (prefix; slugs = raw[i..], untranslated)
  HIT  → pathSegments = prefix + slugs; enforce the single localized form (301)
         on the ALIAS PART only; assign the 4-tuple from the navigation
  MISS → pathSegments = raw; static navigation match, then convention — both on raw,
         no 301 (a localized technical URL does not exist any more)
```

**Outbound (`localizedUrl()`)** — mirror image. Query/fragment split off first.
Default language: unchanged. Otherwise: alias lookup on the canonical segments with the
same rule (via `Router::matchAlias()`, so a dangling alias is a miss on both sides);
HIT → localize the alias part, keep the remainder; MISS → language prefix only.

**Page cache** — a request that carries slugs is never cached (`PageCachePolicy`):
the identity has no slug dimension. (ADR-015 D2, key-by-URL, stays deferred.)

**Validation** — `SlugTranslator::validate()` (DEBUG) + `TranslationCatalog`: a
localized value must not equal a module key (else `/fr/<module>` is read as an alias).

## Request matrix

Table fr: `kontakt→contact`, `wohnen→habiter`, `referenzen→references`.
Aliases: `/home`, `/kontakt`, `/wohnen` (exact) · `/referenzen` (accepts_slugs).
Module `frontend`, controller `contact` (convention routes), action `index/lage` (4-tuple nav).

| # | Request | Resolution | Result |
|---|---|---|---|
| 1 | `/` | no segments | defaults → home, de |
| 2 | `/fr` | no segments, lang fr | defaults → home, fr (root redirect of ADR-015 untouched) |
| 3 | `/kontakt` | exact alias | contact page, de |
| 4 | `/fr/contact` | translate → `/kontakt`, exact | contact page, fr |
| 5 | `/fr/kontakt` | translate = identity, exact; localized form differs | 301 `/fr/contact` |
| 6 | `POST /fr/kontakt` | as 5, not a read method | no 301, handled |
| 7 | `/contact` (de) | no translation in de, no alias | convention: module `contact` unknown → 404 |
| 8 | `/kontakt/foo` | `/kontakt/foo` no alias; `/kontakt` exact-only | MISS → raw → 404 (today: contact page) |
| 9 | `/fr/contact/foo` | as 8 | 404 |
| 10 | `/referenzen` | exact | list |
| 11 | `/referenzen/mein_name` | prefix, accepts_slugs | action gets `[mein_name]` |
| 12 | `/fr/references/mein_name` | prefix translated, remainder raw | action gets `[mein_name]`, fr |
| 13 | `/fr/referenzen/mein_name` | hit; alias part not localized | 301 `/fr/references/mein_name` |
| 14 | `/fr/references/contact` | remainder raw | slugs `[contact]` — NOT `kontakt` (today: rewritten) |
| 15 | `/referenzen/a/b` | prefix | slugs `[a, b]` — the action decides (404 if unknown) |
| 16 | `/frontend/main/contact/get-form` Fetch, de | raw | controller contact |
| 17 | `/fr/frontend/main/contact/get-form` Fetch | raw, no translation | controller contact, fr — FIXED |
| 18 | `/fr/frontend/main/contact/danke` page | alias miss → raw | controller contact, fr — FIXED |
| 19 | `/frontend/main/index/lage` | alias miss; static nav on raw | page, de |
| 20 | `/fr/frontend/main/index/lage` | as 19 | page, fr, NO 301 (today: 301 to `…/situation`) |
| 21 | `/fr/frontend/main/index/situation` | raw, action `situation` unknown | 404 (today: 200) — accepted, nobody emits it |
| 22 | `/fr/habiter` | translate → `/wohnen` | page, fr |
| 23 | `/fr/wohnen` | hit, not localized | 301 `/fr/habiter` |
| 24 | `/fr/home` without table entry | identity, exact | page, fr, no 301 |
| 25 | `/media/front/x.png` | reserved | unchanged |
| 26 | `/xx/home` | invalid language | unchanged (InvalidRouteException) |
| 27 | `/fr/gibtsnicht` | miss → raw → convention | 404 |
| 28 | `/backend/system/cache/list` | alias miss → static nav | unchanged |
| 29 | `/kontakt?x=1` | query is not part of the path | alias hit, not cached (query) |

Outbound round trip (every emitted URL must resolve to the same target):

| `localizedUrl(x, 'fr')` | emits | inbound row |
|---|---|---|
| `/kontakt` | `/fr/contact` | 4 |
| `/kontakt?x=1#f` | `/fr/contact?x=1#f` | 4 |
| `/referenzen/mein_name` | `/fr/references/mein_name` | 12 |
| `/frontend/main/contact/get-form` | `/fr/frontend/main/contact/get-form` | 17 |
| `/frontend/main/index/lage` | `/fr/frontend/main/index/lage` | 20 |
| `/` | `/fr` | 2 |
| `https://…`, `#`, `mailto:` | unchanged | — |

Canonical / hreflang / language switch: `currentCanonicalPath()` = canonical alias path
+ slugs (raw) or, without navigation, the raw path; both go through `localizedUrl()`
→ consistent with the table above.

## Behaviour changes (deliberate)

- Technical URLs are never localized (rows 20, 21). The technical URL of an aliased
  page is consolidated by `rel=canonical`, no longer by a 301.
- An alias without `accepts_slugs` rejects a remainder (rows 8, 9).
- Remainders are no longer translated (row 14).
- Slug requests bypass the page cache.

## Files

1. `kernel/shared/src/Entities/NavigationAlias.php` — `acceptsSlugs` (`accepts_slugs`, bool, default false).
2. `kernel/core/src/Services/NavigationUrlResolver.php` — `matchAlias()` exact / accepts_slugs rule; per-instance memo of the alias list.
3. `kernel/core/src/Http/Request.php` — `runParsing()` as above; `translateSlugsToCanonical()` → pure function on a segment list; `enforceLocalizedSlug()` on the alias part; docblocks.
4. `kernel/core/src/autoload/prod/php/Helper.php` — `localizedUrl()` alias-aware, query/fragment safe.
5. `kernel/core/src/Routing/PageCachePolicy.php` — slugs → `newPage()`.
6. `kernel/core/src/Services/SlugTranslator.php` + `TranslationCatalog.php` — value ≠ module key.
7. `module-backend` alias edit form + list — the checkbox / a marker.
8. `tests/routing-alias-first.php` — standalone script covering the matrix + round trip.
9. Docs: ADR-014 + ADR-015 amendment notes; `topics/translation.md`, `routing.md`, `navigation.md`, `i18n.md`.

## Residual risks

- A table value equal to the path of a DIFFERENT alias that has no table entry of its
  own shadows that alias in the non-default language (translated lookup wins). Data
  problem; the translation tool should warn. axo3 has an alias `/contact` — check before
  adding `kontakt → contact` there.
- Stale localized technical URLs in open tabs across the deploy (row 21).
- Inbound and outbound must ship together (one release; page cache is release-local).

## Review outcome (2026-09-17) — what changed against the first draft

Taken over from the review:

- **One class for both directions** — `Routing/AliasPathResolver` (`resolve()` inbound,
  `toLocalized()` outbound). Symmetric by construction, and the test seam.
- **DI:** `NavigationUrlResolver`, `NavigationService`, `Router`, `AliasPathResolver` are
  registered in `pullUpServices()` — `localizedUrl()` must work in a job / a mail.
- **Loop rule decided:** an exact-only alias found at a SHORTER prefix is skipped, the
  search continues (`/referenzen/archiv/x` reaches the slug-accepting `/referenzen`).
- **Raw spelling as alternative** after the translated one, per prefix length — closes
  the round-trip gap of an alias whose own path is a localized word (the first draft
  listed it as a residual risk; it was a violation of the plan's own invariant).
- 301 target is `rawurlencode`d per segment; `setPathSegments()` no longer drops `"0"`.
- Validators: alias path must not start with a module key; `accepts_slugs` identical
  across all aliases of one navigation; localized value ≠ module key.
- The "per-instance memo" of the alias list was dropped — `DataCache` already keeps a
  request-local copy.

Deliberately NOT in this change (existing behaviour, recorded as known issues in the
topics): convention URLs with more than 4 segments answer 200; the segment cleaners
rewrite instead of reject; `localizedUrl()` prefixes reserved paths (`/media`) with the
language; no per-entity meta/canonical for slug pages (`html()` overwrites them);
`PublicFormHandler::checkUrl()` carries no language prefix.

## Verification

- `php tests/routing-alias-first.php` — 59 checks (matrix, outbound, round trip,
  alternative spelling, entity default).
- End to end against a real installation (zihlundsee, `php -S`, table with
  `kontakt → contact`): every matrix row answered as listed — `/fr/contact` 200,
  `/fr/kontakt` 301, `/kontakt/foo` 404, `/fr/frontend/main/contact/get-form` and
  `…/danke` 200, `/fr/frontend/main/index/lage` 200 with canonical `/fr/situation`,
  `/fr/frontend/main/index/situation` 404; nav, hreflang, language switch, form action
  and the popup/detail URLs all carry the right form; a POST is not 301'd.

## Rollout

Inbound and outbound ship together (one framework commit, one release per project).
**Data follows code:** a slug entry that needs the new behaviour (`kontakt → contact`)
goes onto a server only AFTER every door serves a release with this change — on the
old code it breaks the French form endpoints. Before adding it, delete an identity
entry `"contact": "contact"` from the table (1:1 invariant).
