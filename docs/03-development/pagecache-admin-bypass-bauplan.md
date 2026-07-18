# Bauplan — PageCache-Bypass für Admin-Sessions (Bugfix CACHE-ADMIN-001)

**Status:** `[DONE]` — 2026-07-18
**Date:** 2026-07-18

Ziel: Eine Session mit Rolle >= ADMIN nimmt nie am geteilten PageCache teil —
weder lesend noch schreibend. Fixt zwei Symptome derselben Wurzel:

1. **Leak:** Rendert ein eingeloggter Admin eine cachebare Frontend-Seite bei
   Cache-Miss, speichert `Dispatcher::tryStore()` das HTML **inklusive
   Admin-Overlay** (Name, Rolle, Initialen, Backend-URL, Routing-Interna) in den
   geteilten Cache — Besucher erhalten es bis zu TTL (Frontend: 24h) ausgeliefert.
2. **Fehlendes Overlay:** Liegt die Gast-Version im Cache (Server-Hit oder
   304 via ETag), sieht der Admin sein Overlay nicht — obwohl `backend.md`
   «on every full frontend page» zusichert.

## Fehlerbild (verifiziert 2026-07-18)

- `PageCachePolicy::decide()` kennt keine User-Dimension; `PageIdentity` =
  `language + module + group + controller + action`. Admin und Gast teilen den
  Cache-Eintrag.
- Bei `PageFromCache` + Miss rendert der Dispatcher frisch und ruft
  `tryStore()` → `PageCache::set()` → `$response->getHtml()` — dasselbe HTML,
  das der Admin sieht (Overlay wird in `AbstractFrontendController::html()`
  vor dem Store injiziert).
- Bisher nie aufgefallen, weil es die Verkettung braucht: Produktion (kein
  DEBUG) + cachebare Seite + Cache leer/abgelaufen + Erstaufrufer ist Admin.

## Owner-Entscheide (2026-07-18)

| Frage | Entscheid |
|---|---|
| Wer verzichtet auf PageCache? | Nur Rolle >= ADMIN. GUEST + MEMBER cachen weiter |
| Warum MEMBER cachen darf | Frontend rendert für Members byte-identisch zur Gast-Version — einziges rollenabhängiges Markup ist das Admin-Overlay (>= ADMIN). Invariante wird als Rule dokumentiert |
| Wo sitzt der Check? | Früher Ausstieg in `PageCachePolicy::decide()` — die Policy bleibt single source of truth der Cache-Entscheidung |
| Verhalten für Admin | `NewPage` → frischer Render, `Cache-Control: no-store`, `X-Z77-PageCache: BYPASS` |

## Umsetzung

### `PageCachePolicy` (kernel/core/Routing)

- Konstruktor erhält `AuthService` (4. Abhängigkeit).
- `decide()`: nach dem DEBUG-Check früher Ausstieg
  `getCurrentUser()->hasAtLeast(AuthRole::ADMIN)` → `NewPage`.
- Funktioniert, weil die Session VOR der Cache-Entscheidung gestartet ist
  (`AccessGuard::enforce()` läuft in `Dispatcher::execute()` vor `decide()`).
  Ohne Session-Kontext liefert `AuthService` den GUEST-User → kein Bypass.

### `Bootstrap` (DI-Wiring)

- Factory `PageCachePolicy` ergänzt `$c->get('AuthService')`. Die Factory ist
  lazy; instanziert wird die Policy erst über die `Dispatcher`-Factory — zu
  diesem Zeitpunkt ist `AuthService` registriert (Session-Block nach Routing).

## Nebenwirkungen

- Admin bei 08:06-Szenario (eingeloggt, Browser hält altes ETag): Policy
  erreicht den ETag-Vergleich nicht mehr → frischer 200er mit Overlay statt 304
  der Gast-Version. `public, no-cache` der Altantwort erzwingt die Revalidierung.
- Eingeloggte Admins verlieren den PageCache-Vorteil — bei einer Handvoll
  Admins irrelevant.
- Zweite Absicherung für Partial-Labels: Marker-Renders (Admin-only) können
  strukturell nie mehr in den geteilten Cache — unabhängig vom DEBUG-Gate.

## Doku-Pflichten (im gleichen Zug)

1. `docs/topics/cache.md`: Skip-Tabelle + decision flow + BYPASS-Tabelle um
   «role >= ADMIN» ergänzen; neue Rule (Invariante: cachebare Seiten dürfen
   keinen session-abhängigen Inhalt für Rollen < ADMIN rendern); known issue
   **CACHE-ADMIN-001** mit Fix-Vermerk.
2. `docs/topics/backend.md` (Abschnitt Frontend-Admin-Overlay): Verweis auf
   CACHE-ADMIN-001 — Overlay ist jetzt cache-sicher.
3. `npm run docs:check` grün.

## Umsetzungs-Phasen

- [x] **P1 Fix:** `PageCachePolicy` + `Bootstrap`-Wiring
- [x] **P2 Verifikation:** CLI-Harness — `decide()`-Matrix (Gast/Member →
      unverändert cachebar; Admin + SuperUser → NewPage; DEBUG → NewPage wie
      bisher; 6/6 PASS)
- [x] **P3 Doku:** cache.md + backend.md, docs:check grün

## Bewusst NICHT

- Kein Bypass für MEMBER (Invariante dokumentiert statt Cache geopfert — die
  Regel wird erst verschärft, wenn je member-spezifisches Markup auf cachebare
  Seiten kommt).
- Keine per-User-Cache-Varianten (Cache-Key um Rolle erweitern) — YAGNI, Admins
  brauchen keinen Page-Cache.
