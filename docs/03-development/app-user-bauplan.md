# Build plan — the app user: e-mail-code login for the z77 App (replaces device pairing)

**Status:** `[CONCEPT]` — owner decisions 2026-11-12; critical review 2026-11-12 (independent
reviewer, read-only, claims checked against the code) worked in — §10 lists what it changed.
Owner decision A9 (§11) taken 2026-11-12; build. Recorded as
[ADR-058](../02-decisions/adr-058-app-users-email-code-login.md); steps 1, 3 and 4 built 2026-11-12
(`topics/api.md`); step 2 (board «App-Benutzer», developer canvas) approved by the owner 2026-11-12,
step 5 (backend screen «App-Benutzer», «Geräte» removed) built 2026-11-12.
**Date:** 2026-11-12
**Supersedes in part:** [ADR-057](../02-decisions/adr-057-api-device-channel.md) — decisions 4
(pairing), 5 (scopes chosen per device), 6 (scopes independent of the owner's roles) and the
pairing context; recorded as a NEW ADR-058 (not an addendum: four decisions of an ADR approved
the same day are reversed). Also amends [`gateway-app-kanal-bauplan.md`](gateway-app-kanal-bauplan.md)
owner decisions E8, E13, E14, E15.
**Builds on:** the device channel as built (`fc69891`, `3a8b8f6`): device cookie (32-byte secret,
sha256, HttpOnly, SameSite=Strict, Path `/api`), scopes per method, idempotency, `X-Z77-App` +
Origin, the app shell `/app`, `appScreens`.

---

## 1. Why

The first phone test (2026-11-12) showed the pairing flow's weak spot: a QR code scanned by the
camera opens the phone's DEFAULT browser, so the device cookie lands in a browser the user does
not use (and on iOS the home-screen app has its own cookie jar again). The owner asked why a
phone needs pairing at all when a login would do — and widened the goal: the app is not a
receipt app but a **phone-optimised capture app** for several targets and, later, for members.
A typed code (not a link, not a QR) works in whichever browser the app is open in, and a mail
scanner cannot use it up.

## 2. Principles (owner, 2026-11-12)

- **P1 — The z77 App is a phone-optimised capture app.** A normal web app (PWA) of the
  installation; modules contribute tiles (`appScreens`). Examples: receipt with photo
  (built), photo into an album, text into a content entry, photo/video for a blog post, an
  order captured on site.
- **P2 — The phone captures, the backend completes.** The phone never takes the binding step
  (post, invoice, publish, send). Receipts land in the receipt journal; an order from the
  phone would land as an opened order; content from the phone lands for review.
- **P3 — No responsive backend.** The backend stays a desk workplace; what is needed on the
  road becomes an app tile (later also read-only tiles such as «look up a customer»).
- **P4 — Gate (from the review):** this login is ONE factor (the mailbox). It carries
  capture-only scopes. A scope with a binding or outward effect (`mail.send`, publish,
  post) must NOT ride on this login alone — it needs a new decision (a second factor).

## 3. Owner decisions

- **A1 — The app user is an account type of its own**, made only for app access. It has NO
  backend role — not admin, not super user. It may do exactly the app functions stored on it.
- **A2 — An app user is linked to exactly one person**: a backend user OR (later) a member.
  Unique on (realm, linked id) and on the app e-mail. One app user = many devices.
- **A3 — Identified by its app e-mail**, unique across app users.
- **A4 — Login = e-mail code, no password.** A password whose reset runs over the same mailbox
  adds no strength against a mailbox attacker, only a second secret to manage (the review
  judged the choice sound; residual risks in §4).
- **A5 — The device stays signed in** until its cookies / site data are cleared, the access
  expires (cookie 400 days; 180 days unused), the device is blocked, the app user is
  deactivated, or the linked person is deleted. Re-login after expiry is self-service, so
  ADR-057's API-009 (cookie refresh) is closed.
- **A6 — The app functions are assigned in the app-user administration.**
- **A7 — Pairing is removed entirely** (code, QR, pairing window, `POST /api/v1/devices/pair`,
  pairing status, `/app/pair`).
- **A8 — Members: not built now**, the model is ready (A2). A member's capture always lands
  for review (P2 without exception).

## 4. Security rules (lead proposal 2026-11-12, corrected by the review)

**The login record (review B2, B4).** `devices/login {email}` ALWAYS writes one record and
answers 202 with the same text, for unknown, inactive and active addresses alike (the
`LoginFlow::request()` rule «a record for EVERY request», `LoginFlow.php:209`):

- a random request nonce → a short-lived HttpOnly cookie `z77_api_login` (Path `/api`,
  SameSite=Strict, 15 min);
- the record `var/lib/api/login/sha256(nonce).json`: `{app_user_id|null, sha256(nonce . code),
  tries, expires}`; for an unknown address `app_user_id` is null and no mail goes out;
- requests do NOT replace each other — a guess without the nonce counts against nobody, so
  nobody can lock a known address out by requesting codes;
- `devices/verify {code}` + the nonce cookie: one generic answer «Code falsch oder abgelaufen —
  neuen Code anfordern» for wrong, expired, used up and unknown; no tries-left counter.

**Counting under a lock (review B3).** Read, increment, compare and — on success — delete the
record under `flock` on the record file (the `IdempotencyStore::run()` / `TotpGuard::acquire()`
pattern), never check-then-count, never `FileThrottle` for the tries (fixed windows). Two
parallel correct verifies issue ONE device.

**Numbers.** Code: 6 digits, 5 tries, 15 minutes (greylisting tolerant). Per e-mail: 5
requests per hour, 15 per day — answered silently with the same 202. Per /64: 10 requests and
20 verifies per hour — 429. The daily cap per e-mail raises an operator alert
(`AlertService`). Live login records are capped (disk). Honest bound: ~0.06 % guess
success per day of sustained attack, ~2.7 % per year with the daily cap, thousands of mails to
the victim on the way — accepted for capture-only scopes (P4).

**No oracle in failure (review B1).** A send failure is logged, never answered: the 202 is
the same. Timing: the response is sent BEFORE the mail (flush, then send), or both paths get
the same floor. No «no account here» mail (unlike the member login): mailing strangers from an
anonymous endpoint would be a relay; the app is a closed group.

**The mails.** The code mail is sent at once (`EmailService::send()`, never the outbox batch),
with «niemandem weitergeben — z77 ruft nie an und fragt nie nach diesem Code», the requesting
device and time (like `LoginFlow::loginMail()`). After a successful sign-in: a «Neues Gerät
angemeldet» mail to the app e-mail (mitigates code relay by phone call). The mails are
rendered through the real stack with an explicit language (the stateless path sets none).

**Rights (review B5, B7).**
- A scope declares the MINIMUM ROLE per realm the linked person needs to capture:
  `'receipt.capture' => ['label' => …, 'hint' => …, 'realms' => ['backend' => AuthRole::EDITOR]]`;
  no `member` key = never for members. Checked with `AuthRole::rolesSatisfy()` against the
  linked user's roles FROM THE STORE, when assigning AND on every request (rights can shrink).
  This reverses ADR-057 decision 6 deliberately (scopes now depend on the person's roles).
- The app-user administration carries `ApiDeviceAccess::mayActFor()` over: an actor never
  creates, links, changes the e-mail of, assigns scopes to or reactivates an app user linked to
  a person whose roles are above his own, and assigns only scopes he could hold himself.

**Lifecycle (review B6) — one rule.** Deactivating an app user revokes ALL its devices and
deletes its pending login records; reactivation means a fresh code login (consistent with
E13: a block is final). `resolve()` and `verify` check on EVERY call that the app user is
active and the linked person exists. A new `accountRemovedListeners` entry in module-api
deactivates the app user when its backend user is deleted (today a device only dies because
`resolve()` finds no owner). Backend users have no «inactive» flag — role reduction is
covered by the live scope check.

**E-mail change.** Devices stay valid (they belong to the app user, not the address); pending
login records are deleted; the OLD address gets a notice; the actor rule applies.

**Re-login on a device that is signed in** as the same app user revokes the old device row.

**Residual risks, accepted while scopes are capture-only (P4):** mailbox compromise = app
access; online guessing as quantified; code relay by social engineering; lockout of NEW
sign-ins by flooding a known address (signed-in devices keep working); no sign-in while mail
delivery is down; no TOTP even where the linked backend user has one; the 400-day cookie on an
unlocked phone is the real credential.

## 5. Model

- **One-time login codes in the kernel** (`Z77\Shared\Auth`, next to `TotpGuard` — review,
  Rule 9): the record store with nonce, lock, tries and sweep, so members (A8) reuse it.
- **`ApiAppUser`** (module-api, file entity `framework/api/app_users.json`, `CollectionStore`):
  `id`, `email` (normalised lower-case, unique), `linked_realm` (`backend` | `member`),
  `linked_id` (string), `scopes` (list), `active`, `created_at/by`, `changed_at/by`,
  `last_login_at`. No display name — the linked person's name is the name (Rule 2).
- **`ApiDevice`** gains a NEW field `app_user_id`; `owner_id` is never reinterpreted (review:
  the old test phone, owner 1, would otherwise become app user 1's device). A row without
  `app_user_id` resolves to nothing → 401. No upgrade step needed — `next.` and the live host
  share `data/` and `var/lib`, an upgrade run on `next` would hit the live release. The device's
  own `scopes` field is no longer read (the app user's apply).
- **`ApiPrincipal`** (device): `deviceId`, `deviceLabel`, `appUserId`, `linkedRealm`,
  `linkedId`, `ownerName` (the linked person's name — «Handy Peter · iPhone»), `scopes` (the
  app user's, filtered live).

## 6. Endpoints and pages

Built-in endpoint `devices` (no new reserved name):

| Method | Endpoint | Auth | Purpose |
|---|---|---|---|
| POST | `/api/v1/devices/login` `{email}` | anonymous (X-Z77-App + Origin, throttled) | always 202 + the nonce cookie |
| POST | `/api/v1/devices/verify` `{code}` | anonymous + nonce cookie | sets the device cookie |
| GET | `/api/v1/devices/me` | device | label, owner name, scopes |
| POST | `/api/v1/devices/logout` | device | revokes THIS device and clears the cookie |

- The anonymous door is today ONE constant, `ApiKeyGuard::PAIRING_SLUGS`, reused in
  `GatewayController::access()` (line 182): it becomes `login` + `verify`. Both stay
  anonymous — `IdempotencyStore` stores headers, a non-anonymous verify would write the
  plaintext `Set-Cookie` to `var/lib`.
- Page `/app/login` (e-mail → code on one page, `autocomplete="one-time-code"`), `/app` without
  a valid device → `/app/login`; `/app/pair` removed.
- **Service worker:** `app-sw.js` `CACHE = 'z77-app-v1'` → `v2`, `PAGES` `/app/pair` →
  `/app/login`, or installed phones keep the pairing shell.
- **Logout and the offline queue:** the queue belongs to the browser — logout is refused while
  it holds unsent entries («Erst senden oder verwerfen»), so nothing captured goes out under
  another person's name.

## 7. Backend

- **Stammdaten › System › «App-Benutzer»** replaces «Geräte»: standard list (verknüpfte
  Person, App-E-Mail, Funktionen, Geräte, zuletzt angemeldet, aktiv), the app user window
  (the linked person — a picker over backend users now, members later —, the app e-mail, the
  function switches filtered by the rights rule, «aktiv», the devices with «Sperren …»).
  Who administers: see §11. **A board on the developer canvas first (Rule 8).**
- «Geräte» and its pairing window are removed. Navigation: the change does not reach shared
  `data/` on deployed projects (INST-NAV-SHARED-001) — the deploy note says how to merge it;
  projects that override `apiConfig` carry `/app/pair` and must update their copy.

## 8. Migration of what exists

- `devices.json`: rows without `app_user_id` stop working (401) by the model itself — the
  owner's test phone signs in again by code. No upgrade step.
- `var/lib/api/pairing`: disposable (10 minutes), nothing to do.
- Receipts: nothing — `device_id`, `device_name`, `captured_by` are snapshots without a
  foreign key; the capture key is global, so a queue survives a re-login.

## 9. Steps

1. **ADR-058** «App users: e-mail-code login for the device channel» (A1–A8, P4, §4) —
   superseding ADR-057 decisions 4–6; this plan as its build plan.
2. **Board «App-Benutzer»** on the developer canvas; the owner looks.
3. **Kernel + gateway:** the one-time login code store, `ApiAppUser`, `devices/login|verify|
   logout`, the guard's anonymous list, device → app user, the live scope filter with
   minimum roles, the actor rule, the listener, removal of pairing. Tests incl. the lock, the
   anti-oracle (same answer and record for unknown addresses), the lockout immunity.
4. **App shell:** `/app/login`, redirect, logout with the queue rule, SW cache bump; remove
   `/app/pair`. Tests.
5. **Backend «App-Benutzer»** after the board; remove «Geräte». Tests. — **Built 2026-11-12**
   (`topics/api.md` «backend screen «App-Benutzer»», `tests/module-api-app-users-ui.php`). Lead
   decisions on the board's open questions: deactivating is a danger button «Deaktivieren …» with a
   question, reactivating «Aktivieren …» (no switch); a person above the actor is listed, its window
   read-only; choosing the person proposes the person's backend e-mail. Not built: «Belege dieses
   App-Benutzers» (module-api does not know the journal — api.md API-010).
6. **Docs:** api.md, receipt.md (minimum role of `receipt.capture`), security.md, the
   envelope doc, consumer-project deploy notes; `docs:check`.

Rough effort: 3 ≈ 3 days, 4 ≈ 1 day, 5 ≈ 2 days, docs and tests ≈ 1 day.

## 10. What the review changed (2026-11-12)

Blocking findings taken over: B1 the send-failure answer was an oracle → silent 202, send
after the response; B2 a 6-digit code cannot be its own key → one record per request with a
nonce cookie, also for unknown addresses; B3 tries counted under a lock, not with
`FileThrottle`; B4 «a new request replaces the old code» allowed locking anyone out → requests
are independent; B5 privilege escalation through the admin → `mayActFor()` carried over, scopes
only as far as the actor holds them; B6 the lifecycle rested on a listener and an «inactive»
flag that do not exist → one rule, a new listener; B7 «the role that edits the journal»
(ADMIN) would have shut out EDITOR employees → a minimum role per scope, decision 6 reversed
openly. Important ones taken over: the guard's anonymous list, a new field instead of an
upgrade step (shared data on next/live), the SW cache bump, the nav note, the daily cap and
alert, the anti-relay mail texts and the new-device mail, the queue rule at logout, the code
store in the kernel. Minor ones taken over: ADR-058 instead of an addendum, no display name,
endpoint `devices/*` instead of a new reserved `app`, re-login revokes the old row, 15-minute
TTL, a cap on live records, the e-mail-change notice. Kept as sound: a code instead of a
link/QR, the device cookie model, the file entity for app users, live scope checks, no receipt
migration, the removal of pairing, the direct code mail, «e-mail code only».

## 11. Decided — who administers app users

- **A9 — Only an ADMIN creates and manages app users** (owner 2026-11-12). No self-service:
  this replaces E14 (an EDITOR paired his own phone). The actor rule of §4 applies on top
  (never for a person with roles above the actor's).
