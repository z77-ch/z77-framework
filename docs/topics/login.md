# login

2026-11-01

## entry

1. `packages/kernel/shared/src/Services/AuthService.php` — `canLogin` / `login` / `logout` / `getCurrentUser` / `resolveRole`
2. `packages/module-backend/src/Ui/Controllers/System/LoginController.php` — `loginAction` (GET + POST) + `logoutAction`
3. `packages/kernel/shared/src/Repositories/BackendUserRepository.php` — user lookup from JSON store

## file map

SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/Config/AuthRole.php
SOURCE=/packages/kernel/core/src/Session/SessionManager.php
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/packages/kernel/core/public/index.php
SOURCE=/packages/kernel/shared/src/Auth/AuthUser.php
SOURCE=/packages/kernel/shared/src/Entities/BackendUser.php
SOURCE=/packages/kernel/shared/src/Auth/PasswordPolicy.php
SOURCE=/packages/kernel/shared/src/Repositories/BackendUserRepository.php
SOURCE=/packages/kernel/shared/src/Services/AuthService.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Config/layoutConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/LoginController.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginController/loginAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginController/forgotAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginController/resetAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/emails/passwordReset.tpl.php
SOURCE=/packages/module-backend/res/view/templates/emails/passwordChanged.tpl.php
SOURCE=/packages/module-backend/src/PasswordReset/PasswordRecovery.php
SOURCE=/packages/module-backend/src/PasswordReset/PasswordResetTokens.php
SOURCE=/packages/module-backend/src/Jobs/PasswordResetCleanupJob.php
SOURCE=/packages/kernel/shared/src/Auth/LoginThrottle.php
SOURCE=/packages/kernel/shared/src/Throttle/FileThrottle.php
SOURCE=/packages/kernel/shared/src/Validators/BackendUserValidator.php
SOURCE=/packages/kernel/core/src/Config/auth.default.inc.php
SOURCE=/tests/backend-password-reset.php
SOURCE=/packages/kernel/shared/src/Auth/Totp.php
SOURCE=/packages/kernel/shared/src/Auth/TotpGuard.php
SOURCE=/packages/kernel/shared/src/Auth/TotpVault.php
SOURCE=/packages/module-backend/src/TwoFactor/BackendTwoFactor.php
SOURCE=/packages/module-backend/src/TwoFactor/TotpCheck.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/BackendUserController.php
SOURCE=/packages/module-backend/res/view/templates/System/LoginController/codeAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/BackendUserController/twoFactor.tpl.php
SOURCE=/packages/module-backend/res/view/templates/System/BackendUserController/confirmTwoFactorReset.tpl.php
SOURCE=/packages/module-backend/res/view/templates/emails/twoFactorReset.tpl.php
SOURCE=/packages/module-backend/res/view/templates/emails/twoFactorEnabled.tpl.php
SOURCE=/packages/module-backend/res/view/templates/emails/twoFactorDisabled.tpl.php
SOURCE=/packages/kernel/core/src/Controller/ControllerHandler.php
SOURCE=/packages/kernel/shared/src/Alert/AlertService.php
SOURCE=/tests/backend-2fa.php
SOURCE=/tests/support/real-flow-site.php

RUNTIME=/skeleton/data/framework/auth/backendUsers.json
RUNTIME=/skeleton/data/framework/auth/passwordResets.json
RUNTIME=/skeleton/data/framework/auth/totp.key
RUNTIME=/skeleton/var/lib/throttle/backend-totp-guard/
RUNTIME=/skeleton/data/framework/auth/alert/

## mental model

File-based authentication. Users are stored in `data/framework/auth/backendUsers.json`. `AuthService` reads/writes the `auth_user` slot via `SessionManager` and caches the current user per request. Role resolution is hierarchical: Action role > Controller role > Module role > GUEST. `AccessGuard` runs in the request pipeline and redirects unauthenticated requests to the alias `/login` (canonical: `/backend/system/login/login`), storing the original URL in session for post-login redirect. Post-login greeting and post-logout notice are delivered via `MessageService` session-flash (see [`messages.md`](messages.md)).

- `LoginController` extends `AbstractBaseController` (NOT a security controller) — login page must be accessible without auth.
- Guest identity: `id=0`, `isLoggedIn()` returns `false` — `null` is never used for the current user.
- AccessGuard is a pipeline service (replaces the removed `AbstractSecurityController`). CSRF validation for Fetch happens here too.
- A backend session is checked against the store once per request: its `auth_epoch` must equal `BackendUser::$authEpoch`, and the user must still exist. Otherwise `getCurrentUser()` removes `auth_user` and answers guest. This is what «end the user's other sessions» means here: there is no server-side session list.
- Optional per user: a second factor (TOTP, «second factor» below). A user who has not switched it on notices nothing. With it on, the password only leads to the code page, and `auth_user` exists from the code on, not before.

## role system

```text
AuthRole constants: GUEST=0, VISITOR=10, CUSTOMER=15, MEMBER=20, CRON_JOB=30, EDITOR=50, ADMIN=80, SUPER_USER=100
```

`EDITOR` («Redaktor», ADR-045) writes website text and nothing else of the backend.
What that means is NOT coded into the role: the backend config lowers single
controllers/actions to `editor` (framework default: the content editor, the
dashboard, `system/save-preferences`; deleting content stays ADMIN). A project
widens or narrows it in its own module config. The backend menu follows the same
config (see [`backend.md`](backend.md) «menu follows access»).

Resolution order (`AuthService::requiredRole()`, pure — called by
`resolveRoleForCurrentController()` for the dispatch gate and by `canReach()` for
the backend menu and the per-button checks, so both always agree):

```text
Action role > Controller role > Module role > GUEST default
config lookup = controllers[$group][$controllerBaseName]
  group        = ControllerHandler::getCurrentGroup() (e.g. 'system')
  base name    = short class name, e.g. 'LoginController' (NOT FQCN)
  group-level controller wildcard '*' supported (see frontend)
```

The lookup uses the DECLARED names: PHP finds a method or a loaded class in any casing while the
config keys are exact, so `ControllerHandler` resolves the URL to the method's and the class's real
name first (`ReflectionMethod::getName()`, ROUTE-CASE-001, [`routing.md`](routing.md)). Before that,
`/backend-user/twoFactorReset` ran the action with its role entry unread.

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
  → LoginThrottle::tryAttempt($username, $ip)     // reserves the attempt; over a limit: neutral message, NO password_verify
  → BackendUserRepository::findByUsername($username)
  → AuthService::canLogin(BackendUser, $password)   // verifies password_verify; returns ?self
  → null: html(['error' => '...', ...])           // the reservation stays: this was a failure
  → success, BackendUser::hasTotp():              // BACKEND-2FA-001, see the code page below
           → AuthService::logout()                // whoever was signed in in this browser
           → SessionManager::regenerate()
           → session 'auth.second_factor_pending' = {id, at, auth_epoch}
           → redirect /backend/system/login/code  // NO identity; the reservation stays until the code passed
  → success, no 2FA: LoginThrottle::succeeded()   // clears username+IP, hands the IP count back
  completeLogin():
           → AuthService::login()                 // writes auth_user via SessionManager
           → MessageService::pushFlashAfterRedirect('success', 'Hallo {Username}, du bist angemeldet')
           → if BackendUser::isPasswordWeak() → pushMessageAfterRedirect('error', weak-password nag)
             (persistent channel, fires on EVERY login — allowed but reminded; see security.md)
           → resolvePostLoginRedirect()
             → access.origin in session? → reconstruct URL → redirect
             → else → /{currentModule}/{defaultGroup}/{groupDefaultController}/{defaultAction}
               (e.g. /backend/system/dashboard/overview — stays in the module so the
                flash partial is present to render the greeting)
```

### GET + POST `/backend/system/login/code` (BACKEND-2FA-001)

```text
LoginController::codeAction()
  pendingUser()     // marker younger than 5 minutes + user read again + hasTotp() + same auth_epoch
    no → marker removed, flash «Die Anmeldung ist abgelaufen …», redirect /login
  GET  → the form
  POST → CSRF (fail: re-render «Sitzung abgelaufen», nothing counted)
       → BackendTwoFactor::check($user, code)    // one lock per user: guard, verify, spend the step
            Unavailable → «… technischen Problems …» // counters dead: fails closed, the right code too
            Locked → «Zu viele Fehlversuche …»   // also for the right code
            Wrong  → «Der Code ist falsch oder wurde bereits verwendet …», counted
            Passed → marker removed
                   → LoginThrottle::succeeded()  // only now
                   → AuthService::passedSecondFactor($user)->login()
                   → completeLogin()             // flash, weak-password nag, resolvePostLoginRedirect()
```

### GET `/backend/system/login/logout`

```text
LoginController::logoutAction()
  → AuthService::logout()     // removes auth_user via SessionManager
  → MessageService::pushFlashAfterRedirect('info', 'Du wurdest abgemeldet')
  → redirect /login (alias)
```

### GET + POST `/backend/system/login/forgot` — «Passwort vergessen» (LOGIN-RESET-001)

```text
LoginController::forgotAction()
  GET  → form: username OR e-mail
  POST → CSRF (empty token refused) → fail: re-render «Sitzung abgelaufen»
       → PasswordRecovery::request($identifier, $ip)     // returns void: there is no outcome to show
            PasswordResetTokens::purge()                 // every request, also for an unknown user
            FileThrottle 'ip:…'      10/h → over: stop
            findByRecoveryIdentifier → none or no address: stop
            FileThrottle 'account:id' 3/h → over: stop
            Request::getBaseUrl()    // canonicalBaseUrl; unset: throws → logged, no token issued
            PasswordResetTokens::issue(id)               // ends the user's previous link, stores the hash
            EmailService::send(emails/passwordReset)     // false → error_log without the token
       → the one answer: «Falls das Konto existiert, ist eine E-Mail unterwegs.»
```

### GET + POST `/backend/system/login/reset` (LOGIN-RESET-001)

```text
LoginController::resetAction()                  // Referrer-Policy: no-referrer on every answer
  peek(token) + the user exists? no → the one neutral page, link to forgot
  GET  → the form. Nothing consumed: mail scanners follow links
  POST → CSRF → password twice → BackendUserValidator (password only: same floor + tier as the user form)
       → passesSecondFactor($user)              // no 2FA: true. 2FA: the form shows a code field,
                                                // BackendTwoFactor::check(); BEFORE anything changes.
                                                // Wrong: form again, link usable, counted (same lock as the login)
       → password_hash                          // outside the store's lock
       → PasswordResetTokens::consume(token)    // check + mark inside ONE lock; null → neutral page
       → re-read the user, set hash + password_weak, bumpAuthEpoch(), persist
       → notifyPasswordChanged()                // emails/passwordChanged, no link; failure only logged
       → LoginThrottle::forgive()               // username+IP and IP counter of this address
       → flash + redirect /login                // NO automatic login; 2FA stays on
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
| `auth_user` | `['id' => int, 'user_name' => string, 'roles' => string[], 'realm' => string, 'auth_epoch' => int]` (`auth_epoch`: backend realm only; a session from before the field reads 0) | `AuthService::login()` writes, `getCurrentUser()` reads and removes a stale one, `renewSessionStamp()` updates, `logout()` removes |
| `auth.second_factor_pending` | `['id' => int, 'at' => int, 'auth_epoch' => int]` — password right, code outstanding; 5 minutes | `LoginController` writes, reads and removes |
| `auth.totp_enrolment` | `['user_id' => int, 'at' => int, 'secret' => vault ciphertext]` — a running enrolment, new per open, 10 minutes; logout and login clear it | `BackendTwoFactor`, `LoginController` |
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
canLogin(BackendUser $user, string $password): ?self   // verifies credentials; returns self on success, null on failure
passedSecondFactor(BackendUser $user): self          // the code page's way to login(): only after a valid code for exactly this user
login(): void                                        // writes auth_user to session; throws if canLogin() was not called first, and for an account with 2FA without passedSecondFactor()
logout(): void                                       // removes auth_user from session
getCurrentUser(): AuthUser                           // loads from session, checks the stamp, caches per request; returns guest if not logged in or stale
renewSessionStamp(BackendUser): void                 // this session keeps up with HIS OWN password, role or 2FA change (stamp, roles, a NEW session id); no-op for any other session
static stampMatches(sessionData, BackendUser): bool  // the stamp rule, pure: missing = 0
hasSufficientRole(AuthUser, string $requiredRole): bool   // static
resolveRoleForCurrentController(): string                 // the current route → resolveRoleFor()
resolveRoleFor(module, group, ControllerBaseName, actionMethod): ?string   // any route; null = module not installed
static requiredRole(?moduleRole, controllers, group, ControllerBaseName, actionMethod): string   // the rule, pure
canReach(AuthUser, module, group, controller, action): bool   // URL segments as a navigation entry stores them; '' action = default action
```

## BackendUser entity (persistence)

```php
getId(): ?int
getUsername(): string
getPasswordHash(): string            // bcrypt, cost 12
getRoles(): array                    // e.g. ['admin'] or ['guest']
getSortKey(): int                    // order in the user-admin list
isPasswordWeak(): bool               // set when password is set; drives the every-login nag
getSenderIdentities(): array         // list<SenderIdentity> — whose name the user signs mail with (2026-10-23)
getEmail(): string                   // recovery address; '' = no self-service reset (2026-11-01)
getAuthEpoch(): int                  // session stamp; raised by bumpAuthEpoch() (#[Counter]), seeded by seedAuthEpoch(), deliberately no setter
hasTotp(): bool                      // the only 2FA question templates and answers may ask
getTotpSecret() / getTotpActivatedAt() / getTotpLastStep()   // for BackendTwoFactor; no setters
enableTotp() / recordTotpStep() / clearTotp()                // not setters: mapFromArray() cannot reach them
```

JSON keys: `id`, `username`, `initials`, `name`, `email`, `sender_identities`, `password_hash`, `roles`, `preferences`, `sort_key`, `password_weak`, `auth_epoch`, `totp_secret`, `totp_activated_at`, `totp_last_step`.
A file written before `email` / `auth_epoch` / the `totp_*` keys has no such keys and reads `''` / `0`; the next save of that user adds them. No migration.
`sender_identities` (owner decision 2026-10-23): rows `{name, email}`, the first the default;
display data for mail, NOT a credential and not a login address — see [`backend.md`](backend.md)
«user management» and [`mail.md`](mail.md) «sender identities».
`password_weak` is computed via `PasswordPolicy` whenever the password is set (it
cannot be recovered from the hash) — see [`security.md`](security.md).

## second factor (BACKEND-2FA-001)

Optional per user, TOTP only. No mandate, no magic link, no recovery codes (owner, 2026-11-01). The classes `Totp`, `TotpGuard` and `TotpVault` live in the kernel (`Z77\Shared\Auth`), because the backend must not depend on the optional module-member; `BackendTwoFactor` holds what is the backend's, with its own key file (`data/framework/auth/totp.key`) and guard directory (`var/lib/throttle/backend-totp-guard`). Nothing is shared with a member's 2FA.

**Switching on and off** happens for the signed-in user's OWN account; the actions take no id from the request. The dialog `/backend/system/backend-user/two-factor` is opened from the section in the own user form and from the avatar panel. `BackendUserController` stays ADMIN, its own-account actions are lowered to EDITOR in `backendConfig`, so an editor reaches them too.

| Step | What happens |
|---|---|
| Dialog opens, 2FA off | A NEW secret on every open, replacing the earlier one of this session; QR (`QrCode::svg`) and the key by hand. Nothing in the store: it waits in the session as ciphertext, for 10 minutes (`ENROLMENT_SECONDS`), and logout and login clear it. **What a stolen cookie gets:** he can read a secret by opening the dialog, and the owner's next open throws it away, so the secret he read is never the one confirmed. **What remains:** a thief opening the dialog AFTER the owner's last open replaces the owner's secret instead of learning it; the owner's code is refused and he opens the dialog again. Only the thief's own secret could then be confirmed, and that takes the password, which ends the question: with the password and the cookie he owns the account anyway |
| Switch on | Password (through `LoginThrottle`, a stolen session must not guess it here without limit) AND a valid code. The confirming code is spent at once. The user's OTHER sessions end (the stamp is raised, this session keeps up, owner 2026-11-01), and this session's id changes, so the same cookie copied elsewhere ends too. Mail «2FA wurde eingerichtet» to the recovery address when there is one, no link in it; `error_log` line |
| Switch off | A valid, unspent code. A wrong one counts towards the lock. Mail «2FA wurde entfernt», no link; `error_log` line. Other sessions stay |

**The lock and the replay.** `BackendTwoFactor` judges every code inside one lock per user: guard, verify, spend the step, save. So parallel guesses cannot pass the guard before the first failure is counted, and one code cannot pass twice (`Totp::acceptedStep()`, RFC 6238 §5.2; a step at or below `totp_last_step` is refused). 5 failures: 15 minutes locked, the right code refused too. A spent code counts as a failure. Login, reset form and switch off share one counter per user. An unreadable secret (key file lost or damaged) fails closed: no code passes, the user is reset. `TotpVault` creates its key only when the file is absent, atomically (`fopen 'x'`), and only on `encrypt()`; a file of the wrong length is NEVER overwritten: it stays for repair, with a log line.

**Other writers cannot undo this.** The file store writes only the columns a save changed, and `auth_epoch` moves by its difference (`#[Counter]`, [`persistence-file.md`](persistence-file.md) DATA-MERGE-001). So a user-form save or `savePreferences` running beside a switch-on, a reset or an accepted code leaves `totp_*`, the stamp, the password and the roles as they are on disk, a save does not bring a deleted user back, and keys removed by the hand procedure stay removed. Tested in parallel on two servers.

**Who may change what** (owner 2026-11-01, `BackendUserController`, against the STORE's roles): nobody grants a role above his own level; nobody edits, deletes or resets the password or 2FA of an account above his own level (form and menu hide it, the actions refuse it). The same level stays editable. The last SUPER_USER cannot be demoted, not even by himself (`rolesRefusal()`: «Es muss mindestens ein Super-User erhalten bleiben.»), because nobody could grant the role again; he cannot delete himself, and nobody below can reach him. An installation with no SUPER_USER at all is not stopped by this: an ADMIN there cannot create one, that stays a hand edit. A changed role raises the target's stamp, so a demoted user's sessions end; the actor changing his own keeps his session with the new roles. The check runs in one lock with the write (`keepingASuperUser()`, its own lock file beside the store), so two SUPER_USERs demoting each other, or deleting each other, leave one (tested on two servers).

**Id reuse.** The store hands out no deleted id (persistence-file.md DATA-MERGE-001). A row removed by HAND leaves no mark, so there the id can come back: a new user's stamp is `time()`, so the old session does not fit. `removeAction` ends his open reset link and clears his guard file.

### lost device

Never self-service.

**Admin reset:** System › Benutzer, row ⋮ «2FA zurücksetzen» → confirm → POST with entity CSRF (context `backendUserTotpReset`, so the row's delete token does not open it). Shown only when the target has 2FA, the actor is SUPER_USER and the target is not the actor; `totpResetRefusal()` answers both the menu and the action, from the actor's roles in the store, and `backendConfig` gates both actions to SUPER_USER as well, in every URL casing (ROUTE-CASE-001). Effects: `totp_*` cleared, guard reset, `auth_epoch` raised (all his sessions and a pending login end), an open reset link ended, first: it was issued while the code protected it, and if that fails nothing has changed and the admin is told, mail «2FA wurde zurückgesetzt» to his recovery address when there is one (no link), `error_log` line naming target and actor. After it the password alone logs him in; he may set up anew.

**The last SUPER_USER who lost his device, or who cannot get past dead counters** («code refused» below) has nobody to reset him: by hand on the server, with shell access to the project.

1. In `data/framework/auth/backendUsers.json`, in the user's row: remove the keys `totp_secret`, `totp_activated_at` and `totp_last_step`. Raise `auth_epoch` by one, which ends his other sessions.
2. Delete `var/lib/throttle/backend-totp-guard/<sha256 of the user id>.json`, or the whole directory: it is disposable. Otherwise a running lock stays until it expires. With dead counters there is nothing to delete, skip this.
3. He logs in with the password and may set up anew.

⚠️ **NOT through PowerShell** (`Get-Content` → `Set-Content`, `Out-File`, `>`, also with `-Encoding utf8`): Windows PowerShell 5.1 corrupts umlauts and writes a BOM, and `FileStorage::load()` then blanks the file — every user is gone ([`persistence-file.md`](persistence-file.md) DATA-JSON-001). Take a copy first. Use an editor that saves UTF-8 without BOM, or PHP (the same steps run in `tests/backend-2fa.php`):

```php
// reset-2fa.php — php reset-2fa.php max  (from the project root; delete the script afterwards)
$file = 'data/framework/auth/backendUsers.json';
$rows = json_decode(file_get_contents($file), true);
foreach ($rows as &$row) {
    if ($row['username'] === $argv[1]) {
        unset($row['totp_secret'], $row['totp_activated_at'], $row['totp_last_step']);
        $row['auth_epoch'] = ($row['auth_epoch'] ?? 0) + 1;
    }
}
unset($row);
file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
```

### code refused: dead counters (owner 2026-11-01)

The code lock FAILS CLOSED: without a counter there is no limit on guesses, so when the guard cannot write (`var/lib/throttle/backend-totp-guard`) or cannot take its lock, every door that judges a code refuses, the right code too: code page, switch off, reset form. Switching on is refused too, it would lock the user out at his next login. There is **deliberately no bypass**. Accounts without 2FA notice nothing: `LoginThrottle` keeps failing open.

- **What the person sees:** «Die Anmeldung mit Code ist wegen eines technischen Problems gerade nicht möglich. Bitte den Betreiber informieren.», only after a right password or a valid link.
- **What the log says,** on every refusal: `BackendTwoFactor: code refused, counters unavailable — step=<step> dir=<directory> user id <id>`. `write-counter`: a trial write in the directory failed, or this account's own file `<sha256 of the id>.json` there cannot be written; the check did not start. `lock`: the lock file could not be opened. `record-failure`: a wrong code could not be counted. `clear-counter`: a right code could not clear the counter, so it was refused too. Never a code or a secret.
- **The alert:** the recipients are set in the backend, Service → E-Mail, entry `backendCodeLock` (`emailConfig` `forms`, mail.md `recipientsOnly`; owner 2026-11-02, it is a question of who gets a mail, and an operator can change it without the server). One mail per incident through `AlertService` (source `backend-2fa:code-lock`): outage, escalation after 4 hours, recovery at the next code judged. No recipient (the default): none, the log line stays. A project with its own `emailConfig` must copy the entry, the whole file wins: without it the entry is not listed, nobody is told, and each refusal logs `alert recipients not readable`. Reading the recipients takes `data/`, which a dead `var/lib` leaves alone; a dead `data/` stops the login anyway, the accounts are there. Its state is under `data/framework/auth/alert`, not `var/lib/alert`: an alert about a dead `var/lib` cannot keep its memory there. If that state cannot be written either, no mail goes out at all (a full quota), never one per refusal; whatever it does, the answer to the login stays.
- **It recovers by itself:** the next code after the disk is healthy is judged normally.

**What to do, in this order.** First fix the cause: a full quota (`df`, the host's panel), the permissions of `var/lib/throttle` and of the files in `backend-totp-guard` (the PHP user must be able to write there), a dangling `var/lib` link after a release switch. Nothing else is needed; the next code works. Only if that cannot be done soon: remove the account's 2FA, by the admin reset (it judges no code and works with dead counters), or by hand below when no SUPER_USER can get in.

### every way to a backend identity (checked 2026-11-01)

| Path | Second factor |
|---|---|
| `LoginController::handlePost()` → `canLogin()` | The only caller. Branches to the code page |
| `LoginController::codeAction()` → `passedSecondFactor()` → `login()` | Is the second factor |
| `AuthService::login()` | Throws for an account with 2FA without `passedSecondFactor()`: a project's own controller fails loudly instead of bypassing |
| `resetAction` | Never logs in; asks for the code before anything changes |
| `SetupController`, `Install::provisionAdmin()` | Create the first account and redirect to `/login`; no session, and a new account has no 2FA |
| `MemberAuthBridge` → `establishMemberIdentity()` | Realm `member`, role `customer` (`RegistrationFlow::activate`), below every backend screen. Never touches a backend identity. A pending backend login leaves it alone |
| `JobRunner`, `member-cleanup` | Realm `cron`, an in-process actor; no session |
| module-api | Stateless routes, keys per tenant; no backend identity |

No impersonation exists. `auth_user` is written only in `AuthService`.

## admin provisioning (installer)

No default credential is shipped (the framework is open source → anything seeded
would be public). The first account is created at install by `Install::provisionAdmin()`
with role **`superUser`** (ADR-021 — the installation/DMS governor; `admin` (80) is a
normal, grant-managed role and is never provisioned). The username stays `admin` (cosmetic):

| Install context | Result |
|---|---|
| interactive | super-user account created now — hidden password prompt (twice), `PasswordPolicy`-evaluated → `password_weak` |
| non-interactive | no account — one-time `SETUP_TOKEN` written under `data/framework/auth/`, account deferred to `/backend/system/setup/setup` (`SetupController`, also role `superUser`) |

Provisioning runs once: an existing `data/framework/auth/backendUsers.json` is never
overwritten. See [`security.md`](security.md) and [`installer.md`](installer.md).

## the second door (member login, ADR-029)

This topic covers the BACKEND login (`BackendUser`, password). Since 2026-08-07
the framework has a second door that feeds the SAME ACL: the member login
(module-member, passwordless) projects its session into `auth_user` via
`AuthBridgeInterface` / `MemberAuthBridge` — realm `member`, role `customer`
(15), run by `AccessGuard::enforce()` before role resolution. A module carrying
such routes sets its own `loginUrl` (memberConfig: `/member/main/login`) so the
guard never sends a customer to the admin login. Details: `member.md`
(MEM-001) and ADR-029, second decision.

## rules

- When defining a controller for the login page → MUST extend `AbstractBaseController`, NOT a security controller (login page must be accessible without auth)
- When a UI needs the set of selectable roles (e.g. the user-edit form) → MUST derive the role set + order from `AuthRole::getRoleHierarchy()` (SSOT); MUST NOT re-declare which roles exist in the module. German display labels live in the module as a presentation-only map keyed by role, looked up per *existing* role (see backend.md ROLE-DEF-001).
- When configuring a role for a controller in module config → MUST use the short class name as the key (e.g. `'LoginController'`), NOT the FQCN
- When code needs to know whether a user may call some OTHER route (menu entry, button, card) → MUST ask `AuthService::canReach()` (or `resolveRoleFor()`); MUST NOT re-implement the action > controller > module lookup or hard-code a role next to the config — the menu and the gate would drift apart (ADR-045)
- When giving editors access to a screen → MUST lower that controller/action to `AuthRole::EDITOR` in the module config (deviation-only, AUTH-B003); MUST NOT gate on `hasAtLeast(EDITOR)` inside the controller instead — the menu reads the config, not the controller
- When setting the current user → MUST use guest identity (`id=0`, `isLoggedIn()=false`); MUST NOT store `null`
- When access-controlling a module's controllers → MUST set `moduleRole` as the restrictive baseline and add a `controllers` entry ONLY for a deviation (looser: GUEST login/setup; stricter: SUPER_USER backup; or a non-`list` defaultAction); MUST NOT restate the baseline per controller/action — an unlisted controller/action inherits the fallback chain `actionRole → controllerRole → moduleRole` and is never open (AUTH-B003, see backend.md)
- When configuring `loginUrl` in `backendConfig.inc.php` → MUST use the alias `/login`, NOT the canonical 4-segment URL — the alias is resistant to future URL restructuring
- When handling a login POST → MUST validate the session-global CSRF token (`CsrfService::validate()`) before checking credentials; on failure MUST re-render the form with a generic "Sitzung abgelaufen" message. `AccessGuard` does NOT cover the login form (it skips non-Fetch requests, and the login page itself runs without auth).
- When building the reset link → MUST take the origin from `Request::getBaseUrl()` (`canonicalBaseUrl`); MUST NOT read the `Host` header: a forged Host would put the attacker's domain into a genuine mail and hand him the token (SEC-005).
- When storing a reset token → MUST store only its SHA-256 (`PasswordResetTokens`); the plaintext exists in the mail only and MUST NOT reach a log.
- When redeeming a reset token → MUST consume it through `PasswordResetTokens::consume()` (check and mark in one lock) and only on a POST that passed every check; GET MUST NOT consume it, because mail scanners follow links. MUST NOT replace it with find + persist through the entity manager: that is not atomic, and the manager takes the same lock on flush, so it cannot run inside it.
- When a reset succeeds → MUST redirect to `/login`; MUST NOT log the user in.
- When a reset POST deals with the second factor → MUST ask at `LoginController::passesSecondFactor()`, the one place in that flow, before anything changes; MUST NOT let the recovery link bypass it, switch 2FA off or log in.
- When logging a backend user in → MUST go through `LoginController` (`canLogin()` branches on `hasTotp()`, the code page calls `passedSecondFactor()`); MUST NOT call `passedSecondFactor()` without having just seen a valid code for exactly that user; MUST NOT write `auth_user` anywhere but `AuthService`.
- When judging or changing a backend user's second factor → MUST go through `BackendTwoFactor` (the lock per user, the guard, the spent step, the fail-closed refusal); MUST NOT call `Totp::verify()` for it: `verify()` knows no replay.
- When adding a field of the second factor → MUST leave it without a setter, like `auth_epoch` (`mapFromArray()` would accept it from a form body), and MUST answer templates and JSON with `hasTotp()`, never the ciphertext.
- When switching 2FA on or off → MUST take the account from the session; MUST NOT read an id from the request.
- When resetting another user's 2FA → MUST pass `totpResetRefusal()` in the action as well; the menu proves nothing.
- When an action changes another backend account or a role → MUST check against the actor's roles in the STORE (`isAboveActor()`, `rolesRefusal()`); MUST NOT trust `getCurrentUser()->getRoles()`, a session carries the roles it started with. MUST raise `auth_epoch` when roles change.
- When a code cannot be counted or its counter cannot be cleared → MUST refuse it, the right one too, with `TotpCheck::Unavailable`; MUST NOT let the alert or its state change that answer.
- When creating a backend user → MUST seed his stamp (`seedAuthEpoch(time())`); when deleting one → MUST end his open reset link and clear his guard file.
- When writing roles or deleting a backend user → MUST go through `keepingASuperUser()`: the last-SUPER_USER rule holds only in one lock with the write.
- When loading a script that writes to `_Z77` on a guest page → MUST call `addJs` AFTER `html()`, like every backend script: before it the script stands in front of core.js and dies on load (LOGIN-TOGGLE-001).
- When answering the forgot form → MUST give the one neutral answer whatever happened past the CSRF check; MUST NOT let `PasswordRecovery::request()` return an outcome or throw.
- When a code path changes a backend user's password → MUST call `bumpAuthEpoch()` before persisting, and `AuthService::renewSessionStamp()` afterwards if the user's current session is to stay; MUST NOT add a setter for the stamp: `mapFromArray()` would then accept it from a form body.
- When handling a login POST → MUST call `LoginThrottle::tryAttempt()` BEFORE `canLogin()` and skip the password check when it refuses; MUST NOT check first and count after the failure: parallel guesses would all pass the check.
- When determining the post-login fallback URL (no `access.origin`) → MUST resolve via `ModuleManager::getDefaultGroup()` + `getGroupDefaultController()` + `getDefaultActionForController()` on the *current* module and build a 4-segment URL, NOT hardcode `/`. Reason: the redirect target must use a layout that renders flash/message containers, otherwise `MessageService::pushFlashAfterRedirect()` is silently dropped (see [`messages.md`](messages.md) FE-MSG-001).
- When persisting `access.origin` in `AccessGuard::buildRedirect()` → MUST include `group` alongside `module`, `controller`, `action`, `get` so post-login redirect can reconstruct the full 4-segment URL

## see also

- [`persistence-file.md`](persistence-file.md) — `BackendUserRepository`; `passwordHash` round-trip fixed (BUG-P001 resolved)
- [`fetch.md`](fetch.md) — CSRF validation in `AccessGuard` (Fetch POSTs only; the login form is a classic POST and validates the token in the controller itself)
- [`messages.md`](messages.md) — `LoginController::logoutAction` and `handlePost()` call `messageService->pushFlashAfterRedirect()` before returning the `RedirectResponse` for post-login greeting and logout confirmation
- [`security.md`](security.md) — `PasswordPolicy`, `passwordWeak` nag, secure-by-default install + setup token, open security pendenzen (SEC-001…004)
- [`member.md`](member.md) — the SECOND identity: passwordless customer accounts with their own session and their own guard. It never writes `auth_user`, so `AccessGuard` sees a signed-in customer as a guest; the separation is ADR-029
- [`mail.md`](mail.md) — `EmailService::send()`, the template + derived text the two reset mails use, the default system sender
- [`jobs.md`](jobs.md) — `password-reset-cleanup`, and why it ships no schedule
- [`backend.md`](backend.md) «user management» — the `email` field and the 2FA section in the user form, the row's «2FA zurücksetzen»

## known issues

- **BACKEND-2FA-001** — built 2026-11-01 (owner flow), section «second factor»; reviewed the same day, the findings closed and proved in `tests/backend-2fa.php` (390 checks: RFC vectors, offline sections, concurrent processes against one user and one key file, the whole flow through the framework in PHP's built-in server with browser-like cookies and `Sec-Fetch-Mode`, real parallel saves on two servers). **NOT verified: nothing has been seen on a real server, and the dialogs have not been clicked in a browser** (a headless attempt did not get as far as the popup; no JS was added, the dialogs use the existing `data-fetch-*`). Not scanned with an authenticator app either: the codes in the harness are computed with `Totp`, and the QR is compared with the encoding of the expected `otpauth://` address, not read back by a camera. Don't assume these residual risks are closed:
  - **Anyone with the password can lock the owner out for 15 minutes**: the lock counts per account, on purpose, because a code guessed from many addresses is the attack. The login throttle (5 per username+IP) limits how often he gets to the code page from one address.
  - **An abandoned login counts as a failure** of the password throttle until a code passes, so five right passwords without a code lock that address out of the password form for up to 15 minutes.
  - **A spent code counts as a failure.** Whoever switches on and logs in within the same 30 seconds is told to wait for the next code.
  - **Switching OFF ends no session.** Only switching on, a password or role change and an admin reset do.
  - **A stolen session keeps working until one of those happens.** Switching on ends it; if the thief also has the password, he can sign in again up to the code page, not past it.
  - **A reset link consumed, then the save fails** (disk full between `consume()` and `flush()`): the link is gone and the password unchanged; the answer is an error page and the owner asks for a new link. Nothing is exposed, so it stays.
  - **The key file is in `data/`**, so the `data` and `full` backups hold it beside the ciphertext: the encryption protects against a leaked user store, not a leaked backup. Lost key: every 2FA user is reset, by the procedure above if that includes the last SUPER_USER.
  - **Fails closed means a disk problem takes 2FA users out of the backend** until it is fixed, by decision («code refused» above). `TotpGuard` itself decides nothing: the member door still carries on when `recordFailure()` answers false.
  - **The installed CSS:** `.be-form__qr` reaches an existing installation like any asset (`public/` is seed-once, backend.md); until then the QR renders at its own size.
- **LOGIN-TOGGLE-001** — resolved 2026-11-01. The show-password eye had NOT worked on `/login` and on the reset form: `LoginController` called `addJs` before `html()`, so `password-toggle` (and `password-meter`) stood in front of core.js and died with `_Z77 is not defined`; no eye, no meter, the login itself unharmed. Older than the 2FA work (`addJs` before `html()` is in the previous commit too; `SetupController`, which calls it after, always worked). Fixed by the order, the scripts are unchanged, so `.min.js` too. Verified: `tests/backend-2fa.php` (script order in the HTML) and in headless Edge against the `.min.js`: no console error, the toggle in the DOM on login (1), reset (2), setup (1).
- **LOGIN-RESET-001** — built 2026-11-01 (owner: an installation needs it the next day). «Passwort vergessen» for backend users: flows above; `BackendUser.email`, `auth_epoch`; store `data/framework/auth/passwordResets.json` (module-backend, plain rows through `FileStorage`; the file appears with the first request and a missing file reads empty, so there is no installer step). Job `password-reset-cleanup`, no schedule. Verified: `tests/backend-password-reset.php` (198 checks: offline sections plus the whole flow through the framework in PHP's built-in server, with a forged Host and the `file` mail transport; 6 concurrent processes consume one token, one wins). **Verified by the owner 2026-11-01 on a real installation: the flow runs («es läuft»). Not separately confirmed: the login lock on the host.** Don't assume these residual risks are closed:
  - **Timing.** A request for an existing account issues a token and sends a mail while an unknown one does not, so the answer can take measurably longer. The framework has no mechanism to send after the response is flushed; no new infrastructure was built. The limits (3/h per account, 10/h per IP) bound what can be probed.
  - **The account limit can be triggered by anyone** who knows the username: up to 3 mails per hour to its owner, each replacing the previous link, then none for the rest of the hour. A deliberate trade: no mail bombing, at the price of a possible delay.
  - **Login by timing.** An unknown username skips `password_verify`, so the login itself tells unknown from known by its duration. Pre-existing, not changed here; the login throttle bounds it.
  - **The token is in the link's query string,** so it reaches the web server's access log. One-time, 30 minutes, and the hidden field carries it on POST.
- BUG-P001 — resolved. `Naming::toCamelCase` no longer destroys camelCase input; `passwordHash` round-trip works.
- **EDITOR-ROLE-001** — built 2026-09-22 (ADR-045 §1). New role `editor` (50) with label «Redaktor»; the role resolution was factored out of `resolveRoleForCurrentController()` into the pure `requiredRole()` + `resolveRoleFor()` + `canReach()` so the backend menu uses the identical rule. Harness: `tests/backend-access.php`. Since the same day's follow-up (FE-OVERLAY-EDITOR-001, backend.md) an editor sees the frontend admin overlay (view areas, «Seite bearbeiten», logout); its routing info and partial labels stay ADMIN on purpose.
- **ROLE-LEVEL-SSOT-001** — resolved 2026-07-07. The "highest role level ≥ threshold" calc was copied three times (`AuthUser::hasAtLeast`, `AuthService::hasSufficientRole`, `BackendUserController::isAdminCapable`). Consolidated the permissive form into one pure helper `AuthRole::rolesSatisfy(array $roles, string $minRole)` (unknown role → level 0); `hasAtLeast` + `isAdminCapable` now route through it. **`AuthService::hasSufficientRole` was deliberately left as-is** — it uses the fail-secure form (unknown REQUIRED role → `PHP_INT_MAX` → deny everyone), the security-critical dispatch gate, which must NOT be unified onto the 0-fallback semantics.
- **LOGIN-UX-001** — resolved 2026-06-03. Show-password toggle added via `password-toggle.js` (backend asset). One accessible eye button (`aria-pressed` / `aria-label`, focusable), binds to `input[type="password"]` within scope — no template marker, no inline JS. Login + setup load it statically (`addJs`, self-inits on DOM ready); user-edit loads it lazily (`load-script`, popup-scoped) alongside the meter. `LoginController` form renders funnel through a single `renderForm()` so the toggle is registered on the initial GET and on every POST error re-render.

## pending

- **Click through 2FA on a real installation (BACKEND-2FA-001):** switch on with a real app, log in, wrong code, switch off, the reset form with the field, the admin reset with its mail. Look at the dialog in light and dark.
- **Enter the recovery addresses:** every existing account starts without one, and without one the forgot form sends nothing. The first admin is provisioned without one too (installer and `SetupController` do not ask); security.md's roadmap wanted it asked at setup.
