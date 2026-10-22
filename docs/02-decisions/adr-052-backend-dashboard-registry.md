# ADR-052 — Backend dashboard: tiles from the menu, entries from a module registry

**Status:** `[APPROVED]` — board approved by the owner 2026-10-22 (`dashboard-bauplan`), the open
questions answered the same day (see «Owner decisions 2026-10-22»)
**Date:** 2026-10-22
**Builds on:** [ADR-045](adr-045-editor-role-and-frontend-editing.md) (the menu follows access,
`canReach()`), the open-work registry of `persistence-doctrine` (`OpenWorkChecks`, ADR-039
decision 15), [ADR-048](adr-048-help-service.md) (help), [ADR-047](adr-047-controller-led-windows.md)
(windows)

---

## Context

The backend entry page (`DashboardController::overviewAction`) showed two hard-coded cards
(«Frontend», «Stammdaten») from a `CARDS` constant. It said nothing about the work waiting in the
installation — overdue invoices, a dunning proposal, a missing fiscal year, an old backup — and a
new module could not appear on it without editing `module-backend`.

The board asked for: one tile per menu section with its pages as links, the modules' pending work
as badges, a «+ Neu» button where a capture is used daily, and one pending list across all modules.
Every user sees only what he may open — like the menu.

## Decision

1. **Registry key `dashboard`.** A module declares a list of contributor classes under the config
   key `dashboard`; additionally every extension file `App/Config/dashboardConfig.inc.php` of the
   module (`ModuleManager::getConfigExtensions()`) returns a list of classes — how a project adds
   its own without copying the module config (Rule 2). Kernel classes in `Z77\Shared\Dashboard\`:
   `DashboardContributor` (interface, `entries(AuthUser $user): iterable<DashboardEntry>`,
   instantiated without arguments), `DashboardEntry` (value), `Severity` (enum),
   `DashboardContributors` (the registry). A bad entry — missing class, wrong interface,
   constructor arguments, not a list — throws at the first page load, naming module and file.
2. **Two entry kinds.** `DashboardEntry::pending(Severity, label, route, query, count?, amount?)`
   is open work: a badge on its tile and a row of the pending list. `DashboardEntry::create(label,
   route, opens)` is a «+ Neu» button in the head of its tile; `opens` is `page`, `window`
   (`data-window-open`) or `dialog` (`data-fetch-get`) — the attribute the list's own «+ Neu»
   carries. The amount is a `Money`, shown as «CHF 3'870.00» (`AmountFormat`).
3. **Two weights only:** `Severity::Urgent` («dringend») and `Severity::Todo` («zu erledigen»).
   There is no info kind (owner 2026-10-22).
4. **Placement by route.** An entry names only its target page `[module, group, controller,
   action]` (+ query). The dashboard finds the tile whose menu section holds that route — exact
   route first, then the same controller (a create entry's `add` is not in the menu, its list
   is). A module never names a section; moving a page in the menu moves its badge. A pending
   entry no tile holds still appears in the pending list; a create entry without a tile is
   dropped.
5. **Tiles come from the menu.** `DashboardBoard::tilesFrom(BackendMenu)` walks the ONE
   access-filtered menu (`BackendMenu::forCurrentUser()`, the instance the shell reuses); no
   second navigation list.
6. **Access = `canReach()` per entry.** Every entry is kept only when `AuthService::canReach()`
   lets the user open its target — the check the menu makes. A contributor does not filter
   itself and cannot forget it. An editor sees «Webseiten» and no debtor number.
7. **Failure isolation.** A contributor that throws — or yields something else than a
   `DashboardEntry` — is logged (`error_log`, class + module + exception) and replaced by ONE
   line «Pendenzen aus Modul «…» nicht verfügbar» (todo, no link); the other contributors'
   entries and the page stand.
8. **No cache.** One cheap aggregate per entry (a COUNT or a SUM, never a list loaded to be
   counted); the numbers are live on every load.

## Owner decisions 2026-10-22

- The pending list stands BELOW the tiles.
- No action bar: «+ Neuer Auftrag» / «+ Neuer Kontakt» stand in the tile head.
- Amounts are shown for the overdue invoices (count + open amount).
- No counters where nothing is to be done — no info kind, no contact count, no open-receivables
  total. Open quotes are a todo («Offerten nachfassen»), invoice drafts in `invoicing` too
  («Rechnungsentwürfe nicht verbucht» — they still need the booking print).
- Backup: daily — the warning appears when the newest backup is older than 1 day; the threshold
  is the policy key `maxAgeDays` of `config/client/backup.inc.php` (default 1, one place).
- «Zuletzt bearbeitet» as an own zone: later, a second stage.

## Reasoning

- The pattern of `openWorkChecks` / `doctrineEntities`: registration is configuration, visible in
  one line per module, fails at the first question — a runtime `register()` would depend on boot
  order.
- Placement by route keeps the module ignorant of the menu, which is installation data and moves.
- The same `canReach()` as the menu means one answer to «who sees what»; a number is visible
  exactly when the page behind it is.
- Isolation: the entry page is the page every user opens first; one broken module must not take
  it down.

## Consequences

- `DashboardController::CARDS` is gone; the page is built from `BackendMenu` + the registry
  (`DashboardBoard`).
- Every contributor is a small class per module (`{Module}\Dashboard\{Name}Dashboard`); the first
  stage: debtor, order, contact, financial, mandator, and module-backend's service section
  (backup, jobs). Each module's topic doc lists what it contributes.
- A new pending entry needs a cheap query; a repository method may be added for it
  (`BankTransactionRepository::countUnbooked()`).
- Running the queries for a user who will not see their result (an editor) costs a few
  aggregates — accepted; the access check stays in one place.
- The failure line names the module even to a user who cannot open that module's pages —
  accepted, it carries no data.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep a hard-coded card list in the controller | A module cannot appear without editing module-backend (Rule 9); a second «who sees what» list next to the menu |
| Modules name their tile / section | The menu is installation data; a moved page would leave its badge behind |
| The contributor filters by access itself | Every contributor would repeat (and could forget) the check |
| An info kind for plain counters | Owner 2026-10-22: the dashboard shows what is to be done |
| Cache the counts (APCu, short TTL) | Stale numbers on the page that is meant to say «what is open now»; the aggregates are cheap |
