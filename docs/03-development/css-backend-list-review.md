# Review — `navigation/list.css` Bereinigung

> **Status: umgesetzt am 2026-06-09.** Alle Punkte inkl. Rename `.be-nav-*` → `.be-list`/`.be-tree`/`.be-tabs`
> und Button-Vereinheitlichung auf `.be-btn` erledigt. `list.css` gelöscht, Styles in
> `components/_list.scss` + `_buttons.scss` (→ base.css). Siehe `docs/topics/css-backend.md`
> → known issue `CSS-LIST-CONSOLIDATION-001`. Dieses Dokument bleibt als Analyse-/Entscheidungs-Record.

Datum: 2026-06-09
Scope: `packages/module-backend/res/assets/css/navigation/list.css`
Ziel: Wiederverwendbare Klassen aus `list.css` in die immer geladene SCSS-Pipeline
(`base` = common, ggf. `desktop`/`mobile`) überführen, damit Tools-Listen im Backend
überall identisch aussehen und nicht pro Controller separat geladen werden müssen.

> Hinweis: Der unverwandte Doku-System-Guide (früher `review.md` am Repo-Root) liegt
> jetzt als [`docs-system-review.md`](docs-system-review.md) im selben Ordner.

---

## 1. Ausgangslage

### 1.1 `list.css` ist kein SCSS-Output

`list.css` liegt handgeschrieben unter `res/assets/css/navigation/` — **außerhalb** der
7-Datei-SCSS-Pipeline (`base/mobile/tablet/desktop/nav-*`). Es gibt keine
`navigation/list.scss`-Quelle. Damit:

- Hält es die Token-/BEM-Konventionen aus `css-conventions.md` nur inkonsistent ein
  (Fallback-Hexwerte überall: `var(--be-line, #e2e8f0)` etc.).
- Wird es **nicht** vom Watcher/Build erfasst — Änderungen müssen direkt in der `.css` erfolgen.

### 1.2 Es wird von 8 Controllern separat geladen

`addCss('navigation/list', …)` in:

| Controller | Zweck |
|---|---|
| `Content\NavigationController` | Navigationsbaum (DnD, Filter, Subsections) |
| `Content\NavigationGroupController` | Umgebungen / Render-Slots (DnD) |
| `Content\NavigationAliasController` | Alias-Liste |
| `Content\MetaDataController` | Metadaten-Liste (+ Tabs) |
| `Content\ContentController` | Content-Liste (+ `content/editor`) |
| `Content\TranslationController` | Übersetzungs-Liste |
| `System\LoginUserController` | Benutzer-Liste |

→ Der Name `navigation/list` ist **irreführend**: Die Datei ist faktisch das geteilte
„Backend-Listen/Tools"-Stylesheet, das von 7 Nicht-Navigations-Listen mitbenutzt wird.

### 1.3 Zwei parallele Button-Systeme (Kerninkonsistenz)

| Klasse | Quelle | geladen | Verwendung in Templates |
|---|---|---|---|
| `.btn` (+ `--primary/ghost/danger/sm/…`) | `scss/components/_buttons.scss` → **base.css (immer)** | immer | **4×** (nur Login/Setup) |
| `.be-btn` (+ gleiche Varianten) | `navigation/list.css` → **separat** | nur in 8 Controllern | **89×** (gesamtes CRUD-Backend) |

Das tatsächliche Backend-Button ist `.be-btn`, lebt aber in der separat geladenen Datei,
während das „offizielle" `.btn` in base.css praktisch ungenutzt ist. Confirm-/Edit-Modals
nutzen `.be-btn` und funktionieren nur, weil der jeweilige Controller `list.css` lädt.

---

## 2. Klassen-Inventar (was wird wo benutzt)

Legende: ● = in allen 7 Listen, ◐ = mehrere, ○ = einzeln, ✗ = tot.

### 2.1 Geteilt über ALLE Listen → gehört in base (common)

Dies ist die „Tools-Liste", die laut Anforderung überall gleich sein muss.

| Klassen-Block | Reichweite | Templates |
|---|---|---|
| `.be-nav-body` | ● 7 | alle `listAction` |
| `.be-nav-section` (+ `__header/__title/__badge/__actions`) | ● 7 | alle `listAction` |
| `.be-nav-node` (+ `__row/__toggle/__name/__url/__route/__actions`, `--has-children`, `.is-open`, `--ref`, `--inactive`, `__ref-label`, `__canonical`) | ● 7 | alle `listAction` |
| `.be-icon-btn` (+ `--danger`) | ● 8 | alle `listAction` + `ContentController/edit` |
| `.be-btn` (+ `--primary/ghost/danger/sm`) | ◐ 21 | alle Controller, inkl. `edit` + `confirmDelete` |

### 2.2 Mehrfach, aber nicht überall

| Klassen-Block | Reichweite | Templates |
|---|---|---|
| `.be-nav-tabs` / `.be-nav-tab` (+ `--active/--add`) | ◐ 2 | `NavigationController`, `MetaDataController` |
| Drag&Drop-Modifier (`--dragging`, `--drop-before/after/into`, `[draggable]`) | ◐ 2 | `NavigationController`, `NavigationGroupController` |

### 2.3 Echt navigations-spezifisch → darf in `list.css` bleiben

| Klassen-Block | Reichweite | Templates |
|---|---|---|
| `.be-nav-filter` (+ `__input/__icon`) | ○ 1 | `NavigationController` |
| `.be-nav-subsection` (+ `__header/__title`) | ○ 1 | `NavigationController` |

### 2.4 Toter Code → löschen

| Klasse | Verwendung |
|---|---|
| `.be-tag` (Zeile 84) | ✗ 0 — nirgends in Templates/JS/src |
| `.be-children-table` (Zeile 87–90) | ✗ 0 — Kommentar sagt „modal-specific, kept here", aber kein Template nutzt es |

### 2.5 Inkonsistenzen nebenbei gefunden

- Template `NavigationGroupController/listAction.tpl.php:31` nutzt `.be-nav-section__tree`,
  diese Klasse ist in `list.css` **nicht definiert** (kein Styling, vermutlich Altlast).
- Inline-Styles in Templates (`style="font-size:.8rem;color:var(--be-muted,#94a3b8)…"`)
  statt einer Empty-State-Klasse — Kandidat für einen `.be-list__empty`-Modifier.

---

## 3. Zielbild

> Wiederverwendbares wandert in die SCSS-Pipeline und kompiliert nach `base.css`
> (immer geladen = „common"). Responsive Eigenheiten gehen in `_mobile.scss`/`_desktop.scss`.
> Nur echt navigations-spezifisches bleibt in einer dann schlanken, korrekt benannten Datei.

### 3.1 Namens-Entscheidung (offen — bitte bestätigen)

`.be-nav-*` ist semantisch falsch für eine generische Backend-Liste/-Baumstruktur, die von
7 Nicht-Navigations-Controllern benutzt wird. Empfehlung:

- **Empfohlen:** Umbenennen in eine generische Komponente — `.be-list` / `.be-list__row` …
  bzw. `.be-tree` für die Baum-Teile. Sauber, selbsterklärend, BEM-konform.
  Aufwand: Rename in ~7 Templates + JS (`navigation/list.js`, `navigation-group/list.js`).
- **Alternative (minimal):** Klassennamen `be-nav-*` beibehalten, nur verschieben.
  Kein Template-Churn, aber der irreführende Name bleibt.

### 3.2 Vorgeschlagene Zielstruktur (SCSS)

| Inhalt | Ziel-Datei | landet in |
|---|---|---|
| `.be-btn` (+ Varianten) | **mit `.btn` zusammenführen** in `components/_buttons.scss` | base.css |
| `.be-icon-btn` (+ `--danger`) | `components/_buttons.scss` | base.css |
| `.be-nav-body/__section/__node/__tabs` → `.be-list*` | neu `components/_list.scss` | base.css |
| `.be-tag` / `.be-children-table` | **löschen** | — |
| Feste Breiten (`__name` 140px, Filter 200px) als responsive Anpassung | `layout/_mobile.scss` (eng) / `layout/_desktop.scss` (breit) | mobile.css / desktop.css |
| `.be-nav-filter`, `.be-nav-subsection` | bleiben in `navigation/list.css` (oder neu `navigation/list.scss`) | separat |

Hinweis Buttons: `.btn` (4×) vs `.be-btn` (89×) sollten **ein** System werden. Vorschlag:
auf `.be-btn` standardisieren (es ist das tatsächlich genutzte), Login/Setup auf `.be-btn`
umstellen, `.btn` aus `_buttons.scss` entfernen. Damit nur ein Backend-Button, in base.css.

### 3.3 Folge für die Controller

Sobald die geteilten Klassen in `base.css` liegen, entfallen die meisten
`addCss('navigation/list')`-Aufrufe:

- `MetaDataController`, `TranslationController`, `NavigationAliasController`,
  `LoginUserController`, `ContentController`, `NavigationGroupController`:
  `addCss('navigation/list')` **entfernt** (geteilte Klassen sind global).
- Nur `NavigationController` (Filter + Subsection) lädt die dann schlanke navigations-
  spezifische Datei — falls überhaupt noch nötig.
- `ContentController` behält `addCss('content/editor')`.

---

## 4. Migrationsplan (Schritte)

1. **Entscheidung einholen:** Rename `.be-nav-*` → `.be-list*`/`.be-tree*`? (Abschnitt 3.1)
   und Button-Vereinheitlichung auf `.be-btn`? (Abschnitt 3.2)
2. **Toter Code raus:** `.be-tag`, `.be-children-table` aus `list.css` löschen.
3. **`components/_buttons.scss`:** `.be-btn`-Varianten + `.be-icon-btn` ergänzen
   (Token-Werte statt Hex-Fallbacks, BEM, keine Hardcodes). Ggf. `.btn` ersetzen.
4. **`components/_list.scss` neu anlegen:** Listen-/Node-/Tabs-Styles übernehmen,
   in `base.scss` per `@use 'components/list';` einbinden.
5. **Feste Breiten responsiv machen:** Breiten nach `_desktop.scss` verschieben, mobile
   Anpassung in `_mobile.scss` (aktuell gibt es keine — Listen brechen vermutlich < 768px).
6. **Templates anpassen:** Klassennamen umstellen (falls Rename), `be-nav-section__tree`
   bereinigen, Empty-State-Inline-Styles in `.be-list__empty` o. ä. überführen.
7. **`list.css` → schlank:** nur `.be-nav-filter` + `.be-nav-subsection` (oder ganz
   auflösen, falls auch diese generalisiert werden sollen).
8. **Controller entrümpeln:** überflüssige `addCss('navigation/list')`-Aufrufe entfernen.
9. **Build + Sichtprüfung:** `npm run build:backend`, danach alle 7 Listen + Modals
   (edit/confirmDelete) visuell prüfen — Tools-Liste muss überall identisch sein.
10. **Doku:** `docs/topics/css-backend.md` aktualisieren (neue `_list.scss`-Komponente,
    `list.css`-Status, Button-Vereinheitlichung), `npm run docs:check` grün.

---

## 5. Offene Fragen / Entscheidungen

1. **Rename** `.be-nav-*` → generisch? (empfohlen, aber Template-/JS-Churn)
2. **Button-Vereinheitlichung** `.btn` ↔ `.be-btn` — jetzt mitmachen oder separat?
3. **Tabs** (`.be-nav-tabs`) als eigene Komponente `_tabs.scss` oder Teil von `_list.scss`?
4. Sollen `.be-nav-filter` / `.be-nav-subsection` ebenfalls generalisiert werden
   (Filter wird vermutlich auch andere Listen betreffen), oder bewusst navigations-lokal?
5. `navigation/list.css` als Roh-CSS behalten oder in eine echte `.scss`-Quelle überführen,
   damit sie konventions-/build-konform ist?
