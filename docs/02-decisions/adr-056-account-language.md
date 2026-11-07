# ADR-056 — The account's language after sign-in

**Status:** `[APPROVED]` — owner decision 2026-11-07 (z77-rudaz.ch, `docs/entscheide.md`
«Drei Sprachen im Projektraum»); built 2026-11-07, not committed.
**Date:** 2026-11-07
**Amends:** [ADR-015](adr-015-navigation-alias-and-content-slugs.md) / [`i18n.md`](../topics/i18n.md)
«the rendered language of a resolved URL is determined by the URL alone» — for signed-in
requests of a module that opts in.
**Builds on:** ADR-013 (language policy, `I18n`), ADR-014 (`t()`, dictionaries), ADR-029
(the member session feeds the ACL identity through an AuthBridge).

---

## Context

z77-rudaz.ch serves consumers in German, French and English. A consumer chooses a language
once and expects every page and every mail in it — including mails sent without a request
(a job, the comparison service's API call, an operator in the backend). The framework knew
one source of the rendered language: the URL prefix (`/fr/…`). That is right for public
pages (one canonical URL per page and language, a page cache keyed on it), but a member
area is neither indexed nor cached, and its links are technical paths without a prefix:
the language would get lost on the first internal link, and a job has no URL at all.

## Decision

1. **The language belongs to the account.** `MemberAccount::$language`: a two-letter code
   or null; null = the installation's default language. The person changes it on the
   profile («Konto bearbeiten»).
2. **After sign-in the account decides, before sign-in the URL** — per module, opt-in:
   a module config sets `languageFromAccount => true`. The Dispatcher then renders a
   signed-in request into that module in the identity's language (`AuthUser::getLanguage()`,
   set by the module's AuthBridge), also on a URL with another prefix. Without the key
   nothing changes: the URL alone decides (axo3, zihlundsee). A backend identity carries no
   language and is never re-languaged. **After sign-in no URL carries a language prefix**
   (owner, same day): `localizedUrl()` builds the prefix-less form, a signed-in page view on
   `/fr/member/…` is redirected to `/member/…`; the prefix exists for guests only.
3. **Mails go out in the recipient's language, with or without a request.**
   `EmailMessage::language($code)`; `EmailService` renders body and layout inside
   `Translator::inLanguage()`, so every `t()` in the templates answers in it. Subjects are
   translated by the caller with the same code. An account without a language gets the
   default; a mail to an address without an account takes the current (page's) language.
4. **An invitation carries a language.** `invite()` / `inviteAsOperator()` take
   `?string $language`; the token keeps it, the mail and the link (`/fr/member/…`) use it,
   an account BORN from the invitation takes it over. A known address keeps its own.
5. **Dictionaries come in three layers:** package `res/i18n/{lang}.json` (live from the
   package, no seeding) < project `override/z77/<ns>/res/i18n/{lang}.json` (versioned in
   the project) < runtime `data/framework/i18n/{lang}.json` (backend tool). A project adds
   its own keys (`rudaz.*`) without touching the runtime files; `<ns>.js.*` keys travel to
   the client like `js.*`.

## Reasoning

- **Why not stay with the URL:** the member area's links, fetch calls and redirects are
  prefix-less technical paths; carrying a prefix through every one of them is a large,
  error-prone change for nothing — the area is not indexed, not cached, and the person is
  known. A job has no URL to read.
- **Why the account and not the session:** the session dies with the browser, and the
  mail of a job needs the language weeks later.
- **Why opt-in per module:** the packages serve installations that must not change
  (axo3, zihlundsee). One config key, read in one place (Dispatcher), is the whole switch.
- **Why the kernel and not the member module:** which language a request renders is the
  kernel's question (`Request::getLanguage()`); the module only tells it whose language
  that is, through the identity it already projects (ADR-029). Any other account realm can
  use the same seam.
- **Why the URL still decides for guests:** the login card is reached from outside — a
  link in a French mail, a French website — and nothing else is known yet.

## Consequences

- The invariant «a resolved URL renders one language» no longer holds for signed-in
  requests of an opting-in module. Such a module MUST NOT be page-cached (the member
  module is not: `cache.enabled = false`); the page-cache key is taken after the adoption
  anyway, so a cached page would at least be keyed correctly.
- A guest switch shown to a signed-in member would mislead (the URL no longer decides
  there); a module that opts in shows the switch to guests only and the choice on the
  profile.
- New strings of a module go into its package dictionary, not into the core
  `*.default.json` seeds — so they reach installed projects with `composer update`
  (partly answers TRANS-SEED-001 for package strings).
- `t()` placeholders are inserted unescaped (the caller escapes the whole sentence). A
  `…Html` key is markup: its caller escapes the placeholders and outputs it raw.
- German grammar of the tenant noun (`TenantNoun`) stays German; other languages get the
  bare word and phrase their sentences without an agreeing article.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Language prefix on every member URL, redirect to the account's prefix | Every link, fetch URL and redirect of the area would need `localizedUrl()`; a mismatch would loop or flicker; jobs still have no URL. |
| Language in the session only | Lost with the browser; useless for mails sent later. |
| Global switch in `config/i18n` | Changes axo3 and zihlundsee; the decision is per area, not per installation. |
| Project strings in the runtime files `data/framework/i18n/` | Not versioned, overwritten by the backend tool, invisible to a deploy. |
