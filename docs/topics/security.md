# security

2026-07-03

## entry

1. `packages/kernel/shared/src/Auth/PasswordPolicy.php` — single source of truth for password strength (length + blocklist, NOT composition)
2. `packages/module-backend/src/Ui/Controllers/System/LoginController.php` — login + the every-login weak-password nag
3. `packages/kernel/core/src/Installer/Install.php` — `provisionAdmin()`: admin provisioning at install (interactive hidden prompt vs. non-interactive setup token)
4. `packages/module-backend/src/Ui/Controllers/System/SetupController.php` — token-gated first-run admin setup for non-interactive installs (`/backend/system/setup/setup`)

## file map

SOURCE=/packages/kernel/shared/src/Auth/PasswordPolicy.php
SOURCE=/packages/kernel/shared/src/Auth/PasswordTier.php
SOURCE=/packages/kernel/core/src/Config/auth.default.inc.php
SOURCE=/packages/kernel/shared/src/Validators/LoginUserValidator.php
SOURCE=/packages/module-backend/res/assets/js/password-meter.js
SOURCE=/packages/kernel/shared/src/Entities/LoginUser.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginUserController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SetupController.php
SOURCE=/packages/module-backend/res/view/templates/System/SetupController/setupAction.tpl.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/SystemController.php
SOURCE=/packages/kernel/core/src/Installer/Install.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/core/src/Session/SessionManager.php
SOURCE=/packages/kernel/core/src/Exception/ExceptionHandler.php
RUNTIME=/skeleton/config/auth.inc.php
RUNTIME=/skeleton/data/framework/auth/loginUsers.json

## mental model

z77 is open source — anything shipped is public, including the default admin hash. Security therefore follows **secure-by-default**, NOT environment detection: the app must not be usable with a known credential, and the environment (`debug` flag, `Host` header) is never the security control (debug can legitimately run on a public server; the Host header is attacker-controllable). Two pillars: (1) the admin is **provisioned at install** — never shipped — interactively (password prompt) or, when non-interactive, deferred to a token-gated first-run setup; (2) passwords follow a **modern policy** (length + blocklist, NOT composition) that **allows weak passwords but nags on every login** — usability without a forgotten-flag footgun.

- Strength = length + blocklist + trivial-pattern + context (username/site). NIST SP 800-63B: composition rules are rejected (they mislabel passphrases as weak and `Password1!` as strong). The min length is NOT hardcoded — it comes from the installation-wide `PasswordTier` (`config/auth.inc.php` key `passwordTier`), which is the SSOT for each tier's min length and block/nag behaviour (e.g. `strong`≈12, `veryStrong` blocks — illustrative only; see `PasswordTier::minLength()` / `blocksWeak()` for the actual values).
- `PasswordPolicy` itself only classifies (length + blocklist) — it never blocks. `LoginUser.passwordWeak` is computed when the password is set (cannot be derived from the bcrypt hash → stored) and drives the persistent every-login nag. **Exception:** under `veryStrong` the callers turn a weak verdict into a hard block on save (`LoginUserValidator` adds a field error; the installer re-prompts). `notStrong`/`strong` only nag.
- The configured tier is resolved once per controller via `BackendAbstractController::passwordTier()` (reads `config/auth`); the installer resolves it from its merged `authConfig`. Both funnel through `PasswordTier::fromName()`: an ABSENT value → the safe `strong` default, an unknown NON-EMPTY value (typo) → **fatal `\ValueError`** — fail-loud / fail-secure, never a silent downgrade of a security setting. The default in `auth.default.inc.php` is bound to the enum (`PasswordTier::Strong->value`), so a typo in the default itself is a fatal at load time too.
- The live meter (`password-meter.js`) mirrors the policy as a hint; the tier's min length is passed to it via the `data-z77-password-min` attribute. The server policy is the authority on save.
- bcrypt cost 12 for hashing.

## install / setup model (agreed 2026-06-02)

| Install context | Admin creation | Password |
|---|---|---|
| interactive | created immediately by installer (`Install::provisionAdminInteractive`) | prompted (hidden, twice), policy-evaluated → `password_weak` |
| non-interactive (CI / FTP / panel) | deferred — installer writes `SETUP_TOKEN` to `data/`, no admin | entered at `/backend/system/setup/setup` (`SetupController`) |

- Provisioning runs once: if `loginUsers.json` already exists it is never touched (re-install / update). The environment (`debug`, host) is NOT a factor — security is by default, not by detection.
- The provisioned role is **`superUser`** (ADR-021, both paths — installer AND `SetupController`): the first account is the installation/DMS governor. `admin` (level 80) is a normal, grant-managed role and is never provisioned; in the DMS the ACL bypass applies to `superUser` only.
- `SETUP_TOKEN` lives under `data/framework/auth/` (filesystem-only, never `public/`) → "proof of server access" gates setup, defeating the public first-in-first-win race (analogous to GitLab's `initial_root_password`). Random 32-byte hex via `random_bytes`.
- `SetupController` gates every request in order: (1) a user already exists → permanently **locked** (and any stale token is discarded); (2) no token on disk → **unavailable**; (3) submitted token must equal the on-disk token (`hash_equals`). On success the admin is persisted, the token is **deleted** (single-use), and the visitor is redirected to `/login`. Reachable without auth via `AuthRole::GUEST` (no admin exists yet).
- No friendly `/setup` alias by design: a one-shot bootstrap route must not live in the user-editable runtime navigation (`navigation.json`). The canonical 4-segment URL is used and printed by the installer.
- No default credential is ever shipped in any `*.default.json` — `loginUsers.default.json` was removed (Phase 4).
- The installer writes `config/auth.inc.php` (from `auth.default.inc.php` merged with composer extra `core-auth`, like `bootstrap.inc.php`) — `writeAuthConfig()`. The interactive admin prompt is tier-aware: under `veryStrong` it re-prompts until the password passes; otherwise it accepts a weak one with a warning and sets `password_weak`.

## backups (2026-07-16)

Backups are a security surface: the `data` and `full` archives contain the
complete user store (`data/framework/auth/loginUsers.json` — bcrypt hashes),
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

## rules

- When provisioning the admin at install → MUST generate it (interactive: hidden password prompt; non-interactive: write a one-time `SETUP_TOKEN` under `data/`); MUST NOT ship a working default credential in any `*.default.json`.
- When handling backup archives → MUST keep them outside `htmlRoot` and MUST keep every backend backup action `SUPER_USER`-gated; archive names from request input MUST resolve through `BackupHistory::resolvePath()` (see [`backup.md`](backup.md)).
- When setting or changing a password anywhere (installer, `LoginUserController`, setup) → MUST evaluate via `PasswordPolicy` (passing the configured `PasswordTier`) and persist the resulting `passwordWeak` flag; MUST NOT reject a weak password UNLESS the tier is `veryStrong` (the only tier that hard-blocks on save). For `notStrong`/`strong` the policy nags, never blocks.
- When resolving the active password tier → MUST read it via `BackendAbstractController::passwordTier()` (runtime) or `PasswordTier::fromName($this->authConfig['passwordTier'])` (installer); MUST NOT hardcode a min length. `PasswordPolicy`/`PasswordTier` stay pure (no config/DI access) — the tier is passed IN.
- When parsing a security-relevant config value into a typed value (e.g. `PasswordTier::fromName`) → MUST treat an absent value as the safe default but MUST throw on an unknown non-empty value; MUST NOT silently fall back (a typo must not quietly weaken the policy). Bind the shipped default to the enum (`PasswordTier::Strong->value`) so even the default cannot be a stray string.
- When judging password strength → MUST use `PasswordPolicy` (length from `PasswordTier` + blocklist); MUST NOT add character-class composition scoring (`score()`-style upper/lower/digit/special counting).
- When adding a new installation-wide auth-policy setting → MUST add it to `config/auth.inc.php` (default in `packages/kernel/core/src/Config/auth.default.inc.php`, generated by `Install::writeAuthConfig`); MUST NOT put per-user auth properties (e.g. 2FA enablement) there — those belong on the `LoginUser` entity (see roadmap).
- When a logged-in user has `passwordWeak` set → MUST push the nag via `messageService->pushMessageAfterRedirect('error', …)` on the post-login redirect; MUST NOT suppress it after the first login.
- When writing the setup token → MUST place it under `data/` (filesystem-readable only); MUST NOT write it under `public/` (would be web-reachable and re-open the race).
- When gating setup (`SetupController`, `/backend/system/setup/setup`) → MUST refuse once any user exists (locked) AND require a matching on-disk token (`hash_equals`); MUST delete the token after the admin is created (single-use). MUST NOT expose setup via a friendly `/setup` alias in `navigation.json` (a one-shot bootstrap route stays out of user-editable runtime navigation).
- When rendering an error page with `DEBUG` off → MUST show a generic page without file paths or stack traces (see SEC-002).

## see also

- [`login.md`](login.md) — `AuthService`, `AccessGuard`, role hierarchy, login flow that pushes the nag
- [`backend.md`](backend.md) — `LoginUserController` user management (password meter + weak-flag on add/edit)
- [`installer.md`](installer.md) — install flow, data-file seeding, debug flag
- [`messages.md`](messages.md) — `pushMessageAfterRedirect` persistent channel used by the nag

## known issues

- **SEC-001** — resolved 2026-06-02 (Phase 4). No credential is shipped anymore: `loginUsers.default.json` was removed and `Install::provisionAdmin()` creates the admin at install (interactive: hidden password prompt; non-interactive: one-time `SETUP_TOKEN` under `data/`, admin deferred to `/backend/system/setup/setup`). Was CWE-1392 / OWASP A07.
- **`config/auth.inc.php` future tenants** — introduced for `passwordTier` (PWD-POLICY-001, resolved). Intended home for the remaining installation-wide auth-policy values once those pendenzen land: rate-limit / lockout thresholds (SEC-004). Add the keys there at that point — deliberately NOT pre-created (no settings on stock). NOTE: per-user auth properties (e.g. `secondFactor`) do NOT belong here; they live on the `LoginUser` entity (see roadmap below). Only a global *mandate* could ever be a config key.
- **SEC-002** (open): don't assume the error handler hides internals in production — `ExceptionHandler` gates trace output on `ini_get('display_errors')`, NOT the framework `DEBUG` flag; a prod server with `display_errors=On` leaks full filesystem paths + stack traces.
- **SEC-003** — resolved 2026-07-17. `SessionManager::startSession()` now sets explicit cookie params before `session_start()`: `HttpOnly`, `SameSite=Lax` (CSRF defense-in-depth — PHP's ini default for samesite is EMPTY), `Secure` when the request is HTTPS (same detection as `Request::parseUrl`), plus `session.use_strict_mode=1` (rejects uninitialized ids — session fixation). Hardcoded sensible defaults, deliberately NO config keys (no settings on stock); found in the zihlundsee contact-form best-practice review.
- **SEC-004** (open): don't assume login is brute-force-protected — `LoginController` has no rate-limit / lockout / throttle.

## pending

- **Rate-limiting / lockout** on login (fixes SEC-004) — per-username + per-IP backoff.
- **Error handler** (fixes SEC-002) — branch on the framework `DEBUG` flag; generic production page without paths/traces.
- **Security headers** — CSP, `Strict-Transport-Security`, `X-Frame-Options`/`frame-ancestors`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`.
- **Docroot/deny hardening** — ship `.htaccess`/nginx deny rules so `data/` (password hashes) is never web-served even on a misconfigured docroot (defense-in-depth).
- **`config/auth.inc.php` future tenants** — introduced for `passwordTier` (PWD-POLICY-001, resolved). Intended home for the remaining installation-wide auth-policy values once those pendenzen land: rate-limit / lockout thresholds (SEC-004). Add the keys there at that point — deliberately NOT pre-created (no settings on stock). NOTE: per-user auth properties (e.g. `secondFactor`) do NOT belong here; they live on the `LoginUser` entity (see roadmap below). Only a global *mandate* could ever be a config key.
- **Roadmap (stronger auth)** — second factor is a **per-user** property on the `LoginUser` entity (decided 2026-06-03), set by the admin in the user-edit form or by the user themselves (self-service later) — NOT installation/module config (a global config cannot hold per-account TOTP secrets). New field `secondFactor` (`none` / `totp` / `magic`) + a `SecondFactor` enum in `shared/src/Auth/` (domain value of `LoginUser`, alongside `PasswordTier`); TOTP enrolment adds a stored secret field. The login flow (`AuthService` / `LoginController`) branches on it. Separate, optional, later: a *mandate* policy (e.g. \"admins must enable 2FA\") — global/role-level, distinct from the per-user enablement field. Also: Passkeys/WebAuthn (passwordless, phishing-resistant); email on the account (set at setup) for password reset + 2FA delivery — email belongs on the account, NOT in `composer.json` as an install gate. See [`login.md`](login.md) (LoginUser entity) + [`backend.md`](backend.md) (user-edit form).
