# Asset-Architektur — Refactoring-Resultat

Erstellt: 2026-05-06
Bezug: [review-asset-architecture.md](review-asset-architecture.md) (Vorab-Analyse)
Status: Code-Änderungen abgeschlossen, **noch nicht im Browser getestet**.

---

## Was geändert wurde

### Neue Dateien

| Datei | Verantwortung |
|---|---|
| [JavascriptManager.php](../../packages/kernel/core/src/Services/JavascriptManager.php) | JS-Versionierung + `.min`-Suffix-Logik |

### Geänderte Dateien

| Datei | Änderung |
|---|---|
| [LayoutManager.php](../../packages/kernel/core/src/Services/LayoutManager.php) | `render()` entfernt, `buildView()` eingeführt; nutzt jetzt StylesheetManager + JavascriptManager getrennt; Modul-Config Pflicht; alle Read-Getter (getCss/getJs/…) entfernt |
| [HtmlView.php](../../packages/kernel/core/src/Services/HtmlView.php) | Konstruktor nimmt Daten statt LayoutManager-Referenz; `$debug` Parameter entfernt; keine zirkuläre Abhängigkeit mehr |
| [HtmlResponse.php](../../packages/kernel/core/src/Http/Response/HtmlResponse.php) | `getHtml()` ruft `buildView()` lazy auf — late-arrival-fähig |
| [StylesheetManager.php](../../packages/kernel/core/src/Services/StylesheetManager.php) | Nur noch CSS (`getVersionedCss`, `createCss`); JS-Methoden raus |
| [AssetVersionService.php](../../packages/kernel/core/src/Services/AssetVersionService.php) | Nur noch Versionierung; `$debug` Parameter und `minSuffix()` raus |

---

## Architektur — Vorher vs. Nachher

### Vorher

```
HtmlResponse::send()
  └─ HtmlResponse::getHtml()
        └─ LayoutManager::render($context)              ← reiner Pass-through
              └─ LayoutManager::getView() (lazy)
                    └─ new HtmlView($this, $debug)      ← zirkuläre Referenz
              └─ HtmlView::assign()->render()
                    └─ $this->layoutManager->getCss()   ← Late-Lookup
                    └─ $this->layoutManager->getJs()    ← Late-Lookup

AssetVersionService($debug)         ← debug nur für minSuffix() benutzt
StylesheetManager(...)              ← enthält CSS + JS (Namens-Lüge)
```

### Nachher

```
HtmlResponse::send()
  └─ HtmlResponse::getHtml()
        └─ LayoutManager::buildView()                   ← Snapshot, einmal
              └─ new HtmlView(skeletonPath, partials, css, js, nameSpace)
        └─ HtmlView::assign()->render()                 ← arbeitet auf Snapshot

AssetVersionService                  ← reine Versionierung, kein debug
StylesheetManager(assetVersion)      ← nur CSS
JavascriptManager(assetVersion, $debug)  ← nur JS, kennt .min
```

---

## Was die einzelnen Punkte aus dem Vorab-Review jetzt machen

| # | Vorab-Review-Punkt | Status | Wie gelöst |
|---|---|---|---|
| 0 | `echo $version;exit;` | **fixed** | Entfernt aus StylesheetManager.php |
| 1 | Modul-LayoutConfig fehlende Exception | **fixed** | `throwError: true` (LayoutManager.php:96) |
| 2 | Render versteckt in HtmlResponse | **fixed** | `LayoutManager::render()` entfällt; HtmlResponse ruft `buildView()` direkt |
| 3 | Doppeltes `render()` Naming | **fixed** | Es gibt nur noch `HtmlView::render()` |
| 4 | Zirkuläre Abhängigkeit View↔Manager | **fixed** | Snapshot-Pattern — HtmlView hat nur Daten |
| 5 | LayoutManager nimmt `$debug` Parameter | **bewusst beibehalten** | DI ist sauberer als globale Konstante; jetzt konsequent durchgezogen |
| 6 | StylesheetManager hat keinen `$debug` | **bewusst beibehalten** | Nur JavascriptManager braucht ihn (für `.min`); CSS hat keine debug/prod-Variante |
| 7 | AssetVersionService steuert Debug nur halb | **fixed** | `minSuffix()` wanderte zum `JavascriptManager`; `version()` ist jetzt debug-aware: in debug `time()` (frisch pro Request, bypasst Browser-Cache), in prod `filemtime()` (stabil) |
| Bonus | Stylesheet vs. Javascript-Vermischung | **fixed** | Service gesplittet — Klassennamen sagen jetzt die Wahrheit |

---

## Detailbeschreibung der Änderungen

### 1. Modul-LayoutConfig ist Pflicht

**[LayoutManager.php:93-97](../../packages/kernel/core/src/Services/LayoutManager.php#L93-L97)**

```php
$moduleLayoutConfig = DI::getConfigManager()->getArrayConfig(
    configName: 'Ui/Config/layoutConfig',
    nameSpace: $this->nameSpace,
    throwError: true
);
$this->applyLayoutConfig($moduleLayoutConfig);
```

Modul ohne `layoutConfig.inc.php` → harte Exception. Tippfehler im Modulnamen produzieren keine optisch defekte Seite mehr.

ControllerLayoutConfig bleibt optional — Controller dürfen mit Modul-Defaults laufen.

### 2. Snapshot-Pattern (LayoutManager → HtmlView)

**[LayoutManager.php:130-141](../../packages/kernel/core/src/Services/LayoutManager.php#L130-L141)**

```php
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

**HtmlView** kennt den LayoutManager nicht mehr — ist immutabel, in sich abgeschlossen, einfach testbar.

**Lazy build:** `buildView()` wird erst von `HtmlResponse::getHtml()` aufgerufen, also **nach** preExecute, action, postExecute. Late-Asset-Registrations (z.B. `postExecute` fügt Controller-spezifisches JS hinzu) sind erfasst.

### 3. Service-Split: Stylesheet ≠ Javascript

**Vorher:**
```php
StylesheetManager
  ├─ getVersionedCss()     ← CSS
  ├─ createCss()           ← CSS
  └─ getVersionedJs()      ← JS  (semantisch falsch platziert)
```

**Nachher:**
```php
StylesheetManager(AssetVersionService)
  ├─ getVersionedCss()
  └─ createCss()

JavascriptManager(AssetVersionService, $debug)
  ├─ minSuffix()
  └─ getVersionedJs()
```

LayoutManager hält beide:
```php
$this->stylesheetManager = new StylesheetManager($assetVersion);
$this->javascriptManager = new JavascriptManager($assetVersion, $this->debug);
```

`addCss()` → StylesheetManager. `addJs()` → JavascriptManager.

### 4. AssetVersionService ist wieder ein reiner Versions-Service — und nutzt `$debug` jetzt richtig

**Vorher:** Mischte `version()` (Versionierung) und `minSuffix()` (Pfad-Konstruktion) — Klassenname log. Der `$debug` Parameter wurde nur in `minSuffix()` benutzt; `version()` ignorierte ihn.

**Nachher:**
- Nur noch `version($sourcePath): int`. `minSuffix()` lebt im `JavascriptManager`, weil `.min` ein JS-Konzept ist (CSS hat keine debug/prod-Varianten).
- `version()` ist jetzt **debug-aware**:

```php
public function version(string $sourcePath): int
{
    if ($this->debug) {
        return time();          // debug: frisch pro Request → Browser-Cache aus
    }
    return $this->cache[$sourcePath] ??= (filemtime($sourcePath) ?: time());
}
```

**Effekt im Debug-Modus:** Jeder Request → neue Version → kopiert+aufräumt+lädt frisch. Der Browser-Cache wird konsequent umgangen. Kosten: ein Copy + ein Glob-Cleanup pro Asset pro Request. In Debug akzeptabel.

**Effekt in Production:** Unverändert — filemtime, einmal pro Request gestat, stabil über alle Requests bis zur nächsten Asset-Änderung.

### 5. HtmlView räumt toten Code auf

`$debug` Parameter wurde nirgends benutzt → entfernt.

---

## Datenfluss — End-to-End (nachher)

```
1. AbstractBaseController::run()
     new LayoutManager($controllerHandler, DEBUG)
         └─ baut intern: AssetVersionService, StylesheetManager, JavascriptManager

2. preExecute() — optional, z.B. Auth

3. Action ruft $this->html($context):
     LayoutManager::initialize()              ← Module/ControllerConfig laden
     return new HtmlResponse($layoutManager, $context)

4. postExecute() — kann noch Assets hinzufügen via $this->layoutManager

5. Dispatcher::execute() ruft $response->send()

6. HtmlResponse::send() entscheidet:
     ├─ NotModified  → 304, ENDE
     ├─ ServerCached → echo $this->html (aus Cache geladen), ENDE
     └─ NoStore      → echo $this->getHtml()

7. HtmlResponse::getHtml() (nur im NoStore-Pfad):
     $view = $layoutManager->buildView()      ← Snapshot zur Send-Zeit
     return $view->assign($context)->render()

8. HtmlView::render() rendert auf Basis des Snapshots — kein Late-Lookup
```

---

## Bewusst nicht geändert

| Bereich | Begründung |
|---|---|
| `Dispatcher` | Polymorphes `ResponseInterface::send()` ist sauber — nicht anrühren |
| `HtmlResponse::send()` | Drei-Pfad-Logik (NotModified, ServerCached, NoStore) bleibt; nur `getHtml()` intern angepasst |
| `HtmlResponse::fromCache/notModified` Factories | Brauchen weiterhin keinen LayoutManager |
| `AbstractBaseController` | Unverändert — übergibt weiterhin `DEBUG` an LayoutManager |
| `Bootstrap` | Wie vom User gefordert nicht angefasst |
| `filemtime`-basiertes Versioning | Funktioniert für beide Modi korrekt |

---

## Offene Punkte (nicht im Refactoring-Scope)

| # | Punkt | Empfehlung |
|---|---|---|
| 1 | `debug.flag` fehlt in `.gitignore` | Eintrag hinzufügen |
| 2 | `bootstrap.inc.php` veraltetes Feld `'debug' => true` | Löschen |
| 3 | `styles.tpl.php` ist leer | Klären ob absichtlich |

---

## Verifikation vor Test

**Syntax:** Alle 7 betroffenen PHP-Dateien wurden mit `php -l` geprüft — keine Fehler.

**Toter Code:** `grep` nach den entfernten Methoden (`getNameSpace`, `getSkeletonTemplatePath`, `getPartialPaths`, `->getCss()`, `->getJs()`, `layoutManager->render`) im `packages/`-Baum — keine Treffer.

**Modul-Coverage:** Beide Module (`module-backend`, `module-frontend`) haben `layoutConfig.inc.php` → der harte `throwError: true` bricht nichts.

---

## Test-Plan (für die Browser-Verifikation)

| # | Schritt | Erwartetes Resultat |
|---|---|---|
| 1 | `debug.flag` löschen → prod | Seite lädt, JS-Tags zeigen `core_at-{X}.min.js` |
| 2 | `debug.flag` erstellen → debug | Seite lädt, JS-Tags zeigen `core_at-{Y}.js` (kein `.min`) |
| 3 | `debug.flag` wieder löschen | `core_at-{X}.min.js` zurück, alte Versionen bereinigt |
| 4 | CSS prüfen | `<link>` Tags zeigen `*_at-{mtime}.css` |
| 5 | Modul ohne layoutConfig (nur Test) | Wirft Exception statt halb-rendern |
| 6 | postExecute fügt Asset hinzu | Erscheint korrekt im HTML (buildView ist lazy) |
| 7 | Page-Cache-Hit | HTML aus Cache, kein buildView-Aufruf |
| 8 | 304 NotModified | Kein Body, korrekte Header |

Logs: `skeleton/logs/php-error.log`
