# Member login security review — 2026-08-07

Full read-through of the B8 passwordless login (`packages/module-member`) for a working
login and against abuse, cross-checked against [`../topics/member.md`](../topics/member.md),
[`../topics/security.md`](../topics/security.md) and
[ADR-029](../02-decisions/adr-029-member-session-and-framework-acl.md). Reviewer: Fable 5.

The cross-device design — the mail link opens a confirmation page and a human decides
whether the REQUESTING or the READING device gets the session — is a deliberate decision
(spec 1.1.0, decision 5) and was reviewed as such: the question is not *whether* but
*whether the guardrails around it hold*. They do. Four findings, none of which broke the
login; all were resolved or downgraded the same day — see the Verdict at the end.

## Scope reviewed

- **Services:** `TokenService`, `LoginFlow`, `MemberSession`, `MemberAuth`, `PendingLogins`,
  `DeviceKeys`, `DeviceCookie`, `MemberThrottle`, `Totp`, `TotpGuard`, `TotpVault`,
  `TotpSetup`, `RegistrationFlow`, `MemberAccounts`.
- **Entities:** `MemberAccount`, `MemberToken`, `MemberPendingLogin`.
- **Controllers / config:** `LoginController`, `ConfirmController`, `ResendController`,
  `LogoutController`, `ProfileController`, `AbstractMemberController`,
  `AccountsControllerTrait` (backend surface), `memberConfig.inc.php`.
- **Kernel dependencies:** `SessionManager` (regenerate + cookie params), `CsrfService`,
  `PublicFormHandler` / `FormGuard` cascade, `Request` (mode + host), `FileStorage`
  (cycle lock).
- **Templates & JS:** `redeemAction`, `wartenAction`, profile `indexAction`, all
  `emails/*`, `login-wait.js` — checked as XSS sinks and for token exposure.
- **Cron:** `bin/member-cleanup.php`.

## What holds (verified in code)

- **Token mechanism.** 32 bytes from `random_bytes`, only the SHA-256 hash persisted,
  single-use (`usedAt` written together with the redeem decision), 15-minute TTL,
  purpose-bound (`login` vs `confirm`), and a re-request devalues the account's open
  tokens of that purpose. The plaintext exists exactly once — in the mail. Guessing is out
  of reach, and a leak of `tokens.json` yields no usable link.
- **A GET never consumes a login link.** The click renders context through
  `TokenService::inspect()`; only a CSRF-checked POST (`decision=confirm|here`) redeems.
  Mail scanners and safe-link services, which fetch every link automatically, can neither
  release a session nor burn the token. (Registration confirm is the exception — F2.)
- **Cross-device binding.** The waiting record's id lives only in the requesting browser's
  session (`member.pendingLoginId`); `poll()` answers about that record and nothing else.
  No endpoint accepts a record id from input, and ids are random (`p-` + 8 random bytes).
  A stranger can neither attach to, enumerate, nor poll someone else's wait.
- **Human-verifiable context.** Check digits (display-only, on both screens plus the mail
  subject and body), device label and request time let the reader tell an own login from
  one a stranger started on the same address. The mail says what a mismatch means and that
  nothing happens without opening AND confirming. «Hier anmelden» deletes the other
  device's waiting record, so a stranger's requesting browser polls into `dead`, never
  into a session.
- **Session hygiene.** `session_regenerate_id(true)` on session start, on TOTP-pending
  start and on end (fixation defense); rolling 2-hour idle expiry; cookie parameters
  hardened kernel-side (SEC-003: HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode).
  Member keys never touch `auth_user` (ADR-029) — no privilege bleed into the framework
  ACL, and the DMS/page-cache semantics stay untouched.
- **TOTP.** RFC 6238 with ±1 period drift and `hash_equals`; the secret is AES-256-GCM at
  rest and decrypts to null on tampering (→ "no 2FA", never a wrong secret); 5 failures →
  15-minute lock per ACCOUNT, so it survives session rotation, and the right code is
  refused while locked; enabling AND removing both require a valid app code, so a stolen
  session cannot silently drop the second factor; the operator reset covers the
  lost-device case. The 5-minute pending window bounds the interstitial.
- **Device keys («angemeldet bleiben»).** The cookie carries the only plaintext, the
  account stores the SHA-256, compared with `hash_equals`. The key is issued strictly at
  the END of a complete login — after TOTP when 2FA is active — so resuming is not a
  second-factor bypass. Rolling 90-day TTL, cap of 5, revocable individually, all at once,
  and on logout; every miss clears the cookie.
- **Anti-oracle.** Known, unknown and never-confirmed addresses all produce the same page
  and the same waiting record (token-less when no link went out); the difference lives in
  the mail alone. Bot detection (honeypot / time-trap) pretends success. Throttling is
  per address (5/h, window shared with registration) and per session (FormGuard), and it
  counts regardless of account existence — so it is no oracle either. The one deviation is
  F3.
- **CSRF.** Every state-changing POST validates the session token (`redeem`, `totp`, all
  profile actions) or the entity token (backend accounts surface); public forms run the
  full `PublicFormHandler` cascade.
- **Output escaping.** `e()` on every sink, including the attacker-influencable
  User-Agent-derived device label — in pages and in mail bodies.
- **Cleanup.** The cron purges dead tokens, expired waiting records and never-confirmed
  accounts; `confirmed` accounts stay an operator decision.

## Findings

### F1 — Mail links are built from the Host header — Medium — RESOLVED 2026-08-07

> **Closed by [ADR-030](../02-decisions/adr-030-system-configuration.md).** The origin now
> comes from `canonicalBaseUrl` in `config/systemConfig.inc.php` (seed-once, per
> installation), published by `Bootstrap` as `CANONICAL_BASE_URL`; `Request::getBaseUrl()`
> throws when it is unset rather than guessing. No Host-header fallback remains anywhere.
>
> Two things this finding got wrong, both found while fixing it: the blast radius was wider
> than "mail links" — `AbstractBaseController::buildSeoLinks()` fed the SEO canonical and
> hreflang from the same header into pages the `PageCache` keys by path only, so one forged
> request poisoned what every later visitor was served. And the recommendation ("kernel-side
> trusted host, one config value") named the wrong home: see the plan's superseded section.
> The review scoped itself to `module-member`; a header-derived value needs a framework-wide
> grep, not a per-module one.

`AbstractMemberController::absoluteUrl()` and `AccountsControllerTrait::memberAbsoluteUrl()`
build the login, confirm and activation links from `$_SERVER['HTTP_HOST']`. The header is
attacker-controlled, and the framework has no trusted-host concept anywhere.

Attack: the attacker submits the victim's address on the login form with a forged
`Host: evil.tld`. The victim receives a GENUINE mail from the real installation whose link
points at the attacker's domain. Opening it hands the one-time token to the attacker, who
redeems it on the real host — full account takeover.

What limits it: the request must actually reach this application under the forged host,
which requires a catch-all vhost (common on shared hosting and panel setups, absent on a
correctly pinned `ServerName`). And the mail's own guardrails bite: the victim did not
start this login, so no screen shows the check digits, and the mail says in as many words
to delete it in that case. The link is also printed in full, so the wrong domain is
visible.

This is not fixable inside `module-member` alone — it belongs to the kernel (a configured
canonical host, or a `Request::getHost()` that validates against it). Options, in order of
preference:

1. Kernel-side trusted host: one config value, `Request` rejects or normalises anything
   else. Fixes every link-generating feature at once, not just this module.
2. Server-side pinning as the documented deployment requirement (`ServerName` + a default
   vhost that is not this app) — zero code, but it is a promise nobody enforces.

Recommendation: option 1, tracked as a kernel/security pendenz rather than a member-module
patch. Deliberately not changed in this pass — it reaches beyond this module.

### F2 — The registration confirm link changes state on a GET — Low

`ConfirmController::indexAction` redeems the confirmation token and flips
`registered → confirmed` on a plain GET. That is exactly what `member.md` forbids in its
own rule («a state change triggered from a mail MUST route through a POST»). The B8 login
link was built to that rule; the B7 confirm link predates it and was never brought along.

Impact is small: a mail scanner that fetches the link confirms an address whose mailbox it
already sits in, which is the very thing confirmation proves, and the human clicking later
lands on the handled «bereits bestätigt» page. But the rule exists for a reason, and one of
its two consumers ignores it. Either align it with the B8 pattern (landing page + POST
button) or narrow the rule to state changes that grant access. Not changed here — it is a
UX change to the registration flow, not a login fix.

### F3 — A throttled login request does not get the neutral answer — Low

`member.md` states the visitor-facing answer is identical for known, unknown AND throttled
addresses. For throttled it is not: `LoginFlow::request()` returns false, so
`PublicFormHandler` re-renders the form with the send-error banner instead of redirecting
to `/member/main/login/warten`.

This leaks nothing about account existence — the throttle counts per address regardless of
whether an account exists, and the attacker caused the counter themselves. So it is a
documentation error, not a hole; the doc has been corrected rather than the code.

Related loose end in the same path: when throttled, `request()` still opens a waiting
record and writes its id into the session, although the visitor never reaches the waiting
page. The record is garbage that only the cron collects, and the comment claiming the
waiting page must look identical does not apply on this branch.

### F4 — A resumed session rewrites `accounts.json` — Low, operational

`DeviceKeys::restore()` rolls `last_used_at` and `valid_until` on every hit and saves the
whole account. Writes serialise through `FileStorage::withExclusiveLock()` with a ~2 s
acquisition budget, then throw.

**Corrected after a second reading:** this fires far less often than first stated here.
`MemberAuth::current()` only reaches `restore()` when the member session is absent —

```php
$accountId = $this->session->currentAccountId($now);
if ($accountId === null) { return $this->resume($now); }
```

— and `resume()` starts a session, so every following request takes the fast path. One
write per browser start or per 2-hour idle timeout, not one per page view. That is the same
order of magnitude as a login, which writes the store anyway.

So this is a note, not a defect. Rolling lazily (only when `last_used_at` is older than a
day) would still cut the writes and keeps the documented 90-day rolling semantics, but the
motivation is thin at today's frequency — it becomes relevant if the idle window is
shortened or the session cookie is ever dropped.

## Notes (accepted, no action proposed)

- **TOTP code replay inside its window.** A valid code is not consumed, so it works for up
  to ~90 s (period ±1 drift). RFC 6238 suggests refusing reuse. Exploiting it requires the
  code AND the live magic link in the same 90 seconds; the lock guard bounds guessing.
- **`TotpVault` key creation races on first use.** Two concurrent first requests can each
  generate a 32-byte key; the loser's ciphertext becomes undecryptable. Blast radius is one
  half-finished 2FA setup, and `decrypt()` answers null rather than a wrong secret, which
  the setup path already handles by starting over.
- **`Secure` on the device cookie is derived from `$_SERVER['HTTPS']`.** Behind a
  TLS-terminating proxy that value is absent and the cookie goes out without the flag. This
  matches `SessionManager` exactly (SEC-003), so it is consistent, not a new gap — but both
  share the fragility.
- **The dangerous button was the prominent one — REVERSED 2026-08-07 (decision Peter).**
  On the confirmation page «Anmeldung bestätigen» hands another device the session, while
  signing in here is harmless even when you did not start the login. The emphasis now sits
  on the safe action: confirming is the quiet button, «auf diesem Gerät anmelden» the
  primary one. The order stays as it was — confirming belongs directly under the check
  digits it refers to. Covered structurally in the B7 harness (the confirm form carries
  the quiet class, the here-form does not).
- **`$_SERVER` is read outside `Request`** in `AbstractMemberController::absoluteUrl()`,
  `AccountsControllerTrait`, `LoginFlow` (User-Agent), `DeviceKeys` and `DeviceCookie` —
  a deviation from conventions rule 4. There is no alternative today: `Request` exposes
  neither host nor User-Agent, and the kernel has no cookie abstraction. Both are the same
  seam F1 needs, so they should be closed together.
- **`statusAction` changes state on a GET** (creates the session, issues the device key).
  Not exploitable — a `SameSite=Lax` cookie is not sent on cross-site subresource requests,
  and the worst outcome is completing the victim's own pending login — but it is worth
  knowing when the endpoint is touched next.

## Verdict

The login works and the mechanism is sound: high-entropy single-use tokens that are never
stored in the clear, a redemption path no automated fetch can trigger, a waiting record
bound to the requesting session and to nothing an attacker can supply, second factor and
device keys that are correctly ordered relative to each other, and a consistent anti-oracle
answer. The one attack that reached the token — F1 — came in through the Host header from
outside this module and was the kernel's to close; it is closed (ADR-030).

**Status 2026-08-07, end of day:** F1, F2 and F3 are resolved, F4 was downgraded to a note
after a second reading. The one open decision — the emphasis of the two buttons on the
confirmation page — was taken the same evening and the emphasis reversed (see the notes
above). Nothing from this review is outstanding; what remains is operational, not a
finding: `canonicalBaseUrl` has to be set on each installation, which no mechanism can do
for you.
