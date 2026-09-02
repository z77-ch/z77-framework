# Handoff — central data API on axo3: requirements for the framework

**Date:** 2026-09-02
**From:** session in the project `z77-1.0.0-zihlundsee.ch`
**To:** framework `z77-ch-framework-1.0.0` (development moves here)
**Status:** architectural decision made with Peter; nothing built yet.

---

## Short version

axo3.ch becomes the central data broker for all client sites that display unit
data. Sites no longer talk to PropBase (or emonitor) directly — they talk to a
versioned API on axo3 with a per-tenant key. The API is built as a **regular
z77 module running on axo3**, not as a standalone endpoint. The framework needs
three small extensions to carry a session-less JSON API (see «Framework
requirements»).

---

## Context and problem

- zihlundsee is the first PropBase project: a standalone z77 installation that
  consumes `z77/propbase` directly. Setup cost per site today: credentials file
  by hand (`.propbase/`, chmod 700), IP whitelisting at myprop per server,
  module updates deployed n times.
- ~7 existing client projects (SBB, via emonitor) plus zihlundsee; more
  standalone installations are expected. Sites are spread across hosters
  (cyon, hostpoint, …) — sender IPs are not stable enough for per-site
  IP whitelisting upstream.
- axo3 already manages tenant secrets and delivers widgets; it has **no data
  API yet** — that is what gets built.

## Decision

**Central broker on axo3, server-to-server, snapshot pattern kept in the
sites.** Options rejected:

- *Per-site propbase forever (status quo):* administration scales linearly
  with sites — exactly the cost to remove.
- *Full backend (member/verwaltung) as a module in every site:* multiplies
  administration instead of centralizing it.
- *Widget-only delivery for the unit table:* loses server-side rendering
  (SEO, first paint, no-JS); the table stays SSR from the local snapshot.
- *Standalone `api.php` outside the framework on axo3:* was considered for
  deploy decoupling and boot overhead. Dropped, for two reasons argued
  through: (1) the client-side snapshot already makes API downtime
  non-critical — an outage costs freshness only, and the release structure
  (`next` door + rollback) covers the deploy risk; (2) a standalone endpoint
  duplicates auth, throttling, logging and error handling as a second
  security path nobody reviews. The framework already has convention routes
  without navigation entries, `RequestMode::Fetch`, auth roles, GeoGuard and
  the `var/lib` throttle counters.

## Architecture

```
site (any hoster)                    axo3.ch (z77)                 upstreams
┌──────────────────┐   HTTPS + key   ┌───────────────┐   secrets   ┌──────────┐
│ thin client       │ ──────────────▶ │ module-api    │ ──────────▶ │ PropBase │
│ FileSnapshotStore │ ◀────────────── │  key auth     │             │ emonitor │
│ current.php (SSR) │  bundle or 204  │  own snapshot │ ◀────────── │          │
└──────────────────┘                 │  per upstream │             └──────────┘
                                     └───────────────┘
```

- **Sites keep the propbase snapshot pattern unchanged:** atomic swap on
  `current.php`, TTL-gated background revalidation, **never an API call in
  the hot path**. axo3 down → site renders the last good snapshot, one log
  line, retry at next TTL. This IS the required outage protection.
- **Only the transport layer of the site client changes:** `PropBaseClient`
  (token handling, upstream secrets) is replaced by a thin axo3 client
  (HTTPS + bearer key). New site setup = one key.
- **axo3 snapshots the upstreams itself:** upstream outages are invisible to
  clients; rate limits toward myprop/emonitor are controlled in one place.
- **Response format is upstream-neutral:** per tenant, axo3 knows whether
  PropBase or emonitor is behind it; the site sees ONE curated units JSON.
  Candidate for the v1 contract: propbase's `SnapshotData` (already curated
  and hashed).
- **Hash-based revalidation:** the site sends its `curatedHash`; axo3 answers
  204 (unchanged) or the new bundle. The client mechanism exists already.

## Framework requirements (the three extensions)

1. **API-key authentication** as an alternative to the session: bearer token →
   tenant, no session start. A new guard beside the AccessGuard. Keys per
   site, revocable, rotatable without a deploy.
2. **`JsonResponse` as a first-class citizen** — no layout, no template path.
   Today `RequestMode::Fetch` is built for HTML fragments.
3. **Module flag «no layout, no locale/navigation redirect»** so `/api/v1/…`
   never enters the language and navigation logic.

Layered as before: the api module (routing, controllers, key auth, backend UI
for tenants) is a z77 module; `z77/propbase` and a future emonitor package
stay framework-agnostic libraries used as upstream adapters.

## module-api requirements

- Versioned endpoints, `/api/v1/units` (+ `/api/v1/health`). **v1 never
  breaks** — an axo3 deploy must not change the contract; freeze it, add v2
  if needed.
- Auth by key, not IP (hoster IPs vary). Rate limiting via the existing
  `var/lib` throttle counters.
- Backend UI: tenant ↔ site ↔ upstream ↔ key administration; request log —
  one line per request with outcome (the GeoGuard logging philosophy: a
  stretch of failures must be visible, not silent).
- Per-tenant upstream adapter config; axo3-side snapshot store per tenant.
- Monitoring on axo3 itself is REQUIRED once live: the client-side buffer
  hides outages by design — without an alert they are noticed too late.

## Sequencing

1. Framework extensions (key guard, JsonResponse, module flag).
2. module-api on axo3, PropBase adapter first.
3. Site client: strip `z77/propbase` to the generic snapshot client
   (store + revalidation stay; transport swapped), pilot on zihlundsee.
4. emonitor adapter; migrate the 7 existing sites step by step — they stay
   as they are until then.

## Known residual risks

- A **fresh install** with no first snapshot needs axo3 online. Accepted.
- axo3 becomes production-critical infrastructure for ~8+ client sites:
  contract tests for v1 and uptime monitoring are part of the build, not
  an afterthought.

## Open points (decide during build)

- Exact v1 payload contract (field list; start from `SnapshotData`).
- Key format and rotation procedure (two active keys per tenant during
  rotation?).
- Whether `/api/v1/units` serves one dataset per tenant or named datasets
  (a tenant with several projects).
- Where the site-side generic client lives (rename of `z77/propbase` vs.
  new package with propbase depending on it).
