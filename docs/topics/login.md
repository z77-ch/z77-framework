# login

2026-07-03

## entry

1. `packages/kernel/shared/src/Services/AuthService.php` — `canLogin` / `login` / `logout` / `getCurrentUser` / `resolveRole`
2. `packages/module-backend/src/Ui/Controllers/System/LoginController.php` — `loginAction` (GET + POST) + `logoutAction`
3. `packages/kernel/shared/src/Repositories/LoginUserRepository.php` — user lookup from JSON store

## file map

SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/Config/AuthRole.php
SOURCE=/packages/kernel/core/src/Session/SessionManager.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/core/public/index.php
SOURCE=/packages/kernel/shared/src/Auth/AuthUser.php
SOURCE=/packages/kernel/shared/src/Entities/LoginUser.php
SOURCE=/packages/kernel/shared/src/Auth/PasswordPolicy.php
SOURCE=/packages/kernel/shared/src/Repositories/LoginUserRepository.php
SOURCE=/packages/kernel/shared/src/Services/AuthService.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginController.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginController/loginAction.tpl.php

RUNTIME=/skeleton/data/framework/auth/loginUsers.json

## mental model

File-based authentication. Users are stored in `data/framework/auth/loginUsers.json`. `AuthService` reads/writes the `auth_user` slot via `SessionManager` and caches the current user per request. Role resolution is hierarchical: Action role > Controller role > Module role > GUEST. `AccessGuard` runs in the request pipeline and redirects unauthenticated requests to the alias `/login` (canonical: `/backend/system/login/login`), storing the original URL in session for post-login redirect. Post-login greeting and post-logout notice are delivered via `MessageService` session-flash (see [`messages.md`](messages.md)).

- `LoginController` extends `AbstractBaseController` (NOT a security controller) — login page must be accessible without auth.
- Guest identity: `id=0`, `isLoggedIn()` returns `false` — `null` is never used for the current user.
- AccessGuard is a pipeline service (replaces the removed `AbstractSecurityController`). CSRF validation for Fetch happens here too.

## role system

```text
AuthRole constants: GUEST=0, VISITOR=10, MEMBER=20, CRON_JOB=30, ADMIN=80, SUPER_USER=100
```

Resolution order (`AuthService::resolveRoleForCurrentController`):

```text
Action role > Controller role > Module role > GUEST default
config lookup = controllers[$group][$controllerBaseName]
  group        = ControllerHandler::getCurrentGroup() (e.g. 'system')
  base name    = short class name, e.g. 'LoginController' (NOT FQCN)
  group-level controller wildcard '*' supported (see frontend)
```

The `controllers` map is nested by group so base names only need to be unique
within a group — see `backend.md` AUTH-B002 + ADR-005 (revised 2026-06-02).

Access check happens in `AccessGuard` before the controller runs:

```php
$authUser     = authService->getCurrentUser();     // from session or guest
$requiredRole = authService->resolveRoleForCurrentController();
if (!hasSufficientRole($authUser, $requiredRole))  → redirect /login (alias for /backend/system/login/login)
                                                     → stores access.origin in session
```

`LoginController` extends `AbstractBaseController` — bypasses role check entirely.

## flows

### GET `/backend/system/login/login` (or alias `/login`)

```text
Router → navigation.json id=8 → module=backend, group=system, controller=login, action=login
ControllerHandler → LoginController::loginAction()
  → GET → html(['error'=>null, 'username'=>''])
  → baseContext() sets $authUser from session (guest if not logged in)
Template: loginAction.tpl.php (form POST → /backend/system/login/login)
```

### POST `/backend/system/login/login`

```text
LoginController::handlePost()
  → CsrfService::validate($token)                 // session-global CSRF; fail → re-render with generic error
  → LoginUserRepository::findByUsername($username)
  → AuthService::canLogin(LoginUser, $password)   // verifies password_verify; returns ?self
  → null: html(['error' => '...', ...])
  → success: AuthService::login()                 // writes auth_user via SessionManager
           → MessageService::pushFlashAfterRedirect('success', 'Hallo {Username}, du bist angemeldet')
           → if LoginUser::isPasswordWeak() → pushMessageAfterRedirect('error', weak-password nag)
             (persistent channel, fires on EVERY login — allowed but reminded; see security.md)
           → resolvePostLoginRedirect()
             → access.origin in session? → reconstruct URL → redirect
             → else → /{currentModule}/{defaultGroup}/{groupDefaultController}/{defaultAction}
               (e.g. /backend/system/dashboard/overview — stays in the module so the
                flash partial is present to render the greeting)
```

### GET `/backend/system/login/logout`

```text
LoginController::logoutAction()
  → AuthService::logout()     // removes auth_user via SessionManager
  → MessageService::pushFlashAfterRedirect('info', 'Du wurdest abgemeldet')
  → redirect /login (alias)
```

### access denied (any protected backend page)

```text
AccessGuard::run()
  → hasSufficientRole() fails
  → session set: access.origin = {module, group, controller, action, get}
  → redirect /login (alias for /backend/system/login/login)
After login: resolvePostLoginRedirect() reconstructs 4-segment URL from access.origin
```

## session storage

All keys accessed via `SessionManager` (`get` / `set` / `remove`).

| Key | Shape | Owner |
|---|---|---|
| `auth_user` | `['id' => int, 'user_name' => string, 'roles' => string[]]` | `AuthService::login()` writes, `getCurrentUser()` reads, `logout()` removes |
| `access.origin` | `['module' => string, 'group' => string, 'controller' => FQCN, 'action' => 'loginAction', 'get' => array]` | `AccessGuard::buildRedirect()` writes, `LoginController::resolvePostLoginRedirect()` reads + removes |
| `_flash` / `_message` | `[{type, text}, ...]` | `MessageService::push*AfterRedirect()` — see [`messages.md`](messages.md) |

Guest (not logged in): `AuthUser` with `id=0`, `user_name='guest'`, `roles=['guest']`. `AuthUser::isLoggedIn()` returns `true` only if `id > 0`.

## AuthUser API

```php
getId(): int
getUserName(): string
getRoles(): array
hasRole(string $role): bool
hasAtLeast(string $minRole): bool   // uses AuthRole hierarchy
isLoggedIn(): bool                   // id > 0
```

## AuthService API

```php
canLogin(LoginUser $user, string $password): ?self   // verifies credentials; returns self on success, null on failure
login(): void                                        // writes auth_user to session; throws if canLogin() was not called first
logout(): void                                       // removes auth_user from session
getCurrentUser(): AuthUser                           // loads from session, caches per request; returns guest if not logged in
hasSufficientRole(AuthUser, string $requiredRole): bool   // static
resolveRoleForCurrentController(): string                 // reads module config
```

## LoginUser entity (persistence)

```php
getId(): ?int
getUsername(): string
getPasswordHash(): string            // bcrypt, cost 12
getRoles(): array                    // e.g. ['admin'] or ['guest']
getSortKey(): int                    // order in the user-admin list
isPasswordWeak(): bool               // set when password is set; drives the every-login nag
```

JSON keys: `id`, `username`, `password_hash`, `roles`, `preferences`, `sort_key`, `password_weak`.
`password_weak` is computed via `PasswordPolicy` whenever the password is set (it
cannot be recovered from the hash) — see [`security.md`](security.md).

## admin provisioning (installer)

No default credential is shipped (the framework is open source → anything seeded
would be public). The first account is created at install by `Install::provisionAdmin()`
with role **`superUser`** (ADR-021 — the installation/DMS governor; `admin` (80) is a
normal, grant-managed role and is never provisioned). The username stays `admin` (cosmetic):

| Install context | Result |
|---|---|
| interactive | super-user account created now — hidden password prompt (twice), `PasswordPolicy`-evaluated → `password_weak` |
| non-interactive | no account — one-time `SETUP_TOKEN` written under `data/framework/auth/`, account deferred to `/backend/system/setup/setup` (`SetupController`, also role `superUser`) |

Provisioning runs once: an existing `data/framework/auth/loginUsers.json` is never
overwritten. See [`security.md`](security.md) and [`installer.md`](installer.md).

## rules

- When defining a controller for the login page → MUST extend `AbstractBaseController`, NOT a security controller (login page must be accessible without auth)
- When a UI needs the set of selectable roles (e.g. the user-edit form) → MUST derive the role set + order from `AuthRole::getRoleHierarchy()` (SSOT); MUST NOT re-declare which roles exist in the module. German display labels live in the module as a presentation-only map keyed by role, looked up per *existing* role (see backend.md ROLE-DEF-001).
- When configuring a role for a controller in module config → MUST use the short class name as the key (e.g. `'LoginController'`), NOT the FQCN
- When setting the current user → MUST use guest identity (`id=0`, `isLoggedIn()=false`); MUST NOT store `null`
- When access-controlling a module's controllers → MUST set `moduleRole` as the restrictive baseline and add a `controllers` entry ONLY for a deviation (looser: GUEST login/setup; stricter: SUPER_USER backup; or a non-`list` defaultAction); MUST NOT restate the baseline per controller/action — an unlisted controller/action inherits the fallback chain `actionRole → controllerRole → moduleRole` and is never open (AUTH-B003, see backend.md)
- When configuring `loginUrl` in `backendConfig.inc.php` → MUST use the alias `/login`, NOT the canonical 4-segment URL — the alias is resistant to future URL restructuring
- When handling a login POST → MUST validate the session-global CSRF token (`CsrfService::validate()`) before checking credentials; on failure MUST re-render the form with a generic "Sitzung abgelaufen" message. `AccessGuard` does NOT cover the login form (it skips non-Fetch requests, and the login page itself runs without auth).
- When determining the post-login fallback URL (no `access.origin`) → MUST resolve via `ModuleManager::getDefaultGroup()` + `getGroupDefaultController()` + `getDefaultActionForController()` on the *current* module and build a 4-segment URL, NOT hardcode `/`. Reason: the redirect target must use a layout that renders flash/message containers, otherwise `MessageService::pushFlashAfterRedirect()` is silently dropped (see [`messages.md`](messages.md) FE-MSG-001).
- When persisting `access.origin` in `AccessGuard::buildRedirect()` → MUST include `group` alongside `module`, `controller`, `action`, `get` so post-login redirect can reconstruct the full 4-segment URL

## see also

- [`persistence-file.md`](persistence-file.md) — `LoginUserRepository`; `passwordHash` round-trip fixed (BUG-P001 resolved)
- [`fetch.md`](fetch.md) — CSRF validation in `AccessGuard` (Fetch POSTs only; the login form is a classic POST and validates the token in the controller itself)
- [`messages.md`](messages.md) — `LoginController::logoutAction` and `handlePost()` call `messageService->pushFlashAfterRedirect()` before returning the `RedirectResponse` for post-login greeting and logout confirmation
- [`security.md`](security.md) — `PasswordPolicy`, `passwordWeak` nag, secure-by-default install + setup token, open security pendenzen (SEC-001…004)

## known issues

- BUG-P001 — resolved. `Naming::toCamelCase` no longer destroys camelCase input; `passwordHash` round-trip works.
- **ROLE-LEVEL-SSOT-001** — resolved 2026-07-07. The "highest role level ≥ threshold" calc was copied three times (`AuthUser::hasAtLeast`, `AuthService::hasSufficientRole`, `LoginUserController::isAdminCapable`). Consolidated the permissive form into one pure helper `AuthRole::rolesSatisfy(array $roles, string $minRole)` (unknown role → level 0); `hasAtLeast` + `isAdminCapable` now route through it. **`AuthService::hasSufficientRole` was deliberately left as-is** — it uses the fail-secure form (unknown REQUIRED role → `PHP_INT_MAX` → deny everyone), the security-critical dispatch gate, which must NOT be unified onto the 0-fallback semantics.
- **LOGIN-UX-001** — resolved 2026-06-03. Show-password toggle added via `password-toggle.js` (backend asset). One accessible eye button (`aria-pressed` / `aria-label`, focusable), binds to `input[type="password"]` within scope — no template marker, no inline JS. Login + setup load it statically (`addJs`, self-inits on DOM ready); user-edit loads it lazily (`load-script`, popup-scoped) alongside the meter. `LoginController` form renders funnel through a single `renderForm()` so the toggle is registered on the initial GET and on every POST error re-render.

## pending

_(none)_
