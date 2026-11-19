# z77/module-member

The **member area** of z77: a protected frontend area where customers or members register,
sign in **without a password** and manage their own account — plus the backend screen where an
operator activates new registrations. Project-neutral: what a member works *for* (a tenant, a
project, an administration …) and what activation creates is the project's, attached through
hooks.

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-member`); not yet a split target or on Packagist.

## What it does

- **Registration with hard double opt-in** — `register → confirm e-mail → operator activation`:

  ```
  registered ──e-mail confirmed──▶ confirmed ──operator activation──▶ active
  ```

  Activation grants the frontend role `customer` and runs the project's `activationHook`
  (e.g. create a tenant); if the hook fails, the account stays `confirmed`.
- **Invitations** — a member (or, through `InvitationFlow::inviteAsOperator()`, a backend user)
  invites someone to a tenant; the invitee redeems the link and joins that tenant.
- **Passwordless sign-in** — a magic link by e-mail (`LoginFlow`), with a confirmation step when
  the link is opened on another device than the one that asked; optional **2FA** with an
  authenticator app (TOTP), a remembered device key, throttling per address, per browser and per
  origin. One token mechanism for everything (`TokenService`: hashed at rest, time-limited,
  single-use).
- **Profile** — account data, language, 2FA on/off, signed-in devices, tenant switch, delete
  account (`AccountDeletion`, which announces the removal to other modules).
- **Languages** — the member pages and mails are translated (`res/i18n/{de,fr,en}.json`); a
  signed-in member reads and receives mail in the language of her account (ADR-056).
- **Own shell** — `html-shell-skeleton` with header, tabs, user menu; actions placed per ADR-033.
- **Storage** — file-backed through the kernel's unified entity manager
  (`data/framework/member/accounts.json`, tokens, pending logins).
- **Cleanup job** `member-cleanup` (deletes never-confirmed accounts older than `cleanupAfterDays`; confirmed ones always wait for the operator).

## Screens and routes

| Where | What |
|---|---|
| `/member/main/register`, `/confirm`, `/resend` | Registration, e-mail confirmation, request the confirmation link again. |
| `/member/main/login` (+ waiting page, redeem, TOTP step), `/logout` | Sign-in by link, 2FA code. |
| `/member/main/profile` | The signed-in member's profile (role `customer`). |
| Backend «Member-Konten» — `/backend/service/member-accounts/list` | ADMIN. The waiting registrations first; «Freischalten», «Ablehnen» (deletes, no mail), «2FA-Reset». Fragment `Ui\AccountsControllerTrait`, host in `module-backend`; a project may mount narrowed lists. Help: «Hilfe» in the toolbar. |

A project adds its own member pages (`AbstractMemberController`) and decides the menu.

## Project hooks (memberConfig)

`activationHook`, `membershipHook`, `joinHook`, `tenantLabelHook`, `tenantNoun` (the word for
what a member works for), `areaVisibilityHook`, `accountDeletionHook`, `notifyRowsHook`,
`originNotes`, `profileHook`; limits `loginRequestsPerHour`, `loginRequestsPerHourPerIp`,
`invitesPerTenantPerDay`; settings `languageFromAccount` + `languages` (ADR-056), `totpIssuer`,
`memberEntryPath`, `afterLoginUrl`, `cleanupAfterDays`. A project records them in
`override/z77/module/member/src/App/Config/memberConfig.inc.php` — an override of this file
wins whole, so routes and jobs must be carried along.

## Requirements

PHP ≥ 8.2, `z77/kernel` ^1.0, a working mail account (every step is a mail). For the operator
notice «Neue bestätigte Registrierung» configure the form mail `memberConfirmed`.

## Enabling it in a project

`composer require z77/module-member` (path repository while it is not on Packagist), `composer
install`, then override `memberConfig.inc.php` with the project's hooks. The backend list
`/backend/service/member-accounts/list` works through `module-backend` as soon as the package is
installed; no navigation seed ships — the project adds the menu entry. Set `canonicalBaseUrl` in
`config/client/systemConfig.inc.php` — the member pages need it for every link they mail.

## Not built yet

- **Members as app users** of the z77 App (see `z77/module-api`, ADR-058 A8).
- Help texts on the member pages themselves (the backend has a guide for operators; member pages
  are multilingual, help texts are German so far).
- Split repository and Packagist registration.

## Documentation

- Topic: [`docs/topics/member.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/member.md)
- Decisions: ADR-029 (member session and framework ACL), ADR-033 (shell action placement),
  ADR-037 (one account, several tenants), ADR-038 (member knows no project reference), ADR-056
  (account language) in [`docs/02-decisions/`](https://github.com/z77-ch/z77-framework/tree/main/docs/02-decisions)

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
