# ADR-058 — App users: e-mail-code login for the device channel

**Status:** `[APPROVED]` — owner decisions A1–A9 and principles P1–P4, 2026-11-12; built 2026-11-12
(steps 1, 3, 4 of the [build plan](../03-development/app-user-bauplan.md)); the backend screen
«App-Benutzer» built 2026-11-12 after its board (step 5), «Geräte» removed.
**Date:** 2026-11-12
**Supersedes in part:** [ADR-057](adr-057-api-device-channel.md) — decisions 4 (pairing), 5 (scopes
chosen per device, devices owned by a backend user) and 6 (scopes independent of the owner's roles),
and the pairing context. Decisions 1–3 and 7–9 and the addendum (app shell) stay; where they name
pairing, read «code sign-in».
**Build plan:** [`app-user-bauplan.md`](../03-development/app-user-bauplan.md) (owner decisions,
critical review §10).
**Amends:** [`gateway-app-kanal-bauplan.md`](../03-development/gateway-app-kanal-bauplan.md) E8,
E13, E14, E15.

---

## Context

The first phone test (2026-11-12) showed the pairing flow's weak spot: a QR code scanned by the
camera opens the phone's DEFAULT browser, so the device cookie lands in a browser the user does not
use (on iOS the home-screen app has its own cookie jar again). The owner asked why a phone needs
pairing when a login would do, and widened the goal: the z77 App is a phone-optimised capture app
for several targets and, later, for members. A typed code works in whichever browser the app is
open in, and a mail scanner cannot use it up (a link could be).

Principles (owner): **P1** the z77 App is a phone-optimised capture app (a PWA; modules contribute
tiles). **P2** the phone captures, the backend completes — the phone never takes the binding step.
**P3** no responsive backend. **P4 (gate)** this login is ONE factor, the mailbox: it carries
capture-only scopes. A scope with a binding or outward effect (`mail.send`, publish, post) MUST
NOT ride on this login alone — it needs a new decision (a second factor).

## Decision

1. **The app user is an account type of its own (A1)**, made only for app access, with NO backend
   role. It may do exactly the app functions (scopes) stored on it. File entity `ApiAppUser`
   (module-api, `framework/api/app_users.json`, collection mode): `id, email, linked_realm,
   linked_id, scopes, active, created_at/by, changed_at/by, last_login_at`. No display name — the
   linked person's name is the name (Rule 2).
2. **Linked to exactly one person (A2)**: a backend user, or later a member (A8: modelled, not
   offered — a member link resolves to no person). Unique on (realm, linked id) and on the app
   e-mail (A3, normalised lower case). One app user, many devices (at most 10 live).
3. **Login = an e-mail code, no password (A4).** `POST /api/v1/devices/login {email}` and
   `POST /api/v1/devices/verify {code}`, both anonymous (the guard's `ANONYMOUS_SLUGS`, header and
   Origin rule as before). They stay anonymous on purpose: a request with a principal passes the
   idempotency store, which keeps the answer's headers — verify's `Set-Cookie` would land in
   `var/lib` in plaintext.
4. **The one-time code store lives in the kernel** (`Z77\Shared\Auth\LoginCodeStore`, next to
   `TotpGuard`; Rule 9 — members reuse it). One record PER REQUEST (§4 «the login record»).
5. **The device stays signed in (A5)** until its cookie is cleared, it idles 180 days, it is
   blocked, the app user is deactivated or the linked person deleted. Re-login after expiry is
   self-service, so ADR-057's cookie refresh (API-009) is closed. `devices/logout` revokes THIS
   device and clears its cookie.
6. **Devices belong to the app user.** `ApiDevice` gains a NEW field `app_user_id`; `owner_id`
   is never reinterpreted (it keeps «the backend user the device belongs to»). A row without
   `app_user_id` (paired under ADR-057) resolves to nothing — 401; no upgrade step (`next.` and
   the live host share `data/` and `var/lib`). The device's own `scopes` field is no longer read.
7. **Scopes declare a minimum role per realm (reverses ADR-057 decision 6, review B7).**
   `'apiDeviceScopes' => ['receipt.capture' => ['label', 'hint', 'realms' => ['backend' =>
   AuthRole::EDITOR]]]`; no `member` key = never for members; no `realms` = for nobody. Checked
   with `AuthRole::rolesSatisfy()` against the linked person's roles FROM THE STORE when assigning
   AND on every request (`ApiDevices::resolve()` filters live — rights can shrink).
8. **Administration is ADMIN only (A9)** — no self-service (replaces gateway plan E14). The actor
   rule of the device screen is carried over (`ApiDeviceAccess::mayActFor()`, review B5): never for
   a person whose roles lie above the actor's; only scopes the actor could hold himself. The
   functions are assigned in the app-user administration (A6).
9. **Pairing is removed entirely (A7):** `createPairingCode`, `redeemPairingCode`, `pairingState`,
   `cancelPairingCode`, `var/lib/api/pairing`, the `.paired` markers, `POST devices/pair`,
   `/app/pair`, the pairing window of «Geräte».
10. **`ApiPrincipal` (device):** `deviceId`, `deviceLabel`, `appUserId`, `linkedRealm`,
    `linkedId`, `ownerName` (the linked person's name), `scopes` (filtered live).
    `ApiPrincipal::device()` takes them in that order; ADR-057's `ownerId` is gone from it.
11. **Envelope v1, additively:** 202 for an accepted request (`ApiResult::accepted()`), the
    endpoints `devices/login`, `devices/verify`, `devices/logout`; `devices/pair` removed before
    any client depended on it (the device channel is not part of the frozen surface yet).

### The security rules (build plan §4, binding)

- **The login record.** `devices/login` ALWAYS writes one record and answers 202 with the same body
  and the same nonce cookie, for unknown, inactive and active addresses alike. The nonce (64 hex,
  random) goes into `z77_api_login` (HttpOnly, SameSite=Strict, Path `/api`, 15 min); the record
  `var/lib/api/login/sha256(nonce).json` holds `{subject|null, sha256(nonce . code), tries,
  expires}` (`subject` = the app user id). Requests never replace each other; a guess without the
  nonce counts against nobody, so nobody locks a known address out by requesting codes.
- **Counting under a lock.** Read, count, compare and — on success — spend under `flock` on the
  record file; never check-then-count, never `FileThrottle` for the tries. Two parallel correct
  verifies issue ONE device.
- **Numbers.** 6 digits, 5 tries, 15 minutes. Per e-mail 5 requests per hour and 15 per day,
  answered silently (the record then signs in nobody, no mail). Per /64 10 requests and 20
  verifies per hour → 429. The daily cap of an address raises an operator alert (`AlertService`,
  Service › E-Mail entry `apiLoginCap`). Per-address counters, the cap marker and the alert exist
  ONLY for the address of an app user — a malformed or unknown address is never counted, never on
  disk, never in an operator mail (security review M1). Live records capped in two quotas: 5000 of
  a subject, 5000 of nobody (`<dir>/nobody/`) — a flood for unknown addresses must not stop real
  sign-ins (S2).
- **No oracle in failure.** A send failure is logged, never answered. Every mail of the sign-in —
  the code, the new device, the operator alert — goes out after the response where the server can
  finish early (PHP-FPM, LiteSpeed); elsewhere every login request is padded to the same floor
  (2 s), and a request over it is logged. No «no account here» mail (it would be a relay).
- **Verify** answers one generic 403 for wrong, expired, used up, unknown and nobody's records; no
  tries-left counter. It asks again that the app user is active and the person exists.
- **The mails** are sent at once (`EmailService::send()`), in German (`de`, explicit — the stateless
  path sets no language): the code mail with «niemandem weitergeben — z77 ruft nie an …», the
  requesting device and time; after every sign-in «Neues Gerät angemeldet» to the app e-mail.
- **Lifecycle — one rule.** Deactivating revokes ALL devices and spends pending records;
  reactivation means a fresh code sign-in (E13) — it revokes and spends once more before switching
  on (S3: a verify that found the app user active just before the deactivation may issue its device
  just after the revoke). `resolve()` and `verify` check on every call that
  the app user is active and the person exists. An `accountRemovedListeners` entry deactivates the
  app user when its backend user is deleted. Role reduction is covered by the live scope filter.
- **E-mail change.** Devices stay; pending records are spent; the OLD address gets a notice
  (without the new one); the actor rule applies.
- **Re-login** on a browser that holds a live device cookie revokes that row — whichever app user's
  (security review S1, owner answer 4: the cookie proves possession). Only together with the
  queue rule: the app stamps every waiting capture with the app user (`appUser` of `devices/me`
  and `devices/verify`) and sends only those of the app user signed in now; another person's
  entries show «Von einem anderen Zugang erfasst — verwerfen». A successful sign-in clears the
  kept answers first.

## Reasoning

- **A code, not a link or a QR:** it works in whichever browser the app runs in — the exact
  failure of the first phone test — and a mail scanner that opens links cannot spend it.
- **No password (A4):** a password whose reset runs over the same mailbox adds no strength against
  a mailbox attacker, only a second secret to manage. The review judged the choice sound for
  capture-only scopes (P4).
- **One record per request with a nonce (review B2/B4):** a 6-digit code cannot be its own key, and
  a per-account record would let anybody lock a known address out.
- **A minimum role per scope (review B7):** «the role that edits the journal» (ADMIN) would have shut
  out the EDITOR employees who capture; a per-scope minimum keeps the declaration at the module that
  owns the target and lets rights shrink live.
- **A new ADR, not an addendum:** four decisions of an ADR approved the same day are reversed.

## Consequences

- **Accepted residual risks (while scopes are capture-only, P4):** mailbox compromise = app access;
  online guessing — about 0.06 % success per day of sustained attack, about 2.7 % per year with the
  daily cap, thousands of mails to the victim on the way; code relay by social engineering (the
  new-device mail mitigates); lockout of NEW sign-ins by flooding a known address (signed-in
  devices keep working); no sign-in while mail delivery is down; no TOTP even where the linked
  backend user has one; the 400-day cookie on an unlocked phone is the real credential; on a
  server that cannot finish a response early, a mail send slower than the 2 s floor stays
  measurable (it is logged); a well-formed address that is no app user's runs a little less work
  than an app user's (no person lookup) — the same as before the review, not measured as a
  usable difference.
- **Equal-rank ADMINs manage each other's app users** — the existing backend rule
  (`ApiDeviceAccess::mayActFor()` refuses only a person ABOVE the actor). An ADMIN may thus change
  the app e-mail of another ADMIN's app user and receive its codes; the OLD address gets the
  change notice, which is the mitigation. Kept on purpose (security review 2026-11-12, documented
  not changed).
- **Cookies without `Secure` behind a TLS proxy** that does not pass the HTTPS environment — the
  existing rule of the session cookie (SEC-003), api.md API-012. The nonce cookie is not cleared
  after a successful verify (one `Set-Cookie` per answer in the envelope; the record is spent) —
  api.md API-013.
- **Security review 2026-11-12** (before the first commit): M1 (the alert was an anonymously
  triggerable mail with the caller's text), S1 (queued captures could go out under another
  person's sign-in), S2 (one record cap for everybody = silent lockout), S3 (verify vs deactivate
  race), S4 (test gaps) and the minor points are built into the rules above; the «actor could
  hold» check for member-linked app users is open with A8 (api.md API-014).
- **A target scope with a binding or outward effect** needs a new decision before it is offered to
  app users (P4 gate).
- **Consumer projects:** an override of `apiConfig` must carry `/app/login` (instead of
  `/app/pair`), `loginAction`, and the `accountRemovedListeners` entry; an override of
  `emailConfig` must copy the `apiLoginCap` entry; a module declaring `apiDeviceScopes` must add
  `realms`, or the scope is offered to nobody. The navigation change of step 5 does not reach a
  shared `data/` (INST-NAV-SHARED-001).
- **Old devices** (ADR-057 rows) answer 401; their phones sign in again by code. `var/lib/api/pairing`
  is disposable. Receipts need nothing (snapshots, no foreign key).
- **The backend screen «App-Benutzer»** (step 5, built 2026-11-12) replaced the transitional
  «Geräte»: a device is seen and blocked in its app user's window; deactivating is a danger button
  with a question (it signs out every device for good), not a switch; a person above the actor is
  listed read-only (`topics/api.md` «backend screen «App-Benutzer»»).
- Tested by `tests/login-code-store.php` (the kernel store, parallel processes, the two quotas),
  `tests/api-device-channel.php` (B sign-in, C devices, E login flow incl. M1 / S1 / the floor,
  F app users incl. S3 / S4), `tests/app-queue.mjs` (the queue per app user, S1),
  `tests/app-shell.php`, `tests/module-api-app-users-ui.php` (the screen, incl. the real stack), `tests/module-receipt.php` (O17).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep pairing, fix the browser problem | the QR opens the default browser by design; an iOS home-screen app keeps its own cookies — no fix on our side |
| Magic link instead of a code | opens the default browser again; a mail scanner can spend it |
| Password login | a reset over the same mailbox adds nothing against a mailbox attacker, only a second secret |
| One code record per account | a code cannot be its own key; a new request replacing the old lets anybody lock a known address out (review B4) |
| `FileThrottle` for the tries | fixed windows, check-then-count: parallel guesses pass before the first failure counts (review B3) |
| «No account here» mail like the member login | an anonymous endpoint mailing strangers is a relay; the app is a closed group |
| Reinterpret `owner_id` as the app user | the old test phone of backend user 1 would become app user 1's device |
| An upgrade step migrating devices | `next.` and live share `data/` — a step run on `next` hits the live release |
| A new reserved endpoint `app` | `devices/*` already is the device channel's own endpoint; no new reserved name |
| Scopes independent of the person's roles (ADR-057 decision 6) | a person whose rights shrink would keep capturing |
| Self-service: an EDITOR signs in his own phone without an admin (gateway plan E14) | owner A9: only an ADMIN creates app users |
