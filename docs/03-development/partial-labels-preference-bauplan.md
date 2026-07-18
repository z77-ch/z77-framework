# Bauplan — Partial-Labels als User-Preference (PARTIAL-LABELS-002)

**Status:** `[DONE]` — 2026-07-18 (Code + CLI-Verifikation; visueller Owner-Pass offen, siehe P5)
**Date:** 2026-07-18

Ziel: Der Partial-Label-Schalter wandert vom globalen Backend-Service-Panel-Flag
(`data/framework/partial-labels.flag` — gilt für alle Admins) in die
**UserPreferences des `LoginUser`**: jeder Admin entscheidet selbst, ob er die
Labels sehen will, **pro viewArea**. Admin A kann sie eingeschaltet haben,
während der gleichzeitig eingeloggte Admin B sie ausgeschaltet hat. Geschaltet
wird im **Frontend-Adminpanel** (Hover-Overlay); das Backend bekommt vorerst
keinen Schalter.

## Owner-Entscheide (2026-07-18)

| Frage | Entscheid |
|---|---|
| Speicherort | `UserPreferences` am `LoginUser` (gleiches Muster wie Palette/Darkmode/FontScale) |
| Granularität | pro viewArea (`partial_labels` = Map `viewArea → bool`, abwesend = aus) |
| Schalter-Ort | nur Frontend-Adminpanel (`adminOverlay`); Backend momentan ohne Schalter → Backend-Labels vorerst nie aktiv (Struktur lässt spätere Erweiterung zu) |
| DEBUG im Gate | bleibt zwingend — unter DEBUG ist der PageCache bypassed, Marker können nie in geteilte Seiten gecacht werden. Schalter wird ohne DEBUG nicht angezeigt |
| Doppelte Absicherung | CACHE-ADMIN-001-Fix (eigener Bauplan): Admin-Sessions nehmen generell nie am PageCache teil |
| Gleichzeitigkeit | Preferences pro Session/LoginUser geladen (`CurrentUserService`), Marker pro Render — unter DEBUG jede Anfrage frisch → per-User-Verhalten garantiert |
| Backend-Toggle | `SystemController::togglePartialLabelsAction`, Topbar-Zeile, `cache.js`-Wiring und Flag-File werden ersatzlos entfernt |
| Toggle-Mechanik | `<form method="post">` + Redirect zurück — **kein JS** (Rule 7; Overlay bleibt JS-frei, Reload ist erwünscht, weil die Labels sofort umschalten) |
| CSRF | Page-Mode-POST wird von `AccessGuard` nicht CSRF-geprüft (nur Fetch+POST) → Action validiert das Token selbst (`CsrfService::validate`, hidden field) |

## Neues Gate (`PartialLabels::active()`, alle drei)

```text
DEBUG  AND  Session-Rolle >= ADMIN  AND  preferences.partial_labels[viewArea] === true
```

- viewArea = Modul-Key des Requests (`DI::getRequest()->getModule()`) — per
  View-Area-Invariante ist der viewArea-Name der Modul-Key (ADR-022); funktioniert
  in Page- UND Fetch-Mode (kein NavigationService-Lookup nötig).
- Preference via `DI::getCurrentUserService()->getPreferences()` (DB-Read pro
  Request gecached); kein LoginUser (Gast/CLI/early boot) → inaktiv (try/catch
  bleibt).
- Inaktiv → Output bleibt byte-identisch (unverändert).

## Umsetzung

### Kernel

| Baustein | Änderung |
|---|---|
| `UserPreferences` (shared/ValueObjects) | neues Feld `partial_labels` (Map `viewArea → bool`, snake_case-Key, Rule 6); `isPartialLabelsEnabled(string $viewArea): bool`, `setPartialLabelsEnabled(string $viewArea, bool $on)`; `toArray()` persistiert die Map (leere Map = Key entfällt — deviation-only, Rule 2) |
| `PartialLabels` (core/Services) | `FLAG`-Konstante, `flagFile()`, `flagSet()` entfernt; `active()` implementiert neues Gate; Docblock neu |
| `Request` (core/Http) | neuer Getter `getRawRequestUri(): string` (Feld existiert schon) — Rule-4-konforme Quelle für den Return-Pfad des Formulars |
| Render-Seite (`TemplateRenderer`, `HtmlView`, `LayoutManager`) | unverändert — alle Call-Sites gehen durch `PartialLabels::active()` |

### module-frontend

| Baustein | Änderung |
|---|---|
| `Ui/Controllers/Main/AdminPanelController` (neu) | `togglePartialLabelsAction`, `#[Page, HttpMethod('POST')]`: CSRF-Feld validieren → Preference `partial_labels['frontend']` togglen (`CurrentUserService::savePreferences`) → `$this->redirect()` 303 auf den `return`-Pfad (validiert: beginnt mit `/`, nicht `//` — kein Open Redirect; Fallback `/`) |
| `frontendConfig.inc.php` | `controllers['main']['AdminPanelController'] = ['controllerRole' => AuthRole::ADMIN]` — spezifischer Eintrag ersetzt das `'*'`-Wildcard vollständig (`resolveRoleForCurrentController`), alle Actions ADMIN. Kein `defaultAction`-Eintrag: Konvention `home` existiert nicht als Methode → Konvention-URL bleibt 404 |
| `AbstractFrontendController::html()` | Overlay-View-Model ergänzt (nur admin+page): `overlayDev = ['partialLabels' => bool, 'returnPath' => string, 'csrfToken' => string]` — nur wenn `DEBUG` |
| `adminOverlay.tpl.php` | neue Sektion «Entwicklung» (rendert nur, wenn `$overlayDev` gesetzt): Formular mit hidden `_csrf` + `return`, Submit-Button als Toggle-Zeile (Zustand an/aus sichtbar) |
| `admin-overlay.scss` | minimale Styles für die Toggle-Zeile (bestehende Overlay-Optik), `npm run build:frontend` |

### module-backend (Rückbau)

| Baustein | Änderung |
|---|---|
| `SystemController` | `togglePartialLabelsAction` + `PartialLabels`-Import entfernt |
| `partials/shell/topbar.tpl.php` | Partial-Labels-Zeile + `$partialLabels`-Variable entfernt |
| `res/assets/js/system/cache.js` + `.min.js` | Listener + `_togglePartialLabels` entfernt (min manuell nachgeführt — Framework minifiziert nicht, `stylesheet.md`) |

### Datenbestand

- Bestehendes `data/framework/partial-labels.flag` wird von niemandem mehr
  gelesen → inert; Release-Note: darf gelöscht werden. Kein Migrationscode.

## Doku-Pflichten (im gleichen Zug)

1. `docs/topics/view-layer.md`: Abschnitt «partial labels» neu (Gate, Speicherort,
   Schalter-Ort, kein Backend-Schalter); file map (`AdminPanelController`);
   known issue **PARTIAL-LABELS-002** (Umbau), PARTIAL-LABELS-001 Verweis.
2. `docs/topics/backend.md`: Service-Panel-Beschreibung (Partial-Labels-Zeile weg),
   `SystemController`-Actions-Tabelle, Admin-Overlay-Abschnitt (neue Sektion
   «Entwicklung» + `AdminPanelController`).
3. `npm run docs:check` grün.

## Umsetzungs-Phasen

- [x] **P1 Kernel:** `UserPreferences`, `PartialLabels`-Gate, `Request::getRawRequestUri`.
      Dabei mitgefixt: `SystemController::savePreferencesAction` baute die Preferences
      aus dem Request-Body NEU auf und hätte `partial_labels` bei jedem Aussehen-Save
      verworfen — startet jetzt von den gespeicherten Preferences (Rule in backend.md)
- [x] **P2 Frontend:** `AdminPanelController` + Config + Overlay-Sektion + SCSS
      (`npm run build:frontend` gelaufen)
- [x] **P3 Backend-Rückbau:** SystemController, Topbar, cache.js/.min.js
- [x] **P4 Doku:** view-layer.md, backend.md; docs:check grün (29/29)
- [ ] **P5 Verifikation:** CLI-Harness Gate-Matrix ✓ (20/20 PASS: Preference ×
      DEBUG × Rolle × viewArea, Admin-A/Admin-B-Gleichzeitigkeit, Preference-
      Round-Trip + deviation-only); **offen:** visueller Pass durch Owner
      (Overlay-Schalter, Reload, Labels an/aus, zweiter Admin unbeeinflusst)

## Bewusst NICHT

- Kein Backend-Schalter (v1) — Datenstruktur ist bereit, UI folgt bei Bedarf.
- Kein Fetch/JS-Toggle — Formular-POST reicht, Overlay bleibt JS-frei (Rule 7).
- Keine Lockerung der DEBUG-Kopplung — Cache-Sicherheit bleibt strukturell.
- Kein Migrationscode für das alte Flag-File (inert, manuell löschbar).
