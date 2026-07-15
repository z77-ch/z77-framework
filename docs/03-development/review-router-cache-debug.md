# Review — Router, CacheManager & Debug-Mechanismen

**Date:** 2026-04-14
**Files:** `Router.php`, `CacheManager.php`, `Bootstrap.php`, `autoload/debug/php/Functions.php`
**Status:** `[IN PROGRESS]`

---

## Übersicht: Alle Debug-Mechanismen

Das Framework hat **sechs** aufeinander abgestimmte Debug-Mechanismen:

| # | Mechanismus | Wo | Wann aktiv |
|---|---|---|---|
| 1 | `DEBUG` Konstante | `Bootstrap.php:66` | immer — Master-Switch |
| 2 | APCu Clear on Request | `Bootstrap.php:69-71` | nur DEBUG |
| 3 | CacheManager Hit-Tracking | `CacheManager.php:66-107` | nur DEBUG |
| 4 | `debugApcu()` Dump | `Router.php:47-49` | nur DEBUG, am Request-Ende |
| 5 | `setOwnExceptionHandler()` | `Router.php:34` | nur DEBUG |
| 6 | `debug()` Funktion | `autoload/debug/php/Functions.php` | nur DEBUG |

---

## Mechanismus 1 — `DEBUG` Konstante

```php
// Bootstrap.php:66
define('DEBUG', ($bootstrapConfig->getDebug() === true));
```

Master-Switch für alle anderen Mechanismen. Gesetzt aus `config/bootstrap.inc.php` → Quelle ist `composer.json extra.core-bootstrap.debug`.

**Korrekt.** Einziger Nachteil: globale PHP-Konstante ist nicht testbar (v1.1).

---

## Mechanismus 2 — APCu Clear bei jedem Debug-Request

```php
// Bootstrap.php:69-71
if (DEBUG) {
    DI::getCacheManager()->clearAllApcu();
}
```

Sinn: Im Debug-Modus soll nie gecachter Stand gezeigt werden. Jeder Request startet mit leerem APCu-Cache.

**Korrekt und notwendig** — ohne das würde man im Debug immer alten Stand sehen.

---

## Mechanismus 3 — CacheManager Hit-Tracking

```php
// CacheManager.php:66, 77, 93, 105
if (defined('DEBUG') && DEBUG) {
    $this->incrementDebug($key, 'local'); // oder 'apcu', 'file', 'miss'
}
```

Zählt pro Key wie oft aus welcher Cache-Schicht gelesen wurde. Ergebnis landet in `$this->debugStats`.

**Korrekt.** Wird von `debugApcu()` am Request-Ende ausgelesen und angezeigt.

---

## Mechanismus 4 — `debugApcu()` Dump am Request-Ende

```php
// Router.php:46-49
if (DEBUG) {
    $cacheManager->debugApcu(message: 'this is the end of script....', limited: true);
}
```

Zeigt nach dem Controller-Run:
- APCu-Speicher (total/used/free)
- Alle Keys mit Hits, Grösse, Timestamp
- Hit-Statistiken aus Mechanismus 3

**Korrekt** — kommt nach `$controller->run()`, also nach dem vollständigen Request.

**Abhängigkeit:** Muss nach `flushToTarget()` aufgerufen werden, damit APCu den aktuellen Stand zeigt.

---

## Mechanismus 5 — `setOwnExceptionHandler()`

```php
// Router.php:34
if (DEBUG) {
    setOwnExceptionHandler();
}
```

Registriert drei Handler:
- `set_exception_handler` — fängt uncaught Exceptions, zeigt HTML-Fehlerbox
- `set_error_handler` — fängt Warnings/Notices
- `register_shutdown_function` — fängt Fatal Errors

**Korrekt.** Aber: wird in `Router::dispatch()` gesetzt, also erst nach Bootstrap und Routing. Fehler in `Bootstrap::__construct()` oder `pullUp()` werden noch vom PHP-Default-Handler behandelt.

**Frage:** Soll der Handler früher greifen — direkt in Bootstrap? Dann müsste `setOwnExceptionHandler()` schon in `preBoot/php/Functions.php` oder im Bootstrap-Konstruktor verfügbar sein.

---

## Mechanismus 6 — `debug()` Funktion

Inline-Debug-Box mit Aufrufer-Info, Runtime und optionalem Array-Dump.

**Korrekt.** Steht in `autoload/debug/php/Functions.php`, wird in `Router::dispatch()` geladen — also vor `$controller->run()` verfügbar.

---

## BUG — ARCH-004: `flushToTarget()` wird doppelt aufgerufen

```php
// Router.php:44-54
$cacheManager->flushToTarget();      // ← immer, synchron

if (DEBUG) {
    $cacheManager->debugApcu(...);
}

if (!DEBUG) {
    register_shutdown_function(function() use ($cacheManager) {
        $cacheManager->flushToTarget(); // ← nochmals in Prod
    });
}
```

**In DEBUG:** `flushToTarget()` läuft einmal synchron — korrekt.
**In PROD:** `flushToTarget()` läuft synchron UND im Shutdown-Handler — doppelt.

`flushToTarget()` leert `$this->toCache` nicht nach dem ersten Durchlauf. Beim zweiten Aufruf werden alle APCu- und File-Writes nochmals ausgeführt.

### Warum der Shutdown-Handler?

Performance-Optimierung: In Production soll der Cache-Flush erst nach dem Senden der Response passieren. Der User bekommt die Antwort schneller, der Flush läuft danach im Hintergrund.

### Fix

```php
if (DEBUG) {
    $cacheManager->flushToTarget();
    $cacheManager->debugApcu(message: 'this is the end of script....', limited: true);
} else {
    register_shutdown_function(function() use ($cacheManager) {
        $cacheManager->flushToTarget();
    });
}
```

Oder alternativ: `flushToTarget()` am Ende idempotent machen (`$this->toCache = []` nach dem Flush).

---

## Offene Frage — Exception Handler Timing

`setOwnExceptionHandler()` greift erst ab `Router::dispatch()`. Fehler in Bootstrap (z.B. fehlende Config, DI-Fehler) werden vom PHP-Default-Handler ausgegeben — kein schönes HTML-Format.

**Optionen:**
- A: `setOwnExceptionHandler()` in `preBoot/php/Functions.php` verschieben — immer aktiv wenn DEBUG
- B: Status quo akzeptieren — Bootstrap-Fehler sind Entwickler-Fehler, PHP-Output reicht

---

## Action Items

| Priorität | ID | Action |
|---|---|---|
| Bug | ARCH-004 | `flushToTarget()` doppelten Aufruf entfernen — synchronen Call aus PROD-Pfad raus |
| ✓ Won't fix | EH-001 | Exception Handler bleibt in Router — Bootstrap ist Infrastruktur, Fehler dort sind Einrichtungsfehler. PHP-Default reicht. |
