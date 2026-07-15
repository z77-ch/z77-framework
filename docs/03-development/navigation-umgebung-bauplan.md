# navigation-umgebung-bauplan.md — View-Bereich / Umgebung als dynamische Navigation

**Status:** CODE + DOKU FERTIG (Phasen 1–8). Offen nur noch: **Live-Test (morgen)** nach Cache-Leeren + dokumentierte Folgepunkte (Parent-Feld Tag-Anlage, Env-Delete-Schutz, Rollen-Gate). ADR-007 geschrieben, `docs:check` grün.

> ⚠ **Sofort nötig zum Testen:** APCu-Cache leeren (Tag-Entity hat neue Felder; Nav-Array gecacht mit alten Tags). Bis dahin sind Topbar-Tabs/Subnav leer. Service-Panel „Cache leeren" oder Server-Neustart.
**Datum:** 2026-05-29
**Scope:** Das statische Topbar-Badge „Umgebung" (`backend-topbar__env`, hartkodiert `BACKEND`) wird zu einem dynamischen Switcher zwischen UI-Umgebungen (heute frontend/backend, später + member). Umgebung = Modul mit eigenem Layout.

## Kern-Entscheidungen (mit User abgestimmt)

1. **Keine separate Registry.** Die Umgebung hängt an der Navigation/Tag-Struktur, nicht an einer eigenen Daten-Registry. Begründung: `entryUrl` löst `resolveFirstNavigable()`, Sichtbarkeit/`role` löst „User hat erreichbaren Eintrag im Subtree" (ACL-gefiltert), `label` hat die `Tag`-Entity schon, `dot`-Farbe ist reines CSS. Alle vermeintlichen Registry-Felder lösen sich in vorhandener Mechanik auf.
2. **Tag wird zur Baum-Entity** (`parentId` + `sortKey`, exakt wie Navigation NAV-PARENTID-001 / NAV-SORT-001). Top-Level-Tag (`parentId: null`) = Umgebung. Dies ist die in `../topics/navigation.md` `## pending` vorgemerkte „zweite Baum-Entity" → Trigger fürs gemeinsame Tree-Fundament.
3. **3-Ebenen-Modell:**
   ```
   Umgebung (Top-Level-Tag: frontend, backend, member)   ← koppelt an Modul + Layout
      └─ Render-Slot (Kind-Tag: frontend-main, frontend-meta, backend-main, backend-auth)
           └─ Navigation-Baum (Tree-Root → Children)   ← bisheriges Modell, unverändert
   ```
   Tag-Baum **gruppiert**, Navigation-Baum **navigiert**. Navigation-Tree-Roots tragen einen Render-Slot-Tag (Ebene 1), nie einen Umgebungs-Tag (Ebene 0).
4. **Allowlist = Variante B (Modul-gebunden).** Jedes View-Area-Modul markiert sich in `<module>Config.inc.php` mit `'viewArea' => true`. `ModuleManager::getViewAreaKeys()`. `TagValidator`: Top-Level-Tag-`name` MUSS ein View-Area-Modul sein → kein Redakteur kann eine tote Umgebung anlegen. Kind-Tags (Render-Slots) bleiben frei.

## Begriffe

- **Code:** `ViewArea` (eindeutig; `Environment` würde mit Deployment-Env / `DEBUG` kollidieren).
- **UI (Deutsch):** „Umgebung" (bleibt wie im heutigen `title="Umgebung"`).

## Phasen & Pausepunkte

Jeder Pausepunkt ist ein **lauffähiger** Zustand (App rendert, keine kaputten Tags).

### Phase 1 — Tag → Baum-Entity  ⏸ PAUSE 1
Rein additiv, nichts bricht (Defaults: `sortKey=0`, `parentId=null` → alle Tags bleiben Top-Level).
- [x] `packages/kernel/shared/src/Entities/Tag.php` — `?int $parentId` + `int $sortKey` (ohne `#[Clean]`, server-controlled), Getter/Setter analog Navigation. `php -l` grün.
- [x] Daten/JSON bleiben unberührt (mapFromArray überspringt fehlende Keys → Defaults).

### Phase 2 — Allowlist (Code)  ✅ erledigt
- [x] `<module>Config.inc.php` (backend + frontend): `'viewArea' => true`.
- [x] `ModuleManager::getViewAreaKeys(): array` (Module mit `viewArea`-Flag).
- [x] `TagValidator` (+ `viewAreaKeys`-Liste, shared bleibt entkoppelt): `validateParentId` — Top-Level-`name` ∈ View-Areas; Kind = freier Slot. Leere Keys = Check aus.
- [x] `NavigationController` gibt `DI::getModuleManager()->getViewAreaKeys()` rein.
- [x] `php -l` aller geänderten Dateien grün.

### Phase 3 — Daten-Umstrukturierung + Templates  ✅ erledigt (⏸ PAUSE 2)
In einem Rutsch (Daten + Templates bedingen sich; löst den Zwischenzustand auf). UI rendert danach wie vorher.
- [x] `tags.default.json` + `tags.json`: `frontend` (Umgebung, id=1, parent=null) → Kinder `frontend-main`(id 7)/`frontend-meta`(2)/`frontend-secondary`(3); `backend` (Umgebung, id=4) → `backend-main`(8)/`backend-meta`(5)/`backend-auth`(6). `parent_id` + `sort_key` gesetzt.
- [x] `navigation.default.json` + `navigation.json`: Tree-Roots umgehängt — `tag: frontend`→`frontend-main` (id 3,4,5,10), `tag: backend`→`backend-main` (id 1,2,13). `frontend-meta`/`backend-auth` unverändert.
- [x] Templates: Frontend Header + Footer `getByTag('frontend')`→`'frontend-main'`; Backend `navTag` `'backend'`→`'backend-main'` (Topbar/Subnav folgen automatisch); Footer-Meta bleibt `frontend-meta`.
- [x] JSON valide + `php -l` grün.
- [ ] **⚠ Cache leeren — RUNTIME-Schritt durch User** (APCu hält alte Nav-Array-/Tag-Daten; bis dahin sieht die App leer/kaputt aus). Service-Panel „Cache leeren" oder PHP-Server-Neustart.
- [ ] Folgepunkt (nicht Phase 3): Tag-Edit-UI braucht ggf. ein Parent-Feld, sonst lassen sich neue Kind-Tags (Render-Slots) nicht über die UI anlegen — heute nur Umgebungs-Tags. Bewerten in Phase 5.

### Phase 4 — NavigationService Umgebungs-API  ✅ erledigt
- [x] `getViewAreas(): list<{tag,url,active}>` — Top-Level-Tags, gefiltert auf erreichbar (Reachability via `firstNavigableInclusive`/`resolveFirstNavigable`). ACL/Rollen-Gate bewusst aufgeschoben (Topbar ist auth-gated; reine Erreichbarkeit reicht heute).
- [x] `getTopLevelTags()` / `getTagChildren(Tag)` / `sortTags()` — Tag-Baum-Navigation (über `findAll` + PHP-Filter, vermeidet snake/camel-Ambiguität).
- [x] `getCurrentViewAreaName()` — Modul des aktuellen Eintrags (Invariante: Umgebungs-Tag-Name === Modul-Key).
- [x] `resolveViewAreaUrl(Tag)` — entryUrl je Umgebung (inkl. Ref-Auflösung `?via=`). `firstNavigableInclusive()` fix: flache Tree-Roots (Frontend-Seiten) sind selbst navigierbar.
- [x] `php -l` grün.

### Phase 5 + 6 — Dropdown-UI + CSS  ✅ erledigt (⏸ PAUSE 3)
- [x] Backend `header.tpl.php`: `__env`-Badge → Dropdown (Trigger = aktuelle Umgebung mit Dot + Label + Chevron, Menu = alle Umgebungen mit Link auf entryUrl, aktive markiert).
- [x] Inline-Toggle-JS in `partials/footer.tpl.php` (Click + Click-outside, wie Service-Panel).
- [x] `_topbar.scss`: env-Dropdown (`__env-wrap/-label/-chevron/-menu/-item`), token-basiert; Dot-Farben pro Umgebung via bestehende `--<name>`-Modifier. Watcher kompiliert grün.

### Phase 7 — navigation/list hierarchische Anzeige  ✅ erledigt (User-Hinweis 2026-05-29)
Backend `NavigationController::listAction` gruppiert jetzt verschachtelt gem. Tag-Baum: Umgebung → Render-Slot → Navigations-Baum.
- [x] `listAction`: `$areas` über `getTopLevelTags()` → `getTagChildren()` → Tree-Roots je Slot-Tag (repo direkt, inkl. inaktive); `total` pro Umgebung.
- [x] `listAction.tpl.php`: Umgebungs-Section (prominent, `data-group=envName`) → Slot-Subsection (`.be-nav-subsection`, eingerückt) → Baum via `renderNode`. Tabs filtern jetzt pro Umgebung.
- [x] `list.css`: `.be-nav-subsection*`-Styles; Umgebungs-Titel prominent, Slot-Titel = bisherige dezente Mono-Optik.
- [x] `ungrouped` weiterhin echte Waisen (`tag null && parentId null`). `php -l` grün.
- [ ] Folgepunkt: Tag-Verwaltung — Umgebungs-Tag hat nur „umbenennen" (kein Löschen, würde Slots verwaisen); Slot-Tags haben edit+delete. Tag-Anlage braucht weiterhin Parent-Feld (siehe Phase 3 Folgepunkt). Env-Delete-Schutz/Validator offen.

### Phase 8 — Doku  ✅ erledigt
- [x] `../topics/navigation.md`: Tag-Baum-Modell + Umgebungs-Ebene in mental model, Tag-Entity (parentId/sortKey), Service-API, tag-Konvention, Regeln; known issue NAV-ENV-001; pending bereinigt; see-also → ADR-007.
- [x] `../02-decisions/adr-007-navigation-tree-model.md` — geschrieben (Status APPROVED): geschichtetes Modell, Tag-Baum, View-Area-Bindung, Opener/Refs/`$uiCurrent`, server-controlled Ordering, Rejected Alternatives.
- [x] `npm run docs:check` grün (17/17).

### Live-Test (morgen)  ⏸ OFFEN
1. **APCu-Cache leeren** (Service-Panel „Cache leeren" oder Server-Neustart).
2. Backend-Topbar: Umgebungs-Dropdown zeigt Frontend + Backend, Backend aktiv markiert; Klick „Frontend" → `/home`.
3. Frontend `/home`: Header zeigt Home/About/Services/Contact; Footer Legal/Privacy.
4. Backend Topbar-Tabs (Webseiten/Stammdaten/test) + Subnav rendern wie vorher.
5. `navigation/list`: hierarchisch (Frontend → Hauptnavigation/Fusszeile…, Backend → Sektionen/Stammdaten/Authentifizierung…), Tabs filtern pro Umgebung.

## see also
- `../topics/navigation.md` — SSOT Navigation + Tag-Modell (`## pending`: Tree-Fundament, ADR-007).
- `navigation-opener-entscheidungs.md` — Vorlauf (Single-Tag-Modell, XOR, Refs).
