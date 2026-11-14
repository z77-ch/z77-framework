# z77/module-backend

The authenticated admin interface of the z77 framework: sign-in (with optional second factor
and password reset), the dashboard, website content and navigation, user management, the
installation's service tools — and the shell that hosts the backend screens of every business
module (orders, invoices, bookkeeping, contacts, the Drive).

Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not
commit here — issues and pull requests go to the monorepo.

## What it does

- **The shell** — top bar (area switcher, installation name, environment switcher, help /
  KI-Assistent, avatar menu), the left rail with the area's pages, the action cell, the toolbar
  and the crumb line. The menu follows the access config: an entry is shown only when the user
  may open its target (`BackendMenu`, ADR-045). Layout rules: [`backend-screen.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/backend-screen.md).
- **Sign-in** — `/login` with throttling, an optional TOTP second factor per user, «Passwort
  vergessen» by e-mail link, a POST-only logout; first-run setup by token for non-interactive
  installs.
- **Dashboard** — one tile per visible menu area plus the open work every installed module
  reports through the `dashboard` registry (ADR-052).
- **Help and KI-Assistent** — every screen with help shows «Hilfe» (lists: at the right end of
  the toolbar). With `z77/kibot` installed and configured, the trigger becomes «✦ KI-Assistent»,
  a chat that answers from all help templates of the installation (ADR-048, ADR-055). Base
  knowledge that spans screens lives under `res/view/templates/Backend/Guide/`.
- **Per-user appearance** — dark mode, seven colour palettes, font size; stored with the user.
- **Host of other modules' screens** — thin controllers under `src/Ui/Controllers/{Finance,Order,Contact,Documents,Service,System}/`
  mount a module's `…ControllerTrait` (ADR-018). Such a route resolves only when the owning
  module is installed.

## Screens and routes

URL schema: `/backend/{group}/{controller}/{action}` (ADR-005). Role = the minimum role
(`superUser` > `admin` > `editor`); the module baseline is `admin`, deviations are listed in
`src/App/Config/backendConfig.inc.php`.

| Menu | Route | Role | Notes |
|---|---|---|---|
| — | `/login` (`system/login/login`), `…/code`, `…/forgot`, `…/reset`, `…/logout` | guest | Sign-in, 2FA code page, password reset, logout confirm |
| — | `/backend/system/setup/setup` | guest | First admin of a non-interactive install, gated by `SETUP_TOKEN` |
| Übersicht | `/backend/system/dashboard/overview` | editor | Entry page after sign-in |
| Webseiten › Inhalte | `/backend/content/content/list` | editor (delete: admin) | Content documents, variants, publish, versions; page editor on the website |
| Webseiten › Metadaten | `/backend/content/meta-data/list` | admin | SEO title / description per page and language |
| Webseiten › Übersetzungen | `/backend/content/translation/list` | admin | UI texts and route slugs per language |
| Webseiten › Navigation | `/backend/content/navigation/list` | admin | Menu tree (drag & drop), render slots |
| Webseiten › Navigation › Nav Alias | `/backend/content/navigation_alias/list` | admin | URL aliases of navigation entries |
| Stammdaten › System › Benutzer | `/backend/system/backend-user/list` | admin | Accounts, roles, sender identities; own 2FA (editor), 2FA reset (superUser) |
| Stammdaten › System › App-Benutzer | `/backend/system/api-app-user/list` | admin | Mount of `z77/module-api` |
| Service › Backup | `/backend/service/backup/list` | superUser | Data / database / full backups: run, download, delete |
| Service › E-Mail | `/backend/service/email-settings/list` | admin | Form recipients and document-mail texts |
| Service › Jobs | `/backend/service/job/list` | superUser | Schedules, run now, queue, history, runner heartbeat |
| Service › Import | `/backend/service/import/list` | superUser | Adopt framework defaults or an inbox JSON file, record by record (ADR-032) |
| Service › Formular-Protokoll | `/backend/service/form-log/list` | admin | Public-form submits, country blocklist (geo guard) |
| Service › Statistik | `/backend/service/stats/list` | admin | Monthly web statistics, client link, monthly mail; link door `/stats/report/{token}` (guest) |
| Service › Postausgang | `/backend/service/outbox/list` | admin | Mount of `z77/module-mailing` |
| — | `/backend/service/member-accounts/list` | admin | Mount of `z77/module-member` (no menu seed entry) |
| Finanzen, Aufträge, Kontakte, Drive | `/backend/{finance,order,contact,documents}/…` | admin | Mounts of `module-vat`, `module-financial`, `module-debtor`, `module-receipt`, `module-mandator`, `module-order`, `module-article`, `module-contact`, `module-dms` |

The shell's own endpoints: `/backend/system/system/{clear-cache,toggle-debug,toggle-noindex}`
(admin) and `…/save-preferences` (editor); the assistant's `/backend/system/assistant/{config,ask}`
(editor, 404 while not configured).

The menu entries come from the navigation seed `navigation.d/kernel.json` (kernel) and each
module's own seed; the installer adds new entries on `composer update`, add-only (ADR-050).

## Background jobs

Declared in `backendConfig['jobs']`, switched on and scheduled in Service › Jobs:
`backup-data`, `backup-db`, `backup-full`, `import-apply`, `form-log-cleanup`,
`password-reset-cleanup`, `stats-report-mail` (no default schedule) and `stats-rollup`
(`daily@04:40`), `geoip-update` (`weekly@mon,04:20`) — the two that ship a schedule because it
is a duty (privacy promise, licence). Jobs only run when the server calls
`php vendor/bin/z77-run` every minute.

## Requirements

- PHP ≥ 8.2, `z77/kernel` ^1.0.
- Optional: `z77/kibot` for the KI-Assistent; the business modules listed above for their screens.

## Enabling it in a project

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. It wires the four web-stack packages (kernel, module-frontend,
module-backend, module-dms), the installer, and the override structure. The installer creates
the first account (role `superUser`) or a one-time setup token.
`composer require z77/module-backend` only adds this module to an **existing** z77 project.

Project settings, never by editing `vendor/` (CE principle):

| What | Where |
|---|---|
| Access deviations, extra controllers | `override/` copy of `src/App/Config/backendConfig.inc.php` (the whole file wins — keep the shipped deviations) |
| Templates, help texts | same path under `override/…/res/view/templates/` (a help template there replaces or adds help) |
| Password strength, login limits | `config/client/auth.inc.php` |
| Backup retention, warning age | `config/client/backup.inc.php` |
| KI-Assistent (key file, texts) | `config/client/kibot.inc.php`, key `backend` |
| Dashboard entries of a project | `override/…/App/Config/dashboardConfig.inc.php` |

CLI: `php vendor/z77/module-backend/bin/assistant-index.php [--check]` rebuilds the
assistant's help index ahead of the first question.

## Not built yet

- The bell in the top bar (notifications) has no function yet; open work is on the dashboard.
- No backend-wide search / command palette.
- No stylesheet editor for the website.
- Restoring a backup from the backend — deliberately CLI only (`z77-restore`), see `backup.md`.

## Documentation

Topic docs (single source of truth per area) in the monorepo:
[`backend.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/backend.md) (shell, menu, dashboard, users),
[`login.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/login.md) (sign-in, roles, 2FA, password reset),
[`content.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/content.md), [`navigation.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/navigation.md),
[`css-backend.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/css-backend.md), [`kibot.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/kibot.md),
[`backup.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/backup.md), [`jobs.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/jobs.md),
[`import.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/import.md), [`stats.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/stats.md),
[`forms.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/forms.md).

## License

MIT — see the monorepo. Author: Peter Ruepp, [z77.ch](https://z77.ch).
