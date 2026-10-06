# Bauplan — Offenes Gateway für Geräte (App-Kanal) + erstes Ziel: mobiler Buchungsbeleg

**Status:** `[KONZEPT]` — Entwurf, noch nicht freigegeben
**Date:** 2026-09-29

Zwei Teile:

- **Teil A — Gateway (generisch):** Ein offener Service, der Sicherheit gewährt, Zugriff
  prüft, erlaubt und an ein Ziel durchreicht. Die Mechanik wird einmal gebaut; jede
  Funktion (Buchungsbeleg, Textinhalte ändern, Mail versenden, …) ist nur ein Ziel.
- **Teil B — Buchungsbeleg (erstes Ziel):** Beleg mit dem Handy fotografieren, erfassen
  und direkt buchen — als PWA, ohne App Store / Play Store.

Owner-Vorgaben 2026-09-29:

1. **Offen programmierbar.** Ein Gateway für beliebige Ziele; Finance ist das erste.
2. **Das bestehende Gateway erweitern**, kein zweites bauen (`module-api`).
3. **Kein Store.** Client ist eine PWA auf iPhone und Android.
4. **Anmeldung einmalig** (Geräte-Kopplung), danach ohne Login; pro Gerät revozierbar.
5. **Keine Freigabe** beim Beleg — sofort gebucht; im Backend nur ein Hinweis
   («Handy», «neu»).
6. **Routing statt Subdomain** — alles auf der Domain der Installation.
7. **Regel 7 neu:** JS dort, wo es Sinn macht; Logik, Prüfung und Berechtigung auf dem
   Server; JS nie eine Angriffsfläche ([`conventions.md` → JavaScript](../01-handbook/conventions.md)).
8. **Alles gehört ins Framework** — Mechanik, Konzept, Doku.

---

# Teil A — Gateway

## A1. Ablauf

```text
Request
  │
  1  Form prüfen        Grösse, Format, Rate-Limit, Herkunft (Origin/CSRF-Schutz)
  2  Wer fragt?         Credential → Principal (Bearer-Key ODER Geräte-Cookie)
  │                     unbekannt/gesperrt → 401, sofort, ohne weitere Auskunft
  3  Ziel + Recht       Ziel registriert? Scope des Ziels ⊂ Scopes des Principals?
  │                     nein → 404 / 403 (Envelope v1)
  4  Durchreichen       Ziel-Service erhält geprüften Principal + Request
  5  Ziel prüft Inhalt  fachlich (bei Finance: OneLineEntryForm, LedgerService) und führt aus
  6  Ziel antwortet     ApiResult (Payload oder typisierter Fehler) — nie direkt an den Client
  7  Antwort prüfen     Envelope, Header, keine internen Details nach aussen; Log-Zeile
  │
Response
```

**Grundsätze:**

- **Erst «wer», dann «was».** Ohne gültiges Credential gibt es 401, bevor irgendein Ziel
  angeschaut wird — ein Unbekannter erfährt nicht, welche Ziele existieren.
- **Gateway prüft die Form, das Ziel den Inhalt.** Das Gateway kennt keine Fachregeln;
  die Fachlogik existiert nur einmal, im Ziel-Service.
- **Die Antwort geht immer durch das Gateway** (`ApiResponder`): gleicher Envelope, gleiche
  Header, interne Fehler nur ins Log (500 ohne Details).
- **Jeder Zugriff wird protokolliert** (`logs/api.log`: Principal, Gerät, Ziel, Status,
  Dauer).

## A2. Was es schon gibt

`module-api` bildet den Ablauf bereits ab ([`topics/api.md`](../topics/api.md)):
`GatewayController` → `ApiKeyGuard` → `ServiceRegistry` → `ApiServiceInterface::handle()`
→ `ApiResult` → `ApiResponder`, dazu Rate-Limit (`FileThrottle`), Log (`ApiLog`),
Envelope v1 ([`api-envelope-v1-2026-09-02.md`](api-envelope-v1-2026-09-02.md)).

## A3. Was fehlt (Erweiterung, additiv zu v1)

| # | Erweiterung | Heute |
|---|---|---|
| 1 | **Zweites Credential: Geräte-Cookie** (HttpOnly) neben dem Bearer-Key; beide ergeben einen Principal | nur Bearer-Key → Tenant |
| 2 | **Scopes:** Principal trägt Scopes; jedes Ziel deklariert seinen Scope pro Methode (z.B. `receipt.capture`, `content.edit`, `mail.send`) | Tenant/Endpoint-Freigabe |
| 3 | **POST** mit JSON-Body und Datei-Upload; `ApiRequest` erhält Methode, Body, Dateien | nur GET/HEAD (`GatewayController.php:80`), `ApiRequest` ohne Body |
| 4 | **Idempotenz-Schlüssel** für schreibende Requests (Header), damit ein Wiederholungsversand nicht doppelt ausführt | — |
| 5 | **CSRF-Schutz für den Cookie-Pfad** (siehe A5) | nicht nötig (Bearer) |
| 6 | **Geräte-Verwaltung:** Kopplung per Code/QR aus dem Backend, Geräteliste, Scopes pro Gerät, Sperren, `last_used_at` | — |
| 7 | **CSP** für die App-Seiten | Framework setzt keine CSP |

Regel aus `topics/api.md` bleibt: v1 wird nur ergänzt; Änderungen bestehender Felder,
Status- oder Fehlercodes → v2.

## A4. Zwei Kanäle, dieselben Ziele

| Kanal | Wer | Credential | Beispiel |
|---|---|---|---|
| Maschine | Server ↔ Server | Bearer-Key (Tenant, `keyRef`) | AXO3-Units |
| Gerät | Mensch mit Handy/PC | HttpOnly-Geräte-Cookie (Gerät, Besitzer, Scopes) | Buchungsbeleg |

Beide landen im selben Gateway und bei denselben Ziel-Services.

**Warum kein Bearer-Key im Browser:** Ein Key in `localStorage`/IndexedDB ist für jedes
Script lesbar → verletzt Regel 7. Das Geräte-Cookie ist HttpOnly; der Browser sendet es,
JS sieht es nie.

## A5. Sicherheit des Geräte-Kanals

- **Geräte-Cookie:** 32 Byte Zufall, HttpOnly, Secure, SameSite=Strict, Pfad `/api`;
  Server speichert nur SHA-256, Vergleich `hash_equals`. Vorbild:
  `module-member` `DeviceCookie` / `DeviceKeys`.
- **CSRF:** Der API-Pfad ist stateless (keine Session, kein Session-Token). Schutz über
  SameSite=Strict + Pflicht-Header (z.B. `X-Z77-App: 1`, den ein fremdes Formular nicht
  setzen kann) + `Origin`-Prüfung gegen die eigene Domain.
- **Kopplung:** Code einmalig, kurzlebig (~10 min), gehasht, Rate-Limit auf das Einlösen.
- **Schmal berechtigen:** Ein Gerät erhält nur die Scopes, die es braucht — auch wenn der
  Besitzer Admin ist. Kein Scope gibt Zugriff auf das Backend.
- **Gerät verloren:** im Backend sperren → nächster Request 401.
- **Kritische Ziele** (Empfehlung):
  - `mail.send`: nur Vorlagen, bekannte Empfänger, striktes Rate-Limit, jede Mail im Log
    — sonst wird ein gestohlenes Gerät zur Spam-Schleuder.
  - `content.edit`: Versionierung/Rückgängig, HTML serverseitig bereinigen — sonst
    XSS-Einfallstor auf der Website.

## A6. Client (PWA-Hülle)

- Eine App (`z77 App`) pro Installation: Manifest, Service Worker, Startseite mit Kacheln —
  nur die Ziele, für die das Gerät Scopes hat.
- Eine JS-Bibliothek `_Z77.app`: Versand an das Gateway, Pflicht-Header, Idempotenz-UUID,
  Offline-Warteschlange (IndexedDB, **kein Geheimnis**, Eintrag nach Bestätigung gelöscht),
  Anzeige der Fehler aus dem Envelope.
- Ohne JS: jede Kachel bleibt ein normales Formular, soweit der Ziel-Flow es zulässt.

---

# Teil B — Buchungsbeleg (erstes Ziel)

## B1. Ablauf für den Nutzer

App öffnen → «Beleg» → Foto → Datum, Text, Betrag, Soll, Haben, MwSt → senden →
«Buchung 2026/17 erfasst».

## B2. Warum PWA reicht

| Bedarf | PWA | Bemerkung |
|---|---|---|
| Kamera | ja | `<input type="file" accept="image/*" capture="environment">` |
| Installierbar, Icon, Vollbild | ja | Manifest + Service Worker |
| Offline erfassen, später senden | ja | Warteschlange (A6) |
| Dauerhafte Anmeldung | ja | HttpOnly-Cookie; auf iOS als installierte App (Home-Bildschirm) verlässlich |

## B3. Aufgabenteilung

| Teil | Wo | Was |
|---|---|---|
| Kamera, Vorschau | JS | aufnehmen, anzeigen, neu aufnehmen |
| Bildaufbereitung | JS | vor dem Upload auf ~1600 px, JPEG verkleinern |
| Kontenwahl, MwSt | JS (Hinweis) | Suche im Kontenplan, MwSt-Code vorschlagen |
| Prüfung, Buchung | Server (Ziel) | `OneLineEntryForm` → `PostingRequest` → `LedgerService::post()` mit Idempotenz-Schlüssel |
| Beleg ablegen | Server (Ziel) | `module-dms` `UploadService` (MIME-Sniffing, Allowlist, Grösse) |
| Hinweis im Backend | Server | Journal: «Handy», «neu» bis zum ersten Öffnen |

## B4. Ziele (Endpunkte)

| Methode | Endpunkt | Scope | Zweck |
|---|---|---|---|
| GET | `/api/v1/accounts` | `receipt.capture` | Kontenplan (postbar, aktiv), ETag |
| GET | `/api/v1/vat-codes` | `receipt.capture` | MwSt-Codes, ETag |
| POST | `/api/v1/receipts` | `receipt.capture` | Bild + Felder → buchen + ablegen |

Antwort auf POST: Journalnummer oder die deutschen Feldfehler des `OneLineEntryForm`.

## B5. Datenmodell (neu)

- **Verknüpfung Beleg ↔ Buchung:** `JournalEntry` → DMS-`Document` (1:n). `module-financial`
  kennt heute keine Anhänge.
- **Herkunft:** `source = mobile`, `device_id`, Status «neu» bis zum ersten Öffnen.
- Migration über `persistence-doctrine` (ADR-039).

---

## Offene Entscheide

1. **Principal im Ziel:** `UploadService` verlangt `authz->require('folder', …, 'write')`
   mit Session-Principal, `LedgerService` einen `actor`. Wie erhalten die Ziel-Services auf
   dem stateless Pfad den Geräte-Principal (Besitzer + Scopes)?
2. **Ort der Geräte-Verwaltung:** Kernel (`shared/Auth`) oder `module-api`?
   Wiederverwendung von `module-member` `DeviceKeys` prüfen.
3. **Ort des Beleg-Ziels:** `module-financial` (Service + Backend-Hinweis) oder eigenes
   Modul?
4. **DMS-Ablage:** Belegordner pro Geschäftsjahr oder pro Monat?
5. **Betrag:** Pflichtfeld (Annahme: ja).
6. **Später:** OCR/KI-Vorbefüllung aus dem Foto — nur als Vorschlag, der Server prüft wie
   bei Handeingabe.
7. **Envelope-Doku:** Abschnitt «Base» sagt «no cookie» — für den Geräte-Kanal additiv
   ergänzen.

## Umsetzungsschritte (Vorschlag)

1. Offene Entscheide klären, Bauplan freigeben; ADR für den Geräte-Kanal (zweites
   Credential im Gateway).
2. Gateway: `ApiRequest` (Methode, Body, Dateien), POST, Scopes, Idempotenz, CSRF-Schutz.
3. Geräte-Verwaltung: Datenmodell, Kopplung, Backend-Maske, Guard für das Geräte-Cookie.
4. CSP für die App-Seiten.
5. Ziel Buchungsbeleg: Services `accounts`, `vat-codes`, `receipts`; Verknüpfung
   Beleg ↔ Buchung; Hinweis im Journal.
6. PWA-Hülle + `_Z77.app` + Kachel «Beleg».
7. Doku: `topics/api.md`, Envelope-Doku, `topics/financial.md`; Tests; Live-Test iPhone +
   Android.
