# Review: `LayoutManager::createCss` / `StylesheetManager::createCss`

Erstellt: 2026-05-12

---

## Call chain

```
LayoutManager::createCss(name, nameSpace, template, data, version, mediaQueryOption)
  → StylesheetManager::createCss(name, nameSpace, template, data, version)
      → FileFinder::getBasePaths(nameSpace, 'assetPaths')
            → output dir = assetPaths[0]/css/
      → is_file("{dir}/{name}_at-{version}.css")
            → exists: return immediately, no render, no disk write
      → FileFinder::getFirstTplMatch("{template}.css.tpl.php", nameSpace)
      → closure: extract($data) + ob_start + include $tplPath → CSS string
      → atomicWrite(target, css)    ← file_put_contents + rename
      → AssetCleaner::cleanupVersionsFor(dir, name, 'css', target)
      → return absolute target path
  → LayoutManager::toWebPath(filePath)
  → dedup check: not already in assets['css'] by web path
  → assets['css'][] = ['name', 'path', 'mediaQueryOption']
```

---

## Versioning

`createCss` unterscheidet sich fundamental von `addCss`:

| | `addCss` | `createCss` |
|---|---|---|
| Versionsquelle | `AssetVersionService::version()` → `filemtime` (prod) / `time()` (debug) | **Caller-supplied `int $version`** |
| Typischer Wert | Datei-mtime | `max(updatedAt)` der treibenden Entities |
| Debug-Override | Ja — request time → erzwingt neues Stamp pro Request | Nein — gleicher Stamp wie übergeben |
| `AssetVersionService` involviert | Ja | Nein |

Der `AssetVersionService` wird für `createCss` gar nicht aufgerufen.

### Wann wird die Datei erstellt?

**Bedingung:** `{dir}/{name}_at-{version}.css` existiert noch **nicht** auf Disk.

Sobald die Datei vorhanden ist, gibt `StylesheetManager::createCss` sie sofort zurück — kein Template-Render, kein Disk-Write, kein Cleanup. Das ist eine explizite Idempotenz-Optimierung: gleiche Version = gleicher Output = keine Neuberechnung nötig.

Cleanup (`AssetCleaner::cleanupVersionsFor`) läuft nur bei einem effektiven Neuschreiben, nicht beim Cache-Hit.

---

## Abhängigkeiten

### `LayoutManager::createCss`
- `StylesheetManager` (über Constructor injiziert)
- `toWebPath()` (intern, braucht Konstante `ABS_PUBLIC_PATH`)
- `$this->assets['css']` für Dedup

### `StylesheetManager::createCss`
- `DI::getFileFinder()->getBasePaths(nameSpace, 'assetPaths')` → bestimmt Output-Dir (erster Pfad gewinnt)
- `DI::getFileFinder()->getFirstTplMatch(...)` → Template-Datei
- Closure mit `extract()` + `ob_start/include` → CSS rendern
- `atomicWrite()` → `file_put_contents` + `rename`
- `AssetCleaner::cleanupVersionsFor()` → Cleanup nach Write

---

## Befunde

### B1 — `extract($data)` kann Closure-Parameter überschreiben (Bug)

```php
$css = (static function (string $tplPath, array $data): string {
    extract($data);         // läuft NACH dem Closure-Parameter-Binding
    ob_start();
    include $tplPath;       // $tplPath könnte jetzt überschrieben sein
    return (string) ob_get_clean();
})($tplPath, $data);
```

Wenn `$data` einen Key `tplPath` enthält (z.B. weil die Controller-Action Slider-Daten mit einem `tplPath`-Feld übergibt), überschreibt `extract` die lokale `$tplPath`-Variable. Das falsche Template wird included.

**Risiko:** Gering in normaler Verwendung, aber defensiv korrigierbar.

**Fix:** `EXTR_SKIP` verhindert, dass `extract` existierende Variablen überschreibt:

```php
extract($data, EXTR_SKIP);
```

### B2 — Kein Debug-Override für Template-Änderungen

In `addCss` sorgt `AssetVersionService` in debug für ein neues Stamp pro Request → Browser sieht immer die aktuelle Version des Assets.

In `createCss` kommt das Stamp vom Caller (`max(updatedAt)` der Entities). Ändert der Developer das CSS-Template, ohne gleichzeitig Entities in der DB anzupassen, **bleibt die generierte Datei cached** — weil Version gleich, Datei existiert, kein Neuschreiben.

**Konsequenz im Entwicklungsalltag:** Template-Änderung am CSS ist unsichtbar, bis entweder eine Entity geändert oder die Cache-Clear-Aktion ausgeführt wird. Das kann zu Verwirrung führen ("meine CSS-Änderung hat keinen Effekt").

**Handlungsoptionen:**
1. Dokumentieren als bekanntes Dev-Verhalten → Dev muss bei Template-Änderungen `clearAll()` ausführen
2. In debug-Modus die Existenz-Prüfung überspringen (immer neu rendern) — aber das würde die Semantik des `version`-Parameters aushöhlen

Empfehlung: Option 1, explizit in Topic-Doc ergänzen.

**Konkreter Fall: `BackendAbstractController::postExecute()`**

> **Note (2026-05-27):** Dieser Use-Case existiert nicht mehr. Die Per-User-Preferences-CSS-Generierung wurde entfernt — Palette/Theme laufen jetzt über `data-be-palette` / `data-be-theme` Attribute am `<html>` und CSS-Selektoren in `_colors.scss`. Siehe `docs/topics/backend.md` (APPEARANCE-PIPELINE-001) und `docs/topics/css-backend.md`. Der unten beschriebene Caching-Effekt war eine reale Falle dieses Use-Cases; der Hinweis bleibt als Beispiel für die Versionierungs-Eigenheit von `createCss`.

```php
$version = abs(crc32(serialize($prefs->toArray())));
```

Hier ist der Stamp ein CRC32-Hash der Präferenzen-Daten — weder `filemtime` noch `time()`. Ändert der Developer `user-preferences.css.tpl.php` strukturell (neue CSS-Variable), bleibt die Datei gecacht bis der User seine Präferenzen ändert oder `clearAll()` läuft. Für diesen Use Case ist das weniger kritisch als bei anderen Templates, weil der CSS-Inhalt vollständig datengetrieben ist — aber das Verhalten ist dasselbe.

### B3 — Output-Dir immer `assetPaths[0]`

```php
$basePaths = DI::getFileFinder()->getBasePaths($nameSpace, 'assetPaths');
$dir = str_replace('\\', '/', rtrim($basePaths[0], '/\\')) . '/css';
```

Wenn der erste registrierte Asset-Pfad für den Namespace `vendor/z77/module-frontend` ist (statt `public/assets/frontend`), wird die generierte Datei in den vendor-Baum geschrieben. Das wäre beim `composer update` weg.

In der aktuellen FileFinder-Konfiguration steht `public/assets/frontend` immer vor `vendor/` — daher kein Problem heute. Aber die Abhängigkeit ist implizit und nicht abgesichert.

**Risiko:** Gering solange Konvention eingehalten wird. Könnte bei einem neuen Modul brechen, das nur in `vendor/` registriert ist.

### B4 — Race condition bei parallelen Requests (benign)

Zwei Requests prüfen gleichzeitig `!is_file($target)`, beide sehen `false`, beide rendern das Template und rufen `atomicWrite` auf. Der zweite `rename` überschreibt den ersten mit identischem Inhalt. Cleanup läuft zweimal.

**Resultat:** Korrekt (gleicher Output), leicht erhöhter Aufwand, kein Datenverlust.

### B5 — `ob_get_clean()` bei fehlgeschlagenem `ob_start()`

Wenn `ob_start()` fehlschlägt (z.B. Output-Buffering-Level-Limit erreicht), gibt `ob_get_clean()` `false` zurück. `(string) false` = `""`. Eine leere CSS-Datei wird versioned und gecached.

Kein Exception, kein Log — die leere Datei existiert mit korrektem Namen, wird also bis zum nächsten Version-Bump oder `clearAll()` ausgeliefert.

**Risiko:** Sehr gering in normaler PHP-Umgebung, aber schwer debugbar wenn es passiert.

---

## Zusammenfassung

| # | Befund | Schwere | Empfehlung |
|---|---|---|---|
| B1 | `extract($data)` überschreibt `$tplPath` bei Key-Kollision | Mittel | `extract($data, EXTR_SKIP)` |
| B2 | Template-Änderungen in dev bleiben gecached | UX (Dev) | Topic-Doc ergänzen, oder `clearAll()` in Workflow |
| B3 | Output-Dir implizit an `assetPaths[0]` gebunden | Gering | Kein Code-Fix nötig; Konvention dokumentieren |
| B4 | Parallele Requests = doppelter Write (benign) | Info | Kein Fix nötig |
| B5 | Leere CSS bei `ob_start()`-Fehler, kein Exception | Gering | Optional: `ob_start()` Rückgabe prüfen |

**Priorität:** B1 ist ein echter defensiver Fix. B2 sollte ins Topic-Doc.
