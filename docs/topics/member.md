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
- **Anti-oracle is a design constraint, not a feature:** register, resend and login answer the same page for a known, an unknown and a never-confirmed address — the mail differs, the page never does. A waiting record is opened even when no link went out. A THROTTLED address is the one exception (MEM-005): it gets the form's send-error banner, which reveals the counter the visitor filled themselves, never whether an account exists.

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
- When adding a mail or page to a login/registration path → MUST keep the visitor-facing answer identical for a known, an unknown and a never-confirmed address; the differences belong in the mail body only. The throttled case may differ (MEM-005) — it says something about the visitor's own request rate, never about the account.
- When building an absolute URL for a mail link → MUST take the origin from `Request::getBaseUrl()` (`absoluteUrl()` / `memberAbsoluteUrl()` already do); MUST NOT read `$_SERVER['HTTP_HOST']`, which the client chooses.
- When storing anything derived from a token, a device key or a TOTP secret → MUST store a hash (tokens, device keys) or the vault ciphertext (TOTP); MUST NOT log or persist the plaintext.
- When a state change is triggered from a mail → MUST route it through a POST on a page; MUST NOT let a GET link change state, because mail scanners and safe-link services fetch every link automatically.
- When reading or writing device keys → MUST go through `DeviceKeys`; MUST NOT treat the `label` as an identity — it is derived from the User-Agent and two workplaces share it.
- When comparing the ISO timestamps of device keys → MUST compare as timestamps (`strtotime`), MUST NOT compare as strings: the values carry their UTC offset and sort wrong across the daylight-saving change.
- When a project deploys this module → MUST ship `res/assets/js/login-wait.js` to `public/`, otherwise the waiting page never advances.
- When adding a route to this module → MUST add it to BOTH the module config and any project override of that file, since the override wins whole.

## known issues

- **MEM-001:** don't assume the `roles` array on `MemberAccount` feeds the framework ACL. It holds `AuthRole` strings (e.g. `member`), but nothing reads them during dispatch — `AccessGuard` resolves `AuthUser` from `loginUsers.json`. A controller declared `AuthRole::MEMBER` locks signed-in customers OUT. See ADR-029.
- **MEM-002:** don't assume a member session survives a page cache. Member pages are excluded via `cache.enabled = false` in the module config; if a project moves member-specific markup onto a cacheable page, the page-cache bypass has to be widened first (see `cache.md` CACHE-ADMIN-001).
- **MEM-003** — resolved 2026-08-07. `TemplateRenderer` used to extract into a scope holding its own `$path` / `$context`, so a view variable of either name was silently dropped and the template saw the renderer's value. The render scope now carries prefixed locals (`$z77TplPath` / `$z77TplContext`) and no view variable can collide; the same shape was applied to `EmailService` and `StylesheetManager`, which had the inverse bug (no `EXTR_SKIP`, so a `tplPath` key chose the included file). View variables may now carry any name — this module's `pending` is no longer a work-around, just a good name.
- **MEM-004:** don't assume a deleted device-key cookie can be cleaned up server-side. The cookie WAS the identity; the orphaned entry is indistinguishable from a second real device with the same browser. Only the cap and the profile list address it.
- **MEM-005:** don't assume the neutral answer covers the throttled case. `LoginFlow::request()` returns false on throttle, so `PublicFormHandler` re-renders the form with the send-error banner instead of the waiting page. Harmless (the counter is per address and the visitor filled it themselves), but the page is NOT identical. Same path opens a waiting record nobody will ever see — garbage the cron collects.
- **MEM-006** — resolved 2026-08-07. The mail links used to be built from `$_SERVER['HTTP_HOST']`, so a forged Host under a catch-all vhost produced a genuine login mail carrying an attacker-owned link. Both helpers now take the origin from `Request::getBaseUrl()`, i.e. the installation's configured `canonicalBaseUrl` (SEC-005). Note the residual: an installation that has not set that value still falls back to the header (SEC-006) — setting it is a deployment step, not something this module can enforce.
- **MEM-007:** don't assume the registration confirm link obeys this module's own POST rule. `ConfirmController::indexAction` flips `registered → confirmed` on a GET (B7 predates the rule; B8's login link follows it). Low impact — a scanner confirms a mailbox it already sits in — but the rule and the code disagree.
- **MEM-008:** don't assume a page view is read-only for a remembered device. `DeviceKeys::restore()` rolls the key and rewrites the whole `accounts.json` — but only where `MemberAuth::current()` finds NO session and resumes one, i.e. once per browser start or per 2-hour idle timeout, not per request. That is the same order of magnitude as a login, so it is a note, not a problem; it only matters if the idle window is ever shortened or the session cookie is dropped.

## pending

- **Roll device keys lazily (MEM-008)** — optional: only when `last_used_at` is older than a day, keeping the documented rolling-90-day semantics. Low value at today's write frequency; the reason to do it is if the idle window ever shrinks.
- **Decide the confirm-button emphasis** — «Anmeldung bestätigen» is the primary button while it is the one that hands a stranger the session; «hier anmelden» is harmless. Reversing the weight trades the everyday case against the attack case; the check digits and the mail wording already carry the defence. Conscious decision, not a silent change.
- Second factor for the ADMIN login (`LoginUser.secondFactor`, `security.md` roadmap) is untouched by this module — the TOTP implementation here (`Totp`, `TotpVault`, `TotpGuard`) is reusable for it, but no seam exists yet.
- Member session vs. framework ACL is an open architecture question — framed in ADR-029, to be decided when a role-based member application is built.
- The member layout registers the `member-main` nav slot but does not render it (`navigation.md`); a project that wants member navigation overrides the layout.

## see also

- [`login.md`](login.md) — the ADMIN login this module deliberately does not touch (`AuthUser`, `AccessGuard`, role resolution)
- [`security.md`](security.md) — CSRF, throttling and the per-user second-factor roadmap for `LoginUser`
- [`forms.md`](forms.md) — the public-form standard the register/login/resend pages are built on
- [`mail.md`](mail.md) — `EmailMessage`, templates and the email settings the mails go out through
- [`../02-decisions/adr-029-member-session-and-framework-acl.md`](../02-decisions/adr-029-member-session-and-framework-acl.md) — why the two auth worlds are separate and what has to be decided before they meet
- [`../03-development/ideas/magic-link-passwordless-login.md`](../03-development/ideas/magic-link-passwordless-login.md) — the original idea this module realises
- [`../03-development/member-login-security-review-2026-08-07.md`](../03-development/member-login-security-review-2026-08-07.md) — full read-through of the login for abuse; what holds and why, and the reasoning behind MEM-005…008
- [`../03-development/member-mem-findings-bauplan.md`](../03-development/member-mem-findings-bauplan.md) — solution plan for MEM-001…008: what gets built, what stays a documented constraint, and in which order
