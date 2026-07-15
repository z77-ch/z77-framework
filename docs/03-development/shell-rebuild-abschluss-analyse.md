# Shell-Rebuild — Abschluss-Analyse & Cleanup-Readiness

2026-07-04

Analyse des abgeschlossenen Backend-Shell-Umbaus. Grundlage für die anschliessende
Legacy-Bereinigung. Kontext/Regeln der Shell: [`../topics/css-backend.md`](../topics/css-backend.md)
(SHELL-REBUILD) + [`../topics/backend.md`](../topics/backend.md).

## Ergebnis: funktional fertig

Nach der Migration der letzten zwei Views rendert **jeder** Backend-View durch die
Shell. **Kein Template** nutzt mehr das Legacy-Content-Header-Muster
(`.backend-content-header`) — verifiziert per repo-weitem Grep (0 Treffer ausserhalb der
Legacy-Chrome-Dateien selbst).

„Funktional fertig" heisst: die Shell ist der einzige aktive Chrome-Pfad. Die
Legacy-Dateien sind zwar noch physisch da und teils noch registriert, werden aber von
keinem gerenderten Skeleton mehr ausgegeben (bzw. nur noch ins Leere gerendert). Das
eigentliche Löschen ist der separate Cleanup (unten).

## Migrations-Stand (alle Views)

| View | Header-Band | Stand |
|---|---|---|
| `content/content/list` | hc1 (add) + hc2 (Sprachumschalter) | fertig |
| `content/navigation/list` | hc1 (add) + hc2 (Filter/Print/Aliase) | fertig |
| `content/navigation-group/list` | hc1 (add „Umgebung") | **neu 2026-07-04** |
| `content/navigation-alias/list` | hc1 (add „Alias") | **neu 2026-07-04** |
| `content/meta-data/list` | hc2 (Sprachumschalter) | fertig |
| `system/login-user/list` | hc1 (add) | fertig |
| `documents/drive/list` | hc1 (upload) + hc2 (Pfad/Ordner/Papierkorb) | fertig |
| `content/translation/list` | hc1 = Add-Picker-Panel («＋ Eintrag» → Text / Slug) | **neu 2026-07-04** — zwei Add-Arten → Panel-Picker (`.be-shell-add`); hc2 frei für andere Controls |
| `system/dashboard/overview` | — (eigenes `.be-overview`-Layout) | by design |
| `system/login/login`, `system/setup/setup` | — (Guest-Skeleton, kein Chrome) | fertig (LAYOUT-B001) |

Mechanik: `BackendAbstractController::loadHeaderSlots()` lädt Konventions-Partials
`{Group}/{Controller}/{action}.hc1|hc2|hc3.tpl.php` automatisch, wenn vorhanden. Ein View
„droppt" nur die Datei(en) — kein Controller-Code. Nur `hc1` gesetzt (kein `hc2`) ist ok:
das Skeleton rendert beide Slots sobald einer gesetzt ist (`$hasHead`), der leere zweite
hält nur die Band-Ausrichtung (bereits durch `login-user/list` erprobt).

## Legacy-Inventar nach Abschluss

Drei Kategorien — entscheidend für die Löschreihenfolge.

### A) Voll tot — kein Consumer mehr (direkt entfernbar)

| Artefakt | Nachweis |
|---|---|
| `res/view/templates/partials/header.tpl.php` | Shell-Skeleton gibt `$header` nicht aus; Guest auch nicht. Nur noch in `layoutConfig` registriert → ins Leere gerendert. |
| `res/view/templates/partials/footer.tpl.php` (`.backend-footer`) | Shell-Skeleton gibt `$footer` **nicht** aus (die Shell hat eigenen Hamburger via `shell.js`). Ebenfalls nur registriert → verworfen. |
| Registrierungen `header` + `footer` in `layoutConfig.inc.php` `levelElements.body` | die zwei Zeilen dazu |
| `_topbar.scss` **Dead-Teil**: `.backend-topbar`, `__brand/__logo/__name/__version/__modules/__spacer/__cmd*/__hamburger`, `.backend-module-tab*` | nur von `partials/header.tpl.php` genutzt |
| `.backend-content-header` / `.backend-breadcrumb` / `.backend-content` in `layout/_desktop.scss` | letzter Consumer war die eben migrierten 2 Views → jetzt 0 |

### B) Verwoben — erst entflechten, dann löschen

| Artefakt | Warum |
|---|---|
| `_topbar.scss` **Live-Teil**: `.backend-topbar__env*`, `__bell`, `__avatar*` | die **Shell-Topbar** (`partials/shell/topbar.tpl.php`) nutzt diese Klassen für Umgebungs-Switcher, Glocke, Avatar. → in `_shell.scss` (oder neues `_env`-Component) verschieben, bevor `_topbar.scss` gelöscht wird. |
| `<body class="backend">` im Shell-Skeleton | zieht `body.backend` (overflow/bg/font) aus `layout/_desktop.scss`. Die `.be-shell` setzt zwar eigene `height:100dvh`, aber die Body-Basis (overflow-hidden gegen Doppel-Scrollbar, bg/color/font) kommt noch von dort. → eine autarke Body-Regel in `_shell.scss` geben, bevor `_desktop.scss` weg kann. |
| `html-default-skeleton.tpl.php` (Backend) | ist der Framework-Default `LayoutDefaults::SKELETON = 'html-default-skeleton'`. → Konstante auf `html-shell-skeleton` umbiegen (das Frontend hat sein **eigenes** `html-default-skeleton`, bleibt unberührt), dann Backend-Datei löschen. |

### C) Bleibt — von der Shell aktiv genutzt (NICHT anfassen)

| Artefakt | Rolle |
|---|---|
| `_subnav.scss` (`.backend-subnav`, `.backend-tree-*`) | Spalte 1 der Shell rendert `$subnav`; `_shell.scss` restyled `.backend-subnav` nur. |
| `_service-panel.scss` | Avatar-Dropdown, von der Shell-Topbar genutzt. |
| `_shell.scss`, `partials/shell/*`, `shell.js` | die Shell selbst. |
| die 4 `tokens/*.scss`, `base/*`, alle übrigen `components/*` | Design-System, palettenweit. |

## Cleanup-Reihenfolge (nach dieser Analyse verfeinert)

Kein Git → **jede Löschung erst nach erfolgreichem Live-Test**; zu löschende Dateien vorher
in den scratchpad sichern (manuelles Undo).

1. **Voll-tote Partials** (A): `partials/header.tpl.php` + `partials/footer.tpl.php` löschen,
   `header`- + `footer`-Registrierung aus `layoutConfig` raus. Verify: login + je eine
   eingeloggte Seite. **✅ DONE 2026-07-04** — beide Partials + Registrierungen entfernt; `/login`
   200, keine PHP-Fehler; 4 stale SOURCE-Zeilen (backend/css-backend/login.md file maps) mit
   entfernt, `docs:check` grün. (Prosa-Referenzen auf header/footer → Phase-5-Sammelpass.)
2. **`_topbar.scss` entflechten** (B→A): Live-Teil (`__env/__bell/__avatar`) nach `_shell.scss`,
   Dead-Teil löschen, `@use 'components/topbar'` entfernen. Verify: Shell-Topbar über alle
   Paletten + Dark. **✅ DONE 2026-07-04** — Live-Block (env/bell/avatar, Klassennamen unverändert)
   ans Ende von `_shell.scss` verschoben; `_topbar.scss` + `@use` entfernt; SCSS kompiliert (live=19,
   dead=0 in base.css), deployt; `docs:check` grün. Offen: Klassen heissen weiter `.backend-topbar__*`
   (Umbenennung auf `.be-shell-topbar__*` optional, bräuchte Markup-Änderung — bewusst nicht gemacht).
   Prosa (`_topbar.scss` in scss-Baum + „what goes where" von `css-backend.md`) → Phase-5-Pass. **Live-Check
   offen (eingeloggt): Umgebungs-Switcher-Dropdown, Glocke, Avatar + `--open`-Ring über alle Paletten.**
3. **Body autark machen + Layout-Dateien auflösen** (B). **✅ DONE 2026-07-04.** Wichtige
   Erkenntnis beim Umsetzen: die `layout/*.scss` waren NICHT rein Legacy — sie trugen Live-Anteile.
   Vorgehen (voll, User testet Responsive):
   - **Live umgezogen:** `.be-overview`-Responsive (mobile+tablet) → `_overview.scss` (`@media`);
     env/bell-Mobile-Hide → in den bestehenden `@media (max-width:767px)`-Block von `_shell.scss`;
     `body.backend`-Basis (bg/color/font/overflow:hidden) → `_shell.scss` (Shell besitzt eigene
     `100dvh` + interne Scrollbereiche). Subnav-Mobile (`.backend-subnav{display:none}` + `--open`)
     NICHT übernommen — der Shell-Column-1-Drawer ersetzt es (die alte Regel versteckte die Subnav
     sogar fälschlich im Drawer).
   - **Gelöscht:** `layout/`-Verzeichnis (6 Partials) + 6 scss-Entry-Files (`mobile/tablet/desktop/
     nav-*.scss`); `_topbar.scss` bereits in Phase 2. Der `sass --watch` hat die zugehörigen
     `res/assets/css`-Outputs automatisch mitgelöscht; die deployten `skeleton/public`-Outputs +
     versionierten Kopien manuell entfernt.
   - **`layoutConfig` `styleSheets`** auf nur `base` reduziert (war 7 Einträge).
   - Verify: `php -l` clean; base.css enthält env/bell-Hide + `body.backend` + Overview-`@media`;
     **0** Dead-Layout-Klassen in base.css; `/login` 200, referenziert nur noch `base.css`, keine
     PHP-Fehler; `docs:check` grün; `css-backend.md` Struktur-Sektionen (mental model / scss-Baum /
     Output / what-goes-where / JS-Note) aktualisiert.
   - **✅ Live-Check bestanden (User, 2026-07-04):** Responsive mobile / tablet / desktop sauber,
     kein Body-Scroll-Problem. `overflow:hidden` bleibt.
   - **Nachtrag Subnav-Fixes (2026-07-04):** (a) `.backend-tree-node:hover` nutzte `--be-bg` — im
     Dark-Band NICHT überschrieben (Text hell + BG hell = unlesbar) → auf `--be-surface2` (bandtauglich).
     (b) `.backend-tree-node--ref` Akzent-Rand (`box-shadow: inset 3px`) entfernt (User-Wunsch „nur Pfeil");
     das ↗-Glyph bleibt. Listen-Ansicht `.be-nav-node--ref` (NAV-REF-UI-001) bewusst unangetastet.
4. **Default-Skeleton** (B). **✅ DONE 2026-07-04 — mit Plan-Abweichung.** Ursprünglich geplant:
   `LayoutDefaults::SKELETON` → `html-shell-skeleton` umbiegen. **Verworfen** (verifiziert): Backend
   UND Frontend setzen `documentTpl` explizit (`html-shell-skeleton` bzw. `html-default-skeleton`),
   **niemand** fällt je auf `LayoutDefaults::SKELETON` zurück (feuert nur bei leerem `documentTplPath`).
   Die Konstante ist ein modul-übergreifender Core-Default — sie auf das backend-spezifische
   `html-shell-skeleton` zu setzen wäre falsch (bräche jedes Modul, das je auf den Default zurückfiele
   und dieses Template nicht hat). Daher: **Konstante unangetastet**, nur das tote Backend-
   `html-default-skeleton.tpl.php` gelöscht (Frontend-Version bleibt — dessen Config referenziert es).
   Backend-`layoutConfig`-Revert-Kommentar korrigiert; `backend.md` SOURCE + 2 Prosa-Stellen
   (`html-default-skeleton` → `html-shell-skeleton` fürs Token-Rendering) gefixt. `php -l` clean.
5. **Dark-Band-Tokens** (offener Doc-Punkt): Token-Set aus `_shell.scss` in die Palette-Datei
   falten (fixed vs. per-palette entscheiden).
6. **Deploy + Doc**: `shell.js` minifizieren (`.min.js` fehlt noch), `composer install -d skeleton`,
   `npm run build:backend`, Topic-Docs (SHELL-REBUILD → resolved), `npm run docs:check` grün.

## Verifikations-Checkliste (bitte im Browser, eingeloggt)

Vor dem Cleanup bestätigen, dass der Abschluss visuell stimmt:

- [ ] `/backend/content/navigation-group/list` — Header-Band mit „+ Umgebung" (dunkler linker
      Slot), Band über beide Spalten ausgerichtet, kein doppelter/fehlender Titel, Baum + DnD ok.
- [ ] `/backend/content/navigation-alias/list` — Header-Band mit „+ Alias", Liste + Aktiv-Switch ok.

Beide spiegeln exakt das erprobte Muster von `system/login-user/list` (nur `hc1`).

## Offen / bewusst später

- **Preview-Spalte (hc3 / „Phase 3")** — nur Gerüst, kein View nutzt sie. Kein Legacy-Blocker.
- **Dark-Band-Tokens** — in den Cleanup (Schritt 5) gelegt.
