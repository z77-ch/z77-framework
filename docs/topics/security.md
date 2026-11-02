# security

2026-11-02

## entry

1. `packages/kernel/shared/src/Auth/PasswordPolicy.php` — single source of truth for password strength (length + blocklist, NOT composition)
2. `packages/module-backend/src/Ui/Controllers/System/LoginController.php` — login + the every-login weak-password nag
3. `packages/kernel/core/src/Installer/Install.php` — `provisionAdmin()`: admin provisioning at install (interactive hidden prompt vs. non-interactive setup token)
4. `packages/module-backend/src/Ui/Controllers/System/SetupController.php` — token-gated first-run admin setup for non-interactive installs (`/backend/system/setup/setup`)

## file map

SOURCE=/packages/kernel/shared/src/Auth/PasswordPolicy.php
SOURCE=/packages/kernel/shared/src/Auth/PasswordTier.php
SOURCE=/packages/kernel/shared/src/Auth/LoginThrottle.php
SOURCE=/packages/kernel/shared/src/Auth/Totp.php
SOURCE=/packages/kernel/shared/src/Auth/TotpGuard.php
SOURCE=/packages/kernel/shared/src/Auth/TotpVault.php
SOURCE=/packages/module-member/src/Services/TotpCodeCheck.php
SOURCE=/packages/module-backend/src/TwoFactor/BackendTwoFactor.php
SOURCE=/packages/kernel/shared/src/Throttle/FileThrottle.php
SOURCE=/packages/module-backend/src/PasswordReset/PasswordRecovery.php
SOURCE=/packages/module-backend/src/PasswordReset/PasswordResetTokens.php
SOURCE=/packages/kernel/core/src/Config/auth.default.inc.php
SOURCE=/packages/kernel/shared/src/Validators/BackendUserValidator.php
SOURCE=/packages/module-backend/res/assets/js/password-meter.js
SOURCE=/packages/kernel/shared/src/Entities/BackendUser.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/BackendUserController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SetupController.php
SOURCE=/packages/module-backend/res/view/templates/System/SetupController/setupAction.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SystemController.php
SOURCE=/packages/kernel/core/src/Installer/Install.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/shared/src/Forms/FormGuard.php
SOURCE=/packages/kernel/shared/src/Attributes/Csrf.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/csrfField.tpl.php
SOURCE=/packages/kernel/core/src/Session/SessionManager.php
SOURCE=/packages/kernel/core/src/Exception/ExceptionHandler.php
SOURCE=/packages/kernel/core/src/Http/Response/DeliveryPolicy.php
SOURCE=/packages/kernel/core/src/Http/Response/SecurityHeaders.php
SOURCE=/packages/kernel/core/src/Http/Response/HtmlResponse.php
SOURCE=/tests/security-headers.php
SOURCE=/tests/file-delivery-policy.php
SOURCE=/tests/file-delivery-browser.php
SOURCE=/tests/state-changing-get.php
SOURCE=/tests/support/real-flow-server.php
RUNTIME=/skeleton/config/auth.inc.php
RUNTIME=/skeleton/config/client/securityHeaders.inc.php
RUNTIME=/skeleton/data/framework/auth/backendUsers.json

## mental model

z77 is open source — anything shipped is public, including the default admin hash. Security therefore follows **secure-by-default**, NOT environment detection: the app must not be usable with a known credential, and the environment (`debug` flag, `Host` header) is never the security control (debug can legitimately run on a public server; the Host header is attacker-controllable). Two pillars: (1) the admin is **provisioned at install** — never shipped — interactively (password prompt) or, when non-interactive, deferred to a token-gated first-run setup; (2) passwords follow a **modern policy** (length + blocklist, NOT composition) that **allows weak passwords but nags on every login** — usability without a forgotten-flag footgun.

- Strength = length + blocklist + trivial-pattern + context (username/site). NIST SP 800-63B: composition rules are rejected (they mislabel passphrases as weak and `Password1!` as strong). The min length is NOT hardcoded — it comes from the installation-wide `PasswordTier` (`config/client/auth.inc.php` key `passwordTier`), which is the SSOT for each tier's min length and block/nag behaviour (e.g. `strong`≈12, `veryStrong` blocks — illustrative only; see `PasswordTier::minLength()` / `blocksWeak()` for the actual values).
- `PasswordPolicy` itself only classifies (length + blocklist) — it never blocks. `BackendUser.passwordWeak` is computed when the password is set (cannot be derived from the bcrypt hash → stored) and drives the persistent every-login nag. **Exception:** under `veryStrong` the callers turn a weak verdict into a hard block on save (`BackendUserValidator` adds a field error; the installer re-prompts). `notStrong`/`strong` only nag.
- The configured tier is resolved once per controller via `BackendAbstractController::passwordTier()` (reads `config/auth`); the installer resolves it from its merged `authConfig`. Both funnel through `PasswordTier::fromName()`: an ABSENT value → the safe `strong` default, an unknown NON-EMPTY value (typo) → **fatal `\ValueError`** — fail-loud / fail-secure, never a silent downgrade of a security setting. The default in `auth.default.inc.php` is bound to the enum (`PasswordTier::Strong->value`), so a typo in the default itself is a fatal at load time too.
- The live meter (`password-meter.js`) mirrors the policy as a hint; the tier's min length is passed to it via the `data-z77-password-min` attribute. The server policy is the authority on save.
- bcrypt cost 12 for hashing.

## install / setup model (agreed 2026-06-02)

| Install context | Admin creation | Password |
|---|---|---|
| interactive | created immediately by installer (`Install::provisionAdminInteractive`) | prompted (hidden, twice), policy-evaluated → `password_weak` |
| non-interactive (CI / FTP / panel) | deferred — installer writes `SETUP_TOKEN` to `data/`, no admin | entered at `/backend/system/setup/setup` (`SetupController`) |

- Provisioning runs once: if `backendUsers.json` already exists it is never touched (re-install / update). The environment (`debug`, host) is NOT a factor — security is by default, not by detection.
- The provisioned role is **`superUser`** (ADR-021, both paths — installer AND `SetupController`): the first account is the installation/DMS governor. `admin` (level 80) is a normal, grant-managed role and is never provisioned; in the DMS the ACL bypass applies to `superUser` only.
- `SETUP_TOKEN` lives under `data/framework/auth/` (filesystem-only, never `public/`) → "proof of server access" gates setup, defeating the public first-in-first-win race (analogous to GitLab's `initial_root_password`). Random 32-byte hex via `random_bytes`.
- `SetupController` gates every request in order: (1) a user already exists → permanently **locked** (and any stale token is discarded); (2) no token on disk → **unavailable**; (3) submitted token must equal the on-disk token (`hash_equals`). On success the admin is persisted, the token is **deleted** (single-use), and the visitor is redirected to `/login`. Reachable without auth via `AuthRole::GUEST` (no admin exists yet).
- No friendly `/setup` alias by design: a one-shot bootstrap route must not live in the user-editable runtime navigation (`navigation.json`). The canonical 4-segment URL is used and printed by the installer.
- No default credential is ever shipped in any `*.default.json` — `backendUsers.default.json` was removed (Phase 4).
- The installer writes `config/client/auth.inc.php` (from `auth.default.inc.php` merged with composer extra `core-auth`, like `bootstrap.inc.php`) — `writeAuthConfig()`. The interactive admin prompt is tier-aware: under `veryStrong` it re-prompts until the password passes; otherwise it accepts a weak one with a warning and sets `password_weak`.

## backups (2026-07-16)

Backups are a security surface: the `data` and `full` archives contain the
complete user store (`data/framework/auth/backendUsers.json` — bcrypt hashes),
the `db` archive a full SQL dump. Consequences (owned by
[`backup.md`](backup.md), summarized here):

- Archives live under `{project}/backup/` — in the project root, NEVER under
  `htmlRoot`, so they are not web-reachable; download only through
  `/backend/service/backup/download` (`FileResponse`).
- The whole backend surface (`BackupController`, group `service`) is
  `AuthRole::SUPER_USER` on every action (ADR-021 governance).
- The CLI entry (`vendor/bin/z77-backup`, ADR-028) has no HTTP auth by design:
  shell access = permission (whoever can run PHP against the project files can
  read them anyway). There is deliberately no token-gated backup URL.
- DB credentials for the dump pass through a short-lived `0600` defaults file,
  never the command line (process-list exposure) — see `MysqlDumper`.

## CSRF contract + public-form protection (2026-07-18)

The CSRF token (`CsrfService`, session-bound, `$csrfToken` auto-injected into
every `html()` context) travels on ONE of two paths, checked by `AccessGuard`:

| Path | Token transport | Check |
|---|---|---|
| Fetch POST (JS `fetch()`) | **`X-CSRF-Token` header** | global — every Fetch+POST, unconditional |
| Page-mode POST (form submit) | hidden field **`csrf_token`** | opt-in via the `#[Csrf]` action attribute (header also accepted) |

- `#[Csrf]` (Z77\Shared\Attributes, enforced in `AccessGuard::enforce()` before
  the action, write methods only): failure → Fetch = error envelope, Page = 303
  to `/`. Actions that want a friendly failure UX (message + form re-render,
  e.g. a public contact form) validate in-action via `CsrfService` instead —
  both are legitimate; pick per endpoint.
- Consumers: `AdminPanelController::togglePartialLabelsAction` (`#[Csrf]`), both logouts (in-action: a refused logout gets a page, never silence),
  zihlundsee contact form (in-action, UX). Origin: CONTACT-CHECK-001 — a project
  fetch() sent the token only in the body and every blur check died at the
  guard; see `docs/03-development/review-email-service-usage.md`.

**FormGuard** (`Z77\Shared\Forms\FormGuard`, extracted from the zihlundsee
contact form): session-based abuse protection for public forms, per form key
(`FormGuard::forKey('contact')`) — time-trap (`armTimeTrap()` on render,
idempotent so a re-render does not restart the window / `isTooFast()` on submit
/ `disarmTimeTrap()` once the submit completed), per-session rate limit (`recordSend()` /
`isRateLimited()`, sliding 1-h window). No confirmation flag — the PRG target is
a thank-you page of its own ([`forms.md`](forms.md), PUBLIC-FORM-004).
Honeypot names are declared per form
(`FormDefinition::honeypots()`, default `website`/`fax`). Convention on bot
detection (honeypot OR too fast): pretend success, send nothing. Not a DI
singleton — built per key like `Mailer::create()`.

Since 2026-07-20 app code does **not** call `FormGuard` directly: a public form
is declared as a `FormDefinition` and `PublicFormHandler` runs the whole cascade
(CSRF → bot → validate → rate limit → send → PRG) in the fixed order above —
see [`forms.md`](forms.md). `FormGuard` stays the mechanics underneath and is
still the right tool for a non-form endpoint that needs the same protections.

## rules

- When a project JS posts to a Fetch-mode endpoint → MUST send the CSRF token as the `X-CSRF-Token` header (`AccessGuard` checks ONLY the header on the global fetch path); a body copy alone dies at the guard (CONTACT-CHECK-001).
- When an action changes state → MUST carry `#[HttpMethod('POST')]` (or, for a page that also shows a form, save only in its POST branch) and a CSRF check; MUST NOT change anything on a GET, also not behind a confirmation in the link: any page can fire a GET with an image tag, and a prefetch or a mail scanner fires it unasked (SEC-GET-001; the exceptions are named there).
- When a logout's token does not fit → MUST answer a page with a fresh token and a button; MUST NOT end in a silent redirect (SEC-GET-001).
- When a partial renders a page-mode form → MUST take the token from `partials/csrfField`, which reads the service; only for a signed-in user or a page the page cache never stores.
- When adding a page-mode POST endpoint → MUST protect it against CSRF: `#[Csrf]` attribute (reject semantics) or in-action `CsrfService::validate` (friendly-UX semantics); the hidden field MUST be named `csrf_token` (matches the auto-injected `$csrfToken`).
- When building a public form → MUST declare it as a `FormDefinition` and run it through `PublicFormHandler` ([`forms.md`](forms.md)), which applies the `FormGuard` mechanics (time-trap / rate-limit) and the honeypot convention (bot detected → pretend success, send nothing); MUST NOT hand-roll session keys or the cascade. For a non-form endpoint that needs the same protections, `FormGuard::forKey()` stays the direct tool.
- When provisioning the admin at install → MUST generate it (interactive: hidden password prompt; non-interactive: write a one-time `SETUP_TOKEN` under `data/`); MUST NOT ship a working default credential in any `*.default.json`.
- When handling backup archives → MUST keep them outside `htmlRoot` and MUST keep every backend backup action `SUPER_USER`-gated; archive names from request input MUST resolve through `BackupHistory::resolvePath()` (see [`backup.md`](backup.md)).
- When setting or changing a password anywhere (installer, `BackendUserController`, setup) → MUST evaluate via `PasswordPolicy` (passing the configured `PasswordTier`) and persist the resulting `passwordWeak` flag; MUST NOT reject a weak password UNLESS the tier is `veryStrong` (the only tier that hard-blocks on save). For `notStrong`/`strong` the policy nags, never blocks.
- When resolving the active password tier → MUST read it via `BackendAbstractController::passwordTier()` (runtime) or `PasswordTier::fromName($this->authConfig['passwordTier'])` (installer); MUST NOT hardcode a min length. `PasswordPolicy`/`PasswordTier` stay pure (no config/DI access) — the tier is passed IN.
- When parsing a security-relevant config value into a typed value (e.g. `PasswordTier::fromName`) → MUST treat an absent value as the safe default but MUST throw on an unknown non-empty value; MUST NOT silently fall back (a typo must not quietly weaken the policy). Bind the shipped default to the enum (`PasswordTier::Strong->value`) so even the default cannot be a stray string.
- When judging password strength → MUST use `PasswordPolicy` (length from `PasswordTier` + blocklist); MUST NOT add character-class composition scoring (`score()`-style upper/lower/digit/special counting).
- When changing another backend account or a role → MUST check the actor against the store: nobody grants or edits above his own level, the last SUPER_USER stays one ([`login.md`](login.md) «second factor»).
- When limiting login attempts → MUST go through `LoginThrottle` (failures per username+IP AND per IP, keyed on `REMOTE_ADDR` via `Request::getClientIp()`); MUST NOT lock on the username alone: anyone could lock any user out.
- When adding a new installation-wide auth-policy setting → MUST add it to `config/client/auth.inc.php` (default in `packages/kernel/core/src/Config/auth.default.inc.php`, generated by `Install::writeAuthConfig`); MUST NOT put per-user auth properties (e.g. 2FA enablement) there — those belong on the `BackendUser` entity (see roadmap).
- When storing a TOTP secret → MUST store the `TotpVault` ciphertext, one key file per door (`data/framework/member/totp.key`, `data/framework/auth/totp.key`); MUST NOT log it or hold it in plaintext, also not in the session during an enrolment.
- When a logged-in user has `passwordWeak` set → MUST push the nag via `messageService->pushMessageAfterRedirect('error', …)` on the post-login redirect; MUST NOT suppress it after the first login.
- When writing the setup token → MUST place it under `data/` (filesystem-readable only); MUST NOT write it under `public/` (would be web-reachable and re-open the race).
- When gating setup (`SetupController`, `/backend/system/setup/setup`) → MUST refuse once any user exists (locked) AND require a matching on-disk token (`hash_equals`); MUST delete the token after the admin is created (single-use). MUST NOT expose setup via a friendly `/setup` alias in `navigation.json` (a one-shot bootstrap route stays out of user-editable runtime navigation).
- When a file reaches a browser → MUST go through `FileResponse` / `BytesResponse` / `ByteSourceResponse`: `DeliveryPolicy` is an allowlist on the type, sets nosniff and the sandbox, and no caller can opt out. A new response class that sends file bytes MUST apply it too. A file under `public/` is served by the web server by extension, past this policy: anything user-supplied there MUST be on a safe extension list with a guard. Hand-placed developer files beside the DMS partitions are the developer's and stay untouched; a DMS partition's hand-placed files are served as text (DMS-ACTIVE-001, [`documents.md`](documents.md)).
- When something answers with an HTML document → MUST go through `HtmlResponse` or call `SecurityHeaders::send()` after its `Content-Type`, as the error pages do; a stricter value an action sets first stays (`no-referrer` on the reset page).
- When a project needs another security header value, or none, or one more → MUST write only the deviation into `config/client/securityHeaders.inc.php` (header name => value, null switches off); MUST NOT send it from a template or a controller: a second `Content-Security-Policy` is added to the first by the browser, it does not replace it. A `Header always set` in `public/.htaccess` wins over that file (SEC-HEAD-001).
- When rendering an error page with `DEBUG` off → MUST show a generic page without file paths or stack traces (see SEC-002).

## see also

- [`login.md`](login.md) — `AuthService`, `AccessGuard`, role hierarchy, login flow that pushes the nag
- [`backend.md`](backend.md) — `BackendUserController` user management (password meter + weak-flag on add/edit)
- [`installer.md`](installer.md) — install flow, data-file seeding, debug flag
- [`messages.md`](messages.md) — `pushMessageAfterRedirect` persistent channel used by the nag
- [`forms.md`](forms.md) — `PublicFormHandler`: the standard cascade that applies `FormGuard` + the in-action CSRF check for every public form
- [`member.md`](member.md) — the customer login: the TOTP door the backend's shares its kernel classes with, the magic link this topic's roadmap can reuse, and where SEC-005 bites hardest (MEM-006)

## known issues

- **DMS-ACTIVE-001** — resolved 2026-11-02. Stored XSS through file delivery: an uploaded HTML, SVG or XHTML ran on the application's domain, with the session of whoever opened it, and in a public folder from a static copy with no login. Three lines: `DeliveryPolicy` at output, a static copy only for a safe extension with a safe MIME, a versioned guard per DMS partition that removes old copies provably made by the DMS. Hand-placed files outside a partition are not touched; inside one they are served as text. After a deploy one request to a non-existent `/media/...` path repairs an installation whose Drive nobody opened. Detail, verification and what is not verified (the guard on a real Apache): [`documents.md`](documents.md).
- **SEC-GET-001** — resolved 2026-11-02. The backend and member logout were GET links: an image tag on any page signed a user out, and a device key died with the member's. Both sign out only on a POST with the session's token, with a form button in the backend topbar, the frontend overlay and the member's account panel. **Amended the same day, after a review:** the first version was `#[HttpMethod('POST'), Csrf]`, and Abmelden failed silently: a stale tab whose PHP session had gone ended in a 303 to `/` with no word, a device key and its cookie stayed, the next visit was signed in; and every plain-shape member page (`partials/shell/header`) rendered an empty token. Now a GET and a POST whose token does not fit get a small page, «Sitzung abgelaufen: bitte Abmelden bestätigen» (on a GET «Bitte Abmelden bestätigen»), with a fresh token; its button signs out, device key included. The same page for a GET is simpler than a 404, and a bookmark or a project's old link has a way out. **It signs nobody out by itself:** a cross-site POST carries no cookie (session and device cookie are `SameSite=Lax`), and even with one it only gets the page, which cannot be framed from elsewhere. Forms in partials take their token from `partials/csrfField`, from the service, so no caller can forget it, and `CsrfService::getToken()` treats an empty stored value as absent. They stand only on signed-in pages, which the page cache never stores; the harness checks a guest's cached page holds none. The member's `device-remove`, `device-remove-all`, `totp-remove`, `konto`, `loeschen` and `mandant` carry `#[HttpMethod('POST')]` too (they refused a GET in the action before, except the fetch branches, which only the attribute closes). Searched every other module's actions by their source: nothing else changes state on a GET; the `remove` / `delete` / `toggle` / `reset` actions are POST by attribute or branch, the `confirm*` actions only render. **Still a GET on purpose:** `ConfirmController` (MEM-007), the login link in the requesting browser (`member.md`, the session cookie is the proof), `frontend/main/stats/event` (one count), a language or view choice remembered in the session (`setContentEditLanguage`, `?via=`, a URL's language prefix), which change no record. Verified: `tests/state-changing-get.php` through the real stack (GET changes nothing, POST without token refused, POST with it works, each action; both shell shapes, the stale session with and without a device key, a POST without cookies); the rows compared with the old links by computed style in headless Edge. Not seen on a real installation.
- **Lottie expressions** — resolved 2026-11-02 ([`lottie.md`](lottie.md) LOTTIE-NOEVAL-001). `module-frontend`'s player was the full build, which evaluates expressions inside the JSON with `eval`: a page playing a Lottie file from a DMS folder ran what that file held, on the page's origin, for whoever could write to the folder. It is the light build of the same version now, no `eval`, no `new Function`; an expression in a file is ignored and the property stands still. So a project with such a file bakes it into keyframes and puts it into the DMS FIRST, then takes the framework.
- **SEC-HEAD-001** — resolved 2026-11-02 (owner, hardening H3), except the page CSP, which only observes. `SecurityHeaders` sends the set from `HtmlResponse::sendHeaders()` and from the error pages, one place, so a controller's page, a page-cache miss, a hit, a 304 and a HEAD all carry it (the cache stores the body only, the headers come fresh, so a changed project file counts for cached pages at once). **Enforced:** `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`, `Content-Security-Policy: frame-ancestors 'self'`. **Only observes:** `Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'`, without a report URL: a violation is a line in the browser's console, nothing is blocked. `style-src` keeps `'unsafe-inline'` for the `style=` attributes of the templates. A file answer stays `DeliveryPolicy`'s alone, JSON and fetch answers stay as they were: a browser neither frames nor sniffs them into a page, and `JsonResponse` is the stateless API's path too, so it would not be trivially shared.
  **HSTS is deliberately not sent:** a browser remembers it for as long as it says, up to a year, so one installation without working HTTPS on every host it answers for is unreachable for whoever visited once, and nothing on the server takes that back. A project that knows its hosts adds it: `'Strict-Transport-Security' => 'max-age=31536000'` in `config/client/securityHeaders.inc.php`, the one place a project's values live. Header name => value, null or '' switches one off, only the deviation goes in; the file is optional and not seeded, and a file that throws or returns no array costs a log line, not the page, as does an entry without a name (ignored) or a value of another type (the default stays). It is `config/client`, so it survives a release switch.
  **What the observing CSP would block on the framework's own pages,** seen in headless Edge's console through the real stack: backend login, forgot form, dashboard, user list with its add dialog and the 2FA dialog, navigation and content list, DMS Drive, member login, profile, devices and 2FA setup with its QR data URI, a frontend page with a Lottie, the contact form, the frontend overlay: only **`module-frontend/res/view/templates/partials/head/fonts.tpl.php:1`**, the Google Fonts stylesheet (`style-src`, `fonts.googleapis.com`) and with it the font files (`font-src`, `fonts.gstatic.com`), on every frontend page. **The framework's templates hold no executable inline script and no `on*=` handler or `javascript:` URL.** The `<script>` blocks in them are data, which a CSP does not stop: `module-debtor/.../InvoiceController/form.tpl.php:351`, `module-order/.../OrderController/edit.tpl.php:478`, `kernel/shared/.../partials/treePicker.tpl.php:103`, `module-frontend/.../partials/head/structured-data.tpl.php:6`, `HtmlResponse.php` (the envelope), `HtmlView.php:142` (i18n). The Lottie player holds no `eval` any more (LOTTIE-NOEVAL-001). **Not walked:** the mailing outbox's preview iframe (its mail's images would be `img-src`), a PDF or video preview in the Drive, the assistant panel, a project's own templates. Before this CSP is ever enforced: decide about the fonts, watch a real installation's console, and read a project's templates.
  **A project's `public/.htaccess` wins:** a `Header always set` of the same name replaces the header PHP sent, each arrives once, and the `.htaccess` value holds whatever `securityHeaders.inc.php` says. Measured on a real Apache for a page without a login, a 404 and a 302; a signed-in page and a file download were not measured. So a value belongs in one of the two: either drop the line from `.htaccess` and set it in `securityHeaders.inc.php`, or know that the file has no say over that header.
  Verified: `tests/security-headers.php` through the real stack, each header exactly once on every path, the file's three moves; `tests/backend-password-reset.php` keeps its `no-referrer`. The uncaught-error branch is proved by its source (`tests/uncaught-error-status.php` checks the status, not these headers). **Don't assume framing from another domain still works:** a site embedded in another domain's iframe stops rendering; that project sets `Content-Security-Policy` and `X-Frame-Options` in its file. Not seen on a real installation.
- **MEMBER-2FA-001** — resolved 2026-11-02: the member's TOTP rejects a spent code, judges every code in one lock per account, removal goes through the guard, a running setup waits in the session, dead counters fail closed, the login mail has an IP limit, the issuer comes from configuration. Detail: [`member.md`](member.md).
- **Backend 2FA** — built 2026-11-01, optional per user, TOTP only: [`login.md`](login.md) BACKEND-2FA-001 with its residual risks. Verified by `tests/backend-2fa.php`; confirmed by the owner on a real installation on 2026-11-02, release from framework commit 3662e38 (2FA and «Passwort vergessen»; what that does not cover: login.md `## pending`). The code lock fails closed on dead counters, by the owner's decision, while `LoginThrottle` keeps failing open: the second factor has no limit without its counter, the password keeps its bcrypt. How to read and fix it: login.md «code refused».
- **SEC-001** — resolved 2026-06-02 (Phase 4). No credential is shipped anymore: `backendUsers.default.json` was removed and `Install::provisionAdmin()` creates the admin at install (interactive: hidden password prompt; non-interactive: one-time `SETUP_TOKEN` under `data/`, admin deferred to `/backend/system/setup/setup`). Was CWE-1392 / OWASP A07.
- **`config/client/auth.inc.php` tenants** — introduced for `passwordTier` (PWD-POLICY-001, resolved). Since 2026-11-01 it also holds the login thresholds (SEC-004): `loginFailuresPerUsernameIp`, `loginFailuresPerIp`. Who is told when the backend refuses 2FA codes on dead counters is not here: Service → E-Mail, entry `backendCodeLock` (login.md). The file is seed-once, so an existing installation does not have them and runs on the defaults in `LoginThrottle` (`auth.default.inc.php` is bound to the same constants, one value in one place); add a key only to deviate. NOTE: per-user auth properties (e.g. `secondFactor`) do NOT belong here; they live on the `BackendUser` entity (see roadmap below). Only a global *mandate* could ever be a config key.
- **SEC-002** (open): don't assume the error handler hides internals in production — `ExceptionHandler` gates trace output on `ini_get('display_errors')`, NOT the framework `DEBUG` flag; a prod server with `display_errors=On` leaks full filesystem paths + stack traces. The uncaught-error handler added 2026-09-22 (`ExceptionHandler::handleUncaught()`, BOOT-ERR-001) follows the same gate — with `display_errors` off it answers a generic 500 without message or trace; `Bootstrap` sets `display_errors` from `DEBUG`, so the gap is the boot phase before that line.
- **SEC-003** — resolved 2026-07-17. `SessionManager::startSession()` now sets explicit cookie params before `session_start()`: `HttpOnly`, `SameSite=Lax` (CSRF defense-in-depth — PHP's ini default for samesite is EMPTY), `Secure` when the request is HTTPS (same detection as `Request::parseUrl`), plus `session.use_strict_mode=1` (rejects uninitialized ids — session fixation). Hardcoded sensible defaults, deliberately NO config keys (no settings on stock); found in the zihlundsee contact-form best-practice review.
- **SEC-004** — resolved 2026-11-01. `LoginController::handlePost()` runs through `LoginThrottle` (over `FileThrottle`, `var/lib/throttle/login`): 5 failures per username+IP and 30 per IP in 15 minutes; over a limit the neutral «Zu viele Fehlversuche, bitte in 15 Minuten erneut versuchen.» and no `password_verify`. The attempt is reserved BEFORE the check and handed back on success (`FileThrottle::release()`, new), so parallel guesses cannot slip past the limit; success clears username+IP (`forget()`), a password reset also the IP counter. Verified: `tests/backend-password-reset.php`. Don't assume: the window is fixed, so a run across its boundary gets up to twice the limit; behind a reverse proxy all clients share one REMOTE_ADDR and the 30 per IP count for everyone; with unwritable counters (a dangling `var/lib`) the login is NOT limited and logs so — fails open on purpose, a broken disk must not lock the operator out of the backend. Counter files of past windows stay until `var/lib` is wiped (a `FileThrottle` property, one tiny file per address and window).
- **SEC-007** — resolved 2026-11-01. `CsrfService::validate()` passed an empty token for a session that held none, because `hash_equals('', '')` is true; it now refuses `''` itself, for every caller. No legitimate submit changes: every form comes from `html()`, which issues the token first, and `AccessGuard` already refused an empty header or field. The in-action callers (`PublicFormHandler`, member login, profile and register, `SetupController`) were the ones exposed. Verified: `tests/csrf-service.php`.
- **SEC-005** — resolved 2026-08-07. Generated absolute URLs used to come from `$_SERVER['HTTP_HOST']`, a client-supplied header, with no trusted-host concept in the framework. Two reachable consequences: a magic-link mail built under a forged Host carried an attacker-owned link (genuine mail, real installation → token exfiltration), and `buildSeoLinks()` rendered canonical/hreflang from the same source into pages the `PageCache` keys **by path only** — so a single forged request poisoned what every later visitor was served. Now one seam: `canonicalBaseUrl` (bootstrap config) → `Request::getBaseUrl()`, used by the member mail links and the SEO set; the dead `url_origin()` preBoot helper (which additionally trusted `HTTP_X_FORWARDED_HOST`) is gone. Exactly one `HTTP_HOST` read remains, in `Request::requestOrigin()`, which routing uses deliberately — whichever host answered is the one whose paths must resolve.
- **SEC-006** — resolved 2026-08-07 by ADR-030. There is no header fallback left: `canonicalBaseUrl` lives in `config/client/systemConfig.inc.php` (seed-once, per installation), `Bootstrap` publishes it as `CANONICAL_BASE_URL`, and `Request::getBaseUrl()` **throws** when it is empty rather than guessing. Loading tolerates the empty value on purpose — a fatal at boot would take the backend down, i.e. the surface an operator needs to fix it — so the failure lands at the point of use: a cron aborts instead of mailing links that point nowhere, and the backend shell shows a persistent Störer until the value is set. Each installation must fill it in once (`https://kunde.ch`); it is deliberately not in `composer.json`, which is committed and could therefore not differ between staging and production.
  **Amended 2026-09-22 (BOOT-SETUP-001, [`bootstrap.md`](bootstrap.md)):** «the backend shell shows a Störer» did not hold — `AbstractBaseController::html()` built the SEO set eagerly for every page, so the backend and the first-run setup died on the throw. The SEO set is now built on first read (`SeoLinks`); only pages that print a canonical (the frontend head) need the origin. The protection is unchanged: no Host fallback, no empty canonical — a frontend page without `canonicalBaseUrl` answers 500. The Störer appears only AFTER login (the setup page and `/login` show none); before that, the installer's closing line names the file and the key (`installer.md` INST-FRESH-001).

## pending

- **Error handler** (fixes SEC-002) — branch on the framework `DEBUG` flag; generic production page without paths/traces.
- **Reject a mismatching `Host`** (optional hardening on top of SEC-005, deliberately not built) — answer 400/421 when the request's `Host` differs from `canonicalBaseUrl`. Defense-in-depth against further Host tricks, but it breaks apex-vs-www and staging setups, so it needs its own opt-in switch and a migration story. Not urgent: with the origin configured, a forged `Host` no longer reaches any generated URL.
- **Backend editor for `systemConfig`** — SUPER_USER-gated, so `canonicalBaseUrl` can be corrected without a deploy (ADR-030 leaves the file hand-maintained for now). Must clear APCu + page cache on write, like the existing debug/noindex toggles.
- **Enforcing the page CSP** — it only observes (SEC-HEAD-001). Needs a decision about the Google Fonts in `partials/head/fonts`, a look at a real installation's console, and a project's own templates read first.
- **Docroot/deny hardening, nginx part** — the Apache side is done (INST-DENY-001, 2026-09-02: the installer seeds `Require all denied` `.htaccess` files into `data/`, `config/`, `logs/`; see [`installer.md`](installer.md)). Equivalent nginx `location` deny snippets are still only documentation-by-hand; on nginx the correct docroot remains the only defence.
- **Roadmap (stronger auth)** — second factor is a **per-user** property on the `BackendUser` entity (decided 2026-06-03), set by the admin in the user-edit form or by the user themselves (self-service later) — NOT installation/module config (a global config cannot hold per-account TOTP secrets). New field `secondFactor` (`none` / `totp` / `magic`) + a `SecondFactor` enum in `shared/src/Auth/` (domain value of `BackendUser`, alongside `PasswordTier`); TOTP enrolment adds a stored secret field. The login flow (`AuthService` / `LoginController`) branches on it. Separate, optional, later: a *mandate* policy (e.g. \"admins must enable 2FA\") — global/role-level, distinct from the per-user enablement field. Also: Passkeys/WebAuthn (passwordless, phishing-resistant); email on the account (set at setup) for password reset + 2FA delivery — email belongs on the account, NOT in `composer.json` as an install gate. See [`login.md`](login.md) (BackendUser entity) + [`backend.md`](backend.md) (user-edit form).
  **Status note 2026-08-07:** none of this is done for `BackendUser`. What exists is a working
  TOTP implementation in `module-member` (RFC 6238 with the RFC vectors, AES-256-GCM secret
  vault, 5-failure / 15-minute lockout, QR enrolment) plus a passwordless magic-link login —
  but for CUSTOMER accounts on a separate identity, not for `BackendUser`
  (see [`member.md`](member.md), [`../02-decisions/adr-029-member-session-and-framework-acl.md`](../02-decisions/adr-029-member-session-and-framework-acl.md)).
  Those services are reusable for the admin login when this roadmap item is taken up; the
  `secondFactor` field, the enum and the `AuthService` branch are still missing.
  **Owner decisions 2026-09-22 (raised by the z77.ch P2 exit check — the developer was locked out
  of his own backend, and there is no recovery path):**
  - **Self-service recovery is required.** A customer who cannot get into the backend must not
    have to ask the developer.
  - **Both ways, selectable per user.** Either a password with a «Passwort vergessen» link (a
    one-time, short-lived link to the account's e-mail sets a new password), or passwordless
    sign-in by magic link. Both reuse the `module-member` link mechanism.
  - **2FA (TOTP) must be possible** on top of either way. The recovery link does not bypass the
    second factor.
  - **Timing:** after P3 (module-debtor) of the order/debtor/financial Bauplan. Until then, a
    lost admin password is reset by hand on the server.
  **Status 2026-11-01:** the password way is built («Passwort vergessen», one-time link valid 30
  minutes; [`login.md`](login.md) LOGIN-RESET-001), with the recovery address on the account
  (`BackendUser.email`), but it is not yet asked for at setup and every existing account starts
  without one.
  **Status 2026-11-01, later:** 2FA (TOTP) is built, optional per user and switched on by the user
  himself ([`login.md`](login.md) BACKEND-2FA-001); the recovery link does not bypass it; a lost
  device is an admin reset. It is not the shape sketched above: three fields on `BackendUser`
  (`totp_secret`, `totp_activated_at`, `totp_last_step`), no `secondFactor` field and no enum, and
  the admin cannot set it in the user form, only reset it. The owner's flow of that day decided
  against recovery codes and against a magic link as a second factor. Still open: passwordless
  sign-in by magic link, «selectable per user», a mandate, Passkeys/WebAuthn. The member's TOTP
  has what the backend's got since 2026-11-02 ([`member.md`](member.md) MEMBER-2FA-001).
