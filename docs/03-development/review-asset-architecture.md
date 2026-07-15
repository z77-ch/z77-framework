# Review: Asset-Architektur (LayoutManager, HtmlView, AssetVersionService, StylesheetManager)

Erstellt: 2026-05-06
Reviewer-Auftrag: Profi-Analyse von 7 Beobachtungen plus Lösungsvorschlägen
Betroffene Dateien:
- [LayoutManager.php](../../packages/kernel/core/src/Services/LayoutManager.php)
- [HtmlView.php](../../packages/kernel/core/src/Services/HtmlView.php)
- [HtmlResponse.php](../../packages/kernel/core/src/Http/Response/HtmlResponse.php)
- [AssetVersionService.php](../../packages/kernel/core/src/Services/AssetVersionService.php)
- [StylesheetManager.php](../../packages/kernel/core/src/Services/StylesheetManager.php)
- [AbstractBaseController.php](../../packages/kernel/core/src/Controller/AbstractBaseController.php)

---

## 0. Kritischer Bug (vor allen anderen Punkten zu fixen)

**Datei:** [StylesheetManager.php:34](../../packages/kernel/core/src/Services/StylesheetManager.php#L34)

```php
$version  = $this->assetVersion->version($sourcePath);
echo $version;exit;        // ← liegen gelassener Debug-Eintrag
$dir      = str_replace(...);
```

**Befund:** Der gesamte CSS-Flow ist tot, sobald `getVersionedCss()` aufgerufen wird. Die Seite gibt nur die Versionsnummer aus und beendet PHP. JS funktioniert weiter, weil `getVersionedJs()` den `exit` nicht enthält.

**Lösung:** Zeile 34 entfernen. Sofort.

---

## 1. Fehlende Exception bei fehlender ModuleLayoutConfig

**Datei:** [LayoutManager.php:87-104](../../packages/kernel/core/src/Services/LayoutManager.php#L87-L104)

```php
$moduleLayoutConfig = DI::getConfigManager()->getArrayConfig(
    configName: 'Ui/Config/layoutConfig',
    nameSpace: $this->nameSpace,
    throwError: false           // ← stiller Fallback
);
if ($moduleLayoutConfig) {
    $this->applyLayoutConfig($moduleLayoutConfig);
}
```

**Analyse:**
- Ein Modul ohne `layoutConfig.inc.php` ist nach Konvention kein gültiger Zustand. Skeleton, Default-Partials und Standard-Asset-Set werden zentral dort definiert.
- `throwError: false` schluckt den Fehler und produziert ein halb-konfiguriertes Layout (Fallback auf `DEFAULT_SKELETON`, leere Section, kein CSS).
- Das maskiert echte Konfigurationsfehler — ein vergessener Tippfehler im Modulnamen erzeugt nicht eine Exception, sondern eine optisch defekte Seite.
- ControllerLayoutConfig (Zeile 97-104) ist hingegen korrekt optional — Controller dürfen ohne eigene Config laufen und nur die Modul-Defaults nutzen.

**Lösung:**

```php
$moduleLayoutConfig = DI::getConfigManager()->getArrayConfig(
    configName: 'Ui/Config/layoutConfig',
    nameSpace: $this->nameSpace,
    throwError: true            // Modul-Config ist Pflicht
);
$this->applyLayoutConfig($moduleLayoutConfig);
```

**Konsequenz:** Jedes Modul muss eine `layoutConfig.inc.php` mitbringen. Das ist die Konvention — nun auch hart durchgesetzt.

---

## Architektur-Kontext: Das Response/Dispatcher-Pattern bleibt unangetastet

Bevor Punkt 2 diskutiert wird: Die polymorphe Response-Architektur ist sauber und soll nicht angefasst werden.

**Dispatcher kennt nur `ResponseInterface`:**
```
Dispatcher::execute()
  └─ resolveResponse() → wählt einen ResponseInterface
  └─ $response->send()                  ← einzige Aufrufstelle, polymorph
```

**`HtmlResponse::send()` hat drei interne Pfade:**
| Pfad | Cache-Mode | Body-Quelle | LayoutManager nötig? |
|---|---|---|---|
| 304 | `NotModified` | leer (return vor body) | nein |
| Cache-Hit | `ServerCached` | `$this->html` ist bereits gesetzt durch `fromCache()` | nein |
| Neu | `NoStore` | `getHtml()` rendert frisch | **ja** |

Alle Vorschläge in den folgenden Punkten betreffen ausschliesslich den **dritten Pfad** — also `HtmlResponse::getHtml()`. Das `send()`-Pattern, die Factory-Methoden (`fromCache`, `notModified`) und der Dispatcher selbst bleiben unverändert.

---

## 2. LayoutManager->render() versteckt in HtmlResponse

**Datei:** [HtmlResponse.php:99-102](../../packages/kernel/core/src/Http/Response/HtmlResponse.php#L99-L102)

```php
public function getHtml(): string
{
    return $this->html ??= $this->layoutManager->render($this->context);
}
```

**Analyse — die heutige Aufrufkette:**

```
HtmlResponse::send()
  └─ HtmlResponse::getHtml()                 ← memoized
        └─ LayoutManager::render($context)    ← reine Delegation
              └─ HtmlView::assign()->render() ← echte Arbeit
```

Drei Render-Hops für eine einzige Operation. Die mittlere Stufe (`LayoutManager::render`) ist eine reine Pass-through-Methode.

**Probleme:**
1. **Zirkuläre Abhängigkeit:** LayoutManager hält HtmlView, HtmlView hält LayoutManager (siehe Punkt 4).
2. **Indirektion ohne Mehrwert:** Die `render`-Methode am LayoutManager existiert nur, weil der Manager seinen View kapselt. Das ist Ergebnis, nicht Absicht.
3. **Tracing ist unangenehm:** Wer einen Render-Bug debuggt, springt 3× durch Klassen.

**Lösung A (minimal-invasiv):** `HtmlResponse` arbeitet direkt mit `HtmlView`.

```php
class HtmlResponse implements ResponseInterface
{
    public function __construct(
        private ?HtmlView $view = null,
        private array $context = []
    ) {}

    public function getHtml(): string
    {
        return $this->html ??= $this->view->assign($this->context)->render();
    }
}
```

Der Controller liefert den View statt des Managers:
```php
return new HtmlResponse($this->layoutManager->getView(), $context);
```

**Lösung B (sauberer, empfohlen):** LayoutManager ist Builder, HtmlView wird vom Builder konstruiert und an HtmlResponse übergeben. Die zirkuläre Referenz löst sich auf — siehe Punkt 4.

```php
// Controller:
$view = $this->layoutManager
    ->initialize()
    ->buildView();          // Snapshot — read-only View
return new HtmlResponse($view, $context);
```

`HtmlView` erhält dann **fertige Daten** statt einer Referenz auf den Manager (CSS-Liste, JS-Liste, Skeleton-Pfad, Partials). Damit ist der View ein echtes Render-Objekt ohne Backreference.

---

## 3. Naming: HtmlView::render() vs. HtmlResponse::send()

**Analyse:**
- `render()` ist semantisch korrekt für „erzeuge HTML als String". Standard-Bezeichner in MVC-Frameworks (Symfony, Laravel, Zend).
- `send()` ist semantisch korrekt für „schreibe HTTP-Header und Body in den Output-Stream".
- Beide Begriffe stehen für unterschiedliche Operationen — das ist nicht das Problem.

**Was der User wirklich kritisiert:** Es gibt aktuell **zwei** `render()` in der Kette (`LayoutManager::render` und `HtmlView::render`). Das ist die eigentliche Konfusion.

**Lösung:** Mit Punkt 2 verschwindet `LayoutManager::render` — dann gibt es nur noch `HtmlView::render` (rendert) und `HtmlResponse::send` (sendet). Klar getrennt.

---

## 4. LayoutManager hält HtmlView (Zirkularität)

**Datei:** [LayoutManager.php:39, 500-506](../../packages/kernel/core/src/Services/LayoutManager.php#L500-L506)

```php
private ?HtmlView $view = null;

private function getView(): HtmlView
{
    if ($this->view === null) {
        $this->view = new HtmlView($this, $this->debug);   // ← übergibt sich selbst
    }
    return $this->view;
}
```

**HtmlView seinerseits:** [HtmlView.php:14-21](../../packages/kernel/core/src/Services/HtmlView.php#L14-L21)

```php
public function __construct(
    private LayoutManager $layoutManager,    // ← hält den Manager
    private bool $debug = false
) {
    $this->renderer     = new TemplateRenderer($layoutManager->getNameSpace());
    $this->skeletonPath = $layoutManager->getSkeletonTemplatePath();
    $this->partials     = $layoutManager->getPartialPaths();
}
```

**Befund:**
- Klassische zirkuläre Abhängigkeit. Beide Objekte halten sich gegenseitig.
- Im Konstruktor wird der Manager nur als **Datenquelle** verwendet (Skeleton, Partials, getCss, getJs, getNameSpace).
- HtmlView braucht den Manager **nicht** — er braucht die Daten zum Render-Zeitpunkt.

**Verantwortlichkeiten klar trennen:**

| Objekt | Aufgabe | Phase |
|---|---|---|
| LayoutManager | Konfiguration sammeln (Skeleton wählen, Partials/Assets registrieren) | Build-Phase |
| HtmlView | Aus Snapshot HTML rendern | Render-Phase |

**Lösung — Snapshot-Pattern:**

```php
// LayoutManager.php
public function buildView(): HtmlView
{
    return new HtmlView(
        skeletonPath: $this->documentTplPath,
        partials:     $this->partialFilePaths,
        css:          $this->assets['css'],
        js:           $this->assets['js'],
        nameSpace:    $this->nameSpace,
    );
}
```

```php
// HtmlView.php
public function __construct(
    private string $skeletonPath,
    private array  $partials,
    private array  $css,
    private array  $js,
    private string $nameSpace,
) {
    $this->renderer = new TemplateRenderer($nameSpace);
}
```

**Vorteile:**
- Keine zirkuläre Referenz.
- HtmlView ist immutable nach Konstruktion → sicher cacheable, sicher in Tests stubbar.
- LayoutManager kann nach `buildView()` verworfen werden.
- `render()` lebt nur noch an einer Stelle (HtmlView).

---

## 5. & 6. Debug-Parameter inkonsistent durchgereicht

**Aktuell:**

| Klasse | Hat `$debug` Parameter? | Nutzt es wofür? |
|---|---|---|
| LayoutManager | ✅ ja | Reicht es weiter an AssetVersionService und HtmlView |
| HtmlView | ✅ ja | **wird nie verwendet** (siehe Bug-Tabelle CP-Doku Eintrag 7) |
| AssetVersionService | ✅ ja | Nur in `minSuffix()` |
| StylesheetManager | ❌ nein | — |

**Analyse:**

Der User stellt die richtige Frage: **Warum überhaupt Parameter, wenn `DEBUG` als globale Konstante vorhanden ist?**

Es gibt zwei legitime Antworten — beide sind defensible, aber nicht beide gleichzeitig:

**Position A: Reine Dependency Injection.**
- `DEBUG` als Konstante existiert nur, weil das Bootstrap es so definiert hat.
- Klassen sollen testbar sein → Debug-Flag wird durchgereicht.
- Konsequenz: **alle** Klassen, die Debug-Verhalten haben, bekommen den Parameter — auch StylesheetManager, falls er ihn braucht.

**Position B: Globale Konstante akzeptieren.**
- `DEBUG` ist ein Bootstrap-Fact, der über die ganze Request-Lifetime stabil ist.
- Reaches the Code via `defined('DEBUG') && DEBUG`.
- Konsequenz: **keine** Klasse bekommt den Parameter. Auch nicht LayoutManager, AssetVersionService, HtmlView.

**Heutige Mischung ist die schlechteste aller Welten:**
- HtmlView nimmt Parameter, nutzt ihn nie (toter Code → siehe Bug-Tabelle).
- AssetVersionService nutzt es nur an einer Stelle.
- StylesheetManager nutzt es nicht, obwohl es CSS/JS verwaltet.

**Empfehlung — Position A konsequent (DI ist sauberer):**

1. **HtmlView** — Parameter entfernen (wird nicht genutzt).
2. **LayoutManager** — Parameter behalten (initialisiert AssetVersionService).
3. **AssetVersionService** — Parameter behalten, aber besser entkoppeln (siehe Punkt 7).
4. **StylesheetManager** — bleibt ohne Parameter (er delegiert alle Debug-Entscheidungen an `AssetVersionService`).
5. **AbstractBaseController** — passt bereits, übergibt `DEBUG` einmal an LayoutManager.

Das löst Punkt 5 (HtmlView räumt auf), Punkt 6 (StylesheetManager bewusst ohne Parameter, weil Verantwortung im AssetVersionService liegt — nicht aus Vergesslichkeit).

---

## 7. AssetVersionService — Debug steuert nur minSuffix, nicht version() (DER eigentliche Konzeptfehler)

**Datei:** [AssetVersionService.php](../../packages/kernel/core/src/Services/AssetVersionService.php)

```php
final class AssetVersionService
{
    private array $cache = [];

    public function __construct(private bool $debug = false) {}

    public function version(string $sourcePath): int
    {
        return $this->cache[$sourcePath] ??= (filemtime($sourcePath) ?: time());
    }

    public function minSuffix(): string
    {
        return $this->debug ? '' : '.min';
    }
}
```

**Befund — was tatsächlich passiert:**

| Modus | minSuffix() | version() | Effekt |
|---|---|---|---|
| `DEBUG=true` | `''` (sucht `core.js`) | `filemtime(core.js)` | Cache bustet nur bei direkter JS-Editierung |
| `DEBUG=false` | `'.min'` (sucht `core.min.js`) | `filemtime(core.min.js)` | Cache bustet nur bei Build |

**Was der User korrekt anmerkt:** `version()` ignoriert das Debug-Flag. Das ist nicht zwingend ein Funktionsbug, aber ein **Konzeptbug**:

1. **Verantwortung gemischt.** Der Service heisst `AssetVersionService`, aber `minSuffix()` hat nichts mit Versionierung zu tun — das ist Pfad-Konstruktion. Der Klassenname lügt.
2. **Debug-Flag tut nur die Hälfte.** Wer den Namen liest, erwartet entweder „Debug ändert Versionierung" oder „Debug ändert nichts an der Versionierung". Aktuell ändert es **etwas anderes** (Suffix), was beim Lesen verwirrt.
3. **In-Memory-Cache versteckt Reload-Probleme.** Beim Watch-Build (npm watcher) wird die Quelldatei zwar überschrieben, aber wenn ein Request die mtime einmal cached hat, bleibt sie für den ganzen Request stabil. Korrekt — aber für den Debug-Mode evtl. zu konservativ.

**Lösung — Verantwortungen klar trennen:**

### Option 1: Service splitten (empfohlen)

`AssetVersionService` macht nur Versionen. Der Suffix wandert in einen separaten kleinen Service oder direkt in den `StylesheetManager`.

```php
// AssetVersionService.php
final class AssetVersionService
{
    private array $cache = [];

    public function __construct(private bool $debug = false) {}

    /**
     * In production: filemtime — stable, automatic cache bust on rebuild.
     * In debug: filemtime as well, but cache is per-request — a watch-rebuild
     * during the same request keeps the same number (acceptable; the next
     * request picks up the new mtime).
     */
    public function version(string $sourcePath): int
    {
        return $this->cache[$sourcePath] ??= (filemtime($sourcePath) ?: time());
    }
}
```

```php
// StylesheetManager.php — bekommt den Modus direkt
final class StylesheetManager
{
    public function __construct(
        private AssetVersionService $assetVersion,
        private bool $debug = false
    ) {}

    private function minSuffix(): string
    {
        return $this->debug ? '' : '.min';
    }
}
```

**Vorteil:** Jede Klasse macht genau eine Sache. Punkt 6 löst sich automatisch — StylesheetManager hat den Parameter, weil er der natürliche Owner der `.min`-Logik ist.

### Option 2: Debug bewusst auch in version() berücksichtigen

Wenn das gewünschte Debug-Verhalten **aggressives Cache-Busting** wäre (jeder Request frisch):

```php
public function version(string $sourcePath): int
{
    if ($this->debug) {
        return time();          // jeder Request → neue Versionsnummer
    }
    return $this->cache[$sourcePath] ??= (filemtime($sourcePath) ?: time());
}
```

**Aber:** Dann müllen sich `assets/.../core_at-{X}.js` Dateien an (Cleanup wirft die alten weg, OK). Und der Browser lädt jede Sekunde neu, auch ohne Code-Änderung. Für Watch-Workflows ist `filemtime` praktisch besser — Editieren bewirkt Reload, sonstige Reloads sind cached.

**Empfehlung: Option 1.** Das ist die saubere Architektur. Option 2 nur, wenn ein konkreter Bedarf für aggressives Busting nachgewiesen ist.

---

## Zusammenfassung — Refactoring-Plan in Reihenfolge

| Schritt | Änderung | Datei(en) | Risiko |
|---|---|---|---|
| 1 | `echo $version;exit;` entfernen | StylesheetManager.php:34 | Trivial — sofort |
| 2 | Modul-LayoutConfig wird Pflicht (`throwError: true`) | LayoutManager.php:87-94 | Niedrig — alle Module haben bereits eine |
| 3 | `HtmlView` Parameter `$debug` entfernen (toter Code) | HtmlView.php:16, LayoutManager.php:503 | Niedrig |
| 4 | `minSuffix()` von AssetVersionService → StylesheetManager verschieben | AssetVersionService.php, StylesheetManager.php, LayoutManager.php (addJs throw) | Mittel — 3 Dateien betroffen |
| 5 | `LayoutManager::render()` entfernen, Snapshot-Pattern für HtmlView | LayoutManager.php, HtmlView.php, HtmlResponse.php, AbstractBaseController.php | Hoch — Architektur-Änderung |

**Schritte 1-4 können sofort und einzeln erfolgen.** Jeder ist isoliert testbar.

**Schritt 5 ist eine Architektur-Migration.** Sollte in einer eigenen Session mit Plan-Diskussion vorbereitet werden.

---

## Nicht-Probleme (zur Klarstellung)

- **`$debug` Parameter ist nicht per se schlecht.** Die Frage „warum nicht die globale Konstante?" ist legitim, aber DI ist die bessere Antwort — nur konsequent durchziehen.
- **`HtmlView::render()` Naming ist korrekt.** Das ist Standard-MVC. Die Konfusion entsteht durch das doppelte `render()` (LayoutManager + HtmlView), nicht durch den Begriff selbst.
- **`filemtime()` als Versionsquelle ist solide.** Automatisches Cache-Busting bei Build oder Edit, deterministisch, kein Datenbankzugriff. Nicht ändern.
