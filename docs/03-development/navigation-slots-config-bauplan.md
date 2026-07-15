# Bauplan: view areas + nav slots aus Modul-Config (Modell A)

2026-07-05
Grundlage: [`review-navigation-areas.md`](review-navigation-areas.md) — Modell A vom
Entwickler gewählt (2026-07-05).

## Ziel

Struktur (Umgebung + Navigations-Bereiche/Slots) wandert aus der frei editierbaren
Daten-Entity `NavigationGroup` in **Modul-Config + `ModuleManager`**. Danach gibt es
im Navigations-Umfeld nur noch **zwei** Entities:

```
Navigation       → Baumstruktur (module/group/controller/action, parentId, sortKey,
                   slot, ref, active, param). Tree-Root trägt einen SLOT-String.
NavigationAlias  → id, navigationId, path, isCanonical, active. Public Entry-URL.
```

Alles Strukturelle (welche Umgebungen, welche Slots, deren Labels/Reihenfolge) ist
Code/Config. Die einzigen frei editierbaren Daten sind die Navigations-Einträge selbst
+ ihre Aliase.

## Zwei Kernentscheidungen (aus dem Review, festgehalten)

1. **Umgebung (Ebene 0) aus `{module}Config.inc.php`.** Die env ist keine Row mehr —
   ihr Name IST der Modul-Key (`viewArea: true`), das Label kommt aus dem Config.
   Löst env-add/edit, env-delete-Schutz (R005) und env/slot-Entity-Vermischung (R003).
2. **Slots (Ebene 1) registriert im Config → Fail-Fast im Template.** Ein Slot-Zugriff
   mit unbekanntem Namen wirft, statt still eine leere Navigation zu liefern (heutiges
   `getByGroupSlug('frontnd-meta')` → `[]`). Löst den toten `frontend-secondary` (R001)
   + Magic-String-Drift (R002).

## Zielbild Config

`{module}Config.inc.php` (frontend + backend):

```php
'viewArea'      => true,
'viewAreaLabel' => 'Frontend',      // war NavigationGroup.label der env-Row
'navSlots'      => [                 // geordnete Map: key → Label (Reihenfolge = sort)
    'main'      => 'Hauptnavigation',
    'meta'      => 'Fusszeile',
    'secondary' => 'Zusatznavigation',
],
```

Voller Slug (Daten + Template-Zugriff) = `{moduleKey}-{key}` → `frontend-meta`.
`ModuleManager` aggregiert die Slot-Registry über alle view-area-Module (Set aller
gültigen Slugs) — das ist die „registrierte Menge", gegen die validiert wird.

## Offene Punkte — VOR Code klären (Phase 0)

- **A1 — Fail-Fast-Mechanismus.** Empfehlung: eine werfende Zugriffsmethode
  `NavigationService::getBySlot(string $slot): array`, die gegen die ModuleManager-
  Registry prüft und bei Miss `UnknownNavigationSlotException` wirft. **Keine**
  generierten Konstanten/Enums (wäre Doppelpflege Config↔Enum; „Framework-Code minimal
  halten"). Der volle Slug bleibt der lesbare Identifier.
- **A2 — `AbstractTreeEntityController`.** Verliert mit dem Wegfall des Group-Controllers
  einen seiner zwei Consumer. Entscheidung: `moveAction` zurück in `NavigationController`
  falten (Empfehlung — Minimalismus, 1 Consumer rechtfertigt keine Basisklasse) ODER die
  Basisklasse für künftige Tree-Element-Controller behalten. Berührt ADR-009.
- **A3 — `ElementAnchorRules`.** Der Group-FK-Teil (`GROUP_MISSING`/`GROUP_NOT_LEAF`)
  entfällt (keine Group-Hierarchie mehr). Der **XOR + orphan**-Teil bleibt gültig:
  ein Tree-Root trägt einen Slot & keinen parent, ein Kind trägt parent & keinen Slot.
  Klären: `ElementAnchorRules` für diesen Rest behalten, oder XOR direkt in
  `NavigationValidator` inlinen. Leaf-Regel wird zu **Registry-Membership** (slot ∈
  registrierte Slots).

## Fußabdruck

### Entfernt
| Datei / Artefakt | Grund |
|---|---|
| `packages/kernel/shared/src/Entities/NavigationGroup.php` | Struktur → Config |
| `packages/kernel/shared/src/Repositories/NavigationGroupRepository.php` | — |
| `packages/kernel/shared/src/Validators/NavigationGroupValidator.php` | — |
| `packages/module-backend/src/Ui/Controllers/Content/NavigationGroupController.php` | Slots nicht mehr CRUD-verwaltet |
| `.../NavigationGroupController/{listAction,edit,confirmDelete,actions,list.hc1}.tpl.php` | — |
| `packages/module-backend/res/assets/js/navigation-group/list.{js,min.js}` (+ deployed `skeleton/public/...`) | Group-DnD-Screen weg |
| `skeleton/data/framework/routing/navigation_groups.json` + `packages/kernel/core/data/.../navigation_groups.default.json` | Slots aus Config |
| `NavigationService`: `getAllGroups`, `getTopLevelGroups`, `getGroupChildren`, `getNavigationGroup`, `groupTree`, `groupRepo`, Cache-Keys `groups-all`/`group-entity` | — |
| backendConfig ACL-Einträge für `navigation-group` | Route weg |

### Geändert
| Datei | Änderung |
|---|---|
| `packages/*/App/Config/{frontend,backend}Config.inc.php` | `viewAreaLabel` + `navSlots` ergänzen |
| `packages/kernel/core/src/Services/ModuleManager.php` | `getViewAreaLabel`, `getNavSlots($key)`, aggregierte Slot-Registry (`isKnownSlot`, `allSlots`), Slug-Bau `{module}-{key}` |
| `packages/kernel/shared/src/Entities/Navigation.php` | `navigationGroupId:?int` → `slot:string` (`#[Clean('ident')]`); `get/setNavigationGroupId` → `get/setSlot` |
| `packages/kernel/core/src/Services/NavigationService.php` | `navTree` scopeOf `getNavigationGroupId` → `getSlot`; `getByGroupSlug`/`getByGroupId` → `getBySlot` (registry-check, wirft); `getViewAreas`/`resolveViewAreaUrl`/`getCurrentViewAreaName` auf ModuleManager view-areas + Config-Slots (view-model statt `NavigationGroup`); ctor-Dep `NavigationGroupRepository` raus, `ModuleManager` rein |
| `packages/kernel/shared/src/Validators/NavigationValidator.php` | `validateTag`: XOR bleibt, Leaf-Regel → Slot ∈ Registry (siehe A3) |
| `.../Content/NavigationController.php` | add/move/`nextSortKey`/`applyMovePolicy`: `navigationGroupId` → `slot`; cross-slot-Guard auf String; ggf. `moveAction` aufnehmen (A2) |
| `.../NavigationController/edit.tpl.php` | Slot-Selektor aus `getNavSlots` (Wert = voller Slug) statt Leaf-Group-Liste |
| `.../NavigationController/listAction.tpl.php` | Hierarchie env→slot→tree aus ModuleManager statt `getTopLevelGroups`/`getGroupChildren`; Group-CRUD-Buttons raus |
| `module-frontend/.../partials/{header,footer}.tpl.php` | `getByGroupSlug(...)` → `getBySlot(...)` |
| `module-backend/.../partials/{subnav,shell/topbar}.tpl.php` + `adminOverlay.tpl.php` | Slot-Zugriff + `getViewAreas`-view-model (kein `NavigationGroup` mehr) |
| `.../BackendAbstractController.php` | `navGroupSlug = 'backend-main'` bleibt (voller Slug), Zugriff über `getBySlot` |
| Daten `navigation.{json,default.json}` | `navigation_group_id`(int) → `slot`(string) migrieren; Sandbox-Altlast (R004: orphan id14→parent13, Test-Einträge) bereinigen |

### Unberührt / geklärt
- `AbstractFrontendController` konsumiert `getViewAreas()` — nur das view-model ändert sich.
- `MetaDataController` nutzt `getPublicViewAreaKeys()` (ModuleManager) — schon Config-basiert, bleibt.
- `NavigationAlias`-Stack: komplett unberührt.
- `Navigation` bleibt `TreeNode`-Consumer (parentId/sortKey) — `TreeService`-Fundament steht weiter; nur `NavigationGroup` fällt als zweiter Consumer weg.

## Phasen (jede endet in einer Pause: `php -l`, JSON valide, betroffene Routen 200/302, `npm run docs:check` wo Docs berührt — Framework bleibt je Pause grün)

### Phase 0 — Festschreibung (nur Entscheidung + ADR, kein Produktivcode) ✅ ERLEDIGT 2026-07-05
1. ✅ A1 (werfende `getBySlot`, keine Enums) / A2 (`moveAction` zurück in `NavigationController`,
   Basisklasse weg) / A3 (XOR in `NavigationValidator` inlinen, `ElementAnchorRules` entfernen)
   entschieden.
2. ✅ ADR-022 geschrieben (`adr-022-view-areas-and-nav-slots-in-module-config.md`): revidiert
   ADR-007 §1–3 + „separate registry"-Rejection, hebt ADR-009-Split auf, berührt ADR-008.
3. ✅ Migrations-Mapping fixiert (im ADR): `7→frontend-main`, `2→frontend-meta`, `8→backend-main`,
   `6→backend-auth`. `frontend-secondary` (id 3, R001-Leiche) + `backend-meta` (id 5, nur default,
   kein Eintrag) werden NICHT übernommen.
> **Pause P0 — erreicht.**

### Phase 1 — Config + ModuleManager (additiv, nichts entfernt) ✅ ERLEDIGT 2026-07-05
1. ✅ `viewAreaLabel` + `navSlots` in frontend (`main`/`meta`) + backend (`main`/`auth`) Config.
2. ✅ `ModuleManager`: `getViewAreaLabel`, `getNavSlots` (→ `{module}-{key}` ⇒ label),
   `getAllNavSlots` (Registry), `isKnownSlot`. Rein additiv — kein Konsument umgestellt.
3. ✅ `php -l` grün (ModuleManager + beide Configs).
> **Pause P1 — erreicht.** (Framework-Verhalten unverändert, da additiv; funktionaler
> Registry-Smoke folgt in P2 mit dem ersten `getBySlot`-Konsumenten.)

### Phase 2+3+4 — zusammengezogen ✅ ERLEDIGT 2026-07-05

**Begründung der Zusammenlegung:** `getBySlot` braucht das `slot`-Feld (P3), und das
Umbenennen `navigationGroupId → slot` bricht zwangsläufig den `NavigationGroupController`
(liest das alte Feld) → P4-Löschung muss mit. Getrennte Pausen hätten nur Wegwerf-Brücken
erzeugt. Als **einen** verifizierten Umschaltschritt umgesetzt.

1. ✅ **Entity**: `Navigation::navigationGroupId (?int)` → `slot (string, #[Clean('ident')])`;
   `get/setSlot`. `TreeService` scopeOf → `getSlot`.
2. ✅ **NavigationService**: `getBySlot(slot)` mit Registry-Check + `UnknownNavigationSlotException`;
   `getViewAreas`/`resolveViewAreaUrl` auf ModuleManager (view-model `{key,label,url,active}`);
   `getActiveSectionByGroupSlug`→`getActiveSectionBySlot`; `iterateSections(slot)`; ctor-Dep
   `NavigationGroupRepository` → `ModuleManager`; group-Methoden + Cache-Keys entfernt.
3. ✅ **Validator** (A3): `validateSlot` inline (XOR + orphan + Registry-Membership);
   `ElementAnchorRules`/`AnchorViolation` gelöscht (Navigation war einziger Consumer).
4. ✅ **Controller**: `NavigationController` add/actions/move/`nextSortKey`/`applyMovePolicy`
   auf slot; `listAction`-Hierarchie aus Config; `edit.tpl` Slot-Radios aus `getAllNavSlots`;
   `listAction.tpl` env→slot aus Config, Group-CRUD-Buttons raus.
5. ✅ **Chrome**: frontend header/footer (`getBySlot`), backend subnav/topbar (`navSlot`,
   view-model), adminOverlay; `BackendAbstractController` `navGroupSlug`→`navSlot`.
6. ✅ **MetaDataController** (nachträglich gefunden — nutzte `getTopLevelGroups`): auf
   `getPublicViewAreaKeys` + `getViewAreaLabel` migriert, Template-view-model angepasst.
7. ✅ **P4-Löschung**: `NavigationGroup` Entity/Repo/Validator/Controller + 5 Templates +
   JS (res + deployed) + `navigation_groups*.json`; backendConfig ACL raus; Bootstrap-Import
   raus. **A2 (moveAction zurückfalten) aufgeschoben** — `NavigationController` erbt weiter von
   `AbstractTreeEntityController` (1 Consumer, funktional; reiner Refactor, Restpunkt unten).
8. ✅ **Daten**: beide `navigation*.json` `navigation_group_id → slot`; Sandbox-Cleanup
   (orphan id 14/15/16 mit non-existentem parent 13; id 17 «Nav Groups» → gelöschter Controller).
   Runtime 21 / default 19 Einträge.

**Verifikation:** `php -l` alle grün; JSON valide; Dev-Server `/`,`/home`,`/login` 200, Backend
302; Frontend-Nav rendert (main + meta Slots korrekt); CLI-Boot: `getViewAreas` (2 Areas mit
Config-Labels), `getBySlot('backend-main')` (Webseiten/Stammdaten/Drive), `iterateSections`
(3/2/2 Kinder), `getBySlot('frontend-meta')` (Legal/Privacy), Fail-Fast wirft. Repo-weit **0**
Code-Referenzen auf `NavigationGroup`/`navigation-group` (nur noch Kommentare + Topic-Docs → P5).
> **Pause P2+3+4 — erreicht.** Offener Restpunkt: A2-Fold (`moveAction` in `NavigationController`,
> `AbstractTreeEntityController` auflösen) + TreeService-Docblock (nennt noch `getNavigationGroupId`/
> `ElementAnchorRules`) — beides in P5 mitziehen.

### Phase 5 — Docs + ADR finalisieren ✅ ERLEDIGT 2026-07-05
1. ✅ Topic-Docs auf das Config-Slot-Modell umgeschrieben: `navigation.md` (Mental-Model,
   API, Entity-Feld, `NavigationGroup entity`-Sektion → `view areas + render-slots (config)`,
   Rules, see-also, neuer resolved-Eintrag **NAV-SLOTS-CONFIG-001**, obsolete Tag-Verwaltung-
   Pendenz ersetzt); `tree.md` (Navigation einziger Consumer, `ElementAnchorRules`/`AnchorViolation`
   als entfernt dokumentiert); `backend.md` (Group-Controller-Zeile + Vererbungs-Absatz);
   `metadata.md` (`getTopLevelGroups`→`getPublicViewAreaKeys`); File-Maps in navigation/tree/
   backend/persistence-file/entity-data-handling bereinigt (gelöschte SOURCE-Pfade raus).
2. ✅ Code-Docblocks: `TreeService` (scopeOf-Beispiel + `isLeaf`), `AbstractTreeEntityController`
   (Consumer-Beispiel) auf Slot-Modell/single-Consumer aktualisiert.
3. ✅ `review-navigation-areas.md` als **IMPLEMENTED** markiert.
4. ✅ **A2 zurückgedreht:** `AbstractTreeEntityController` bleibt (Wiederverwendungs-Naht laut
   ADR-008/009, Null-Kosten bei 1 Consumer) — statt moveAction zu falten. In ADR-022-Restpunkt
   + backend.md dokumentiert.
5. ✅ `npm run docs:check` **27/27 grün, 0 Violations**.
> **Pause P5 — fertig. Gesamter Umbau abgeschlossen.**

## Risiken
- Grosser Eingriff (Entity-Feld, Service, Validator, Controller, Templates, Daten, Config, Docs), aber kleiner als der ADR-015-Umbau: kein Routing-/Cache-/SEO-Pfad betroffen.
- Datenmigration muss verlustfrei sein (jede `navigation_group_id` → korrekter Slot-Slug).
- ADR-007/009-Revision: nicht still abweichen — ADR-Nachtrag in P0 ist Voraussetzung.
- Cross-Slot-DnD-Guard (heute cross-group) muss auf String-Slot korrekt weiterlaufen.

## see also
- [`review-navigation-areas.md`](review-navigation-areas.md) — Befunde R001–R005, Modell A/B-Abwägung
- [`../topics/navigation.md`](../topics/navigation.md) / [`../topics/tree.md`](../topics/tree.md)
- [`../02-decisions/adr-007-navigation-tree-model.md`](../02-decisions/adr-007-navigation-tree-model.md) · [`adr-008`](../02-decisions/adr-008-tree-foundation.md) · [`adr-009`](../02-decisions/adr-009-tree-entity-naming-and-controller-split.md)
- [`navigation-umgebung-bauplan.md`](navigation-umgebung-bauplan.md) — der ursprüngliche env-Switcher-Bauplan (das, was hier vereinfacht wird)
