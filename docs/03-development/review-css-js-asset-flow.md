# CSS/JS Asset Flow — Vollständiger Debug-Flow

Erstellt: 2026-05-05  
Zweck: Schritt-für-Schritt-Analyse des gesamten Asset-Flows (CSS + JS, Versioning, Debug-Toggle).  
Vorgehen: Jeden Checkpoint einzeln prüfen — erst lesen, dann debuggen.

---

## Überblick

```
HTTP Request
  └─ skeleton/public/index.php
       └─ define ABS_BASE_PATH, ABS_INDEX_PATH
       └─ Bootstrap::__construct()
            └─ [CP-1] DEBUG aus debug.flag
            └─ [CP-2] clearAllApcu() wenn DEBUG=true
       └─ Bootstrap::pullUp() → Dispatcher
            └─ AbstractBaseController::run()
                 └─ [CP-3] new LayoutManager(controller, DEBUG)
                      └─ [CP-4] new AssetVersionService(debug)
                      └─ [CP-5] new StylesheetManager(assetVersion)
                 └─ applyLayoutConfig()
                      └─ [CP-6] loadCssFromConfig() → addCss()
                           └─ [CP-7..12] StylesheetManager::getVersionedCss()
                      └─ [CP-6] loadJsFromConfig() → addJs()
                           └─ [CP-13..20] StylesheetManager::getVersionedJs()
            └─ HtmlView::render()
                 └─ [CP-21] renderCss() → <link> Tags
                 └─ [CP-22] renderJs() → <script> Tags
                 └─ [CP-23] toWebPath() → absoluter Pfad → Web-Pfad
```

---

## Phase 1 — Bootstrap

### CP-1 — DEBUG aus debug.flag

**Datei:** `packages/kernel/core/src/Bootstrap.php:75`

```php
define('DEBUG', file_exists(ABS_BASE_PATH . '/data/framework/debug.flag'));
```

**Was zu prüfen:**
- `ABS_BASE_PATH` = `str_replace('\\', '/', dirname(__DIR__))` aus `skeleton/public/index.php`
- Erwarteter Wert: `z:/z77-ch-framework-1.0.0/skeleton` (forward slashes)
- Pfad zum Flag: `z:/z77-ch-framework-1.0.0/skeleton/data/framework/debug.flag`

**Debug einfügen nach Zeile 75:**
```php
define('DEBUG', file_exists(ABS_BASE_PATH . '/data/framework/debug.flag'));
error_log('[CP-1] ABS_BASE_PATH=' . ABS_BASE_PATH);
error_log('[CP-1] debug.flag exists=' . (file_exists(ABS_BASE_PATH . '/data/framework/debug.flag') ? 'YES' : 'NO'));
error_log('[CP-1] DEBUG=' . (DEBUG ? 'true' : 'false'));
```

**Bekannte Risiken:**
- `ABS_BASE_PATH` könnte Backslashes enthalten wenn `str_replace` nicht greift
- `bootstrap.inc.php` hat ein veraltetes Feld `'debug' => true` — dieses wird NICHT für `DEBUG` verwendet (toter Config-Wert, sollte entfernt werden)

---

### CP-2 — clearAllApcu()

**Datei:** `packages/kernel/core/src/Bootstrap.php:78-80`

```php
if (DEBUG) {
    DI::getCacheManager()->clearAllApcu();
}
```

**Was zu prüfen:**
- Wird bei DEBUG=true ausgeführt → löscht ALLE APCu-Einträge
- Bei DEBUG=false: APCu-Einträge aus früheren Requests bleiben erhalten
- Richtung prod→debug: APCu wird geleert ✓
- Richtung debug→prod: APCu wird NICHT geleert — alte Keys sind aber mit anderem Filename-Key, kein Problem

**Debug einfügen:**
```php
if (DEBUG) {
    DI::getCacheManager()->clearAllApcu();
    error_log('[CP-2] APCu cleared');
}
```

---

## Phase 2 — LayoutManager Konstruktion

### CP-3 — LayoutManager erstellen

**Datei:** `packages/kernel/core/src/Controller/AbstractBaseController.php:32-35`

```php
$this->layoutManager = new LayoutManager(
    DI::getControllerHandler(),
    DEBUG
);
```

**Was zu prüfen:**
- Wird `DEBUG` korrekt übergeben?
- Ist die Konstante zu diesem Zeitpunkt definiert?

**Debug einfügen:**
```php
error_log('[CP-3] LayoutManager created, debug=' . (DEBUG ? 'true' : 'false'));
$this->layoutManager = new LayoutManager(
    DI::getControllerHandler(),
    DEBUG
);
```

---

### CP-4 — AssetVersionService

**Datei:** `packages/kernel/core/src/Services/LayoutManager.php:57`

```php
$this->assetVersion = new AssetVersionService($this->debug);
```

**Was zu prüfen:**
- `$this->debug` = Wert der von CP-3 kommt
- `minSuffix()` gibt `''` zurück wenn debug=true, `'.min'` wenn debug=false

**Debug einfügen im AssetVersionService-Konstruktor** (`packages/kernel/core/src/Services/AssetVersionService.php:19`):
```php
public function __construct(private bool $debug = false) {
    error_log('[CP-4] AssetVersionService created, debug=' . ($debug ? 'true' : 'false') . ', minSuffix=' . $this->minSuffix());
}
```

---

## Phase 3 — CSS Asset Resolution

### CP-5 bis CP-12 — getVersionedCss()

**Datei:** `packages/kernel/core/src/Services/StylesheetManager.php:21-53`

Gilt für jeden CSS-Aufruf (base, mobile, tablet, desktop, nav-mobile, nav-tablet, nav-desktop).

**Debug einfügen am Anfang der Methode:**
```php
public function getVersionedCss(string $baseName, string $nameSpace): string
{
    error_log("[CP-CSS-START] getVersionedCss('{$baseName}', '{$nameSpace}')");
```

#### CP-5 — FileFinder: Source-Datei finden

```php
$sourcePath = DI::getFileFinder()->getFirstAssetMatch(
    fileName: "css/{$baseName}.css",
    nameSpace: $nameSpace,
    throwError: true
);
```

**Debug:**
```php
error_log("[CP-5] CSS sourcePath='" . $sourcePath . "'");
error_log("[CP-5] CSS sourcePath slashes=" . (str_contains($sourcePath, '\\') ? 'BACKSLASH!' : 'forward'));
```

**Risiko:** `FileFinder` gibt Pfad mit gemischten Slashes zurück (Windows).  
APCu-Cache: Pfad wird mit Key `[FileFinder::class, 'assetPaths', '', 'css/base.css']` gespeichert.

#### CP-6 — Versionsnummer (mtime)

```php
$version  = $this->assetVersion->version($sourcePath);
$dir      = str_replace('\\', '/', dirname($sourcePath));  // FIX: normalisiert
$fileBase = basename($sourcePath, '.css');
$target   = "{$dir}/{$fileBase}_at-{$version}.css";
```

**Debug:**
```php
error_log("[CP-6] CSS version={$version}, dir='{$dir}', fileBase='{$fileBase}'");
error_log("[CP-6] CSS target='{$target}'");
```

#### CP-7 — Early Return (Zieldatei existiert)

```php
if (is_file($target)) {
    return $target;  // Cleanup läuft NICHT — für CSS OK (kein .min-Gegenstück)
}
```

**Debug:**
```php
error_log("[CP-7] CSS target exists=" . (is_file($target) ? 'YES (early return)' : 'NO (will copy)'));
```

**Hinweis:** Early Return bei CSS unkritisch — CSS hat keine debug/prod-Varianten.

#### CP-8 — Copy + Cleanup

```php
copy($sourcePath, $target);
foreach (glob("{$dir}/{$fileBase}_at-*.css") ?: [] as $path) {
    if ($path !== $target) {
        @unlink($path);
    }
}
```

**Debug:**
```php
$globResult = glob("{$dir}/{$fileBase}_at-*.css") ?: [];
error_log("[CP-8] CSS glob pattern='{$dir}/{$fileBase}_at-*.css'");
error_log("[CP-8] CSS glob results=" . implode(', ', $globResult));
foreach ($globResult as $path) {
    $willDelete = ($path !== $target);
    error_log("[CP-8] CSS path='{$path}' → " . ($willDelete ? 'DELETE' : 'KEEP (is target)'));
    if ($willDelete) { @unlink($path); }
}
```

**Kritisch:** `$path !== $target` — müssen exakt gleiche Slash-Richtung haben.  
Mit dem Fix (`str_replace` auf `$dir`) sollten beide forward slashes haben.

---

## Phase 4 — JS Asset Resolution

### CP-9 bis CP-20 — getVersionedJs()

**Datei:** `packages/kernel/core/src/Services/StylesheetManager.php:123-155`

**Das ist der kritischste Teil — hier ist der Hauptbug.**

**Debug einfügen am Anfang:**
```php
public function getVersionedJs(string $baseName, string $nameSpace): string
{
    error_log("[CP-JS-START] getVersionedJs('{$baseName}', '{$nameSpace}')");
```

#### CP-9 — minSuffix bestimmen

```php
$min = $this->assetVersion->minSuffix();
```

**Debug:**
```php
error_log("[CP-9] JS min='{$min}' (debug=" . ($min === '' ? 'true' : 'false') . ")");
```

**Erwartung:**
- debug.flag vorhanden → DEBUG=true → `$min = ''` → sucht `core.js`
- debug.flag fehlt → DEBUG=false → `$min = '.min'` → sucht `core.min.js`

#### CP-10 — FileFinder: Source-Datei finden

```php
$sourcePath = DI::getFileFinder()->getFirstAssetMatch(
    fileName: "js/{$baseName}{$min}.js",
    nameSpace: $nameSpace,
    throwError: true
);
```

**Debug:**
```php
$lookingFor = "js/{$baseName}{$min}.js";
error_log("[CP-10] JS looking for='{$lookingFor}'");
error_log("[CP-10] JS sourcePath='{$sourcePath}'");
error_log("[CP-10] JS sourcePath slashes=" . (str_contains($sourcePath ?? '', '\\') ? 'BACKSLASH!' : 'forward'));
```

**APCu-Cache:** Wenn APCu `js/core.js` → Pfad gecacht hat (aus debug-Session), und dann auf prod umgeschaltet wird (APCu nicht geleert), gibt es KEINEN Konflikt — denn die Suche nach `js/core.min.js` hat einen anderen Key. ✓

**Aber:** Wenn APCu bei debug=false den Pfad `js/core.min.js` → `…/core.min.js` gecacht hat, und dann debug=true wird (APCu geleert bei DEBUG=true) → frischer Lookup. ✓

#### CP-11 — Versionsnummer + Pfad-Konstruktion

```php
$version  = $this->assetVersion->version($sourcePath);
$dir      = str_replace('\\', '/', dirname($sourcePath));  // FIX
$fileBase = basename($sourcePath, $min . '.js');
$target   = "{$dir}/{$fileBase}_at-{$version}{$min}.js";
```

**Debug:**
```php
error_log("[CP-11] JS version={$version}");
error_log("[CP-11] JS dir='{$dir}'");
error_log("[CP-11] JS fileBase='{$fileBase}'");
error_log("[CP-11] JS target='{$target}'");
```

**Was zu prüfen:**
- `$dir` hat nur forward slashes? → Fix sollte das sicherstellen
- `$fileBase` bei debug=true: `basename('core.js', '.js')` = `'core'` ✓
- `$fileBase` bei debug=false: `basename('core.min.js', '.min.js')` = `'core'` ✓
- `$target` debug=true: `…/core_at-1778006453.js`
- `$target` debug=false: `…/core_at-1778006453.min.js`

**Sonderfall — unterschiedliche mtime:**  
`core.js` und `core.min.js` können unterschiedliche mtimes haben → unterschiedliche Version-Nummern. Das ist korrekt und erwartet.

#### CP-12 — Zieldatei erstellen (wenn nötig)

```php
if (!is_file($target)) {
    if (!copy($sourcePath, $target)) {
        throw new \RuntimeException("...");
    }
}
```

**Debug:**
```php
$targetExists = is_file($target);
error_log("[CP-12] JS target exists=" . ($targetExists ? 'YES (skip copy)' : 'NO (will copy)'));
if (!$targetExists) {
    $copied = copy($sourcePath, $target);
    error_log("[CP-12] JS copy result=" . ($copied ? 'OK' : 'FAILED!'));
    if (!$copied) { throw new \RuntimeException("Failed to create versioned JS file at '{$target}'."); }
}
```

#### CP-13 — glob + Cleanup (KRITISCH)

```php
foreach (glob("{$dir}/{$fileBase}_at-*.js") ?: [] as $path) {
    if ($path !== $target) {
        @unlink($path);
    }
}
```

**Debug:**
```php
$globPattern = "{$dir}/{$fileBase}_at-*.js";
$globResult  = glob($globPattern) ?: [];
error_log("[CP-13] JS glob pattern='{$globPattern}'");
error_log("[CP-13] JS glob count=" . count($globResult));
foreach ($globResult as $path) {
    $normalized = str_replace('\\', '/', $path);
    $isTarget   = ($path === $target);
    error_log("[CP-13] JS path='{$path}' | normalized='{$normalized}' | target='{$target}' | match=" . ($isTarget ? 'YES (KEEP)' : 'NO (DELETE)'));
    if (!$isTarget) { @unlink($path); }
}
```

**Das ist der Hauptverdächtige.** Wenn `$path` Backslashes und `$target` Forward-Slashes hat, schlägt `$path !== $target` fehl → `$target` wird gelöscht → Datei fehlt → 404.

**Mit dem Fix** (`str_replace` auf `$dir`) sollten beide dasselbe Format haben.

---

## Phase 5 — Web-Pfad Konversion

### CP-14 — toWebPath()

**Datei:** `packages/kernel/core/src/Services/LayoutManager.php:507-512`

```php
private function toWebPath(string $absFilePath): string
{
    $normalized = str_replace('\\', '/', $absFilePath);
    $base       = rtrim(str_replace('\\', '/', ABS_PUBLIC_PATH), '/');
    return '/' . ltrim(substr($normalized, strlen($base)), '/');
}
```

**Was zu prüfen:**
- `ABS_PUBLIC_PATH` = `ABS_BASE_PATH . '/' . ltrim($bootstrapConfig->getHtmlRoot(), '/')` = `skeleton/public`
- `getHtmlRoot()` = `'public'` (aus bootstrap.inc.php) → `ABS_PUBLIC_PATH` = `z:/…/skeleton/public`
- `$normalized` = `z:/…/skeleton/public/assets/backend/js/core_at-123.js`
- `substr($normalized, strlen($base))` = `/assets/backend/js/core_at-123.js`
- Rückgabe: `/assets/backend/js/core_at-123.js` ✓

**Debug einfügen:**
```php
private function toWebPath(string $absFilePath): string
{
    $normalized = str_replace('\\', '/', $absFilePath);
    $base       = rtrim(str_replace('\\', '/', ABS_PUBLIC_PATH), '/');
    $webPath    = '/' . ltrim(substr($normalized, strlen($base)), '/');
    error_log("[CP-14] toWebPath: abs='{$absFilePath}' | base='{$base}' | web='{$webPath}'");
    return $webPath;
}
```

**Risiko:** Wenn `ABS_PUBLIC_PATH` nicht dasselbe Präfix wie `$normalized` hat (z.B. durch Backslashes), liefert `substr()` den vollen Pfad → `$webPath` = `z:/…` statt `/assets/…` → 404.

---

## Phase 6 — HTML Ausgabe

### CP-15 — renderJs() / renderCss()

**Datei:** `packages/kernel/core/src/Services/HtmlView.php:104-116`

```php
private function renderJs(string $position): string
{
    $html = '';
    foreach ($this->layoutManager->getJs() as $js) {
        if (($js['position'] ?? 'footer') !== $position) { continue; }
        $src  = htmlspecialchars($js['path']);
        $attr = ($js['defer'] ?? false) ? ' defer' : '';
        $attr .= ($js['async'] ?? false) ? ' async' : '';
        $html .= '<script src="' . $src . '"' . $attr . '></script>' . PHP_EOL;
    }
    return $html;
}
```

**Debug:**
```php
foreach ($this->layoutManager->getJs() as $js) {
    error_log("[CP-15] JS asset: path='" . $js['path'] . "' position='" . $js['position'] . "'");
```

---

## Skeleton-Struktur Prüfliste

Folgende Dateien müssen im Skeleton vorhanden sein:

### JS-Quellen (vor Versioning)
```
skeleton/public/assets/backend/js/
  core.js          ← debug-Quelle
  core.min.js      ← prod-Quelle
  system/
    cache.js       ← debug-Quelle
    cache.min.js   ← prod-Quelle
```

### CSS-Quellen (vor Versioning)
```
skeleton/public/assets/backend/css/
  base.css
  mobile.css
  tablet.css
  desktop.css
  nav-mobile.css
  nav-tablet.css
  nav-desktop.css
```

### Debug-Flag
```
skeleton/data/framework/debug.flag   ← Datei erstellen = debug=true, löschen = prod
```

**TODO: `.gitignore` für debug.flag** — fehlt noch!

### Versioned Copies (werden generiert, müssen NICHT manuell vorhanden sein)
```
skeleton/public/assets/backend/js/core_at-{mtime}.js         ← debug
skeleton/public/assets/backend/js/core_at-{mtime}.min.js     ← prod
skeleton/public/assets/backend/css/base_at-{mtime}.css       ← beide Modi gleich
```

---

## Bekannte Bugs und Status

| # | Bug | Datei | Status |
|---|---|---|---|
| 1 | `getVersionedJs()`: glob zu breit (`*{$min}.js` trifft auch Gegenstücke) | StylesheetManager.php | **FIXED** |
| 2 | `getVersionedJs()`: Early Return überspringt Cleanup | StylesheetManager.php | **FIXED** |
| 3 | `getVersionedCss()`: `glob()` ohne `?: []` Fallback | StylesheetManager.php | **FIXED** |
| 4 | Windows-Pfade: `dirname()` gibt Backslashes → `$path !== $target` schlägt fehl | StylesheetManager.php | **FIXED** (str_replace in allen 3 Methoden) |
| 5 | `debug.flag` fehlt in `.gitignore` | `.gitignore` | **OFFEN** |
| 6 | `bootstrap.inc.php`: veraltetes Feld `'debug' => true` (toter Config-Wert) | bootstrap.inc.php | **OFFEN** (löschen) |
| 7 | `HtmlView::$debug` wird gespeichert aber nie verwendet | HtmlView.php | Niedrig |
| 8 | `styles.tpl.php` ist leer | partials/head/styles.tpl.php | Klären (absichtlich?) |

---

## Testreihenfolge morgen

```
1. Alle Debugs einfügen (pro CP einzeln)
2. debug.flag LÖSCHEN → prod-Modus
3. Seite laden → php-error.log prüfen
4. Erwartung: core_at-{X}.min.js im HTML
5. debug.flag ERSTELLEN → debug-Modus
6. Seite laden → php-error.log prüfen
7. Erwartung: core_at-{Y}.js im HTML, core_at-{X}.min.js gelöscht
8. Wieder löschen → prod
9. Erwartung: core_at-{X}.min.js wieder da, core_at-{Y}.js gelöscht
```

**Log-Datei:** `skeleton/logs/php-error.log`
