# web-stats-bauplan.md — Site statistics without a third party

**Status:** STEP 1.1 + 1.2 BUILT AND REVIEWED (2026-09-23, review findings worked in, not yet
committed) — recording, the event endpoint, the project-declared event list, the `stats-rollup`
job and the generic privacy paragraph. Steps 1.3 (report page), 1.4 (token link), 2 (mail) and
3 (zihlundsee text) are NOT built. Single source of truth from here:
[`../topics/stats.md`](../topics/stats.md). Trigger: the client of zihlundsee.ch asked what we
can offer as a statistical evaluation of site traffic; the same request will come from other
projects, so it is framework work, not project work.

**Review 2026-09-23 (independent) — result: no privacy blocker.** On no path does an IP, a
full user agent, a full referrer URL or a non-whitelisted query key reach a file. Findings
worked in the same day; the four open questions were decided by the OWNER — binding, not to
be reopened by a successor:

- **E1 — `stats-rollup` ships `daily@04:40`.** Reason: here the deletion IS the privacy
  promise («Rohdaten werden nach 7 Tagen gelöscht»); without a schedule the raw lines — one
  daily visitor key per line — accumulate until someone remembers the switch, and that is the
  worse risk. A deliberate exception to «a deleting job ships no schedule», of the same kind
  as `geoip-update` (a duty, not a convenience); recorded as JOBS-SCHED-001 in `topics/jobs.md`
  and in ADR-031's addendum of 2026-09-23. The operator can still switch it off.
- **E2 — the raw lines stay out of the backup.** They moved to `logs/stats/` (own directory),
  excluded in `BackupService::FIXED_EXCLUDES` — in CODE, unconditionally, never through the
  seed-once backup config (BACKUP-LIB-001). `logs/` itself stays in the archive (the form log
  is a record). Files written before the move lie in `logs/` and are read, folded and swept by
  the rollup for a transition of RAW_RETENTION_DAYS + 1 (STATS-008).
- **E3 — the visitor key keeps the user agent** (an office behind one NAT address must not be
  one visitor), although that makes the key client-influenced and the per-key caps blind to an
  agent-rotating client. Counterweights: the beacon endpoint is throttled per ADDRESS
  (`FileThrottle`, `var/lib/throttle/stats`, 300/hour, IPv6 per /64, answer still 204, fails
  open), and the rollup keeps at most `MAX_KEYS_PER_DAY` (20 000) keys per day in memory —
  beyond that a line with a new key is dropped, tallied under `capped`, and the job note warns
  loudly. Page-view floods cost real requests and get no extra brake.
- **E4 — the event PATH is evaluated, not dropped.** The aggregate gains `event_pages`
  (event → path → count, capped like `referrers`): «on which page was the floor plan opened».
  For that the event path is put on the page view's route (percent-decoding once, UTF-8
  allowed — `/über-uns` used to drop the whole event —, language prefix stripped, alias
  canonicalised through `AliasPathResolver`; what is no path becomes `/`), and the beacon
  snippet encodes once: `encodeURIComponent(decodeURIComponent(location.pathname))`.

Also fixed from the review: the rollup fails loudly instead of losing data (a corrupt month
file blocks its month and is left for a hand fix; an unreadable day file is never marked done
or swept — B5); the endpoint type-checks its parameters (B6); the runtime-created directory
gets the deny `.htaccess` (B7); an `@` in a utm value becomes `unknown` and «never a recipient
id in utm_*» is a project rule (B8); the referring host is validated as a hostname (B9); the
just-in-case helpers are gone (B10); the privacy paragraph names every stored field, the
status, the key, visits/entries/browsers, the country clause as optional, the backup exclusion,
the schedule and the retention as «24 months, the running one included» (B11 — the code now
keeps exactly 24 month files). Harness: 131 → 160 checks, each finding with a case that failed
before the fix.

**Built 2026-09-23 (step 1), with the decisions taken while building:**

- Hook: `Dispatcher::execute()` after `$response->send()` (`index.php` is a frozen trampoline);
  a page-cache HIT and a 304 pass through the same `send()` → counted (acceptance 1).
- Events: the PROJECT declares them (owner 2026-09-23) in `App/Config/statsEventsConfig.inc.php`
  under `override/` (additive extension file, not a module-config copy — BOOT-CONFIG-001);
  map name → German label; the framework ships none. Endpoint is **GET**
  `/frontend/main/stats/event?event=…&path=…` (a beacon POST dies at the global CSRF check).
- Aggregate at `data/framework/stats/YYYY-MM.json` (not `data/stats/` — the framework's data
  directories live under `data/framework/`). Referring hosts kept by NAME per month, top 50 +
  `other` (orchestrator 2026-09-23; English data key, the report labels it); source classes
  apart, `internal` (navigation within the site) kept as its own class.
- Visits and entry pages derived in the ROLLUP from the raw lines (orchestrator 2026-09-23,
  owner request «which page does a visit start on»): a gap of more than 30 minutes
  (`StatsRollup::VISIT_GAP_SECONDS`) starts a new visit, the first page view is the entry; a
  beacon never starts a visit, a line without a key counts neither; a visit across midnight
  counts twice (day files are the unit). No new event, no change to the raw line.
- Two abuse caps in the rollup: `PAGE_VIEW_DAY_CAP` = 100 page views per visitor and day,
  `EVENT_DAY_CAP` = 20 beacon events per visitor, day and event name (a claim is bounded
  tighter than an observation). Raw retention 7 days, aggregates 24 months (owner default).
- GeoIP per page view costs ~5 ms on the Windows dev box (open + tree walk); **measuring it on
  cyon is the gate before step 1.3** — no optimisation now.
- Not counted, deliberately: 404 (routing 404s never reach the Dispatcher; the rest are probes).
- Job `stats-rollup` registered in `backendConfig` — first without a schedule (it deletes),
  since the review of the same day WITH `daily@04:40` (owner decision E1 above).
- Verified: `php tests/web-stats.php` (incl. a real SAPI run); per-request cost measured
  (see stats.md).

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
| `status` | response | 2xx/3xx as sent; **404 is deliberately NOT counted** (decided while building, 2026-09-23 — a routing 404 never reaches the Dispatcher, the rest are probes; see `topics/stats.md`) |
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
plus visitors per day and per month as distinct `visitor` values; built as
`data/framework/stats/`, with visits and entry pages added, see the status block). Raw files are deleted
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

1. A page view on a **cached** page is counted (the trap in 1.1). — **verified** (harness, SAPI)
2. Two requests from one visitor on one day count as one visitor; the same visitor the
   next day cannot be matched to the day before (salt rotation). — **verified**
3. Backend, assets, bots and a logged-in editor produce no lines. — **verified**
4. A form submission, a floor-plan PDF and an application click each show up as their
   own event. — **verified** for declared names (undeclared names are dropped)
5. `stats-rollup` deletes raw files older than 7 days and leaves the aggregate. — **verified**,
   incl. the abuse cap
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
