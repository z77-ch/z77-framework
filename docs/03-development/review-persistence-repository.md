# Review: Persistence & Repository Layer

**Stand:** 2026-05-07
**Scope:** UnifiedEntityManager → DataSourceResolver → FileEntityManager → FileRepository + Shared\Repositories + Naming

---

## Zusammenfassung

7 Probleme gefunden: 1 kritischer Bug (Datenkorruption bei save), 2 Architektur-Mängel (falsche Repository-Schicht, fehlende EntityRepository-Basis), 1 Namespace-Fehler (UserPreferences), 1 toter Code, 2 mittlere Mängel.

---

## BUG-P001 — Kritisch: `Naming::toCamelCase` zerstört camelCase → Datenkorruption

**Datei:** `packages/kernel/shared/src/Libraries/Convention/Naming.php:24`

```php
// strtolower VOR ucwords → camelCase-Input wird falsch:
$input = str_replace(' ', '', ucwords(strtolower($input)));
```

`toCamelCase('passwordHash')`:
- `strtolower` → `'passwordhash'`
- `ucwords` → `'Passwordhash'`
- Ergebnis: `'Passwordhash'` — nicht `'PasswordHash'`

**Folge im Persistence-Flow:**

`FileRepository::toArray()` (Zeile 99–105) schreibt PHP-Property-Namen direkt als JSON-Keys:
```php
$row[$prop->getName()] = $prop->getValue($entity); // → "passwordHash", "navigationId", ...
```

`FileRepository::hydrate()` liest diese camelCase-Keys zurück:
```
Naming::toSetter('passwordHash') → setPasswordhash()  ← Methode existiert nicht → Fatal Error
```

**Konkreter Crash-Ablauf (LoginUser):**

1. `loginUsers.json` hat manuell gesetzte snake_case Keys (z.B. `password_hash`) → Hydration klappt
2. `AuthService::savePreferences()` ruft `repo->save($user)` → `toArray()` schreibt camelCase: `"passwordHash"`
3. Nächster Login: `hydrate()` findet `passwordHash` → `setPasswordhash()` → **Fatal Error: Call to undefined method**

Der Preferences-Test (offene Pendenz) würde diesen Bug sofort auslösen.

**Betroffene Properties:**
- `LoginUser`: `passwordHash`
- `MetaData`: `navigationId`, `themeColor`, `applicationLd`
- `Navigation`: `aliasUrl`

**Fix:**

In `Naming::toCamelCase`: `strtolower` entfernen, nur Wort-Grenzen nach Trennzeichen hochziehen:
```php
// Vorher:
$input = str_replace(' ', '', ucwords(strtolower($input)));
// Nachher: strtolower nur wenn explizit gewünscht, nicht als Standard
$input = str_replace(' ', '', ucwords(preg_replace('/[^a-zA-Z0-9]+/', ' ', $input)));
```

In `FileRepository::toArray()`: JSON-Keys als snake_case schreiben, konsistent mit dem JSON-Format:
```php
$row[Naming::toSnakeCase($prop->getName())] = $prop->getValue($entity);
```

---

## ARCH-P001 — Repository-Pattern falsch implementiert: Wrapper statt EntityRepository

**Problem:** `packages/kernel/shared/src/Repositories/` ist eine reine Delegations-Schicht ohne Mehrwert.

**Aktueller Flow:**
```
LoginController
  → new LoginUserRepository($em)        ← direktes new, kein DI
  → LoginUserRepository::findByUsername()
  → $em->getRepository(LoginUser::class) ← gibt generischen FileRepository zurück
  → FileRepository::findBy(...)
```

**Beabsichtigter Flow (Ziel-Architektur):**
```
DI::getUnifiedEntityManager()->getRepository(LoginUser::class)
  → UnifiedEntityManager erkennt driver ('file')
  → booted FileEntityManager (lazy, einmalig)
  → FileEntityManager findet entity-spezifisches Repository
  → gibt LoginUserRepository zurück (extends EntityRepository)
  → EntityRepository: find / findAll / findBy / findOneBy / save / delete
  → LoginUserRepository: findByUsername() (entity-spezifisch)
```

**Was fehlt:**

1. `EntityRepository` abstract/base class in `packages/kernel/persistence/` — stellt CRUD bereit
2. `FileEntityManager::getRepository()` muss entity-spezifische Repos instanzieren, nicht immer `new FileRepository()`
3. Convention: Entity `LoginUser` → konkretes Repo `Z77\Shared\Repositories\LoginUserRepository`
4. Wenn kein konkretes Repo existiert, Fallback auf generischen `FileRepository`

**Indiz:** Alle drei Shared-Repositories (`LoginUser`, `Navigation`, `MetaData`) sind identisch aufgebaut — pure Durchleitung ohne Eigenlogik ausser den entity-spezifischen Find-Methoden. Das ist das Zeichen der fehlenden Basisklasse.

---

## ARCH-P002 — `UserPreferences` im falschen Namespace

**Datei:** `packages/kernel/shared/src/Auth/UserPreferences.php`

`Auth\` ist ausschliesslich Authentifizierungs-Logik. `UserPreferences` ist ein Value Object / DTO:
- Kein `#[Entity]`-Attribut (keine Datenbankbindung)
- Keine Auth-Semantik (kein Login, kein Token, kein Rolle)
- Wird in Session gespeichert und aus `LoginUser::$preferences` befüllt

**Fix:** Verschieben nach `packages/kernel/shared/src/ValueObjects/UserPreferences.php`

Alle Imports anpassen:
- `AuthService.php`
- `LoginController.php`
- `SystemController.php` (falls vorhanden)

---

## DEAD-P001 — `packages/kernel/core/src/Routing/NavigationRepository.php` ist totes Duplikat

**Datei:** `packages/kernel/core/src/Routing/NavigationRepository.php`

Diese Klasse:
- Wird von nichts mehr verwendet (Bootstrap nutzt `Z77\Shared\Repositories\NavigationRepository` via `NavigationService`)
- Enthält eine eigene `hydrateFromArray()` — dieselbe Logik wie `NavigationRepository::hydrateChild()` in Shared
- War der ursprüngliche Router-NavigationRepository, ersetzt durch den Shared-Aufbau

**Fix:** Datei löschen.

---

## MED-P001 — Hydration für Navigation-Children an 3 Stellen dupliziert

> **Erledigt 2026-05-29 (hinfällig).** Die Children-Array-Hydration existiert nicht mehr: das Tree-Modell wurde auf `parentId` (Skalar am Kind) umgestellt — kein verschachteltes `children`-Objekt mehr zu hydrieren (Topic `navigation.md` NAV-PARENTID-001). Der tote Core-`NavigationRepository` (DEAD-P001) ist gelöscht, der shared `NavigationRepository` enthält keine `hydrateChild()` mehr (Query-Logik liegt im `NavigationService`). `FileRepository::hydrate()` lädt nur noch Skalare. Befund nur noch historisch.

Hydrations-Logik für Children-Arrays existiert dreifach:
- `packages/kernel/core/src/Routing/NavigationRepository::hydrateFromArray()` (toter Code, s. DEAD-P001)
- `packages/kernel/shared/src/Repositories/NavigationRepository::hydrateChild()`
- `FileRepository::hydrate()` (generisch, aber kann keine nested Objekte)

Die Wurzel: `Navigation::$children` ist ein `array` von rohen Arrays, nicht ein `array` von `Navigation`-Objekten. `FileRepository::hydrate()` kann das nicht ohne Erweiterung lösen.

**Fix:** Nach ARCH-P001-Umsetzung: `NavigationRepository extends EntityRepository` kann `hydrateChild()` intern halten. Die Core-Kopie fällt mit DEAD-P001 weg.

---

## MED-P002 — `AuthService::savePreferences()` nimmt Repository als Parameter

**Datei:** `packages/kernel/shared/src/Services/AuthService.php:119`

```php
public function savePreferences(UserPreferences $prefs, LoginUserRepository $repo): void
```

Der Caller muss den Repo selbst beschaffen und übergeben. Das bricht das DI-Pattern — `AuthService` soll Abhängigkeiten im Konstruktor erhalten oder via DI auflösen.

**Fix:** `LoginUserRepository` als Konstruktor-Parameter in `AuthService`, registriert im Bootstrap.

---

## MED-P003 — `LoginController` instanziert Repository direkt

**Datei:** `packages/module-backend/src/Ui/Controllers/LoginController.php:43`

```php
$user = (new LoginUserRepository(DI::getUnifiedEntityManager()))->findByUsername($username);
```

Nach ARCH-P001 soll das heissen:
```php
$user = DI::getUnifiedEntityManager()->getRepository(LoginUser::class)->findByUsername($username);
```

---

## Abhängigkeiten zwischen den Issues

```
BUG-P001  → sofort beheben, unabhängig von allem anderen
ARCH-P001 → Basis für MED-P002, MED-P003
DEAD-P001 → unabhängig, sofort möglich
ARCH-P002 → unabhängig, sofort möglich
MED-P001  → wird durch ARCH-P001 mitgelöst
MED-P002  → nach ARCH-P001
MED-P003  → nach ARCH-P001
```
