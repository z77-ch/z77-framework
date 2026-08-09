# Arbeitsfläche & Listen — Bauplan

2026-08-08

Umbau der Inhaltsschicht des Backends: die Fläche **innerhalb** der Shell (Panes) und die
Zeile darin. Die Shell selbst (Topbar, Kopfband hc1/hc2, Orientierungsspalte) ist fertig und
wird nicht angefasst — siehe [`shell-rebuild-abschluss-analyse.md`](shell-rebuild-abschluss-analyse.md).

Beschlossene Grundlage: [ADR-018](../02-decisions/adr-018-css-tokens-scoped-to-viewarea-wrapper.md)
Revision 2026-08-08 (strukturelle Layout-Primitive dürfen geteilt werden, visuelle Komponenten
nicht) + Bindungsregeln 5–7.

Stand (alles 2026-08-08): **Schritt 1, 2 und 5 erledigt** — `z77-split` / `split.js` / Schalen-Spalte 3
entfernt; `.be-list` v2 mit Pilot `service/backup/list`; DMS-Bereiche auf `z77-split` umgehängt
(Schritt 5 vorgezogen, damit das Primitiv einen echten Nutzer hat statt nur einen Griff der Schale).
**Offen: Schritt 3** (11 Bildschirme migrieren), danach Schritt 4 (v1 löschen).

Begriffe, weil sie im Gespräch kollidiert sind: **Bereiche** = die ziehbaren Flächen nebeneinander
(`z77-split`, DMS-Muster). **Tabellenspalten** = die Felder einer Zeile innerhalb *eines* Bereichs
(`.be-list` v2). Beides hiess vorher „Spalten".

---

## Warum überhaupt

Auslöser war kein Geschmacksurteil, sondern ein struktureller Befund: es gibt keine
Listenkomponente. Es gibt eine **Navigationsbaum-Zeile** (`.be-tree--hub`, ein starres
6-Spalten-Raster `[Toggle | Aktiv-Schalter | ⋮ | name | url | route]`), in die 12 Bildschirme
ihre Daten falten. Genau einer davon ist wirklich ein Baum.

Zwei Anforderungen kommen dazu und entscheiden den Zuschnitt:

1. **Auftragsbearbeitung und Buchhaltung** sind geplant. Beide wollen „Liste links, Detail
   rechts" — die Struktur, die heute nur das DMS-Drive hat.
2. **Das DMS muss auch im Frontend laufen** (Kundendateien: Rechnungen, Buchhaltungsunterlagen).
   Damit darf die Inhaltsschicht **kein** backend-eigener Baustein sein; sie fällt unter
   ADR-018 Regeln 5–7 und gehört nach `packages/kernel/shared`.

---

## Schritt 1 — Bestandsaufnahme

Methode: alle 12 Listenbildschirme im Quelltext gelesen (`listAction.tpl.php` + die
Kopfband-Partials `list.hc1|hc2` + die `actions`-Partials), nicht im Browser. Erfasst wird,
welche Felder ein Bildschirm **tatsächlich** zeigt — unabhängig davon, in welches der drei
Textfächer sie das Raster heute zwingt.

### Legende

- **Fächer** = wie viele der drei Textfächer (`__name` / `__url` / `__route`) belegt sind
- **Felder** = wie viele fachlich eigenständige Werte die Zeile zeigt
- **Klebe-String** = mehrere Felder mit `·` in EIN Fach geschrieben

### Pro Bildschirm

| # | Bildschirm | Aufbau | Kopfband | Felder | Fächer | Klebe-String |
|---|---|---|---|---|---|---|
| 1 | `content/navigation/list` | Tabs → Sektion → Subsektion → **Baum** | hc1 add, hc2 Filter/Druck/Aliase | 3 | 3 | nein |
| 2 | `content/content/list` | flach, 1 Sektion | hc1 add, hc2 Sprache | 5 | 3 | ja |
| 3 | `content/meta-data/list` | Tabs → Sektion, flach | hc2 Sprache | 4 | 3 | ja |
| 4 | `content/navigation-alias/list` | flach, ohne Sektionskopf | hc1 add | 5 | 3 | ja |
| 5 | `content/translation/list` (UI) | 2 Listen untereinander | hc1 add-Picker | **1 + n Sprachen** | 2 | ja (Matrix) |
| 6 | `content/translation/list` (Slugs) | s. o. | s. o. | **1 + n Sprachen** | 2 | ja (Matrix) |
| 7 | `service/backup/list` | 3 Sektionen, je eigener Auslöser | **keins** | 5 | 2 | ja |
| 8 | `service/email-settings/list` | 1 Sektion + Erklärtext | **keins** | 7 | 3 | ja |
| 9 | `service/member-accounts/list` | 1 Sektion | **keins** | 8 | 3 | ja |
| 10 | `service/job/list` | Banner + 3 Sektionen | **keins** | 10 | 2 | ja |
| 11 | `service/import/list` | **Zustandsmaschine**, 3 Phasen | **keins** | 6 + Diff-Tabelle | 3 | ja |
| 12 | `system/backend-user/list` | flach, ohne Sektionskopf | hc1 add | 3 | 2 | nein |
| — | `documents/drive/list` (DMS) | **3 Panes** | hc1 Upload, hc2 Pfad + Aktionen | 5 | eigene Zeile | nein |

### Was in den Fächern wirklich steht

| Bildschirm | `__name` | `__url` | `__route` |
|---|---|---|---|
| navigation | Name | URL (roh-HTML) | Route |
| content | Titel | Slug | Sprache · Blockzahl · inaktiv |
| meta-data | Seitenname | URL | ✓/✗ + Meta-Titel |
| navigation-alias | Pfad | → Zielname (Zielpfad) | canonical-Label |
| translation | Key | `de: … · fr: …` je Sprache | *leer* |
| backup | Dateiname | Datum · Grösse · Auslöser · Dateizahl | *leer* |
| email-settings | Key (+ „nicht mehr in Config") | Empfänger · Betreff · Routenzahl | Badge **+ Button** |
| member-accounts | E-Mail + Name + Firma | registriert · bestätigt · Mandant | Badge **+ bis zu 3 Buttons** |
| job (Jobs) | Label + Key | Zeitplan · Zustand · nächster Lauf · offen · letzter Lauf | *leer* |
| job (Wartet/Verlauf) | Job-Key | Zustand · Zeit · Urheber · Notiz | Formular (`grid-column:6`) |
| import (Quelle) | Quellenname | Beschreibung | Formular (`grid-column:6`) |
| import (Plan) | Label + Entscheidungsmarker | Konsequenz | Entity |
| backend-user | Benutzername (+ „du") | Rollen | **explizit leer** |

---

## Befunde aus der Bestandsaufnahme

### B1 — Die Zeile hat 3 Fächer, die Daten brauchen 3 bis 10 Felder

Median 5. Acht von zwölf Bildschirmen kleben deshalb mehrere Werte mit `·` in ein Fach. Das
Fach ist `nowrap` + Ellipsis: bei schmaler Spalte verschwinden die hinteren Felder ersatzlos,
und weil es **nirgends Spaltenköpfe gibt**, ist nicht rekonstruierbar, was fehlt.

### B2 — Drei der sechs Rasterspalten sind meistens leer, aber reserviert

| Spalte | Breite | belegt auf |
|---|---|---|
| 1 Aufklapp-Pfeil | 1rem | 1 von 12 (nur navigation) |
| 2 Aktiv-Schalter | 2.4rem | 4 von 12 |
| 3 ⋮ Aktionen | 1.6rem | 9 von 12 |

Zehn Templates rendern ein leeres `<span class="be-tree__toggle" aria-hidden="true"></span>`
allein, um Spalte 1 zu füllen. Jede Zeile beginnt mit ~5rem Nichts. Das ist die Ursache des
optischen „Verschoben"-Eindrucks.

### B3 — Vier Bildschirme brauchen Zeilenaktionen, die das ⋮ nicht fassen kann

`job`, `import`, `email-settings`, `member-accounts` setzen Buttons und ganze Formulare direkt
in die Zeile. Weil das Raster explizit ist, landen sie sonst in den 1.6rem-Icon-Spalten und
überlappen. Die Templates hacken mit `style="grid-column:6"` dagegen — **mit erklärendem
Kommentar**, zweimal wortgleich. Wenn ein Template dokumentieren muss, warum es die Komponente
umgeht, ist die Komponente falsch geschnitten.

### B4 — Drei Bildschirme brauchen einen Unterbereich pro Zeile

`job` (Aktionsleiste), `import` (Diff-Tabelle **und** Aktionsleiste) hängen zusätzliche Blöcke
**ausserhalb** der Zeile an und bilden die leeren Rasterspalten mit `padding-left:2.1rem` von
Hand nach. Eine Zeile ist heute genau eine Zeile — aufklappbare Details gibt es nur im Baum.

### B5 — `translation` ist keine Liste, sondern eine Matrix

Key × Sprache, Spaltenzahl variabel (Anzahl konfigurierter Sprachen). Heute in einen
Klebe-String gefaltet, der abgeschnitten wird — bereits als Einschränkung dokumentiert
(`css-backend.md` LIST-ACTIONS-HUB-001, „Value-column caveat"). Braucht echte Spalten.

### B6 — Vier Bildschirme haben ihre Hauptaktion im Body statt im Kopfband

`backup`, `job`, `import`, `member-accounts` haben **kein** `list.hc1`/`hc2`. Ihre primären
Aktionen („Jetzt sichern", „Jetzt einreihen", „Plan berechnen", „Freischalten") stehen mitten
im Inhalt. Das Kopfband ist der einzige Ort, der über alle Bildschirme gleich aussieht — und
genau diese vier nutzen ihn nicht.

### B7 — Zwei Sektionskopf-Varianten, eine davon ohne CSS

- `.be-list__section-header` + `__section-title` + `__section-badge` — echte Komponente, Titel
  + Zähler + Trennlinie. Genutzt von: navigation, meta-data, email-settings, member-accounts.
- `.be-list__section__head` — **null Zeilen CSS im gesamten SCSS**. Die Optik kommt aus
  Inline-Styles. Genutzt von: translation, backup, job, import.

Das ist keine Gestaltungsentscheidung, sondern ein Copy-Paste-Ast. Es erklärt exakt, warum
diese vier Bildschirme anders aussehen als die anderen vier.

### B8 — Inline-Styles als Ersatz-Designsystem

| Bildschirm | `style="` |
|---|---|
| import | 52 |
| job | 25 |
| translation | 13 |
| backup | 8 |
| email-settings | 6 |

Wiederkehrend derselbe String `font-size:.8rem;color:var(--be-muted,#94a3b8)`. Der
`#94a3b8`-Fallback ist laut eigener Entscheidung (`css-backend.md` CSS-BACKEND-TOKENS-001) tot
— **49 Vorkommen** im Repo.

### B9 — Zwei Farbsysteme, eines dunkelmodus-blind

`email-settings` und `member-accounts` nutzen `.badge--success|--warning|--muted` aus
`_badges.scss`. Die laufen auf `--color-*`, und `--color-*` wird unter `[data-be-theme="dark"]`
**nie** überschrieben → helle Chips auf dunkler Fläche. Gleicher Fehler:
`.be-tabs__tab--active { color: var(--color-text-inverse) }` (hart weiss, auch auf hellen
Akzenten wie citrus/sonne).

### B10 — Tote und fehlende Bausteine

| Baustein | Zustand |
|---|---|
| `components/_tables.scss` (`.table`) | vollständig definiert, **von keinem Template genutzt**, läuft auf `--color-*` |
| `components/_pagination.scss` | vollständig definiert, **von keinem Template genutzt** |
| Sortierung | **existiert nirgends** — kein Bildschirm kann nach einer Spalte sortieren |
| Spaltenköpfe | **existieren nirgends** |
| `.be-tree__actions` | verwaist seit dem ⋮-Umbau, Entfernung steht als Pendenz offen |

Nicht sortieren und nicht blättern zu können ist für Navigation (30 Einträge) verkraftbar. Für
Buchhaltung ist es ein Ausschlusskriterium.

### B11a — Schalen-Spalte 3 ist Gerüst, kein Feature (entschieden: entfällt)

Beim Prüfen der Übergabe-Bedingung („Spalte 3 darf fallen, wenn der Inhalt das dann festlegt")
verifiziert:

- `html-shell-skeleton.tpl.php` schreibt `data-col3="off"` **fest**. Nichts im Repo schaltet je
  auf `"on"`.
- `partials/shell/preview.tpl.php` rendert damit ausschliesslich seinen Leerzustand
  („Auswählen für die Vorschau.").
- `hc3` ist in `BackendAbstractController::loadHeaderSlots()` verdrahtet, aber **kein einziges**
  `*.hc3.tpl.php` existiert.
- Der rechte Mobil-Drawer ist **unerreichbar**: der einzige `[data-shell-drawer]`-Auslöser im
  ganzen Repo ist der Burger mit `="l"` (topbar). `is-drawer-r` kann nie gesetzt werden — der
  Schliessen-Knopf in `preview.tpl.php` schliesst etwas, das sich nicht öffnen lässt.

**Konsequenz für den Entwurf:** Fallenlassen kostet nichts — es geht keine Funktion verloren.
Aber es **schenkt** auch nichts: das Mobil-Verhalten für ein rechtes Detail-Pane ist heute nur
toter Code. Die Arbeitsfläche muss es **bauen**, nicht erben. Siehe B11b.

### B11b — „Detail auf schmalem Schirm" ist in BEIDEN Umsetzungen ungelöst

`_drive.scss` blendet das Vorschau-Pane unter 60rem aus, mit dem Kommentar „kept reachable via
row click → modal in JS". **In `drive.js` gibt es dazu nichts** — keine `matchMedia`, kein
`innerWidth`, keine Breiten-Logik (165 Zeilen, verifiziert per Grep). Unter 60rem ist die
Vorschau ersatzlos weg.

Damit hat weder die Shell (B11a) noch das DMS eine funktionierende Antwort auf „Detail auf
schmalem Schirm". Das ist eine **Anforderung an die Arbeitsfläche**, kein Rückschritt durch das
Streichen von Spalte 3.

### B11 — Panes hat genau ein Bildschirm

`.dms-drive` (Ordner | Liste | Vorschau) ist die einzige Umsetzung des Musters, das die
kommenden Module brauchen. Zwei weitere Bildschirme hätten sie offensichtlich verdient:

- **navigation** — Baum links, Detail rechts (heute: Modal über der ganzen Liste)
- **meta-data** — Seitenliste links, Metadaten-Formular rechts (heute: Modal)

`.dms-drive` liegt in `module-dms` und ist damit für keinen anderen Bildschirm erreichbar.

---

## Was der Entwurf können muss (abgeleitet aus B1–B11)

1. **Zeile = n benannte Felder**, nicht drei Fächer. Mit Kopfzeile und definierter Priorität
   beim Schmalerwerden (welches Feld weicht zuerst).
2. **Optionale Zeilen-Slots** statt reservierter Leerspalten: Auswahl, Zustandsschalter,
   Aktionsmenü erscheinen nur, wo es sie gibt (B2).
3. **Aktionsbereich pro Zeile**, der Buttons und Formulare trägt, ohne das Raster zu umgehen
   (B3).
4. **Aufklappbarer Detailbereich pro Zeile** für Diff, Unterformular, Fehlerliste (B4).
5. **Echte Spalten**, damit `translation` eine Matrix sein kann (B5).
6. **Sortieren pro Spalte + Blättern** (B10) — Voraussetzung für Buchhaltung.
7. **Ein Sektionskopf**, nicht zwei (B7).
8. **Panes als geteiltes, host-neutrales Primitiv** mit Ziehgriff an jeder Kante (B11 + ADR-018
   Regeln 5–7), Präfix `z77-`.
9. **Ein Detail-Pane muss auf schmalem Schirm zum Overlay werden**, nicht verschwinden — mit
   Auslöser, Hintergrund und Schliessen. Weder Shell noch DMS haben das heute (B11a/B11b); es
   ist neu zu bauen, nicht zu übernehmen.
   *Erweitert 2026-08-09 nach dem ersten Durchklicken:* dasselbe gilt für die
   **Orientierung**. Unter 40rem standen sonst zwei zu schmale Spalten nebeneinander. Die
   Arbeitsfläche kennt deshalb drei Rollen — `--nav`, `--grow`, `--detail` — und ein
   Bildschirm erbt das Schmalverhalten, indem er seine Bereiche benennt. Auftragsbearbeitung
   und Buchhaltung bekommen es ohne eigenes CSS. Bedingung, die dabei nicht verhandelbar ist:
   jedes Overlay bringt seinen Auslöser **innerhalb** der Arbeitsfläche mit — ein Auslöser im
   Kopfband der Schale ist für eine Container-Query unerreichbar und im Frontend-Host gar nicht
   vorhanden. Details: `css-backend.md` SPLIT-NARROW-001.

---

## Schritt 2 — Entwurf

Zwei getrennte Bausteine, **bewusst nicht einer**. Die Trennlinie ist ADR-018 Regel 5
(Geometrie-Test): Panes bestehen ihn, die Zeile nicht.

| Baustein | Wo | Präfix | Warum dort |
|---|---|---|---|
| **Arbeitsfläche** (Panes, Ziehgriffe, Overlay) | `packages/kernel/shared` | `z77-` | reine Geometrie → geteilt (ADR-018 R5/R6) |
| **Liste** (Zeile, Spalten, Kopf) | `module-backend` | `be-` | trägt Schrift, Farbe, Zustände → visuell, bleibt bereichseigen |

**Konsequenz, die man kennen muss:** das DMS bekommt die Arbeitsfläche, behält aber seine
eigene Zeile (`.dms-file`, zweizeilig mit Vorschaubild). Müsste später eine *Backend*-Liste im
Frontend erscheinen (z.B. Rechnungen als Liste statt als Dateien), wird sie dort mit der
Frontend- bzw. DMS-Zeile gebaut — nicht durch Teilen der Backend-Zeile. Das ist die bewusste
Position von ADR-018, kein Versehen.

### 2.1 `z77-split` — die Arbeitsfläche

Markup-Vertrag. Breiten sind **Werte**, keine Gestaltung, und stehen deshalb als
Custom-Property im Markup — dasselbe Muster wie das bestehende `style="--node-depth:2"`:

```html
<div class="z77-split" style="--z77-split-a: 16rem; --z77-split-b: 22rem">
    <div class="z77-split__pane">…Orientierung…</div>

    <span class="z77-split__handle" data-z77-split="--z77-split-a"
          data-z77-split-min="180" data-z77-split-max="480"></span>

    <div class="z77-split__pane z77-split__pane--grow">…Liste…</div>

    <span class="z77-split__handle" data-z77-split="--z77-split-b"
          data-z77-split-min="240" data-z77-split-max="560"></span>

    <div class="z77-split__pane z77-split__pane--detail" data-z77-split-detail>…Detail…</div>
</div>
```

- **n Panes → n−1 Griffe.** Jede Trennlinie ist fassbar; das `--grow`-Pane nimmt den Rest.
  Genau ein `--grow` pro Arbeitsfläche.
- **Der Griff bringt seine Parameter selbst mit** (Variablenname, Minimum, Maximum). Die
  Zugrichtung ergibt sich daraus, ob das zu ändernde Pane vor oder hinter dem Griff steht —
  keine `l`/`r`-Sonderfälle mehr.
- **Jedes Pane scrollt selbst** (`overflow:auto`, `min-height:0`), die Seite scrollt nicht mit.
- **Vollbild pro Pane** über das vorhandene Muster: ein `[data-z77-split-full]`-Knopf setzt
  `[data-full]` auf dem Wurzelelement — dieselbe Mechanik wie `[data-popup-fullscreen]` bei den
  Modalen, kein neuer Apparat.

**Schmaler Schirm (Anforderung 9, heute nirgends gelöst).** Ein Pane mit
`data-z77-split-detail` wird unterhalb einer Schwelle zum Overlay statt zu verschwinden:
Hintergrund, Schliessen-Knopf, `Esc`. Auslöser ist ein beliebiges `[data-z77-split-open]`
(typisch die Zeile selbst).

Die Schwelle ist eine **Container-Query, keine `@media`-Abfrage** — und das ist keine
Stilfrage: die Panes sind ziehbar, also kann ein Pane in einem breiten Fenster schmal sein.
Eine Viewport-Abfrage würde genau dann das Falsche tun. (Sass reicht `@container` unverändert
durch — geprüft.)

**Tokens** (vollständiger Satz auf `.z77-split`, ADR-018 R7 — durch den Geometrie-Test klein):

| Token | Zweck |
|---|---|
| `--z77-split-line` | Trennlinie zwischen Panes |
| `--z77-split-handle` | Griff in Ruhe |
| `--z77-split-handle-active` | Griff bei Hover/Ziehen |
| `--z77-split-backdrop` | Hintergrund des Overlays |

Bindung host-seitig, wie beim DMS: `.be .z77-split { --z77-split-line: var(--be-line); … }` in
`components/_dms-host.scss` (oder einer Geschwisterdatei). Ein Host, der nicht bindet, bekommt
neutrale Standardwerte.

**JS: `packages/kernel/shared/res/assets/js/split.js`** — neben `panel-toggle.js`, gleiche
Machart (datenattribut-getrieben, eine IIFE, kein Build).

`shell.js` verliert seine 25 Zeilen `makeResize` und wird **erster Nutzer**: die Schale setzt
dieselben Attribute wie jede Arbeitsfläche. Damit gibt es einen Ziehmechanismus im Framework,
nicht zwei.

**Breiten merken:** pro Benutzer, global fürs Backend. Beim Loslassen ein
Fire-and-Forget-POST auf `save-preferences` (wie `appearance.js`); Feld `split_widths` als
Abbildung Variablenname → px. `savePreferencesAction` muss wie gehabt von den **gespeicherten**
Preferences ausgehen, sonst fallen fremde Felder raus.

### 2.2 `.be-list` v2 — die Zeile

Kernwechsel: **die Liste definiert ihre Spalten einmal, jede Zeile erbt sie.** Damit ist die
Spaltenzahl eine Eigenschaft der Liste statt eine Konstante der Komponente — das ist die
eigentliche Auflösung von B1.

```html
<div class="be-list" style="--be-list-cols: minmax(10rem,1fr) minmax(0,2fr) 9rem">
    <div class="be-list__head">
        <a class="be-list__col" href="?sort=name">Name</a>
        <a class="be-list__col" href="?sort=size">Grösse</a>
        <span class="be-list__col">Herkunft</span>
    </div>

    <div class="be-list__row">
        <span class="be-list__cell">…</span>
        <span class="be-list__cell" data-priority="2">…</span>
        <span class="be-list__cell" data-priority="3">…</span>
    </div>
</div>
```

**Optionale Vorspalten statt reservierter Leerspalten** (löst B2). Jede Vorspalte trägt ihre
eigene Spur bei; ohne Modifier trägt sie **nichts** bei — kein leeres `<span>` mehr, kein
5rem-Vorlauf:

```scss
.be-list__row,
.be-list__head {
    display: grid;
    grid-template-columns:
        var(--be-list-select,) var(--be-list-state,) var(--be-list-menu,)
        var(--be-list-cols)
        var(--be-list-actions,);
}
.be-list--select  { --be-list-select:  1.5rem; }
.be-list--state   { --be-list-state:   2.4rem; }
.be-list--menu    { --be-list-menu:    1.6rem; }
.be-list--actions { --be-list-actions: auto; }
```

`var(--x,)` mit leerem Rückfallwert liefert nichts, wenn die Variable nicht gesetzt ist — Sass
reicht das unverändert durch (geprüft). Keine Kombinatorik, beliebig kombinierbar.

**Aktionsspalte** als echte letzte Spalte (`.be-list__actions`) — trägt Buttons und ganze
Formulare. Damit entfallen die `style="grid-column:6"`-Hacks in `job` und `import` (B3).

**Detailbereich pro Zeile** als volle Breite (`grid-column: 1 / -1`), aufklappbar über ein
Attribut an der Zeile. Damit entfallen die `padding-left:2.1rem`-Nachbauten (B4):

```html
<div class="be-list__row" data-open>…</div>
<div class="be-list__detail">…Diff / Unterformular / Fehlerliste…</div>
```

**Verhalten beim Schmalerwerden:** `data-priority="n"` pro Zelle, ausgewertet per
`@container` — dieselbe Begründung wie oben, weil die Liste in einem ziehbaren Pane sitzt. Die
Spalte mit der höchsten Ziffer weicht zuerst; ihr Wert wandert in den Detailbereich statt zu
verschwinden. Kopfzeile und Zelle nutzen dasselbe Attribut, also können sie nicht auseinanderlaufen.

**Sortieren und Blättern serverseitig** (B10): `?sort=` / `?dir=` / `?page=` als Links, kein JS.
Die vorhandene, ungenutzte `_pagination.scss` wird damit erstmals verdrahtet. Entspricht
Konventionsregel 7 (so wenig JS wie möglich) — und funktioniert ohne JS überhaupt.

**Ein Sektionskopf** (B7): `.be-list__section-header` bleibt, das CSS-lose
`.be-list__section__head` verschwindet aus allen vier Templates.

**Matrix** (B5): `translation` setzt `--be-list-cols` aus der Sprachliste zusammen — n Sprachen
= n Spalten. Kein Sonderfall, nur ein anderer Wert.

### 2.3 Reihenfolge

Jeder Schritt ist für sich lauffähig und rückholbar.

| # | Schritt | Rückholung |
|---|---|---|
| 1 | **erledigt 2026-08-08** — `z77-split` + `split.js` gebaut, `shell.js` wird Nutzer, Spalte 3 entfernt | Datei löschen, `shell.js` zurück |
| 2 | **erledigt 2026-08-08** — `.be-list` v2 **neben** `.be-tree--hub`, pilotiert auf `service/backup/list` | Modifier nicht benutzen |
| 3 | 12 Bildschirme einzeln migriert; die 4 fehlenden Kopfbänder (B6) entstehen dabei | pro Bildschirm einzeln |
| 4 | `.be-tree--hub`, `.be-tree__actions`, `.table` gelöscht | erst wenn Schritt 3 komplett |
| 5 | **erledigt 2026-08-08, vorgezogen** — DMS-Bereiche auf `z77-split` (`.dms-file` bleibt) | `_drive.scss` zurück |

**Vorgezogen und erledigt (2026-08-08):** die vier fehlenden Kopfbänder. Beim Bauen zeigte
sich, dass B6 falsch diagnostiziert war — es fehlten nicht die Aktionen, es fehlte das **Band**:
`html-shell-skeleton.tpl.php` rendert es nur, wenn ein Slot gefüllt ist, also begann der Inhalt
dieser vier Bildschirme 46px höher als überall sonst. Behoben, indem das Band bedingungslos
rendert (es gehört zur Shell, nicht zum Bildschirm). Gefüllt wurde mit dem, was jeder Bildschirm
wirklich hat, statt mit erfundenen Add-Buttons:

| Bildschirm | Band | Warum kein hc1-Add |
|---|---|---|
| `backup` | hc1: `.be-shell-add`-Picker (Daten / Datenbank / Gesamtprojekt) | drei Arten → Picker ist die bestehende Regel |
| `job` | hc2: Runner-Heartbeat als `.be-shell-status` | Einreihen ist pro Job, nicht global |
| `import` | hc2: die zwei globalen Plan-Aktionen, nur solange ein Plan existiert | „Plan berechnen" ist pro Quelle |
| `member-accounts` | hc2: Warteschlange («N Konten warten auf Freischaltung») | Konten kommen von aussen, jede Aktion ist pro Zeile |

Neu dabei entstanden und für Schritt 3 wiederverwendbar: `.be-shell-status`, `.be-list__empty`,
`.be-list__section-hint`. Details: `css-backend.md` HEADER-BAND-ALWAYS-001.

## Offene Entscheidungen

- **Präfix des geteilten Primitivs: `z77-`** — entschieden 2026-08-08. Erfüllt ADR-018 Regel 6
  (bereichsunabhängig, weder `be-` noch `fe-` noch `dms-`).
- **Breiten merken.** Heute setzt `shell.js` nur einen Inline-Style; nach dem Neuladen ist alles
  auf Standard. Weg existiert (`UserPreferences` + `save-preferences`). Offen: global fürs
  Backend oder pro Bildschirm.
- **`import` bleibt Sonderfall.** Der Bildschirm ist eine Zustandsmaschine mit Formularen, keine
  Liste. Er sollte die neue Zeile nutzen dürfen, aber nicht ihr Zuschnitt-Massstab sein.

## Nicht Teil dieses Umbaus

- Die Shell (Topbar, Kopfband, Orientierungsspalte) — fertig, bleibt.
- `.dms-file` (die DMS-Zeile) — funktioniert, ist zweizeilig mit Vorschaubild und hat eine
  eigene Berechtigung. Kandidat für einen späteren Abgleich, nicht für Ersatz.
- Farb-/Kontrastfragen aus B9 — eigener Durchgang, sonst vermischt sich ein Layout-Umbau mit
  einer Palettenkorrektur.
