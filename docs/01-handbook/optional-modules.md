# Optional modules — member, api, kibot

**Status:** `[CURRENT]` — enabling, config, screens and security notes of the three opt-in packages
**Last updated:** 2026-11-20

---

None of these is installed by default. Each is added per project with `composer require` (on Packagist since release 2.0.0), switched on by registering its namespace in the project's `autoload.psr-4` (`kibot` is not a module and needs none), and configured in the project's `override/` or `config/client/` — never by editing `vendor/` (CE principle, [`architecture.md`](architecture.md)).

## module-member — member area

**For:** a protected frontend area where customers register, sign in **without a password** (magic link by e-mail, optional TOTP 2FA) and manage their account. What a member works *for* (a tenant, a project) is the project's, attached through hooks.

**Enable:**

1. `composer require z77/module-member`.
2. Register the namespace in the project's `composer.json` `autoload.psr-4`: `"Z77\\Module\\Member\\": ["override/z77/module/member/src/"]`, then `composer install`. `composer require` alone does **not** make it a module — the installer reads the project's psr-4, not `vendor/` ([`business-suite-setup.md` §2](business-suite-setup.md#2-switch-the-modules-on)).
3. Set `canonicalBaseUrl` in `config/client/systemConfig.inc.php` (every mailed link needs it).
4. Working mail account (every step is a mail — see [`jobs-and-cron.md`](jobs-and-cron.md)); for the operator notice configure the form mail `memberConfirmed`.
5. Override `memberConfig.inc.php` in `override/z77/module/member/src/App/Config/` — it replaces the file **whole**, so carry routes and `jobs` along.
6. Ship `res/assets/js/login-wait.js` and `shell.js` to `public/assets/member/js/` (otherwise the waiting page never advances).
7. Add the menu entry yourself (no navigation seed ships).

**Config keys a project most likely changes** (`memberConfig`): hooks `activationHook`, `membershipHook`, `joinHook`, `tenantLabelHook`, `areaVisibilityHook`, `accountDeletionHook`, `profileHook`; wording `tenantNoun`; `totpIssuer`; `afterLoginUrl`, `memberEntryPath`; `cleanupAfterDays` (30); limits `loginRequestsPerHour`, `loginRequestsPerHourPerIp`, `invitesPerTenantPerDay`; languages `languageFromAccount` + `languages`.

**Routes and screens:** `/member/main/register`, `/confirm`, `/resend`, `/login`, `/logout`, `/profile` (role `customer`). Backend «Member-Konten» (`/backend/service/member-accounts/list`, ADMIN): «Freischalten», «Ablehnen», «2FA-Reset». Job `member-cleanup` (no default schedule).

**Security notes:**

- Registration is double opt-in: confirm e-mail, then operator activation (`registered` → `confirmed` → `active`); a failing `activationHook` leaves the account `confirmed`.
- Visitor-facing answers are identical for known, unknown and never-confirmed addresses; the login throttle is invisible.
- Tokens and device keys are stored hashed, TOTP secrets as vault ciphertext. A mail link that **grants access** goes through a POST, never a GET.
- Customer pages declare `AuthRole::CUSTOMER`, never `AuthRole::MEMBER`. Link origin from `Request::getBaseUrl()`, never `HTTP_HOST`.

Topic: [`member.md`](../topics/member.md). Package: `packages/module-member/README.md`.

## module-api — JSON gateway and z77 App

**For:** the stateless, versioned JSON API (`/api`) with bearer-key authentication, and the **z77 App** (`/app`, a PWA) where app users sign a phone in by e-mail code and use granted functions (today: capturing receipts).

**Enable:**

1. `composer require z77/module-api`.
2. Register the namespace in the project's `composer.json` `autoload.psr-4`: `"Z77\\Module\\Api\\": ["override/z77/module/api/src/"]`, then `composer install` — only then the module is registered and the navigation seed «App-Benutzer» merged; `composer require` alone installs the package but does not activate it ([`business-suite-setup.md` §2](business-suite-setup.md#2-switch-the-modules-on)).
3. Machine channel: override `apiConfig.inc.php` with `apiKeyResolver` (bearer key → tenant) and `apiServices` (endpoint → `ApiServiceInterface`). The override **replaces** the package file — carry the full config, including `apiDeviceResolver`.
4. App: enable a module that offers a scope (e.g. `module-receipt`, scope `receipt.capture`) and create app users in the backend.
5. Needs HTTPS, a working mail account (codes) and, on Apache, the `Authorization` header passed through to PHP.

**Config keys** (`apiConfig`): `rateLimitPerHour` (600), `apiServices`, `apiKeyResolver`, `apiDeviceResolver`; per module `apiDeviceScopes` and `appScreens`.

**Routes and screens:** `/api/v1/…` (`health`, `devices/login`, `devices/verify`, `devices/me`, `devices/logout`, module endpoints), `/app`, `/app/login`. Backend «App-Benutzer» (`/backend/system/api-app-user/list`, ADMIN): create app users (person, app e-mail, functions), deactivate, block devices. Storage: `data/framework/api/app_users.json`, `devices.json`.

**Security notes:**

- `/api` is `stateless: true`, cache off, no `#[Fetch]`/`#[Page]`. Keys are stored as SHA-256 hashes, compared with `hash_equals`, two active keys per connection for rotation; plaintext is shown once.
- Services return `ApiResult` only and read input through `ApiRequest`; the v1 contract only gains fields. Writing requests carry `Idempotency-Key`.
- Device sign-in always answers the same 202 (no address enumeration); one-time codes are judged by `LoginCodeStore::verify()`. Scopes are re-filtered by the linked person's roles on **every** request.
- Scopes with binding or outward effect (send, publish, post) need a new owner decision (e-mail code is one factor). The app CSP is enforced: no inline script or `style=`; the device cookie is HttpOnly and the app stores no credential.

Topic: [`api.md`](../topics/api.md); [ADR-057](../02-decisions/adr-057-api-device-channel.md), [ADR-058](../02-decisions/adr-058-app-users-email-code-login.md). Package: `packages/module-api/README.md`.

## kibot — AI chatbot (website widget and backend KI-Assistent)

**For:** a standalone package (`z77/kibot`, no framework dependency) that answers questions from a topic set: a cheap model picks 1–3 topics, the server reads their files within a budget, an answer model streams the reply. Two bots use it: the **website widget** (hand-maintained sources) and the **backend «KI-Assistent»** (answers from all help texts, [ADR-055](../02-decisions/adr-055-ki-assistent-instead-of-help-button.md)).

**Enable:**

1. `composer require z77/kibot` (publishes `res/assets` to `public/assets/kibot/`).
2. Put the Anthropic key in a file outside `public/` and outside the repo (see [`jobs-and-cron.md`](jobs-and-cron.md#ai-key-ki-assistent-and-creditor-recognition)).
3. Write `config/client/kibot.inc.php` with at least `paths.keyFile`. Without the package or the file both routes answer 404.
4. Website widget only: create `shared/kibot/topics.json` and the source files; add `/assets/kibot/css/bot.css` and `/assets/kibot/js/bot.js` (`data-config`, `data-endpoint`) to the frontend layout **override**.
5. Backend assistant: nothing more — it is on as soon as the key file is readable; its knowledge index is generated from the help templates (`php vendor/z77/module-backend/bin/assistant-index.php` to rebuild on demand).

**Config** (`config/client/kibot.inc.php`, deviations only; lists replace as a whole): `paths` (`keyFile` — no default, `sourceRoot` `shared/kibot`, `logDir` `logs/kibot`), `widget` (`botName`, `welcomeMsg`), `prompt` (`intro`), `contactCtas`, `api`, `budget`, `limits`, `log`; backend texts under `backend`; `creditorAi` for the creditor recognition.

**Routes:** website `GET /frontend/main/kibot/config`, `POST /frontend/main/kibot/ask`; backend `GET /backend/system/assistant/config`, `POST /backend/system/assistant/ask` (every logged-in backend user). Backend help additions: «Hilfe ergänzen» (`/backend/system/help/edit`, ADMIN).

**Security notes:**

- The key is never in a config, log or widget config; only its path is. Error messages name the path, not the key.
- All routes are `#[Fetch]` (no cached page carries a token); the CSRF token travels as `X-CSRF-Token` header and in the body; the guard adds honeypot, session + IP rate limits and length caps.
- The widget renders DOM-only (no `innerHTML`, no inline script) so it runs under a CSP limited to the own origin.
- Privacy: only question, history and help texts leave the server (about 2–3 Rp. per question); no record data or form values.
- The backend assistant's knowledge comes only from help templates and their additions — never hand-edit `data/kibot/help/` (regenerated).

Topic: [`kibot.md`](../topics/kibot.md). Package: `packages/kibot/README.md`.
