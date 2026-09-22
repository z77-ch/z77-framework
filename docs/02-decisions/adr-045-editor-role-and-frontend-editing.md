# ADR-045 — Editor role, versions on every live save, editing on the page

**Status:** `[APPROVED]` — decisions by Peter 2026-09-22 (zihlundsee session); build in progress on `feat/frontend-editing`
**Date:** 2026-09-22

---

## Context

Since ADR-044 every designed page reads a content document with a fixed, keyed
structure (blueprint). Editing happens in the backend list «Inhalte», which
means: find the right document, open it, find the right slot. The person who
writes the text (in an SME: the owner, an assistant) thinks in pages, not in
slugs. And the backend is ADMIN-only: there is no role that may write text but
not touch users, navigation, backups or jobs.

Three facts from the code (2026-09-22):

- Roles are one linear level each (`AuthRole::getRoleHierarchy()`: guest 0 …
  member 20, cronJob 30, admin 80, superUser 100). Who may call what is already
  config: `moduleRole` / `controllers[group][Controller]['controllerRole' |
  'actions']` in the module config, resolved by `AuthService`.
- The backend menu is **not** filtered by role (`NavigationService::getViewAreas()`
  says so explicitly): a user below a target's role sees the link and gets the
  login redirect on click.
- The content editor is a backend modal (backend CSS, icon sprite, popup dialog,
  CSRF meta tag) — none of that exists on a frontend page.

## Decision

### 1. Role `editor` («Redaktor»), level 50

A new AuthRole between cronJob (30) and admin (80). What it may reach is the
existing access config, nothing new: the backend keeps `moduleRole => ADMIN`, and
a project (or the framework default) lowers single controllers/actions to
`editor` — today the content editor (list, edit, add, variants, publish) and the
dashboard. **Deleting** (documents, variants, versions) stays ADMIN.

### 2. The menu follows the access config

A backend menu entry is shown only if the current user's role satisfies the role
the target controller/action requires — the same `AuthService` resolution the
AccessGuard applies. One source of truth: making a screen reachable for editors
(config) makes it visible for them; there is no second «who sees what» list that
could disagree with who may call what.

### 3. Every live save keeps the previous state as a version

Whoever saves a live document (backend editor, frontend editor, publishing a
set): the stored live copy is first archived as a one-document variant with the
key `v-YYYYMMDD-HHMMSS`. Documents carry `changedBy` (username) and `changedAt`
(ISO time) of the state they hold, so a version reads «Stand vom 22.09.2026 15:30
(anna)». Rollback = publish that version (which itself archives the current live
copy first). Only ADMIN may delete versions. Nothing expires on its own.

### 4. Editing on the page

For a user with at least `editor` who switched it on, a frontend page renders
one edit button per blueprint **slot** (intro, FAQ table, …); see «Addendum»
below:

- **Markers:** a template marks a slot's root element with the attribute
  `ContentView::editAttribute($slot)` returns — `data-content-edit="…"` for an
  editor, `''` for everyone else (so a visitor's HTML is unchanged). Pages behind
  a preview (`?preview=`) mark the variant, otherwise the live copy.
- **The editor opens in an iframe** over the page: a backend URL
  (`/backend/content/content/slot?…`) rendering the existing blueprint editor for
  that ONE slot on a bare backend page. The backend page brings its own CSS and
  JS; the site's stylesheet cannot break it and it cannot break the site.
- **Saving one slot** merges the posted slot into the stored blocks server-side
  (the other slots are never taken from the post) and goes through the same
  validator, optimistic lock and version rule as the backend editor. On success
  the iframe tells its parent (`postMessage`, same origin only) and the page
  reloads — what you then see is what is stored.
- **No page cache for editors:** `PageCachePolicy` bypasses the cache for
  `editor` and up (today: admin and up), or an editor would get the cached
  visitor page without buttons.

### Addendum (2026-09-22, same day): overlay for editors, the switch, the pencil

Peter's test on `next` as «Redaktor» showed two gaps:

- **No way back.** The admin overlay (backend link, logout) was ADMIN-only; an
  editor on the site could neither reach the backend nor log out. → The overlay
  shows from `editor`. What it offers follows §2: the view areas are filtered
  with the same access rule as the backend menu (`NavigationService::entryAllowedIn()`
  over `AuthService::canReach()`); routing info and the dev tools (partial
  labels) stay ADMIN.
- **Buttons everywhere.** The «Bearbeiten» text buttons sat on every page as
  soon as an editor was logged in. → (a) a small pencil icon instead of text
  (accessible name «<Slot> bearbeiten»); (b) a switch «Seite bearbeiten» in the
  overlay, **off by default for everyone**, stored per user and view area in
  `UserPreferences` (`content_edit`), toggled by a form POST
  (`AdminPanelController::toggleContentEditAction`, role EDITOR). Off = no
  markers and no `content-edit.js`: the page is what a visitor gets, plus the
  overlay.

One decision for both halves: `PageEditing::active()` (full page + role >=
editor + switch on). `PageContent` marks the slots from it,
`AbstractFrontendController` adds the script from it. Not tied to DEBUG: it is
an editing tool, not a development tool. The switch is a visible user
preference, not hidden state: it is shown where it acts and says what it does.

## Reasoning

- **Live save for editors (not variants only):** in an SME there is often no
  second person to approve; forcing a variant + an admin to publish makes the
  tool unusable (Peter). The version on every save makes any save undoable, and
  only the admin can remove that safety net.
- **Menu from access config:** a separate visibility list would drift from the
  access rules; showing links a user cannot follow is the current behaviour and
  the worst of both.
- **Iframe over re-implementing the editor on the page:** the editor exists and
  is tested (blueprint mode, field profiles, lock). Loading backend CSS into a
  frontend page would clash with the site's resets both ways; an iframe isolates
  them at zero cost.
- **Per slot, reload after save:** the page templates, not JavaScript, turn field
  values into markup; writing text into the DOM from JS would show something the
  server never rendered.

## Consequences

- New AuthRole constant + label; the backend user admin lists it automatically
  (ROLE-DEF-001).
- `NavigationService` (or the backend topbar/subnav) gets a role filter.
- `Content` gains `changedBy` / `changedAt`; `ContentVariantService` gains the
  version rule and becomes the one place a live copy is written.
- Every designed page template needs one attribute per slot (project work, one
  line each); pages without markers simply show no buttons.
- Editors see fresh, uncached pages — as admins already do.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Editors may only write variants, an admin publishes | Not practicable in an SME (Peter, 2026-09-22). |
| contenteditable directly on the page | Field profiles (formatting, links) and the page templates decide the markup; JS-inserted text would differ from the render. |
| Editor modal in the frontend DOM with backend CSS | Backend base.css resets and site CSS clash in both directions. |
| Separate per-role menu config | Second source of truth next to the access config. |
| Capability/permission system instead of a level | Not needed for one new role; the level model plus per-controller config covers it. Revisit if roles stop being linear. |

## Build status

2026-09-22, `feat/frontend-editing`: §1 built (role, label, access config) · §2 built (`BackendMenu`, shared `AuthService::requiredRole()`/`canReach()`) · §3 built (`ContentVariantService::saveLive()`/`restore()`, one `v-…` rule also for publish) · §4 built: markers (`ContentView::editAttribute()`, set by `PageContent` for EDITOR+ on full pages via `forEditor()`), slot editor (`ContentController::slotAction` on `html-bare-skeleton`, `edit.tpl.php` in slot mode, `Blueprint::enforceSlot()`; a live copy shown in a preview is saved into the preview set), iframe + buttons (`content-edit.js`, loaded by `AbstractFrontendController` for EDITOR+), parent message via the new core command `post-message`, page-cache bypass (`PageCachePolicy` from EDITOR). First consumer: zihlundsee.ch (all pages and shared blocks marked). Open: not tried in a browser by a person yet (headless Edge only); framing headers must allow same origin once security headers come (security.md pending). Details: [`../topics/content.md`](../topics/content.md) «Editing on the page».

2026-09-22, `feat/editor-panel` (addendum): overlay from EDITOR with access-filtered view areas (`NavigationService::entryAllowedIn()`, `BackendMenu::allowsIn()` delegates), switch «Seite bearbeiten» (`UserPreferences` `content_edit`, `AdminPanelController::toggleContentEditAction`, `frontendConfig` EDITOR), one decision `PageEditing::active()` for markers and script, pencil icons in `content-edit.js`. Harnesses: `tests/content-page-editor.php` (switch cases), `tests/backend-access.php` (overlay endpoint roles). Verified in zihlundsee with curl and headless Edge (editor + admin test users; guest pages byte-identical before/after). Details: [`../topics/backend.md`](../topics/backend.md) «frontend admin overlay», FE-OVERLAY-EDITOR-001.
