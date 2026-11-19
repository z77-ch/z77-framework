# Upgrade notes — a 1.6 web project to 2.0

**Status:** `[DRAFT]` — prepared 2026-11-19 for release 2.0.0 ([`release-2.0.0-bauplan.md`](release-2.0.0-bauplan.md)).
**Scope:** a project that installed the four public packages `z77/kernel`, `z77/module-frontend`,
`z77/module-backend`, `z77/module-dms` at `1.6.0` (2026-09-11) and moves to `2.0.0`.
**Source:** `git log 1.6.0..204c415 -- packages/kernel packages/module-frontend packages/module-backend packages/module-dms`
(220 commits, 739 files) and the diffs of configs, templates, public signatures and deleted
files. `CHANGELOG.md` starts 2026-11-15; everything before that date is only here and in the
topic docs.

The business modules, `persistence-doctrine`, `module-member`, `module-api` and `kibot` were never
released — for them 2.0.0 is the first version, there is nothing to upgrade.

---

## 0. Procedure

1. **Read §1 against your `override/` tree first.** In z77 an override REPLACES the framework's
   file whole — a class (PSR-4: the project path wins), a template (first FileFinder match wins), a
   config (`override/…/Config/*.inc.php` replaces the framework's file, no merge). Every file you
   override carries the 1.6.0 state and none of the changes below. List them and diff each against
   the framework:

   ```bash
   # in the project
   find override -type f | sort
   # in a monorepo checkout, per overridden path P (e.g. packages/module-backend/res/view/templates/partials/shell/topbar.tpl.php)
   git diff 1.6.0..2.0.0 -- P
   ```
2. Change the requires: `"z77/kernel": "^2.0"`, `"z77/module-frontend": "^2.0"`,
   `"z77/module-backend": "^2.0"`, `"z77/module-dms": "^2.0"`, `"z77/docs": "^2.0"` (require-dev).
3. `composer update` (not `install`: the lock changes, and the new `vendor/bin/z77-restore` proxy
   only appears through `update` — `packaging.md` PKG-005). Read the installer's output: it names
   the navigation entries it added and the `public/` assets it refreshed, kept or removed (§4).
4. `php .releases/check.php` — names every config key your overrides lack (PKG-OVERRIDE-001, §1 B13).
5. Work through §1, then smoke-test: `/`, every starter page you use, `/login`, logout, one
   save in the backend, one Drive upload, one mail (contact form), one backup.

---

## 1. Breaking changes

Ordered by how likely a project hits them.

### B1 — Backup config: the `database` block is refused (kernel)

`config/client/backup.inc.php` no longer holds the connection. The `db` backup (and the SQL dump
inside the `full` archive, new in 2.0) reads `config/client/database.inc.php` (ADR-039 decision 4)
— the installer seeds that file on the update, with `name` empty = no database. A backup config
that still carries `'database' => [...]` makes every `db` backup FAIL with
«config/client/backup.inc.php still carries a 'database' block …»; data backups still run.

**Do:** move `host`, `port`, `name`, `user`, `pass` → `password` into
`config/client/database.inc.php`; in the backup config keep only
`'dump' => ['mysqldump' => 'mysqldump', 'mysql' => 'mysql', 'user' => null, 'password' => null]`
(a read-only backup user goes into `dump.user` / `dump.password`). Without a database: delete the
`database` key. Check: `php vendor/bin/z77-backup db` (or the backend Service › Backup).

### B2 — Logout is POST with the CSRF token; a GET logout answers 404 (kernel, backend, frontend)

SEC-GET-001: nothing signs out or changes state on a GET. The backend topbar, the frontend admin
overlay and the member account panel post a form with the session's token.

**Do:** every own logout link (`<a href="…/logout">`) in an override — typically
`partials/shell/topbar.tpl.php` (backend), `partials/adminOverlay.tpl.php` (frontend) or a project
header — becomes a `<form method="post">` with the CSRF token field; copy the 2.0 markup of the
framework partial. Check: logout works; `GET /backend/system/login/logout` → 404.

### B3 — Starter pages read content documents; stock pages without them answer 404 (frontend)

`IndexController` no longer passes `contentHtml` / `content`. Every starter action (`home`,
`about`, `services`, `contact`, `thanks`, `legal`, `privacy`) reads the page's content document
through `PageContent` and its structure from `frontendConfig` → `contentBlueprints`, and passes
section values (`hero`, `intro`, `cards`, …). A page without an active document throws
`NotFoundException` → **404**. Starter content is seeded on the FIRST install only
(`Install::writeDataFiles()`, STARTER_DATA_DIRS) — an existing `data/content/` never receives the
2.0 documents.

- A project that overrides `IndexController` itself: unaffected (its copy is whole).
- A project that overrides only the action TEMPLATES: they no longer get `$contentHtml` /
  `$content` → empty output. Override the controller too, or adapt the templates to the section
  variables.
- A project on the stock controller and templates: `services`, `contact`, `legal`, `privacy`,
  `thanks` had no document in 1.6 and now answer 404; `home` / `about` documents have the old
  shape. Create the documents in Webseiten › Inhalte (or copy
  `vendor/z77/kernel/core/data/content/*.default.json` to `data/content/<slug>.<lang>.json`), or
  override the controller.

Also: the contact form redirects to `/thanks` (PRG) and `/thanks` is a navigation entry in the new
slot `frontend-route` («Ohne Menü»).

### B4 — Default frontend languages `de, en, fr` → `de, en`; French starter content removed (frontend)

`frontendConfig` → `languages` is `['de', 'en']`; `about.fr` / `home.fr` default documents are gone.
**Do:** a project that offers French and does not override `frontendConfig.inc.php` adds the
override with `'languages' => ['de', 'en', 'fr']` (the global `config/client/i18n` whitelist is
unchanged). Existing French documents in `data/content/` stay.

### B5 — Aliases match exactly; slug translation only on the alias part (kernel routing)

ADR-014/015 addendum (2026-09-17), `Routing\AliasPathResolver`:
- An alias matches its exact path. `/kontakt/foo` is **404** unless the alias has
  `accepts_slugs` = on (new field, default **off** for every existing alias).
- Slug translation applies only to the part that resolves to an alias; technical URLs
  (`/fr/frontend/main/index/…`) are never localized and no longer 301.
- The alias validator refuses a path starting with a module key and a translated slug equal to a
  module name; `accepts_slugs` must be the same on every alias of one navigation entry.

**Do:** every alias that serves a rest (`/referenzen/<name>`) gets «accepts slugs» in Webseiten ›
Navigation › Nav Alias. Check each localized link of the site once.

### B6 — Security headers on every HTML answer (kernel)

SEC-HEAD-001, `Http\Response\SecurityHeaders`: enforced `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`,
`Content-Security-Policy: frame-ancestors 'self'`; observing only
`Content-Security-Policy-Report-Only` (`script-src 'self'`, …). A page framed by another origin is
blocked.

**Do:** if the site must be framed elsewhere or needs another value, record only the deviation in
`config/client/securityHeaders.inc.php` (optional, not seeded; header => value, `null`/`''`
switches one off). A `Header always set` in `public/.htaccess` wins over PHP. Open the browser
console once: report-only violations name inline scripts and foreign origins your templates use.

### B7 — Active content from the DMS is never served inline (dms, kernel)

DMS-ACTIVE-001: HTML, SVG, XHTML documents are delivered under a type allowlist
(`DeliveryPolicy`) — not as active pages. A public folder's static copy under `public/media` is
made only for a safe extension AND a safe type; each DMS partition under `public/media` gets its
own guard `.htaccess`, and on the first write of that guard unsafe files that belong to a document
are swept. Executable extensions are refused at upload (DMS-MEDIA-PHP-001,
DMS-UPLOAD-EXT-001).

**Do:** a site that linked an uploaded HTML/SVG file as a page or an `<img src>` SVG: check it
still shows; serve such files from the project's own assets instead of the Drive. Hand-placed files
in `public/media` outside DMS partitions are not touched.

### B8 — CSRF: an empty token is refused (kernel)

SEC-007: a session without a token used to compare `''` with `''` and pass. **Do:** every own
form that posts to a `#[Csrf]` action renders the token (`DI::getCsrfService()->getToken()`).

### B9 — Frontend admin overlay: new context, shown to editors (frontend)

`AbstractFrontendController` passes `overlayForm` (`returnPath`, `csrfToken`), `overlayInfo`
(route info, admin only), `overlayEdit` (`on`) and `overlayDev` (now only `partialLabels`, DEBUG
only); the overlay appears for the new role `editor` too (rail «redaktion»). **Do:** an
overridden `partials/adminOverlay.tpl.php` that reads `$overlayDev['csrfToken']` /
`['returnPath']` breaks — re-copy the 2.0 partial (it also carries the POST logout, B2).

### B10 — Frontend layout partials (frontend)

- `partials/header` and `partials/footer` render the brand through the shared
  `partials/brandMark` (`Z77\Shared`) — the place a project replaces its logo now.
- The footer's tagline and copyright come from the content document `footer`
  (`Ui\Services\FooterContent`); the dictionary keys `footer.tagline` / `footer.rights` and the
  hard-coded «v1.0.0» are no longer used.
- `partials/head/fonts` is empty (Inter from Google Fonts removed, CSS-FRONTEND-FONTS-001).
- `partials/head/meta` / `social` / `seo` read the module config block `site` (`name`, `author`,
  `locales`, `image`, `Libraries\Seo\SiteIdentity`): no more «Max Mustermann» author or
  example.com Open Graph; the `<title>` fallback is `site.name` (was «Z77 Framework»). Every
  template gets a `$site` variable — a template variable of that name is shadowed.
- `html-default-skeleton` renders a new body slot `preview` (`layoutConfig`); an overridden
  skeleton without `<?= $preview ?? '' ?>` shows no preview notice.

**Do:** set `'site' => [...]` in the frontend config override; check overridden header, footer,
head partials and skeleton against 2.0.

### B11 — Backend screens: action cell, toolbar, dialogs (backend)

- «+ Hinzufügen» moved from the action cell to the toolbar (`list.act.tpl.php`): the seven
  `list.hc1.tpl.php` templates are deleted (Content, NavigationAlias, Navigation, Translation,
  Drive, Backup, BackendUser). An override of one of them still renders — a second, stale add
  button.
- Dialogs follow ADR-049: a fixed action row on top, «Schliessen» right-aligned, × in the corner;
  a save answers with the row by fetch instead of a reload; row menus `⋮` are context menus
  (`data-menu-open`). Overridden `edit` / `actions` / `confirm*` templates of backend controllers
  must be re-copied.
- Removed CSS classes: `.be-modal__footer`, `.be-module-card*`, `.be-overview__greeting`,
  `.be-overview__modules`, `.be-shell--tabs`, `.be-shell-topbar__search*` (the overview page
  rebuilt as the dashboard of ADR-052; the topbar's search styles and its bell are gone).
- Shell: `html-shell-skeleton` and `partials/shell/topbar` changed (installation name from
  `systemConfig` → `installationName`, POST logout, no bell).

**Do:** delete overrides of the removed `list.hc1` templates; re-copy any overridden backend
template and adapt it.

### B12 — The Drive's own upload is gone; one shared upload component (dms, kernel, backend)

UPLOAD-001: `module-dms/res/assets/js/documents/upload.js` (+ `.min.js`) and
`res/scss/components/_upload.scss` are deleted. Every upload uses `partials/upload` /
`partials/uploadField` and the script `upload` (`Z77\Shared`), registered in the backend
`layoutConfig`; the Drive's target folder and «Original ausliefern» travel inside the form.

**Do:** an overridden backend `Ui/Config/layoutConfig.inc.php` adds
`['name' => 'upload', 'nameSpace' => 'Z77\\Shared', 'defer' => true]` to `javascripts`; an
overridden Drive `_upload.tpl.php` is re-copied; own SCSS that imported the dms `_upload` partial
drops it.

### B13 — Overridden configs miss the keys 2.0 added (all four)

A config override replaces the file whole (PKG-OVERRIDE-001). `php .releases/check.php` names
the missing top-level keys, `forms` and `jobs`. What 2.0 added:

| Config | New keys / entries | Without them |
|---|---|---|
| `backendConfig` | `groupDefaults` `finance`, `contact`, `order`; `viewAreaEntry`; `controllers` entries with `AuthRole::EDITOR` (Dashboard, SystemController `savePreferences`, Assistant, BackendUser 2FA, Content, Document preview, Drive picker); Finance entries; `StatsController`; `reservedRoutes` `/stats/report`; `dashboard`; jobs `password-reset-cleanup`, `stats-rollup` (ships `daily@04:40`), `stats-report-mail` | editors locked out, dashboard empty, statistics never rolled up (raw files are not deleted after 7 days — a privacy promise), report link 404 |
| backend `Ui/Config/layoutConfig` | script `upload` | uploads fall back to plain forms (B12) |
| `dmsConfig` | `localFileResolvers`, `contentBlocks`, `contentDocuments`, `principalRolesHook`, `extraUploadTypes`, `chunkBytes`, `maxUploadBytes`, `chunkedDownloadFrom`, `downloadChunkBytes`, `zip*`, `accountRemovedListeners`, `activityHook`, jobs `dms-chunk-cleanup`, `dms-zip-prepare`, `dms-zip-cleanup` (ships `daily@03:50`) | the code defaults apply to most; media blocks and the media picker are missing, ZIP leftovers are never cleaned |
| `frontendConfig` | `contentBlueprints` (B3), blocks `TextSection`, `Cards`, `ImageText`, `Cta`, `Form`, `Footer`; `navSlots` `route`; `AdminPanelController` action `toggleContentEdit` (EDITOR); `languages` default changed (B4) | starter pages 404, `/thanks` has no slot, editors cannot switch page editing |
| frontend `Ui/Config/layoutConfig` | body slot `preview` | no preview notice |
| `emailConfig` (shared) | forms `statsReport`, `backendCodeLock`, `apiLoginCap` | the statistics mail has no recipients; the 2FA lock alarm goes to nobody |

### B14 — Every mail carries the e-mail standard (kernel mail)

`emails/layout` (shared) now adds a logo slot (`data-z77-inline="logo"`, filled by
module-mandator when installed), the note «Diese Nachricht wurde maschinell erstellt.»
(`MailClosing`, in the mail's language) unless the body marks itself `data-z77-closing`, a company
foot when a `MailCompany` is registered, and `<html lang>` = the mail's language. Your own mail
bodies (contact form, notifications) get the note.

**Do:** if a body closes itself, add `data-z77-closing` to it; to change the frame, override
`emails/layout` (and re-copy it from 2.0 — the logo and foot slots live there).

### B15 — Web statistics count every public page view (kernel)

`StatsRecorder::observe()` (Dispatcher) writes one line per counted page view of a `public`
module to `logs/stats/stats-YYYY-MM-DD.jsonl` — no switch; editors/admins, bots, non-GET, fetch,
non-HTML and pages marked `data-stats="off"` are not counted. No cookie, no IP stored (a daily
salted key), raw lines deleted after 7 days by `stats-rollup`, aggregates after 24 months.

**Do:** the project's privacy text must describe it from the day of the upgrade — copy and adapt
the paragraph in `docs/topics/stats.md` («privacy paragraph»). Check that `stats-rollup` is in
Service › Jobs and your cron runs `z77-run`.

### B16 — PHP API: changed signatures that break subclasses and implementers (kernel, backend, dms)

Callers are unaffected (only optional parameters were added); a project CLASS that extends or
re-declares one of these must match the new signature:

| Class::method | 1.6.0 | 2.0 |
|---|---|---|
| `Core\Services\NavigationService::resolveUiCurrent` | `(?int $refId): void` | `(?int $refId, array $memory = []): array` |
| `Core\Services\NavigationService::resolveFirstNavigable` / `getViewAreas` | no filter | `?callable $allows = null` added |
| `Core\Routing\Router::matchAlias`, `NavigationUrlResolver::matchAlias` | `(array $segments)` | `+ ?array $alternative = null` |
| `Shared\Backup\BackupService::__construct` | `($baseDir, $config, ?DbDumperInterface $dbDumper)` | `($baseDir, $config, array $database, ?DbDumperInterface $dbDumper)` — a dumper passed as 3rd positional argument is now a TypeError; use `BackupService::fromProjectRoot()` |
| `Persistence\Interface\EntityManagerInterface` | — | new `getTransaction(): TransactionInterface` (an own entity manager must implement it) |
| `Shared\Services\ContentService::find` / `render`, `ContentRepository::findBySlug` | `(slug, language)` | `+ variant` |
| `Shared\Services\AuthService::establishMemberIdentity` | 3 parameters | `+ ?string $language = null` |
| `Shared\Mail\Message::replyTo`, `EmailMessage::replyTo` | address only | `+ string $name = ''` |
| `Shared\Mail\Attachment::__construct` | 3 parameters | `+ ?string $contentId = null` (validated) |
| `Shared\Content\InlineMarkdown::toHtml`, `Libraries\ConfigLocator::path`, `Backup\ZipArchiver::zipDirectory`, `Import\ImportServiceFactory::sourceSpec`, `Api\ApiResult::error` | — | one optional parameter each |
| `Backend\…\System\LoginController::logoutAction` | `RedirectResponse`, GET | `HtmlResponse\|RedirectResponse`, POST only (B2) |
| `Backend\…\Service\JobController::scheduleAction` | `FetchResponse` | `HtmlResponse\|FetchResponse` |
| `Dms\Services\DocumentService::forModule` / `serve` / `purge` / `invalidateMaterializedFolder` | — | one optional parameter each |
| `Dms\Services\SaveService::saveFromUpload` / `replaceFromUpload` | — | `+ bool $isHttpUpload = true` |

No class, public method or global helper of the four packages was removed.

---

## 2. Behaviour changes you will notice (no action unless stated)

- **Language memory** (I18N-SWITCH-DEFAULT-001): a page view without a language prefix forgets
  the remembered language — the session holds the language of the last page seen.
- **Errors:** an unhandled exception answers 500 (not a 200 error page); the setup page is
  reachable without `canonicalBaseUrl`. A module without `defaultGroup` answers its URL with 404.
- **Login throttle** (SEC-004): 5 failed logins per username + IP and 30 per IP within 15 minutes
  block further attempts; tunable in `config/client/auth.inc.php`
  (`loginFailuresPerUsernameIp`, `loginFailuresPerIp`).
- **File storage** (DATA-MERGE-001): a save merges column-wise with what is on disk (two editors
  no longer overwrite each other's fields); a row deleted since it was read is not re-created; ids
  of deleted rows are never reused (sidecar `<collection>.highest-id`).
- **APCu** is used only when loaded AND enabled (`apc.enable_cli=1` for CLI); otherwise the
  per-request cache (CACHE-APCU-CLI-001). The unused `apcuCachePrefix` bootstrap key is gone; a
  leftover is ignored.
- **Jobs:** `z77-run` prints nothing on a healthy pass — cron mails only on an error.
- **Backup:** the `full` archive carries the SQL dump when a database is configured; CLI archive
  names use the installation's time zone; the backend refuses downloads above 500 MB (use SFTP);
  `z77-restore` reads a dump back (CLI only, RESTORE-001).
- **Backend look** (ADR-051): palette «Salbei» is the default (a stored preference keeps its
  palette); fonts Instrument Sans / IBM Plex Mono self-hosted, Inter removed.
- **Navigation:** deleting an entry also deletes its aliases and metadata in all languages and
  is refused while a reference entry points at it.

## 3. Requirements

- **PHP:** unchanged `>=8.2` for the four packages; deprecations of PHP 8.5 removed
  (`setAccessible()`, `imagedestroy()`, `finfo_close()`). Not run on an 8.2 runtime for this note;
  a search for 8.3/8.4-only functions and syntax in the four packages found none.
- **Declared extensions:** unchanged (`mbstring`, `fileinfo`, `zip`; `gd` for module-dms).
- **Used but not declared** (in every default PHP build; release decision D3): `openssl` (backend
  2FA, `TotpVault`), `iconv` (`Shared\Pdf\PdfDocument`), `simplexml` (camt parser — business
  modules only). `intl` is optional (Unicode normalisation of ZIP entry names when present).
- **Binaries:** `mysqldump` and, for `z77-restore`, `mysql` — only with a database, resolved via
  `PATH` (`dump.mysqldump`, `dump.mysql`).

## 4. Installer behaviour on the update

- Seeds `config/client/database.inc.php` once (empty `name` = no database). The other seed-once
  configs are untouched.
- **Navigation seeds** (ADR-050): on every install/update the installer ADDS each menu entry a
  package ships in `navigation.d/*.json` that `data/framework/routing/navigation.json` does not
  hold — recognised by `key`, then route, then parent + ref; placed at the end of its siblings;
  nothing existing is changed. The output lists each added entry («+ «…»»). Check the backend
  menu after the first update; a container your 1.6 installation has without a `key` may appear a
  second time — delete the duplicate.
- **`public/` assets** (INST-ASSET-DIFF-001, INST-ASSET-002): a publication record
  (`var/state/published-assets.json`, written by the installer) lets the update refresh unchanged copies without
  asking and remove copies a package no longer ships. A 1.6.0 installation has no record yet, so
  the update cannot know which old files were its own: files that 2.0 dropped (backend
  `fonts/inter/`, dms `js/documents/upload.js` / `.min.js`) stay under `public/assets/` — delete
  them by hand. A copy you changed is never overwritten silently.
- Starter content (`data/content/`) is seeded on the first install only (B3).
- A new project gets `src/Entities`, `src/Repositories`, `src/Validators`, `src/Services`,
  `src/App/Config` as override tree (no `src/Dom`); existing folders are untouched.

## 5. `data/` and storage

No database migration for the four packages; a web project still needs no database.
New files and directories appear on demand — all inside `data/`, `logs/`, `var/`, so the existing
backup and deploy rules hold:

- `data/framework/auth/totp.key` (encrypts the 2FA secrets — losing it invalidates every enrolled
  2FA; it is in the `data` backup), `passwordResets.json`, `alert/`;
- `data/framework/stats/` (aggregates), `logs/stats/` (raw lines, left out of backups);
- `data/help/` (admins' «Hilfe ergänzen» additions), `data/kibot/help/` (generated, with kibot);
- `data/dms/chunks/`, `data/dms/zips/` (chunked uploads, ZIP bundles);
- `data/framework/jobs/locks/`, `last-pass.json`;
- `<collection>.highest-id` sidecars next to file collections;
- new fields read with defaults from old rows: `Content.variant` (content variants, ADR-044),
  `NavigationAlias.accepts_slugs` (B5), user preference `content_edit`, backend-user 2FA fields.

---

## 6. New, optional

Nothing here is needed to keep a 1.6 project running.

- **Roles and editing:** role `editor` (ADR-045) — writes website text, page editing on the
  website (pen per section), a version on every live save; content blueprints, variants and
  preview links (ADR-044); `partials/previewBar`; the media field and picker for DMS images in
  content.
- **Login:** optional TOTP 2FA with admin reset (BACKEND-2FA-001); «Passwort vergessen» with a
  one-time link (needs a working mail config).
- **Backend:** dashboard (ADR-052), help as a framework service (ADR-048) with «Hilfe ergänzen»,
  KI-Assistent (ADR-055, with `z77/kibot` and its config), controller-driven windows (ADR-047),
  the list standard `Z77\Shared\Listing` (sorting, column search, paging, «Zurücksetzen»),
  `installationName` in `systemConfig`.
- **DMS:** chunked upload and download for large files, ZIP of a selection (with its hooks and
  mails), `extraUploadTypes`, `principalRolesHook` (project roles for members), `activityHook`,
  «Pfad kopieren», player dialog for large media, retention dates on documents.
- **Kernel building blocks:** `Shared\Money` (integer money, ADR-042), the PDF facade over vendored
  FPDF (`pdf/report`, `pdf/table`), `Shared\Payment` (IBAN, QR/SCOR references, camt parser),
  `Files\WriteOnceStore`, `LocalFiles` resolvers, the upload component, `bytes()` response
  helper, sender identities per user («Senden als»), inline mail images, the transaction port of
  the entity manager (with `persistence-doctrine`), web statistics report page, 10-day link and
  monthly mail (`config/client/stats.inc.php` `reportKey`), Lottie component (opt-in scripts),
  `config/client/securityHeaders.inc.php`.
- **Frontend:** starter block types (text section, cards, image + text, CTA, form, footer), the
  `site` identity block, account language for members (ADR-056).

---

## 7. Not covered

- **Per-template diffs.** B11 names the pattern; each of the ~70 changed backend and dms
  templates was not compared one by one. Use the procedure in §0 for every template you override.
- **SCSS / CSS tokens.** Backend tokens changed with ADR-051 (palettes, `--be-*`), the shared
  `_input-look.scss` replaced five copies; only removed `.be-*` classes of `base.css` were checked
  (B11), frontend `fe-*` classes were checked and none was removed. A project that compiles its
  own backend SCSS from `vendor/` sources must rebuild and compare.
- **JavaScript.** `core.js` dropped no `data-*` attribute (checked); behaviour changes of
  `core.js` (live listeners, dirty forms, windows) were not audited for projects with their own
  scripts.
- **i18n keys.** Dictionary keys added or no longer used (e.g. `footer.tagline`) were not listed
  exhaustively.
- **module-member, module-api, kibot and the business modules** — first release, no 1.x
  installation exists.
- **A real upgrade run.** These notes are derived from the diffs; they have not yet been tested by
  upgrading a 1.6.0 project. Do that once (release plan step 5.4) and correct this file.
