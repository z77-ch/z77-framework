# web-stats-bauplan.md — Site statistics without a third party

**Status:** PLANNED (2026-09-23), nothing built. Trigger: the client of zihlundsee.ch
asked what we can offer as a statistical evaluation of site traffic; the same request
will come from other projects, so it is framework work, not project work.

**Decision taken with the owner (2026-09-23):** counted server-side in the framework —
not through the axo3 API (that is the property-data broker, a different domain) and not
per project. No third-party service, no analytics cookie, therefore no consent banner;
an ad blocker cannot remove the count.

Related: `topics/jobs.md` (JobRunner, schedules), `Forms/FormLog.php` (the existing
"one JSONL line per event, monthly file, swept" pattern), `topics/mail.md`
(EmailService), `Libraries/Cache/PageCache.php`.

---

## Goal and non-goals

**Goal:** an owner of a small site can answer — how many people came, from where, which
pages did they look at, what did they actually do (form, floor plan, application), and
is that more or less than last month.

**Non-goals:** no Matomo/GA replacement, no funnels, no cross-site profiles, no
per-person data. Nothing that would need a consent dialogue.

---

## What is recorded

One line per counted request, no IP stored:

| Field | Source | Note |
|---|---|---|
| `at` | server clock | ISO-8601 |
| `path` | request | canonical path, without query except whitelisted campaign keys |
| `status` | response | only 2xx/3xx/404 are interesting |
| `lang` | request | de / fr |
| `visitor` | `sha256(ip + ua + daily salt)`, 16 hex | the salt rotates daily in `var/lib/stats/salt`, the IP is never written — yesterday's visitors cannot be re-identified |
| `source` | Referer | classified: direct / search / social / referral (+ host), campaign from `utm_*` |
| `device` | UA | mobile / tablet / desktop, plus browser family |
| `country` | `GeoIp\CountryLookup` | already installed, local database |
| `event` | see below | `page` for a page view |

**Events** beyond page views, because that is what the client actually wants: form
submitted, floor-plan PDF opened, spec sheet opened, unit popup opened, isometry used,
application link followed (outbound). Page views and outbound links are counted
server-side where they pass PHP; the ones that do not (a `/media` file already
materialized as a static file, an outbound link) are reported by a `sendBeacon` to
`/frontend/main/stats/event`, which accepts ONLY a whitelisted event name plus the
current path — no free text, no id, no cookie.

**Not counted:** assets, the backend, requests of a logged-in editor/admin, known bots
by user agent, and anything the site marks `data-stats="off"`.

---

## Step 1 — build in the framework

**1.1 Recording** — `Shared\Stats\StatsRecorder`, called once per request from the
front controller, *after* the page-cache decision and also on a cache HIT (the HIT is a
real visit; this is the one trap of the whole feature — the counter must not sit inside
the render path). Never throws: a full disk costs a line, never the page (FormLog rule).
Raw lines: `logs/stats-YYYY-MM-DD.jsonl`, append + `LOCK_EX` (`logs/` is shared across
releases and closed by `.htaccess`).

**1.2 Aggregation** — job `stats-rollup`, daily: raw day files → `data/stats/YYYY-MM.json`
(totals per day, per page, per source, per device, per country, per language, per event,
plus visitors per day and per month as distinct `visitor` values). Raw files are deleted
after **7 days**, the aggregates carry no personal data and stay (default 24 months).
`data/` is shared, so a deploy or rollback does not lose history.

**1.3 Report page** — one template, two doors: `/backend/service/stats` for us and the
tokenized page for the client (below). Content: month at a glance with the previous
month beside it, a day curve, top pages, sources, devices, countries, languages, and the
event list (form submissions, documents, application clicks). Print stylesheet so the
page prints as it stands — we send no paper and no PDF.

**1.4 Access link** — `Shared\Stats\ReportToken`: HMAC over `report id + expiry`, key in
`config/client/stats.inc.php` (machine-local, per server, like the geoip key). URL
`/stats/report/<token>`, **valid 10 days**, `X-Robots-Tag: noindex`, no login, no
session, read-only, one report month per token. Rotating the key invalidates every link
that is out.

---

## Step 2 — cron + periodic mail with the link

- Job `stats-report-mail`, schedule set in the backend (Service → Jobs), suggested
  `monthly@06:00` on the 1st. No default schedule in code — same rule as
  `form-log-cleanup`: a job that writes outward is switched on by a human.
- Recipients: the backend mail settings record (`form_key: statsReport`), so the address
  changes without a deploy. Language per recipient.
- The mail carries a short text, the reporting period, two or three headline numbers and
  **the link**. It carries no attachment and no table — the link is the report.
- The link's token is minted by the job with a 10-day expiry, so every mail brings a
  fresh one and an old mail stops working.
- Run and outcome land in the job log; a failed send is visible there, not silent.

---

## Step 3 — privacy text (zihlundsee.ch, DE + FR)

Today the page says in `tracking`: «Diese Website verwendet keine Analyse- oder
Tracking-Dienste … Es findet keine Auswertung Ihres Nutzungsverhaltens statt.» That
sentence becomes false the day this ships. New wording, both languages, in the content
documents `privacy.de.json` / `privacy.fr.json` (section `tracking`, plus one sentence in
`weitergabe` that no data leaves the server):

- what is counted (page views and a handful of clicks),
- that no cookie and no third-party service is involved,
- that the IP is turned into a daily, non-reversible key and is not stored,
- how long raw lines (7 days) and aggregates (24 months) are kept,
- that the evaluation is aggregate and says nothing about a single person.

A generic version of the same paragraph goes into the framework docs, so the next
project can copy it.

---

## Acceptance

1. A page view on a **cached** page is counted (the trap in 1.1).
2. Two requests from one visitor on one day count as one visitor; the same visitor the
   next day cannot be matched to the day before (salt rotation).
3. Backend, assets, bots and a logged-in editor produce no lines.
4. A form submission, a floor-plan PDF and an application click each show up as their
   own event.
5. `stats-rollup` deletes raw files older than 7 days and leaves the aggregate.
6. The mailed link opens the report without a login, prints cleanly, and answers 410
   after 10 days.
7. Deleting `config/client/stats.inc.php` (no key) disables the tokenized page and the
   mail, and counting still works.

---

## Open decisions (owner)

1. Reporting period: monthly (suggested) or weekly.
2. Aggregate retention: 24 months as suggested.
3. Whether the isometry counts as one event or several (hover/click/fullscreen).
4. Whether the client gets a backend login instead of, or in addition to, the link.
5. Whether the report is per site only, or one mail covering several sites of an owner.
