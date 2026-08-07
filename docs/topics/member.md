# member

2026-08-07

## entry

1. `packages/module-member/src/Services/MemberAuth.php` — the one question other code asks: «who is signed in?» (account + roles of the member session)
2. `packages/module-member/src/Services/LoginFlow.php` — request / redeem / approve / poll / logout, i.e. the whole passwordless login
3. `packages/module-member/src/App/Config/memberConfig.inc.php` — routes, the activation hook seam, cleanup window; a project overrides this file whole

## file map

SOURCE=/packages/module-member/src/App/Config/memberConfig.inc.php
SOURCE=/packages/module-member/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-member/src/Entities/MemberAccount.php
SOURCE=/packages/module-member/src/Entities/MemberToken.php
SOURCE=/packages/module-member/src/Entities/MemberPendingLogin.php
SOURCE=/packages/module-member/src/Services/MemberAccounts.php
SOURCE=/packages/module-member/src/Services/TokenService.php
SOURCE=/packages/module-member/src/Services/RegistrationFlow.php
SOURCE=/packages/module-member/src/Services/LoginFlow.php
SOURCE=/packages/module-member/src/Services/MemberAuth.php
SOURCE=/packages/module-member/src/Services/MemberSession.php
SOURCE=/packages/module-member/src/Services/MemberThrottle.php
SOURCE=/packages/module-member/src/Services/PendingLogins.php
SOURCE=/packages/module-member/src/Services/DeviceKeys.php
SOURCE=/packages/module-member/src/Services/DeviceCookie.php
SOURCE=/packages/module-member/src/Services/Totp.php
SOURCE=/packages/module-member/src/Services/TotpSetup.php
SOURCE=/packages/module-member/src/Services/TotpVault.php
SOURCE=/packages/module-member/src/Services/TotpGuard.php
SOURCE=/packages/module-member/src/Ui/Controllers/AbstractMemberController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/RegisterController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/ConfirmController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/ResendController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/LoginController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/LogoutController.php
SOURCE=/packages/module-member/src/Ui/Controllers/Main/ProfileController.php
SOURCE=/packages/module-member/src/Ui/Form/RegisterFormDefinition.php
SOURCE=/packages/module-member/src/Ui/Form/LoginFormDefinition.php
SOURCE=/packages/module-member/src/Ui/Form/ResendFormDefinition.php
SOURCE=/packages/module-member/src/Ui/AccountsControllerTrait.php
SOURCE=/packages/module-member/src/Ui/AccountsLayout.php
SOURCE=/packages/module-member/bin/member-cleanup.php
SOURCE=/packages/module-member/res/view/templates/Main/LoginController/wartenAction.tpl.php
SOURCE=/packages/module-member/res/view/templates/Main/LoginController/redeemAction.tpl.php
SOURCE=/packages/module-member/res/view/templates/Main/ProfileController/indexAction.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/login-link.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/confirm.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/activated.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/no-account.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/existing-account.tpl.php
SOURCE=/packages/module-member/res/view/templates/emails/confirmed-notify.tpl.php
SOURCE=/packages/module-member/res/view/templates/Backend/AccountsController/listAction.tpl.php
SOURCE=/packages/module-member/res/assets/js/login-wait.js
SOURCE=/packages/module-member/res/scss/member.scss
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/MemberAccountsController.php
SOURCE=/packages/module-backend/src/Ui/Config/Service/memberAccountsControllerConfig.inc.php

## mental model

Customer accounts with a **passwordless** login, in their own view area — deliberately separate from the file-based admin login (`login.md`). A visitor registers, confirms the address, an operator activates the account in the backend; from then on the customer signs in with a one-time link. `MemberAuth` is the only thing other building blocks consume: it answers account + roles of the current member session. Persistence is carrier-neutral through the UEM, file-driven at the start; every store writes under `data/framework/member/` at runtime (`accounts.json`, `tokens.json`, `pending-logins.json`, `totp.key`, plus throttle and guard directories).

- **Account states run strictly forward:** `registered` → `confirmed` → `active`. The transition methods throw on anything else; "already there" is the caller's check (`isConfirmed()`/`isActive()`). Only activation grants roles.
- **One token mechanism, two effects** — `MemberToken` carries a `purpose` (`confirm` | `login`). Only the SHA-256 hash is stored; the plaintext exists once, in the mail. Issuing invalidates the account's open tokens of the same purpose (resend semantics).
- **The project side of activation is a config seam:** `memberConfig['activationHook']` names an invokable `__invoke(MemberAccount): ?string`. It runs INSIDE the transition — if it throws, the account stays `confirmed` and nothing is persisted.
- **Two sessions exist side by side.** The member session lives under its own keys (`member.*`) in the kernel session; it never touches `auth_user`. `AccessGuard` therefore sees a signed-in customer as a guest — the member pages guard themselves via `MemberAuth`.
- **Login is a magic link with two redemption paths** (spec-driven, see `see also`): the link opens a confirmation page where the human decides whether the REQUESTING device (release the waiting record, the requester polls until it flips) or the READING device (sign in here) gets the session. Check digits plus a context line let the reader tell an own login from one a stranger started on the same address.
- **Everything optional hangs off the session, not off the click:** the TOTP prompt and the «angemeldet bleiben» device key are always created for the device that receives the session.
- **Device keys are revocable and capped.** The plaintext lives only in the device's cookie (`accountId.keyId.secret`), the account keeps its SHA-256; use rolls it forward 90 days. A deleted cookie leaves an entry nobody can recognise, so `DeviceKeys::MAX_KEYS` lets the longest-unused one give way.
- **Anti-oracle is a design constraint, not a feature:** no page on register, resend or login differs by ACCOUNT STATE — known, unknown and never-confirmed all get the same answer, and only the mail differs. Login goes one step further and hides the throttle too (MEM-005): it always lands on the waiting page, which opens a record even when no link went out and then simply never turns green. Register and resend still surface a throttle as the form's send error — that says something about the visitor's own request rate, never about an account, and on those pages the honest "nothing was sent" is worth more than the symmetry.

## flow

```text
register ──confirm mail──▶ confirmed ──operator activates (hook)──▶ active
                                                                     │
login request ─▶ waiting record + mail (link, check digits, context) │
                    │                                                │
                    ├─ «confirm»  ─▶ record released ─▶ requesting device polls ─┐
                    └─ «here»     ─▶ reading device ──────────────────────────────┤
                                                                                  ▼
                                                      [TOTP prompt if enabled] ─▶ session
                                                      (+ device key when ticked)
```

## rules

- When building a page for signed-in customers → MUST guard it with `MemberAuth::create()->current()` and MUST declare the route `AuthRole::GUEST` in the module config; the framework ACL resolves the ADMIN login, not the member session.
- When a project needs its own behaviour at activation → MUST set `memberConfig['activationHook']` by overriding that config file whole; MUST NOT teach this module a project domain.
- When adding a mail or page to a login/registration path → MUST keep the visitor-facing answer identical for a known, an unknown and a never-confirmed address; the differences belong in the mail body only. On the LOGIN path the throttle MUST be invisible too — `LoginFlow::request()` returns true unconditionally for exactly that reason; on register/resend it may surface as the form's send error.
- When building an absolute URL for a mail link → MUST take the origin from `Request::getBaseUrl()` (`absoluteUrl()` / `memberAbsoluteUrl()` already do); MUST NOT read `$_SERVER['HTTP_HOST']`, which the client chooses.
- When storing anything derived from a token, a device key or a TOTP secret → MUST store a hash (tokens, device keys) or the vault ciphertext (TOTP); MUST NOT log or persist the plaintext.
- When a state change from a mail GRANTS ACCESS (a session, a role, a device key) → MUST route it through a POST on a page; MUST NOT let a GET link do it, because mail scanners and safe-link services fetch every link automatically and would grant it unattended. A change that only RECORDS MAILBOX CONTROL may happen on the GET — the registration confirmation does, deliberately (MEM-007): an automated fetch proves exactly what the click proves, since the scanner sits in the recipient's own mail infrastructure, and the extra click would sit on the highest-friction step of registration.
- When reading or writing device keys → MUST go through `DeviceKeys`; MUST NOT treat the `label` as an identity — it is derived from the User-Agent and two workplaces share it.
- When comparing the ISO timestamps of device keys → MUST compare as timestamps (`strtotime`), MUST NOT compare as strings: the values carry their UTC offset and sort wrong across the daylight-saving change.
- When a project deploys this module → MUST ship `res/assets/js/login-wait.js` to `public/`, otherwise the waiting page never advances.
- When adding a route to this module → MUST add it to BOTH the module config and any project override of that file, since the override wins whole.

## known issues

- **MEM-001** — resolved 2026-08-07 (ADR-029, second decision). The member session now DOES feed the framework ACL: `MemberAuthBridge` (registered in memberConfig under `authBridges`) runs inside `AccessGuard::enforce()` before role resolution and projects the member session into `auth_user` — realm `member`, string account id, the account's roles (`customer`, level 15). Member routes that need a sign-in carry `AuthRole::CUSTOMER` in the config; the guard redirects guests to the module's `loginUrl` (the member login, not the admin login). Two things deliberately did NOT change: `AuthRole::MEMBER` still means backend-assigned Mitglieder (a customer never reaches level 20 — DMS role-ACEs stay untouched), and the member session stays the source of truth — the bridge derives the projection per request, login/logout never write `auth_user` twice. The realm keeps the id spaces apart: `CurrentUserService` and the DMS `Principal` treat a member-realm identity as «no backend user» (no user-ACE/ownership matches).
- **MEM-002:** don't assume a member session survives a page cache. Member pages are excluded via `cache.enabled = false` in the module config; if a project moves member-specific markup onto a cacheable page, the page-cache bypass has to be widened first (see `cache.md` CACHE-ADMIN-001).
- **MEM-003** — resolved 2026-08-07. `TemplateRenderer` used to extract into a scope holding its own `$path` / `$context`, so a view variable of either name was silently dropped and the template saw the renderer's value. The render scope now carries prefixed locals (`$z77TplPath` / `$z77TplContext`) and no view variable can collide; the same shape was applied to `EmailService` and `StylesheetManager`, which had the inverse bug (no `EXTR_SKIP`, so a `tplPath` key chose the included file). View variables may now carry any name — this module's `pending` is no longer a work-around, just a good name.
- **MEM-004:** don't assume a deleted device-key cookie can be cleaned up server-side. The cookie WAS the identity; the orphaned entry is indistinguishable from a second real device with the same browser. Only the cap and the profile list address it.
- **MEM-005** — resolved 2026-08-07. `LoginFlow::request()` used to return false on throttle, so `PublicFormHandler` re-rendered the form with the send-error banner instead of redirecting to the waiting page — the one case where the answer was not identical. It now returns true unconditionally; the throttle still suppresses the mail, it just stops being visible, and the waiting record the throttled path already opened is finally the thing the page renders. Note the accepted cost: a visitor who genuinely hit 5 requests in an hour gets the same silence an unknown address gets. **Registration and resend are NOT affected** — `RegistrationFlow::register()` / `resend()` still return false on throttle, which is correct there: those pages are not the login path and their form error says nothing about an account.
- **MEM-006** — resolved 2026-08-07. The mail links used to be built from `$_SERVER['HTTP_HOST']`, so a forged Host under a catch-all vhost produced a genuine login mail carrying an attacker-owned link. Both helpers now take the origin from `Request::getBaseUrl()`, which returns the installation's `canonicalBaseUrl` from `config/systemConfig.inc.php` (ADR-030) and **throws** when it is unset — no header fallback. Consequence to know: on an installation nobody configured, the login page throws instead of rendering. That is deliberate (a broken link in a mail is worse than a visible error), and the backend stays reachable with a Störer pointing at the missing value.
- **MEM-007** — resolved 2026-08-07 by narrowing the rule, not by changing the flow. `ConfirmController::indexAction` still flips `registered → confirmed` on a GET, and that is now explicitly allowed: the rule guards state changes that GRANT ACCESS, and confirmation grants none — the account moves into the operator's queue, and activation plus every later login each need a fresh mail in the same mailbox. What an automated fetch costs is the claim «a human clicked», downgraded to «the mailbox accepted», which is what e-mail confirmation actually proves. The B8 login link keeps the POST, because it does grant a session.
- **MEM-009:** don't assume a browser can hold both identities. One identity per browser (2026-08-07): `MemberSession::start()` drops `auth_user`, and `MemberAuthBridge` ends the member session (plus this browser's device key) when it finds a signed-in backend-realm `auth_user` — that combination can only mean the password door came last. Consequence to know: an admin signing in at the backend is logged out of the member area on this browser and loses «angemeldet bleiben» here; testing a customer account alongside an admin session needs a second browser profile. The reverse also holds — clicking a magic link ends the backend session.
- **MEM-008:** don't assume a page view is read-only for a remembered device. `DeviceKeys::restore()` rolls the key and rewrites the whole `accounts.json` — but only where `MemberAuth::current()` finds NO session and resumes one, i.e. once per browser start or per 2-hour idle timeout, not per request. That is the same order of magnitude as a login, so it is a note, not a problem; it only matters if the idle window is ever shortened or the session cookie is dropped.

## pending

- **Roll device keys lazily (MEM-008)** — optional: only when `last_used_at` is older than a day, keeping the documented rolling-90-day semantics. Low value at today's write frequency; the reason to do it is if the idle window ever shrinks.
- **Decide the confirm-button emphasis** — «Anmeldung bestätigen» is the primary button while it is the one that hands a stranger the session; «hier anmelden» is harmless. Reversing the weight trades the everyday case against the attack case; the check digits and the mail wording already carry the defence. Conscious decision, not a silent change.
- **Set `canonicalBaseUrl` per installation** — one line in `config/systemConfig.inc.php`; without it the member pages throw as soon as they build a link (ADR-030).
- Second factor for the ADMIN login (`BackendUser.secondFactor`, `security.md` roadmap) is untouched by this module — the TOTP implementation here (`Totp`, `TotpVault`, `TotpGuard`) is reusable for it, but no seam exists yet.
- Member session vs. framework ACL is an open architecture question — framed in ADR-029, to be decided when a role-based member application is built.
- The member layout registers the `member-main` nav slot but does not render it (`navigation.md`); a project that wants member navigation overrides the layout.

## see also

- [`login.md`](login.md) — the ADMIN login this module deliberately does not touch (`AuthUser`, `AccessGuard`, role resolution)
- [`security.md`](security.md) — CSRF, throttling and the per-user second-factor roadmap for `BackendUser`
- [`forms.md`](forms.md) — the public-form standard the register/login/resend pages are built on
- [`mail.md`](mail.md) — `EmailMessage`, templates and the email settings the mails go out through
- [`../02-decisions/adr-029-member-session-and-framework-acl.md`](../02-decisions/adr-029-member-session-and-framework-acl.md) — why the two auth worlds are separate and what has to be decided before they meet
- [`../03-development/ideas/magic-link-passwordless-login.md`](../03-development/ideas/magic-link-passwordless-login.md) — the original idea this module realises
- [`../03-development/member-login-security-review-2026-08-07.md`](../03-development/member-login-security-review-2026-08-07.md) — full read-through of the login for abuse; what holds and why, and the reasoning behind MEM-005…008
- [`../03-development/member-mem-findings-bauplan.md`](../03-development/member-mem-findings-bauplan.md) — solution plan for MEM-001…008: what gets built, what stays a documented constraint, and in which order
