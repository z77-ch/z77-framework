# Pendenzenliste — z77 Framework v1.0.0

**Stand:** 2026-05-07
**Quelle:** review-overall.md, review-bootstrap.md, review-installer.md + laufende Debug-Session

---

## Legende

- `[ ]` offen
- `[x]` erledigt
- Priorität: **Bug** > **Architektur** > **Medium** > **Minor** > **Future (v1.1)**

---

## Bugs — vor Publikation zwingend beheben

### Bootstrap.php

- `[x]` **BUG-B001** — `ltrim(..., '/')` fix
- `[x]` **BUG-B002** — `echo 'Bootstrap::z69 DEBUG IS TRUE<br>'` entfernt

### index.php (skeleton + core)

- `[x]` **BUG-I001** — `ABS_BASE_PATH` mit `str_replace('\\', '/', ...)` normalisiert → konsistente Forward Slashes

### FileFinder.php

- `[x]` **BUG-F001** — `getAbsPath()`: `rtrim` → `ltrim` (Doppelslash bei führendem `/`)
- `[x]` **BUG-F002** — `discoverNamespacePaths()`: wirft bei fehlendem Pfad → jetzt `continue` (lenient)
- `[x]` **BUG-F003** — `findFirstMatch()`: Doppelslash wenn `$subDir = ''`
- `[x]` **BUG-F004** — PHP 8 Deprecation: `$subDir = ''` vor required params → alle nachfolgenden mit Defaults

### StylesheetManager / LayoutManager

- `[x]` **BUG-S001** — `getVersionizedCss` / `getVersionisedCss` → `getVersionedCss` (Namens-Mismatch)

### Install.php

- `[x]` **CRIT-001** — `self::$io` vor `loadConfig()` setzen — bereits in aktuellem Code
- `[x]` **CRIT-002** — `require_once` → `require` — bereits in aktuellem Code
- `[x]` **CRIT-003** — Null-Checks für Pflicht-Config-Keys — `frameworkPrefix`/`modulePrefix` werfen explizit; restliche Keys durch Defaults abgedeckt

---

## Architektur-Entscheidungen offen

- `[x]` **ARCH-001** — `final` auf `ControllerHandler` und `ModuleManager` entfernt
- `[x]` **ARCH-002** — `defaultModule` korrekt in `moduleManager.default.inc.php` → Installer schreibt es in `skeleton/config/moduleManager.inc.php` → `ModuleManager` liest via Config
- `[x]` **ARCH-003** — `DI::__callStatic` wirft jetzt Exception statt `null` zurückzugeben
- `[x]` **ARCH-004** — `flushToTarget()` doppelter Aufruf behoben — DEBUG synchron, PROD via Shutdown-Handler
- `[x]` **ARCH-006** — Won't fix: `core`, `shared`, `persistence`, `module-frontend`, `module-backend` werden immer zusammen installiert (Monorepo). Cross-dependencies in Einzel-`composer.json` wären irreführend. `skeleton/composer.json` ist die einzige Wahrheit.
- `[x]` **ARCH-007** — `tplCachePaths` entfernt: kein realer Use-Case (DataCache cached Daten, Page-Cache cached fertige Seiten, PHP-Template-Rendering selbst ist vernachlässigbar schnell)
- `[x]` **ARCH-008** — Installer komplett neu geschrieben (2026-05-03): static class → Instanz-Pattern, `throw new \RuntimeException` überall konsequent, `mkDirs()`/`writeFile()`/`copyFiles()` werfen bei Fehler, kein stiller Fehler mehr.

---

## Medium — vor Publikation beheben

### Bootstrap.php

- `[x]` **MED-B001** — Autoloader-Block entfernt
- `[x]` **MED-B002** — `new $routerClass(...)` → `new Router(...)`
- `[x]` **MED-B003** — Deutsche Kommentare übersetzt
- `[x]` **MED-B004** — Irreführenden Konstruktor-Docblock korrigiert
- `[x]` **MED-B005** — `$reset = true`-Stil durch saubere Argumente ersetzt

### Install.php

- `[x]` **MED-001** — `scandir()` gegen `false` absichern — `?: []` bereits in aktuellem Code
- `[x]` **MED-002** — `file_put_contents()` Rückgabewert prüfen — `=== false` + throw bereits in `writeFile()`
- `[x]` **MED-003** — `mkdir()` in `writeFile()` Rückgabewert prüfen — im Neubau (ARCH-008) behoben
- `[x]` **MED-004** — `getConfig()` → `loadConfig()` — bereits umbenannt
- `[x]` **MED-005** — `$directories` als Parameter, `$composer` entfernt — Signatur bereits korrekt
- `[x]` **MED-006** — Inneres `$path` → `$singlePath` — bereits in aktuellem Code
- `[x]` **MED-007** — `$vendorPaths` explizit deklarieren — bereits in aktuellem Code

### Sonstiges

- `[x]` **Q-003** — `skeleton/composer.json`: `name` ist `"myvendor/myproject"` (Platzhalter), `type` ist `"project"` (korrekt so)
- `[x]` **Q-004** — `tplDir` fehlt in `bootstrap.default.inc.php` → ergänzt mit `'res/view/templates'`

---

## Persistence & Repository Layer — Pendenzen (offen seit 2026-05-07)

Review: [`review-persistence-repository.md`](review-persistence-repository.md)

### Bugs

- [x] **BUG-P001** — resolved. `Naming::toCamelCase` schützt camelCase-Input jetzt durch Early-Return (nur `ucfirst()`, kein `strtolower` mehr, wenn keine Trenner vorhanden). Kommentare auf Englisch übersetzt. `passwordHash` → `PasswordHash`, Round-Trip funktioniert.

### Architektur

- [ ] **ARCH-P001** — `EntityRepository`-Basisklasse fehlt. `packages/kernel/shared/src/Repositories/` sind reine Delegations-Wrapper ohne Mehrwert. `UnifiedEntityManager::getRepository()` soll entity-spezifische Repos zurückgeben (`LoginUserRepository extends EntityRepository`). `FileEntityManager` muss entity-spezifische Repos instanzieren können, Fallback auf generischen `FileRepository`. **Vor Publikation beheben — vor MED-P002 und MED-P003.**
- [ ] **ARCH-P002** — `UserPreferences` liegt in `Z77\Shared\Auth\` statt `Z77\Shared\ValueObjects\`. Auth ist ausschliesslich Authentifizierungs-Logik. Verschieben + Imports anpassen (`AuthService`, `LoginController`).

### Toter Code

- [ ] **DEAD-P001** — `packages/kernel/core/src/Routing/NavigationRepository.php` ist ein totes Duplikat. Wird nirgends verwendet (Bootstrap nutzt `Shared\Repositories\NavigationRepository`). Enthält eigene `hydrateFromArray()` — dritte Kopie der Hydrations-Logik. Datei löschen.

### Medium

- [ ] **MED-P001** — Hydrations-Logik für Navigation-Children dreifach vorhanden: `Core\Routing\NavigationRepository::hydrateFromArray()` (toter Code), `Shared\NavigationRepository::hydrateChild()`, `FileRepository::hydrate()` (generisch). Fällt mit DEAD-P001 + ARCH-P001 von selbst weg.
- [ ] **MED-P002** — `AuthService::savePreferences(UserPreferences $prefs, LoginUserRepository $repo)` nimmt Repo als Parameter. DI-Pattern gebrochen — Repo soll im Konstruktor injiziert werden. Nach ARCH-P001 umsetzen.
- [ ] **MED-P003** — `LoginController` instanziert `LoginUserRepository` direkt via `new`. Nach ARCH-P001 ersetzt durch `DI::getUnifiedEntityManager()->getRepository(LoginUser::class)`.

---

## Asset-Architektur — Pendenzen (offen seit 2026-05-06)

Aus dem Refactoring der Asset-Architektur (siehe [review-asset-architecture-result.md](review-asset-architecture-result.md)) übrig geblieben:

- `[x]` **CACHE-001** — `SystemController::clearCacheAction()` erweitert (2026-05-06): neuer Service `Z77\Core\Services\AssetCleaner` durchsucht alle registrierten assetPaths rekursiv und löscht alle `*_at-{stamp}.{css,js}` Dateien. Anzahl wird in der Erfolgsmeldung ausgegeben.

- [ ] **ARCH-009** — `LayoutManager` ist ein God-Object (~430 Zeilen, 5 Verantwortungen: Config laden + Skeleton + Partials + Assets + View-Build). Saubere Aufteilung wäre `LayoutConfigLoader` + `PartialRegistry` + `AssetRegistry` + `LayoutManager` als dünner Coordinator. Akzeptabel im aktuellen Umfang — splitten erst bei mehreren parallelen Layout-Strategien sinnvoll.

- [ ] **ARCH-010** — Asset-Listen sind Magic-Arrays (`['name' => …, 'path' => …, 'mediaQueryOption' => …]`). Typsichere DTOs (`CssAsset`, `JsAsset` als readonly Klassen) wären robuster gegen Tippfehler im Schlüssel und selbstdokumentierend. Lösung noch offen, **vor Umsetzung diskutieren**.

- `[x]` **ASSET-001** — Source-Maps Handling umgesetzt (2026-05-06): `JavascriptManager` und `StylesheetManager` kopieren beim Versionieren auch das `.map`-Pendant mit (falls Source-Map existiert). `AssetCleaner` löscht beim Räumen einer versionierten Datei das zugehörige `.map` mit. Pattern erweitert um `.map`-Suffix.

---

## Minor — polishing vor Publikation

### Bootstrap.php

- `[x]` **MIN-B001** — Tippfehler und Formatierung behoben
- `[x]` **MIN-B002** — `$di ->set` → `$di->set`
- `[x]` **MIN-B003** — `pullUp()` PHPDoc vervollständigt

### Install.php

- `[x]` **MIN-001** — `SOURCE_DIR = 'src'` (kein Suffix)
- `[x]` **MIN-002** — Trailing space in `getContentHeader()` entfernt
- `[x]` **MIN-003** — Generiertes PHP durchgehend 4-Space-Indent

---

## Ausstehende Reviews

| Komponente | Status | Nächster Schritt |
|---|---|---|
| `DI.php` | `[x]` | Review + Fixes erledigt 2026-05-03 → `review-di.md` |
| `Router.php` | `[x]` | Review + Fixes erledigt 2026-05-03 → `review-router.md` (inkl. Request.php + ControllerHandler.php) |
| `FileFinder.php` | `[x]` | Review + Fixes erledigt 2026-05-03 → `review-filefinder.md` |
| `CacheManager.php` | Pending | — |
| `packages/kernel/shared/` + `packages/kernel/persistence/` | `[x]` | Review erledigt 2026-05-07 → `review-persistence-repository.md` (Shared\Repositories, FileRepository, Naming, UserPreferences) |
| `packages/module-frontend/` | Pending | — |

---

## Toter Code — vor Publikation entfernen

Leichen im Keller. Alles unten ist entweder nie aufgerufen, nie fertiggestellt, oder durch die aktuelle Architektur obsolet geworden.

### Dateien löschen

- `[x]` **DEAD-001** — `packages/kernel/core/src/Services/TemplateResolver.php` — Datei existiert nicht mehr
- `[x]` **DEAD-002** — `packages/kernel/core/src/Services/RenderedCss.php` — Datei existiert nicht mehr

### Methoden / Properties entfernen

- `[x]` **DEAD-003** — `StylesheetManager`: tote Properties/Methoden existieren nicht mehr — Klasse ist sauber
- `[x]` **DEAD-004** — `LayoutManager::setContext()` / `::getContext()` — Methoden existieren nicht mehr
- `[x]` **DEAD-005** — `LayoutManager::getViewdocumentSettings()` — Methode existiert nicht mehr
- `[x]` **DEAD-006** — `LayoutManager::setDefaultCss()` / `::setDefaultJs()` — Methoden existieren nicht mehr
- `[x]` **DEAD-007** — `AbstractBaseController::$view` — Property existiert nicht mehr

---

## Backend — implementiert (2026-05-04)

- `[x]` **ARCH-R001** — `Request::setController()` kaskadiert jetzt auf Controller-Level `defaultAction` statt Modul-Level. Nav-Hits nutzen `assignModule`/`assignController` ohne Kaskade. Jeder Controller definiert seine eigene `defaultAction` in der Modul-Config.
- `[x]` **FEAT-BE001** — `DashboardController::overviewAction()` — Einstiegsseite nach Login, 6 Modul-Karten im Grid.
- `[x]` **FEAT-BE002** — `SystemController::clearCacheAction()` — POST → APCu-Cache leeren → redirect.
- `[x]` **FEAT-BE003** — `SystemController::toggleDebugAction()` — POST → `$_SESSION['be.devmode']` toggeln → redirect.
- `[x]` **FEAT-BE004** — Service Panel (Avatar-Dropdown) — Debug-Toggle + Clear-Cache + Logout, inline JS im Footer.
- `[x]` **FEAT-BE005** — Werkbank-Design vollständig: Topbar, Subnav, ServicePanel, Overview, Login neu gestylt.

---

## Future — v1.1 (nach Erstpublikation)

### Backend — Überwachung

- [ ] **FEAT-MON001** — `CacheMonitorService`: APCu-Zugriffe loggen (was, wie oft hit/miss), aktiviert via `cacheDebug=true` in bootstrap.inc.php (unabhängig von `debug`). Schreibt in `lib/cache/cache-debug.log`. Eigener Service, nicht in DataCache/Dispatcher eingeflickt.
- [ ] **FEAT-MON002** — Backend-Bereich "Cache Monitor": Logdatei anzeigen + "Clear Log"-Button. Gleicher Bereich wie Clear-Cache-Button (FEAT-BE001).

### Backend — Allgemein

- [ ] **Q-002 / ARCH-B001** — `define('DEBUG', ...)` durch injizierbaren Config-Wert ersetzen
- `[x]` **ARCH-A** — Installer: static → Instanz-Pattern umgesetzt in ARCH-008 (2026-05-03)
- `[x]` **ARCH-C** — Won't fix: Installer ist 180 Zeilen, eine Klasse reicht. Kein Mehrwert durch Aufteilung.
