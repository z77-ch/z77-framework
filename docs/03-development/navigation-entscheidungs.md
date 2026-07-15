# navigation-entscheidungs.md — `url` vs. `alias_url`

**Status:** DONE (2026-05-20)
**Entscheid:** Option 5 — `alias_url` entfallen, Feld `url` hält die optionale friendly URL. Canonical wird aus `module/group/controller/action` abgeleitet.
**Umgesetzt:** Code, Daten, Templates, Validator, Doku — siehe abgehakte Checkliste unten. Topic-Doc-Update in `../topics/navigation.md` (NAV-URL-001).

> **Hinweis (2026-05-29):** Die `url`-Entscheidung gilt unverändert. Die JSON-Snapshots unten zeigen aber noch das alte Tree-Modell (`children: int[]` am Parent) — der aktuelle Stand ist `parentId` am Kind + `sortKey` für die Reihenfolge (SSOT: [`../topics/navigation.md`](../topics/navigation.md)).

---

## Problemstellung

Die `Navigation`-Entity hat heute zwei URL-Felder: `url` und `alias_url`. Semantik ist weder im Code noch in den Daten konsistent.

### Daten heute (skeleton/data/framework/routing/navigation.json)

| id | name  | `url`                            | `alias_url` | module/group/controller/action          |
|----|-------|----------------------------------|-------------|-----------------------------------------|
| 1  | Frontend (Container) | `""`              | `null`      | (alle leer)                             |
| 2  | Stammdaten (Container) | `""`            | `null`      | (alle leer)                             |
| 3  | Home  | `/home`                          | `null`      | frontend/main/index/home                |
| 4  | About | `/about`                         | `null`      | frontend/main/index/about               |
| 6  | Nav.  | `/backend/content/navigation/list` | `null`    | backend/content/navigation/list         |
| 8  | Login | `/backend/system/login/login`    | `/login`    | backend/system/login/login              |
| 9  | Log.  | `/backend/system/login/logout`   | `null`      | backend/system/login/logout             |

Drei Patterns nebeneinander:

- **A (Frontend, 7 Einträge):** `url` enthält die **friendly Kurz-URL**, `alias_url = null`. Die canonical 4-Segment-Form taucht nirgends auf.
- **B (Backend-meta, 3 Einträge):** `url` enthält die **canonical 4-Segment-Form** (= das, was aus den 4 Feldern eh ableitbar wäre), `alias_url = null`.
- **C (Login, 1 Eintrag):** `url` enthält die **canonical 4-Segment-Form**, `alias_url` die **friendly Kurz-URL**.

Pattern A und C widersprechen sich: bei A steht die friendly URL in `url`, bei C in `alias_url`.

### Designabsicht (aus dem Code rückgelesen)

`edit.tpl.php:41` (Placeholder im Eingabefeld für `url`):

```text
"/modul/group/controller/action"
```

`edit.tpl.php:47` (Placeholder für `alias_url`):

```text
"/kurzurl"
```

→ Der **ursprüngliche Designentscheid** war: `url = canonical 4-Segment`, `alias_url = friendly Kurz-URL`. Pattern C entspricht dem. Pattern A bricht den Entscheid und macht das Feld `url` semantisch ambivalent.

---

## Code-Review — wer liest die Felder?

### Entity (`packages/kernel/shared/src/Entities/Navigation.php`)

| Zeile | Methode                | Verhalten                                                    |
|-------|------------------------|--------------------------------------------------------------|
| 51    | `getUrl(): string`     | `aliasUrl ?? url` — **bevorzugt Alias, fällt auf `url` zurück** |
| 52    | `getCanonicalUrl()`    | Roh-Lesen von `$url`                                         |
| 53    | `getAliasUrl(): ?string` | Roh-Lesen von `$aliasUrl`                                  |

`getUrl()` ist der **Public-API-Punkt für UI-Rendering**. Er versteckt die Inkonsistenz vor den Templates — funktioniert aktuell durch Glück: in Pattern A ist `aliasUrl = null` → `url` wird zurückgegeben (friendly, gut). In Pattern C ist `aliasUrl = "/login"` → Alias wird zurückgegeben (friendly, gut). In Pattern B wird canonical zurückgegeben.

### Routing-Lookup (`packages/kernel/core/src/Services/NavigationService.php:59-66`)

```php
public function findByUrl(string $url): ?Navigation {
    foreach ($this->getAll() as $entry) {
        if ($entry->getCanonicalUrl() === $url) return $entry;                                   // 62
        if ($entry->getAliasUrl() !== null && $entry->getAliasUrl() === $url) return $entry;     // 63
    }
    return null;
}
```

- Canonical hat **Vorrang**, Alias ist Fallback.
- Beide Felder können als Eingang dienen.

### Anzeige in Backend-Navigation

| Datei                                                              | Logik                                                                  |
|--------------------------------------------------------------------|------------------------------------------------------------------------|
| `NavigationController.php:124-126`                                 | nach Speichern: `alias ?? canonical` als Hauptanzeige, canonical in Klammern wenn Alias gesetzt |
| `NavigationController/listAction.tpl.php:12-14, 27`                | gleiches Pattern: `displayUrl = aliasUrl ?? url`, canonical in Klammern wenn Alias gesetzt |
| `NavigationController/edit.tpl.php:41, 47`                         | Form: zwei getrennte Felder `url` und `alias_url` mit klarem Placeholder |

### Frontend-Rendering

| Datei                            | Code                                |
|----------------------------------|-------------------------------------|
| `module-frontend/.../header.tpl.php:13, 30` | `<a href="<?= e($entry->getUrl()) ?>">` |
| `module-frontend/.../footer.tpl.php:18, 27` | dito                                |
| `module-backend/.../subnav.tpl.php:11, 22, 26, 41, 43` | `$entry->getUrl()` für href + active-Vergleich |
| `module-backend/.../header.tpl.php:25` | `$children[0]->getUrl()` (Container nimmt URL vom ersten Child) |

### Tote/obsolete Stellen

| Datei                                                  | Status                                                              |
|--------------------------------------------------------|---------------------------------------------------------------------|
| `packages/kernel/core/src/Routing/OBSOLETE-NavigationRepository.php` | OBSOLET, gekennzeichnet — ignorieren                          |
| `packages/kernel/core/src/Http/Request.php:368-372`           | leere Methode `addNavigationUrl()`, Kommentar erwähnt `alias_url` — toter Code |

---

## Drei Routing-Strategien laufen parallel

1. **Navigation-Lookup auf canonical** (`findByUrl` Zeile 62) — über `url`-Feld
2. **Navigation-Lookup auf alias** (`findByUrl` Zeile 63) — über `alias_url`-Feld
3. **Convention-Routing** (`parsePathSegments` in `Request.php`) — positional 4-Segment-Mapping, **unabhängig von navigation.json**

Beispiel Login:
- `/login` → Lookup-Treffer auf Alias (Zeile 63) ✓
- `/backend/system/login/login` → Lookup-Treffer auf Canonical (Zeile 62) ✓
- → Beide Wege funktionieren.

Beispiel Home:
- `/home` → Lookup-Treffer auf Canonical (Zeile 62) ✓
- `/frontend/main/index/home` → Lookup-Miss → Convention-Routing → setModule(frontend), setGroup(main), setController(index), setAction(home) ✓
- → Beide Wege funktionieren.

**Konsequenz:** Die 4-Segment-Convention-URL ist immer erreichbar — auch ohne Navigation-Lookup. Das Feld für die canonical Form in `navigation.json` ist **redundant**, weil sie aus `module/group/controller/action` zusammensetzbar ist.

---

## Entscheid

### Option 5 — `url`-Feld weg, `alias_url` umbenannt zu `url`

**Kernidee:**
- Das heutige `url`-Feld entfällt. Die canonical 4-Segment-URL wird **on-the-fly** aus `module/group/controller/action` gebaut.
- Das heutige `alias_url`-Feld wird umbenannt zu `url`. Es hält die **optionale friendly URL**.
- Wenn das neue `url`-Feld `null` ist, wird die canonical 4-Segment-URL als Anzeige verwendet.

**Neue Datenstruktur (Beispiele):**

```jsonc
// Frontend-Page mit friendly URL
{
    "id": 3,
    "name": "Home",
    "url": "/home",                       // friendly (optional, hier gesetzt)
    "module": "frontend",
    "group": "main",
    "controller": "index",
    "action": "home"
}

// Backend-Page ohne friendly URL
{
    "id": 6,
    "name": "Navigation",
    "url": null,                          // friendly weglassen → canonical wird gerendert
    "module": "backend",
    "group": "content",
    "controller": "navigation",
    "action": "list"
}

// Login mit explizitem Alias
{
    "id": 8,
    "name": "Login",
    "url": "/login",                      // friendly
    "module": "backend",
    "group": "system",
    "controller": "login",
    "action": "login"
}

// Container ohne Ziel
{
    "id": 1,
    "name": "Frontend",
    "url": null,
    "module": "",
    "group": "",
    "controller": "",
    "action": "",
    "tags": ["backend"],
    "children": [3, 4, 5, 10]
}
```

**Neue Entity-API:**

```php
public function getCanonicalUrl(): string
{
    if ($this->module === '') return '';   // Container ohne Ziel
    return '/' . $this->module . '/' . $this->group . '/' . $this->controller . '/' . $this->action;
}

public function getUrl(): string
{
    // Wichtig: explizit auf null UND leer prüfen.
    // `?? ` würde Leerstring durchlassen — das wäre falsch.
    return ($this->url !== null && $this->url !== '')
        ? $this->url
        : $this->getCanonicalUrl();
}

public function getFriendlyUrl(): ?string
{
    return $this->url;   // Raw — null wenn nicht gepflegt
}
```

**Neue findByUrl-Logik:**

```php
public function findByUrl(string $url): ?Navigation
{
    foreach ($this->getAll() as $entry) {
        if ($entry->getCanonicalUrl() === $url) return $entry;
        // friendly nur prüfen, wenn explizit gepflegt (nicht null, nicht leer)
        $friendly = $entry->getFriendlyUrl();
        if ($friendly !== null && $friendly !== '' && $friendly === $url) return $entry;
    }
    return null;
}
```

Zwei Getter — klare Trennung:
- `getUrl()` für UI-Rendering: liefert friendly wenn gepflegt, sonst canonical (nie leerer String oder null).
- `getFriendlyUrl()` für Raw-Zugriff: liefert das Feld 1:1, kann null sein.

**Daten-Migration:**

Das `url`-Feld ist **optional**. Backend-meta-Einträge (id 6, 7, 9) müssen es **nicht** gepflegt haben — `getCanonicalUrl()` baut die URL aus den 4 Routing-Feldern. Wenn jemand sie redundant pflegen will (z.B. für Such-/Filter-Funktionen im Backend-UI), funktioniert das genauso — `findByUrl()` matched beide Wege.

Empfohlene Datenpflege: **leer/null wenn die URL der Convention entspricht**, **gepflegt wenn eine echte friendly URL gewünscht ist**.

| id | Heute `url` | Heute `alias_url` | Neu `url` (empfohlen) | Bemerkung |
|---|---|---|---|---|
| 1 (Container Frontend) | `""` | `null` | `null` | Container ohne Ziel |
| 2 (Container Stammdaten) | `""` | `null` | `null` | Container ohne Ziel |
| 3 Home | `/home` | `null` | `/home` | echte friendly URL |
| 4 About | `/about` | `null` | `/about` | echte friendly URL |
| 5 Services | `/services` | `null` | `/services` | echte friendly URL |
| 6 Navigation | `/backend/content/navigation/list` | `null` | `null` (oder canonical, beides ok) | canonical via Convention erreichbar |
| 7 Benutzer | `/backend/users/user/list` | `null` | `null` (oder canonical, beides ok) | canonical via Convention erreichbar |
| 8 Login | `/backend/system/login/login` | `/login` | `/login` | echte friendly URL aus altem alias |
| 9 Logout | `/backend/system/login/logout` | `null` | `null` (oder canonical, beides ok) | canonical via Convention erreichbar |
| 10 Contact | `/contact` | `null` | `/contact` | echte friendly URL |
| 11 Legal | `/legal` | `null` | `/legal` | echte friendly URL |
| 12 Privacy | `/privacy` | `null` | `/privacy` | echte friendly URL |

**Pro:**
- Eine Quelle pro Eintrag, **keine doppelte Datenhaltung**.
- canonical kann nicht inkonsistent zu module/group/controller/action sein — strukturell verhindert.
- Convention-Routing macht das `url`-Feld für canonical-Backend-Einträge redundant; sie fallen einfach weg.
- Code wird kleiner: ein Field, zwei Getter, klare Trennung "Raw" vs "Render".

**Contra:**
- JSON-Schema bricht (Feld `alias_url` weg, `url` semantisch geändert).
- Migration nötig in beiden navigation.json + im Backend-Edit-Form + im Validator + in den Anzeige-Templates.
- Bei Container-Einträgen (id=1,2) muss `getCanonicalUrl()` `''` zurückgeben — Sonderfall.

---

## Verworfene Alternativen

### Option 1 — Nur ein Feld `url` (alias_url weg, url bleibt redundant)

Wie Option 5, aber das `url`-Feld bleibt für die canonical Form und ist redundant zu module/group/controller/action. Verworfen: doppelte Datenhaltung mit Konsistenzrisiko.

### Option 2 — Beide Felder behalten, Semantik scharf definieren

`url` = friendly primary, `alias_url` = canonical fallback. Verworfen: doppelte Datenhaltung, Edit-Form braucht zwei Felder, Convention-Routing macht eines davon eh redundant.

### Option 3 — Liste von Aliases (`urls: [...]`)

Beliebig viele Aliases pro Eintrag. Verworfen: aktueller Bedarf ist 1× Login, Mehrsprachigkeit läuft über Sprach-Prefix. Overengineering für 1.0.

### Option 4 — Status quo, nur Doku ergänzen

Inkonsistenz bleibt, wird in Topic-Doc beschrieben. Verworfen: Designabsicht (`edit.tpl.php`-Placeholder) und Daten driften weiter auseinander.

---

## Abarbeitungs-Checkliste

### Code

- [x] `Entities/Navigation.php`:
  - [x] Field `$aliasUrl` umbenennen zu `$url`. Type bleibt `?string`. `#[Clean('nullable', 'slug')]` bleibt.
  - [x] Bestehendes Field `$url: string` entfernen.
  - [x] `getCanonicalUrl()` umbauen: baut die URL aus `module/group/controller/action`, gibt `''` für Container (leere Felder).
  - [x] `getUrl()` umbauen: `$this->url ?? $this->getCanonicalUrl()` — `??` ist OK, weil `setUrl()` Leerstring zu `null` normalisiert.
  - [x] Neuen Getter `getFriendlyUrl(): ?string` einführen — Raw-Zugriff aufs neue `url`-Feld (kann null sein).
  - [x] `getAliasUrl()` entfernen.
  - [x] `setUrl(?string)` normalisiert Leerstring zu null, `setAliasUrl()` entfernt.
  - [x] **Geprüft: `NullableFilter` normalisiert nur beim BodyCleaner-Pfad** (Form-POST). Beim JSON-Load via `ArrayMappable` greift kein Cleaner → Setter normalisiert selbst.

- [x] `Services/NavigationService.php`:
  - [x] `findByUrl()` umstellen: erst `getCanonicalUrl()`-Vergleich, dann `getFriendlyUrl() !== null && === $url`.

- [x] `Controllers/Content/NavigationController.php`:
  - [x] Nach Speichern: `getUrl()` als Hauptanzeige, canonical in Klammern wenn friendly gesetzt.

- [x] `templates/NavigationController/listAction.tpl.php`:
  - [x] `$displayUrl = $node->getUrl()`, canonical in Klammern wenn `$friendlyUrl !== null`.

- [x] `templates/NavigationController/edit.tpl.php`:
  - [x] Zweites Input-Feld `alias_url` entfernt.
  - [x] Verbleibendes Input-Feld `url`: Placeholder `/kurzurl (leer = /modul/group/controller/action)`, `required`-Attribut weg.

- [x] `packages/kernel/core/src/Http/Request.php`:
  - [x] Tote Methode `addNavigationUrl()` gelöscht (war Teil der `match()`-default-Branch — wurde im Side-Quest 3 Bugfix sowieso entfernt).

### Daten

- [x] `packages/kernel/core/data/framework/routing/navigation.default.json` migriert.
- [x] `skeleton/data/framework/routing/navigation.json` migriert.
- [x] Cache geleert via Service-Panel.

### Validator

- [x] `NavigationValidator` ist generisch (validiert nur `name`) — keine Änderung nötig. `alias_url` war nie validiert.

### Tests

- [x] Smoke-Test alle URLs — alle grün:
  - `/` → Frontend Home ✓
  - `/home`, `/about`, `/services`, `/contact`, `/legal`, `/privacy` → friendly Frontend ✓
  - `/frontend/main/index/home` (etc.) → canonical Frontend, gleiches Ergebnis ✓
  - `/login` → Backend Login ✓
  - `/backend/system/login/login` → gleiches Ergebnis via canonical ✓
  - `/backend/content/navigation/list` → Navigation-Liste ✓
  - `/backend/system/login/logout` → Logout ✓
- [x] Edit-Form-Test (manuell durch User): friendly setzen/löschen funktioniert wie erwartet.

### Doku

- [x] Topic-Doc `docs/topics/navigation.md` aktualisiert (Feld-Semantik, Daten-Tabelle, neue Sektion `## url field (since 2026-05-20)`).
- [x] Topic-Doc `docs/topics/routing.md`: "two routing strategies"-Tabelle erwähnt jetzt "built canonical + optional friendly `url`".
- [x] `npm run docs:check` → grün (verifiziert am 2026-05-20).

### Migration-Risiko

- Cache muss geleert werden, sonst rendert UI mit alter Datenstruktur.
- Wenn `mapFromArray()` (via `ArrayMappable`) das alte `alias_url`-Feld kennt, gibt es Warnings/Fehler beim Laden. → Prüfen ob ArrayMappable strikt ist oder unbekannte Keys ignoriert.
- Bestehende Bookmarks auf `/backend/content/navigation/list` etc. bleiben funktional (Convention-Routing). Bestehende Bookmarks auf `/home` etc. bleiben funktional (friendly URL im neuen `url`-Feld).

---

## Offene Punkte zur Klärung beim Umsetzen

- [ ] **Anzeige im Backend:** Soll die canonical 4-Segment-URL bei gesetztem `friendly_url` weiterhin in Klammern angezeigt werden (heute: ja), oder reicht die friendly URL als Hauptanzeige? Empfehlung: in Klammern lassen, hilft beim Debugging.
- [ ] **Validierungstiefe friendly URL:** Soll geprüft werden, dass die friendly URL nicht zufällig wie eine canonical 4-Segment-URL aussieht (`/foo/bar/baz/qux`)? Würde Lookup-Konflikte verhindern. Empfehlung: nur warnen, nicht blocken.
- [ ] **ArrayMappable-Verhalten:** Vor der Daten-Migration prüfen, wie `mapFromArray()` mit unbekannten JSON-Keys umgeht. Falls strikt: Migration muss atomar laufen (Code + Daten gleichzeitig deployt).
