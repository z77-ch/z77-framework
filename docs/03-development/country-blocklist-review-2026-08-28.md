# Country blocklist review — 2026-08-28

Read-through of the uncommitted country-rule work on `feat/public-form-standard`: the move of
the blocked countries from `memberConfig` to installation data, and the backend surface that
decides them. Cross-checked against [`../topics/geoip.md`](../topics/geoip.md),
[`../topics/member.md`](../topics/member.md), [`../topics/backend.md`](../topics/backend.md) and
[ADR-018](../02-decisions/) (fragment mount). Reviewer: Opus 5.

Three behaviours were verified by running code, not by reading it: the fail-open promise
(`tests/country-blocklist.php`, 33/33), what a corrupt store does to the backend surface, and
what a missing `module-member` does to the shipped navigation entry. Those three are marked
**verified** below.

## Scope reviewed

- **Storage:** `MemberBlockedCountry` (entity, `data/framework/member/blocked-countries.json`),
  `CountryBlocklist` (read + write service).
- **Gate:** `RegistrationFlow::blockedCountries()` / `blockedByCountry()` / `register()`,
  `CountryLookup`, `RegistrationLog`.
- **Surface:** `RegistrationLogControllerTrait`, `RegistrationLogLayout`, the three templates,
  the backend host `RegistrationLogController` + its layout config.
- **Wiring:** `navigation.default.json` id 30, `backendConfig` role inheritance,
  `memberConfig` (removed key).
- **Harness:** `tests/country-blocklist.php`.

## Purpose — what the rule is for

Registration abuse that is *geographically* concentrated. Not security: the rule guards nothing
secret, it only makes a flood from one origin cheaper to stop than by throttling alone. Two
design commitments follow from that and both hold in the code:

- **Blocklist, never whitelist.** A whitelist locks out the Swiss customer in a holiday WLAN,
  on a VPN, or behind a carrier routing through Frankfurt. A blocklist can only be *too small*,
  which costs an attempt we would have had anyway.
- **Fail open, everywhere.** No database, private range, unassigned range, unreadable store —
  all answer «unknown», and unknown never blocks. Verified: `CountryBlocklist::codes()` returns
  `[]` for a corrupt file, an empty file, and a missing directory.

The move from config to data is the right call and the reasoning in the code says why better
than a summary can: the config's own comment already stated the list is decided from the
registration log, so the default was always empty and every real decision cost a deploy. A
setting only an operator can decide, from evidence only that installation has, is data.

## Flow — what actually happens

**Recording (always, whether or not anything is blocked):**

```
POST /member/main/register
  └─ PublicFormHandler  → csrf | bot | invalid | limited | sent | failed
        └─ observer (RegisterController)
              └─ RegistrationLog::write(form, outcome, email, [origin])
                    reads REMOTE_ADDR, resolves the country at that moment,
                    appends one JSON line to logs/registration-YYYY-MM.jsonl
```

**Deciding (only on a valid submit):**

```
onValid → RegistrationFlow::register()
  1. country rule    blockedByCountry(REMOTE_ADDR)   → note('blocked-country'), return FALSE
  2. origin throttle allowIp(...)                    → note('throttled-ip'),    return FALSE
  3. address throttle allow(email)                   → note('throttled'),       return FALSE
  4. known / new     → mail, return TRUE either way (anti-oracle)
```

The country rule sits **above** both throttles, deliberately: it is a flat refusal rather than a
rate, it needs neither the address nor a counter, and a barred origin should not consume a
throttle slot on its way out. It also short-circuits before any lookup when the list is empty —
the ordinary case costs one file read and nothing else.

**Operating:**

```
Backend → Service → Anfragen   (/backend/service/registration-log/list)
  ├─ tally «Woher»          country → count, over the 300 newest lines
  │     └─ [Land sperren] → confirm-block?code=XX  (modal, reason PREFILLED from the tally)
  │           └─ POST /block   entity-CSRF bound to the code, reason mandatory
  ├─ tally «Was daraus wurde»  effective outcome → count
  ├─ Gesperrte Länder          code · reason · when · by whom · [Aufheben]
  └─ Protokoll                 the raw lines
```

The single best decision in this change is that the tally and the switch are on the same page,
and that the reason field arrives prefilled with the numbers that justify it. A `reason` written
a deploy away from the evidence says «RU gesperrt» and nothing more.

## Prerequisites

| | |
|---|---|
| GeoIP database | `data/framework/geoip/*.mmdb` (MaxMind GeoLite2-Country). **Without it the rule can never fire** — every origin reads as unknown. The surface says so at the top of the page. |
| `z77/module-member` installed | The host controller `use`s a trait from that package. `module-backend` does **not** require it (see F5). |
| Navigation entry | Seed `id:30` in `navigation.default.json`. A fresh install gets it; an **existing** installation must adopt it via Service → Import — and must assign it to an existing menu point rather than accept it as new, or the surface is listed twice. Already noted under `## pending` in `geoip.md`. |
| Role | ADMIN, by module inheritance — no `backendConfig` entry, correct per AUTH-B003 (deviation-only). |
| Retention | `logs/registration-*.jsonl`, 90 days, swept by `member-cleanup`. Personal data (address, IP, country) — needs its own paragraph in the installation's privacy policy. |
| Migration | A project that still sets `memberConfig['blockedCountries']` is **silently ignored** and has to re-enter its countries on the new surface (GEOIP-003). |

## Findings

### F1 — A corrupt store silently switches the rule off and kills the page that would repair it

**Verified.** `CountryBlocklist::codes()` swallows and returns `[]` — correct, that is the
fail-open promise. But `listAction()` calls `$this->countryBlocklist()->all()` unguarded, and
`FileStorage::load()` throws `RuntimeException: Corrupt data file 'framework/member/blocked-countries.json'`.
So a broken file produces:

- the gate: rule off, **no signal anywhere** — no log line, no flash, nothing;
- the backend: a 500 on Service → Anfragen, i.e. the one surface that would show the problem;
- `block()` / `unblock()`: also throw, via `has()` → `find()`.

That the write side throws is deliberate and right. That the **read** side of the backend list
throws is the gap: the operator loses the page exactly when they need it, and until they happen
to open it, nothing tells them the rule stopped working.

*Suggestion:* catch in `listAction()` and render the blocklist section as a visible failure
state — «Sperrliste unlesbar, die Regel ist derzeit AUS» — while the rest of the page (the log,
the tallies) still renders. Leave `block()` / `unblock()` throwing.

### F2 — The rule covers the registration form only

`blockedByCountry()` is called from `RegistrationFlow::register()` and nowhere else. A blocked
country can still request magic-link logins for an existing account, and still redeem an
invitation. `RegistrationLog::FORM_LOGIN` and `FORM_INVITE` exist as constants but nothing
writes them, so the surface only ever contains register rows.

This is *consistent* — both topic docs say «registrations» throughout, and the confirm modal
already says «Bestehende Konten sind nicht betroffen». But the page is titled «Anfragen» and the
button says «Land sperren», which both read wider than the rule is. One sentence in the confirm
modal would close it: *Betrifft nur das Registrierungsformular — Login-Links an bestehende
Konten gehen weiterhin raus.*

The two dead constants should either be used or dropped.

### F3 — The decision basis saturates, and it can saturate in the wrong direction

`SHOW_ROWS = 300` across all monthly files. `countryTally()` and `suggestedReason()` both count
**only within that window**, which is correct and well argued (a summary over a wider set than
the list is a summary nobody can check). The consequence is not symmetric, though:

- Under a flood, «the 300 newest» is a window of hours. The flooding country shows a large
  count — fine, the decision is still right.
- But a legitimate country whose accepted registrations are *older* than those 300 lines shows
  **«0 angenommen»** — which is precisely the pattern the rule is designed to fire on. The
  prefilled reason then hands the operator a sentence that reads like evidence and is an
  artefact of the window.

`geoip.md` lists this under `## pending` as «decide whether the log deserves a month filter».
That frames it as convenience; it is a correctness-of-decision issue and worth re-filing as
such. Cheapest honest fix without a filter: name the window in the prefilled sentence
(«… in den letzten 300 Protokollzeilen …») so the number cannot be mistaken for a total.

Cosmetic, same area: the «340 Versuche» example in the `MemberBlockedCountry` docblock and in
`geoip.md` is arithmetically impossible — the tally cannot exceed 300.

### F4 — The country is read and resolved twice per submit, from two independent IP reads

`RegistrationFlow::register()` reads `$_SERVER['REMOTE_ADDR']` and calls `CountryLookup::of()`;
`RegistrationLog::clientIp()` reads it again and looks up again. Today both produce the same
answer, so nothing is wrong — but the log is the *evidence for the gate's decision*, and the two
are not the same read.

`RegistrationLog`'s docblock says that if this installation ever moves behind a reverse proxy,
the trusted-header handling «belongs here». There are now two «here»s, and a change to one and
not the other would produce a log that shows a different country than the one the gate blocked
on. Note also that `Request` has no client-IP accessor at all, so Rule 4 (HTTP input only
through `Request`) currently has no answer for this — several member services read `$_SERVER`
directly. This is the natural moment to give `Request` a `getClientIp()` and route both callers
through it.

### F5 — The new navigation seed points at a route that fatals without `module-member`

**Verified.** `navigation.default.json` ships in the **kernel**, so every backend installation
gets the «Anfragen» entry. `RegistrationLogController` lives in `module-backend`, which does not
require `z77/module-member`. `ControllerHandler:93` guards with `class_exists()` — that cannot
help: a missing **trait** is a compile-time fatal (`Fatal error: Trait "…" not found`), not a
catchable error, so the click yields a 500 rather than a 404.

**Not a regression.** Navigation id 24 (`drive` → `DriveController` `use`s a `module-dms` trait)
has exactly the same shape and has shipped that way. So this is a pre-existing weakness of the
ADR-018 mount pattern that the new entry inherits, not something this change introduced. Worth
recording as such — and the docblock claim *«Reachable only in projects that install
z77/module-member … the route then has no classes to load»* is factually wrong for both hosts:
the host class does exist, it is the trait that does not.

### F6 — `docs:check` is currently red, for an unrelated reason

`navigation.md:35` and `packaging.md:24` point at `tests/navigation-subpage-cursor.php` and
`tests/build-info.php`, which are **staged for deletion** in the working tree. Nothing to do
with the country work, but it blocks the commit. Either restore the harnesses or drop the
`SOURCE=` lines with them.

## What holds

- Fail-open read path, in every broken state — verified, 33/33 in `tests/country-blocklist.php`.
- No whitelist anywhere; unknown (`??`) is never offered as blockable in the UI, and never
  blocks in the gate.
- Gate above both throttles, so a barred origin consumes no throttle slot.
- Empty list short-circuits before any lookup — the ordinary installation pays one file read.
- Duplicate entries refused rather than deduplicated later, so «aufheben» stays unambiguous.
- Code normalization on the entity, so gate, lookup and hydration all compare equal.
- `reason` mandatory server-side, not only in the template; operator name recorded.
- Entity-CSRF bound to the country code on both write actions.
- ADMIN by module inheritance, no redundant config entry (AUTH-B003).
- ADR-018 mount pattern followed exactly — mirrors `MemberAccountsController` down to the
  one-line layout delegation.
- No JavaScript added; the existing generic `data-fetch-get` / `data-fetch-post` wire is reused.
- MaxMind attribution rendered wherever country results are shown (licence term).

## Verdict

**Integrated: yes, for the registration path.** Gate, storage, backend surface, navigation seed,
role, docs and a harness are all in place and mutually consistent. The config-to-data move is
complete — nothing reads the old key, and the removal is documented in three places plus a
`known issues` entry.

**Not finished:** two things, both about what the operator can see.

1. The blocklist has **no visible failure state** (F1) — it can be off without anyone knowing,
   and the page that would say so is the one that breaks.
2. The decision basis it hands the operator **can lie under load** (F3), in the direction that
   causes a wrong block rather than a missed one.

F2 and F4 are honest scope/seam notes, F5 is pre-existing, F6 is unrelated. Recommendation:
fix F1 and F3 before this ships; fold F2's sentence into the confirm modal while it is open.
