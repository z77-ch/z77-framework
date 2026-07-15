# navigation-opener-entscheidungs.md — Navigation-Einträge als Öffner statt Link

**Status:** DONE — Opener + Tag-Modell-Refactor + Ref-Feature umgesetzt und live; Opener-CSS-Polish (Teil B) abgeschlossen 2026-05-29 (NAV-OPENER-CSS-001 in `../topics/navigation.md`).
**Datum:** 2026-05-20
**Scope:** Navigation-Einträge mit Kindern, die nicht navigieren sollen sondern nur den Subtree zeigen/verstecken. Erweitert um Single-Tag-Modell und UI-Refs zu existierenden Pages.
**Voraussetzung:** [navigation-entscheidungs.md](navigation-entscheidungs.md) (Option 5) umgesetzt — `getCanonicalUrl()` baut aus 4 Routing-Feldern, gibt `''` bei leerem `module`.

> **Datenmodell-Stand (Korrektur 2026-05-29):** Zwei Aussagen weiter unten sind überholt — der aktuelle Stand steht in [`../topics/navigation.md`](../topics/navigation.md) (SSOT):
> - **Opener tragen `tag: null`** (nicht `backend-meta`). Im XOR-Modell trägt nur der Tree-Root einen Tag; alle inneren/Blatt-Knoten inkl. Opener haben `tag: null`.
> - **Baum-Verknüpfung über `parentId`** am Kind, nicht über ein `children: int[]`-Array am Parent. Reihenfolge über `sortKey`. Die `children`/`tags`-Beispiele unten zeigen den historischen Stand und dienen nur der Begründungs-Nachvollziehbarkeit.

---

## ENTSCHEIDUNGEN (2026-05-20, nach Pause)

### 1. Tag-Semantik für Opener: **Option A — `backend-meta`**

Opener-Einträge bekommen den gleichen Tag wie normale Sidebar-Items: `backend-meta`. Begründung des Users: sonst wird die Verwaltung in `navigation/list` schwierig (Opener wäre ungetaggt und würde in der ungrouped-Sektion landen).

**Konvention** (in `docs/topics/navigation.md` ergänzt):
- Opener = alle 4 Routing-Felder leer + `children` nicht leer + Tag `backend-meta`.

### 2. Hierarchie-Tiefe — rekursiv

`NavigationService::getActiveSectionByTag()` und `iterateSections()` suchen jetzt rekursiv durch den ganzen Subtree via `iterateTree`. Damit funktioniert die Active-Section-Erkennung auch wenn das aktive Item zwei oder mehr Ebenen tief sitzt (Section → Opener → Page).

### 3. Topbar-URL bei Opener als first-child

Neue Methode `NavigationService::resolveFirstNavigable(Navigation): ?Navigation` liefert den ersten Descendant mit nicht-leerer URL (depth-first). `header.tpl.php` nutzt das statt `children[0]->getUrl()` → kein `href=""` mehr, selbst wenn das erste Kind ein Opener ist.

### 4. Doppelreferenzierung — Validator-Check

`NavigationValidator::validateChildren()` prüft beim `persist`, dass keine Child-ID bereits in einem anderen Eintrag als Child steht. Voraussetzung: Validator wird mit `NavigationRepository` instanziiert (Pattern wie `TagValidator`). Heute gibt es noch kein UI für Children-Bearbeitung, der Check ist Defense-in-Depth gegen manuelle JSON-Edits und zukünftige UIs.

---

## Test-Setup im skeleton/data/framework/routing/navigation.json (aktuell)

```jsonc
{ "id": 2, "name": "Stammdaten", "tags": ["backend"], "children": [13] }
{ "id": 13, "name": "Daten-Gruppe (Test)",
  "module": "", "group": "", "controller": "", "action": "",
  "tags": ["backend-meta"], "children": [6, 7] }
```

id=6 und id=7 stehen nur unter id=13.children (keine Doppelreferenz). id=2.children referenziert ausschliesslich id=13.

### Verhalten

- Topbar "Stammdaten" → `resolveFirstNavigable(id=2)` durchläuft den Subtree, findet id=6 als erste Page mit URL → `href=/backend/content/navigation/list`.
- Sidebar zeigt children von id=2 = [13]. id=13 ist Opener mit `<details>`, zeigt children [6, 7] aufgeklappt (weil id=6 aktiv ist).

---

## Problemstellung

User möchte Navigationseinträge mit Kindern haben, die **nicht als Link** funktionieren, sondern nur als **Öffner** (Disclosure-Toggle). Anwendungsfall: Gruppierung mehrerer verwandter Sub-Seiten unter einem gemeinsamen Header, ohne dass der Header selbst eine Seite ist.

**Beispiel (zukünftig):**

```text
Benutzer-Verwaltung       ← Öffner, kein Link
  ├ Benutzer              ← navigiert
  ├ Rollen                ← navigiert
  └ Berechtigungen        ← navigiert
```

Heute haben wir kein Beispiel mit Sidebar-Children. Die Topbar-Sections (id=1, id=2) sind strukturell Container, navigieren aber per Konvention zu `children[0]`.

---

## Heutige Render-Stellen

| Stelle | Datei | Verhalten heute |
|---|---|---|
| Backend Topbar (Section-Tabs) | [`header.tpl.php:24-32`](packages/module-backend/res/view/templates/partials/header.tpl.php#L24-L32) | `<a href="children[0].getUrl()">` — Klick navigiert zur ersten Sub-Seite |
| Backend Sidebar Level-1 | [`subnav.tpl.php:26-37`](packages/module-backend/res/view/templates/partials/subnav.tpl.php#L26-L37) | `<a href="item.getUrl()">` — direkter Link |
| Backend Sidebar Level-2 (Children) | [`subnav.tpl.php:43-47`](packages/module-backend/res/view/templates/partials/subnav.tpl.php#L43-L47) | `<a href="child.getUrl()">` — direkter Link, immer sichtbar |
| Frontend Topbar | `module-frontend/.../header.tpl.php:13-17` | `<a href="entry.getUrl()">` — flache Liste |
| Frontend Footer | `module-frontend/.../footer.tpl.php:18, 27` | `<a href="entry.getUrl()">` |

---

## Marker — wann ist ein Eintrag ein Öffner?

### Vorschlag: Konvention "leere Routing-Felder + Children"

Ein Eintrag ist Öffner, wenn:
- `module === ''` (entspricht `getCanonicalUrl() === ''`), UND
- `children` ist nicht leer

Begründung:
- Heutige Container (id=1, id=2) verhalten sich strukturell schon so — leere Routing-Felder.
- Nach dem Side-Quest-1-Umbau gibt `getCanonicalUrl()` bei leerem `module` schon `''` zurück → kein Code-Add nötig.
- Konsistent: "leer = kein Ziel = Öffner".
- Kein neues Feld im Entity oder JSON.

### Verworfen: explizites Feld `isOpener: bool`

Doppelt gemoppelt, weil `module === ''` bereits dasselbe sagt. Mehr Migration, mehr Validierung, kein Mehrwert.

### Verworfen: Tag-basierte Markierung

Tags haben heute eine andere Funktion (Section-Gruppierung). Den Marker dort zu mischen verwässert die Semantik.

---

## Topbar vs. Sidebar — bleiben unterschiedliche Konventionen?

### Topbar-Sections (id=1, id=2) — Empfehlung: heutiges Verhalten beibehalten

Die Topbar-Tabs sind heute schon **Quasi-Öffner**: Klick wechselt die Sidebar (über Section-Aktivierung). Der `<a href>` auf `children[0]` ist ein praktischer Init-Punkt — beim ersten Klick auf einen Tab landet man auf einer Default-Seite der Section.

**Wenn Topbar zu reinem Öffner umgestellt würde:**

- Klick auf "Stammdaten" würde keine Seite mehr aufrufen, sondern nur die Sidebar einblenden.
- Section-Aktivierung läuft heute über `current Navigation` ([NavigationService.php:90-99](packages/kernel/core/src/Services/NavigationService.php#L90-L99)) — ohne Navigation gibt es keine aktive Section.
- → User müsste **zwei Klicks** machen statt einem (erst Tab, dann Sub-Item). Friktion.

Empfehlung: **Topbar bleibt navigierend wie heute** (`<a href="children[0].getUrl()">`). Die Öffner-Funktion brauchen wir in der **Sidebar**, nicht in der Topbar.

### Sidebar Level-1 — neu: Öffner-Modus mit `<details>/<summary>`

Wenn ein Sidebar-Item leere Routing-Felder hat und Children:
- Rendere als `<details>/<summary>` statt `<a href>`
- Children werden nur sichtbar, wenn aufgeklappt
- Klick auf den Header klappt auf/zu, navigiert nicht

---

## Template-Logik (Vorschlag)

### `subnav.tpl.php` — neuer Loop-Body

```php
<?php foreach ($items as $item):
    $isOpener         = ($item->getCanonicalUrl() === '');
    $subItems         = $navigationService->getChildren($item);
    $hasChildren      = !empty($subItems);
    $hasActiveChild   = $hasChildren && in_array(
        $navigation?->getId(),
        array_map(fn($c) => $c->getId(), $subItems),
        true
    );
?>

<?php if ($isOpener && $hasChildren): ?>
    <details class="backend-tree-opener"<?= $hasActiveChild ? ' open' : '' ?>>
        <summary class="backend-tree-node backend-tree-node--opener">
            <span class="backend-tree-node__toggle" aria-hidden="true">
                <svg width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </span>
            <span class="backend-tree-node__label"><?= e($item->getName()) ?></span>
            <span class="backend-tree-node__count"><?= count($subItems) ?></span>
        </summary>
        <div class="backend-tree-children">
            <?php foreach ($subItems as $child):
                $childActive = $currentUrl === $child->getUrl();
            ?>
            <a href="<?= e($child->getUrl()) ?>"
               class="backend-tree-node<?= $childActive ? ' backend-tree-node--active' : '' ?>">
                <span class="backend-tree-node__toggle" aria-hidden="true"></span>
                <span class="backend-tree-node__label"><?= e($child->getName()) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </details>
<?php else: ?>
    <a href="<?= e($item->getUrl()) ?>"
       class="backend-tree-node<?= $isActive ? ' backend-tree-node--active' : '' ?><?= $hasChildren ? ' backend-tree-node--has-children' : '' ?>">
        ... (heutiges Markup)
    </a>
    <?php if ($hasChildren): ?>
        ... (heutige Children-Schleife)
    <?php endif; ?>
<?php endif; ?>

<?php endforeach; ?>
```

### CSS-Anpassungen (in `_subnav.scss` oder ähnlich)

```scss
.backend-tree-opener {
    summary {
        list-style: none;       // kein default-marker
        cursor: pointer;
        &::-webkit-details-marker { display: none; }
    }

    // Pfeil rotiert bei offen
    &[open] .backend-tree-node__toggle svg {
        transform: rotate(90deg);
        transition: transform .15s ease-out;
    }
}
```

---

## Default-Zustand — auf oder zu?

`<details open>` startet aufgeklappt, `<details>` startet zugeklappt.

**Empfehlung:** `open` wenn ein Kind die aktuelle Page ist (`$hasActiveChild`), sonst zu. → Aktive Hierarchie bleibt sichtbar, inaktive Subtrees zugeklappt → übersichtliche Sidebar.

Optional könnte ein User-Preference das Standard-Verhalten überschreiben (z.B. "alle Öffner per Default offen"). Für 1.0 zu früh — kann später kommen.

---

## Frontend (Header/Footer)

Heute komplett flach. Keine Container, keine Children. Wenn der Frontend-User-Wunsch später analog kommt (z.B. ein Mega-Menu mit Sub-Items, dessen Header nicht klickbar ist), nutzen wir dieselbe Konvention.

**Out of scope für diese Entscheidung.** Wird in separater Doku behandelt, wenn der Bedarf auftaucht.

---

## Edit-Form im Backend

Im Navigation-Edit-Form heute:
- Felder `module`, `group`, `controller`, `action` sind alle **required**.

Für einen Öffner-Eintrag müssten diese Felder leer bleiben dürfen.

**Anpassungen:**

- Felder `module/group/controller/action` werden **optional**.
- Wenn alle vier leer sind UND der Eintrag Children hat → "Öffner-Modus" implizit aktiv.
- Im Form-Header könnte ein Hinweis stehen: "Lasse Routing-Felder leer für reine Gruppierung."
- Validator-Erweiterung: entweder alle vier gesetzt oder alle vier leer (Inkonsistenz vermeiden).

---

## Abarbeitungs-Checkliste

### Voraussetzung

- [ ] [navigation-entscheidungs.md](navigation-entscheidungs.md) Side-Quest 1 abgeschlossen (Code + Daten + Tests grün).

### Code

- [x] [`packages/module-backend/res/view/templates/partials/subnav.tpl.php`](packages/module-backend/res/view/templates/partials/subnav.tpl.php):
  - [x] `$isOpener`-Check einbauen (`canonical === '' && hasChildren`).
  - [x] `<details>/<summary>`-Markup für Öffner.
  - [x] `<details open>` wenn `$hasActiveChild`.
  - [x] Bestehender Code-Pfad für normale Items bleibt unverändert.

- [x] [`packages/kernel/shared/src/Validators/NavigationValidator.php`](packages/kernel/shared/src/Validators/NavigationValidator.php):
  - [x] Regel: entweder `module + group + controller + action` alle gesetzt **oder** alle leer (kein Mischzustand).
  - [x] `validateChildren()` — Doppelreferenz-Check (jedes Kind nur einen Parent). Voraussetzung: Validator mit `NavigationRepository` instanziiert.
  - [ ] Bei "alle leer" muss `children` mind. 1 Eintrag enthalten — sonst toter Eintrag. (offen — heute nur soft, weil kein UI children setzt)

- [x] [`packages/module-backend/res/view/templates/NavigationController/edit.tpl.php`](packages/module-backend/res/view/templates/NavigationController/edit.tpl.php):
  - [x] Felder `module/group/controller/action` nicht mehr `required` (HTML).
  - [x] Hinweistext: "Leer lassen für Gruppierung ohne Ziel."

- [x] [`packages/kernel/core/src/Services/NavigationService.php`](packages/kernel/core/src/Services/NavigationService.php):
  - [x] `getActiveSectionByTag()` rekursiv (via `iterateTree`).
  - [x] `iterateSections()` active-Flag rekursiv.
  - [x] Neue Methode `resolveFirstNavigable(Navigation): ?Navigation`.

- [x] [`packages/module-backend/res/view/templates/partials/header.tpl.php`](packages/module-backend/res/view/templates/partials/header.tpl.php):
  - [x] Topbar `href` nutzt `resolveFirstNavigable()` statt `children[0]->getUrl()`.

- [x] [`packages/module-backend/src/Ui/Controllers/Content/NavigationController.php`](packages/module-backend/src/Ui/Controllers/Content/NavigationController.php):
  - [x] `NavigationValidator` mit `repo()` instanziiert (für Doppelreferenz-Check).

### CSS

- [x] `summary`-Default-Marker verstecken (`list-style: none`, `::-webkit-details-marker`).
- [x] Pfeil-Rotation bei `[open]`.
- [x] Aktiver-Subtree-Highlight, wenn ein Child aktiv ist (`--has-active-child`: Label medium-weight + Chevron/Count in Accent).

### Daten

- [x] Test-Eintrag id=13 "Daten-Gruppe (Test)" in `skeleton/data/framework/routing/navigation.json` (Tag `backend-meta`, children `[6, 7]`).
- [x] id=2 `children` auf `[13]` reduziert (keine Doppelreferenz).

### Tests

- [ ] Öffner-Eintrag erstellen, Smoke-Test:
  - Klick auf Öffner → Subtree klappt auf/zu, keine Navigation, keine URL-Änderung.
  - Sub-Item aktiv → Öffner ist standardmässig aufgeklappt.
  - Pfeil-Indikator rotiert beim Auf-/Zuklappen.

### Doku

- [ ] [`docs/topics/navigation.md`](docs/topics/navigation.md) ergänzen:
  - Marker-Konvention "leere Routing-Felder + Children = Öffner"
  - Render-Unterschied Topbar vs. Sidebar
- [ ] ADR-007 (neu) oder ADR-006 Ergänzung (je nach Stand): "Öffner-Konvention für Navigation"
- [ ] `npm run docs:check` → grün.

---

## Offene Punkte

- [ ] **Topbar wirklich nicht ändern?** Bestätigen: Topbar-Sections (id=1, id=2) bleiben navigierend (Klick → erstes Kind). → Empfehlung: ja, bleiben.
- [ ] **Multi-Level Nesting:** Soll ein Öffner selbst Öffner als Children haben können? Strukturell: ja, weil rekursiv. Aber: Sidebar mit drei verschachtelten Ebenen wird unübersichtlich. → Vorerst auf 2 Ebenen begrenzen (kein expliziter Block, aber im Edit-UI ggf. Hinweis).
- [ ] **Active-State des Öffners:** Soll der Öffner-Header selbst visuelles Active-Highlight bekommen, wenn ein Sub-Item aktiv ist? UX-Detail, beeinflusst nur CSS.
- [ ] **Keyboard-Navigation:** `<details>/<summary>` ist nativ keyboard-zugänglich (Space/Enter zum Toggle, Tab durch Children). → Out of the box ok, nichts zu tun.
- [ ] **Mobile/Hamburger-Menu:** Wenn die Sidebar auf Mobile zum Drawer wird, funktionieren `<details>` weiter. → Sollte erfahrungsgemäss klappen, kurzer Live-Test reicht.

---

## 🛑 STAND 2026-05-20 — Pause vor Test (Fortsetzung 2026-05-21)

### Was heute zusätzlich umgesetzt wurde

Über den ursprünglichen Opener-Scope hinaus:

**Tag-Modell umgestellt (single Tag pro Eintrag):**
- `Navigation.tags: array` → `Navigation.tag: ?string`. BodyCleaner-Attribut `#[Clean('nullable', 'ident')]`.
- `NavigationService::getByTag()` — String-Vergleich statt `in_array`.
- `NavigationValidator::validateTag()` — XOR-Regel: Tree-Root (Tag gesetzt, kein Parent) ODER Kind (kein Tag, ein Parent). Orphans und "double role" sind FieldErrors.
- `NavigationController::listAction()` + `removeTagAction()` — Gruppierung und Cascade auf `getTag()`/`setTag()` umgestellt.
- `edit.tpl.php` — Multi-Checkbox → Single-Radio mit "— keine".
- `header.tpl.php` (Backend Topbar) — leerer URL rendert `<span aria-disabled>` statt `<a href="">` (sonst lädt der Browser bei Klick neu).
- Daten umgestellt: id=1 ist nun "Webseiten" (statt "Frontend"), `tag: backend`, `children: []`. Alle Frontend-Pages (id=3-5, 10) sind Tree-Roots mit `tag: frontend`. Legal/Privacy (id=11, 12) sind Tree-Roots mit `tag: frontend-meta`.

**Children-UX:**
- `Navigation::setChildren()` Setter ergänzt.
- "+ Kind"-Button bei jedem Eintrag in `listAction.tpl.php`. Click → `add?parent=<id>` → Form mit hidden `parent_id`, Tag-Sektion versteckt, beim Speichern Parent's children erweitert.
- "+ Eintrag" oben rechts bleibt für Tree-Roots (Tag-Auswahl sichtbar).

**Ref-Feature (UI-Verweise auf existierende Pages):**
- `Navigation.ref: ?int` als neues Feld. `#[Clean('nullable', 'int')]`.
- `NavigationValidator::validateRef()` — Ref-Einträge dürfen keine Routing-Felder, keine Kinder, keinen Tag haben; Target muss existieren und darf nicht selbst Ref sein (keine Ketten).
- `NavigationService::$uiCurrent` als zweite Property neben `$current`. Getter `getUiCurrent(): ?Navigation` returnt `uiCurrent ?? current`.
- `NavigationService::findById(int): ?Navigation` neu.
- `NavigationService::resolveUiCurrent(?int $refId)` — wird vom Dispatcher aufgerufen, setzt `$uiCurrent` wenn `?via=<refId>` zu einem Ref passt, dessen Target gleich `$current` ist. Sonst silently ignored.
- `findByUrl()` — überspringt Ref-Einträge.
- `resolveFirstNavigable()` — gibt Refs als "navigable" zurück (Caller muss `target.getUrl() + ?via=<refId>` bauen).
- `iterateSections()`, `getActiveSectionByTag()`, `isActive()` — alle nutzen `getUiCurrent()` statt `$current`.
- `Dispatcher::resolveNavigation()` liest `?via=` Query-Param und ruft `resolveUiCurrent`.
- `subnav.tpl.php` + Backend `header.tpl.php` — Ref-Rendering: Target laden, href = `target.getUrl() . '?via=' . $refEntry->getId()`. Active-Highlight via `isActive()`.
- `edit.tpl.php` — neues Dropdown "Verweis" (Liste aller navigierbaren, nicht-Ref-Einträge). Controller liefert `$refTargets`.

### Was morgen zu tun ist (2026-05-21)

**Vor dem Test:** APCu-Cache leeren (Entity hat neue Felder `tag`, `ref`; alte serialisierte `Navigation`-Objekte im Cache würden crashen). → Service-Panel "Cache leeren" oder PHP-Server-Restart.

**Test-Szenarios (in dieser Reihenfolge):**

1. **Backend Topbar mit Tag-Modell:**
   - Tabs: "Webseiten" (id=1, inert ohne Kinder), "Stammdaten" (id=2), "test" (id=14).
   - Klick "Webseiten" → nichts passiert (kein href).
   - Klick "Stammdaten" → führt zu Navigation-Listing, Sidebar zeigt Stammdaten-Subtree.
   - Klick "test" → führt zu id=16's URL (im Subtree), Sidebar zeigt test-Subtree.

2. **Frontend Header/Footer:**
   - `/home` → Header zeigt Home, About, Services, Contact (`getByTag('frontend')` flach).
   - Footer zeigt Legal-Block (`getByTag('frontend-meta')`).

3. **Opener-Subtree:**
   - In "Stammdaten" → Sidebar zeigt "Daten-Gruppe (Test)" als `<details open>` (weil id=6 current).
   - Children: Navigation, Benutzer.

4. **+ Kind UX:**
   - Backend → Navigation-Listing → "+"-Button bei id=2 Stammdaten → Form-Titel "Neues Kind in «Stammdaten»", Tag-Sektion versteckt, hidden `parent_id=2`. Speichern → neuer Eintrag erscheint als Kind von id=2.

5. **Ref-Feature (Hauptkontext für morgen):**
   - Anlage: bei einem Eintrag im Webseite-Tree (z.B. id=15 Opener) → "+"-Button → Form öffnen. Name z.B. "Navigation (Verweis)". **Verweis** auswählen: `#6 · Navigation (/backend/content/navigation/list)`. Routing leer lassen. Speichern.
   - Sidebar im Webseite-Tab: neuer Ref-Eintrag sichtbar.
   - Klick auf den Ref → URL `/backend/content/navigation/list?via=<refId>`. Sidebar **bleibt** im Webseite-Tab (nicht zurück zu Stammdaten). Ref-Eintrag ist active markiert.
   - Browser-Refresh mit `?via=` → bleibt im Webseite-Kontext.
   - Klick auf id=6 unter Stammdaten (ohne `?via=`) → Sidebar wechselt zu Stammdaten. ✓ Beide Wege funktionieren.

6. **Validator-Regeln (negativ-Tests):**
   - Ref + Routing-Felder gesetzt → FieldError "Verweis-Eintrag darf keine eigenen Routing-Felder haben".
   - Ref auf nicht-existente ID → FieldError "Ziel-Eintrag #X existiert nicht".
   - Ref auf einen anderen Ref → FieldError "Ketten sind nicht erlaubt".
   - Tag + als Child referenziert → FieldError "sowohl Tag als auch Parent".
   - Tag leer + nirgendwo als Child → FieldError "braucht Tag oder Parent".

### Restpunkte nach Test

- **Teil B — CSS-Polish:** ERLEDIGT 2026-05-29 (NAV-OPENER-CSS-001). `summary`-Marker versteckt, Pfeil-Rotation `[open]`, Active-Subtree-„Trail"-Highlight, Ref-Styling = inset Accent-Border + `↗`-Glyph. Variante „abgesetzt" gewählt (kein gestrichelt — box-shadow kann nicht dashed, würde Layout-Shift erzeugen).
- **Teil D — ADR:** Eine zusammenhängende ADR-Niederschrift, die folgendes festschreibt: Single-Tag-Modell, XOR-Constraint, Opener-Konvention, Refs mit `?via=`-Mechanik, Trennung von `$current` und `$uiCurrent`. Kandidat: ADR-007 "Navigation Tree Model".
