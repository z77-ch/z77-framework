# alert

2026-09-02

## entry

1. `packages/kernel/shared/src/Alert/AlertService.php` — the edge-triggered state machine: `failure()` / `success()` per source
2. `packages/kernel/shared/src/Alert/AlertChannelInterface.php` — one delivery path (email built in; SMS/chat = project or add-on implementation)
3. `Z:\z77\z77-axo3.ch\work\docs\handoff-framework-alarm-service-2026-09-02.md` — the design rationale this implements (external reference)

## file map

SOURCE=/packages/kernel/shared/src/Alert/AlertService.php
SOURCE=/packages/kernel/shared/src/Alert/AlertKind.php
SOURCE=/packages/kernel/shared/src/Alert/AlertMessage.php
SOURCE=/packages/kernel/shared/src/Alert/AlertChannelInterface.php
SOURCE=/packages/kernel/shared/src/Alert/EmailAlertChannel.php
SOURCE=/tests/alert-service.php

## mental model

A snapshot consumer hides outages **by design** — the site keeps rendering the
last good stand with a 200. Only the failing side sees the failure, so the
probe's caller reports every outcome to `AlertService::failure()` /
`success()`, and the service turns the stream into **edges**: one Outage on
ok→failing, one Escalation when the outage outlives the window (time-based,
default 4 h — an overnight outage with exactly one 23:04 mail is practically
none), one Recovery on failing→ok. Everything between edges is deliberate
silence.

- **State** is one JSON per source under `var/lib/alert/` — the shared branch
  of `var/`, so a release switch neither re-announces a running outage nor
  forgets one (same reasoning as the throttle counters, ADR-034/ADR-035).
- **Channels** are declared per installation and fan out per message; a
  throwing channel lands in error_log and never breaks the caller's run or the
  other channels. `EmailAlertChannel` rides the HTTP-free `EmailService`
  (ADR-030), so the service works from ANY probe shape — a cron, or a
  visitor-triggered background revalidation (zihlundsee: page renders from the
  snapshot, a JS fetch hits the revalidate action, THAT server-side call is
  the probe). Consequence of the visitor-triggered shape: no visitors → no
  probes → no alerts; the quiet hours are covered by the remote counterpart
  (ALERT-002), which is therefore REQUIRED, not optional. An edge mail is sent
  inside a visitor's fetch request — accepted, it happens at most three times
  per incident.
- **Message content** is chosen to act on without a follow-up question:
  source, error code, failing-since, last success — and the age of the stand
  still being served, which is what carries the urgency (two hours old is a
  note, two weeks old is an incident).
- **Sources are free-form keys** (`api:axo3:units`, `backup:nightly`): the
  service is generic outage alerting, not an API feature.
- **The blind spot is deliberate and documented:** a dead client (cron off,
  server down) produces no failing probe. The remote side covers it — AXO3
  watches per-key `last_used_at` aging («this customer stopped fetching»).
  The two sides guard each other; neither alone covers the silent case.

Caller seam: the FRAMEWORK-side integration (a site's revalidation cron or
controller) reports outcomes — a framework-agnostic library (e.g. the propbase
client) keeps returning its result object and never imports this namespace.

## integrating in a site (fresh install)

Nothing to require, seed, or configure framework-side: the Alert classes ride
in with the kernel (`composer install`), `var/lib/alert/` is auto-created on
first write, and wiping `var/` merely resets incident state (worst case one
repeated outage mail). What a site builds is ONE wiring point plus one
recipient constant:

1. **Find the probe.** The place where the site actually calls its upstream —
   with the snapshot pattern that is the server side of the background
   revalidation (zihlundsee: the JS ping hits the revalidate action, which
   runs the TTL-gated sync), NOT the visitor-facing render path. A backend
   refresh tool that runs the same sync method reports through the same
   wiring for free — verify, don't duplicate.
2. **Report both outcomes** around that call:

   ```php
   $alert = new AlertService(AlertService::defaultDir(), [
       new EmailAlertChannel(self::ALERT_TO),
   ]);

   try {
       $result = /* … upstream sync … */;
       $alert->success('propbase:units');
   } catch (\RuntimeException $e) {
       $alert->failure('propbase:units', $code, ['url' => $upstream]);
       // then behave exactly as before: one log line, keep serving the snapshot
   }
   ```

   Construction belongs in the site's glue class (the one place with app
   constants); a config file for one address is ceremony. The source key is
   free-form and stays stable across transport changes only if you keep it
   stable — pick it deliberately (`propbase:units` → later `api:axo3:units`
   IS a new source; the old incident state simply expires with the old key).
   The code string is the first word the operator reads: `network`,
   `http_5xx`, `auth`, or the API envelope's `error.code` verbatim.
3. **Test before trusting:** break the upstream config → one revalidation →
   exactly ONE outage mail; again → silence; fix → ONE recovery mail naming
   since-when and the age of the stand; `var/lib/alert/*.json` flips along.

## rules

- When probing an external dependency on a schedule (API revalidation, backup run, import) → the caller MUST report both outcomes (`failure()` AND `success()`) — success is what closes an incident and anchors the staleness age
- When adding a delivery path → MUST implement `AlertChannelInterface` in project code or an add-on package; provider credentials MUST NOT live in the kernel
- When a channel cannot deliver → it MAY throw; it MUST NOT be relied on to throw silently — the service contains it (error_log) and continues
- When choosing the state directory → MUST use `var/lib/alert` (shared branch, `AlertService::defaultDir()`); MUST NOT use `var/cache` (a release switch would reset incident state)
- When wiring alerting into a framework-agnostic library → MUST keep the library reporting plain results; the framework-side caller reports to AlertService — the library MUST NOT depend on this namespace
- When writing alert wording → the built-in channel is terse English; a project wanting different wording MUST ship its own channel, not patch the kernel one

## see also

- [`api.md`](api.md) — API-002: the consumer side of this service (client-triggered), plus AXO3's `last_used_at` counterpart
- [`mail.md`](mail.md) — `EmailService`, the HTTP-free transport the email channel rides on
- [`jobs.md`](jobs.md) — cron shape: alerts must be dispatchable without a request

## known issues

_(none)_

## pending

- **ALERT-001 — zihlundsee wiring**: the pilot's revalidate ACTION (the server side behind the JS background fetch — there is no cron) reports both outcomes to AlertService; part of the site-client swap (api.md API-005; client handoff in the zihlundsee project).
- **ALERT-002 — AXO3 counterpart**: the server-side `last_used_at` aging watch («customer stopped fetching») is AXO3 project code, same edge/state shape — tracked in the AXO3 project, referenced here because neither side alone covers the silent failure.
- **ALERT-003 — SMS channel**: interface is ready; an SMS implementation needs a provider decision (account, credentials, cost) — project or add-on package, not kernel.
