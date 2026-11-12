# Bauplan — Offenes Gateway für Geräte (App-Kanal) + erstes Ziel: mobiler Buchungsbeleg

**Status:** `[KONZEPT]` — Entwurf, noch nicht freigegeben; Teil B durch die Owner-Entscheide
2026-11-12 geändert (siehe «Owner-Entscheide 2026-11-12» am Ende)
**Date:** 2026-09-29 · Nachtrag 2026-11-12

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
5. ~~**Keine Freigabe** beim Beleg — sofort gebucht; im Backend nur ein Hinweis
   («Handy», «neu»).~~ **Ersetzt 2026-11-12:** der Beleg geht in ein **Belegjournal**
   und wird gesammelt verbucht — der Ablauf der Fakturierung (ADR-054), siehe E1.
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

## Owner-Entscheide 2026-11-12

Diskussion des Bauplans mit dem Owner; ersetzen, was oben in Teil B anders steht.

- **E0 — Das Gateway bleibt das Fundament.** Kein Zwischenweg über eine mobile
  Backend-Seite mit Session: der Beleg ist das erste Ziel des Geräte-Kanals (Teil A).
- **E1 — Belegjournal statt sofort buchen** (ersetzt Owner-Vorgabe 5). Gleicher Ablauf wie
  die Fakturierung ([ADR-054](../02-decisions/adr-054-faktura-journal.md)): das Handy
  erfasst in ein Belegjournal; Ende Monat überschauen, korrigieren und in einem Zug
  «Journal verbuchen». Keine Freigabe pro Beleg. Verbucht wird nur ein vollständiger Beleg
  (Konten und MWST gesetzt). Folge: `POST /api/v1/receipts` antwortet mit der
  Journal-Position, nicht mit einer Buchungsnummer; das Hauptbuch sieht nichts bis zum
  Verbuchen.
- **E2 — Was das Handy erfasst: Vorlagen, leerer Rückfall.** Kacheln («Tanken»,
  «Büromaterial», «Spesen Essen» …), jede Vorlage kennt Soll, Haben und MWST-Code — ein
  Tipp, im Journal vorbefüllt. Passt keine: nur Foto, Betrag, Text; Konten und MWST
  ergänzt der Owner im Journal. Keine freie Soll/Haben-Wahl auf dem Handy.
- **E3 — Belegvorlagen sind Stammdaten im Backend**, keine Config-Datei (Muster
  Auftragsstatus / Zahlungskonditionen): Name, Symbol, Soll, Haben, MWST-Code,
  Reihenfolge, aktiv. Pro Installation; jedes gekoppelte Gerät sieht alle aktiven.
  Eine benutzte Vorlage wird deaktiviert, nicht gelöscht. Kacheln pro Gerät / Person:
  später, über die Scopes des Gateways.
- **E4 — DMS-Ablage:** ein Ordner pro Geschäftsjahr, darin pro Monat (`Belege/2026/11`)
  — beantwortet offenen Entscheid 4.
- **E5 — Betrag ist Pflicht**, auch ohne Vorlage — beantwortet offenen Entscheid 5.
- **E6 — Datum** vorbelegt mit heute, änderbar (nachgetragener Beleg).
- **E7 — Offline erfassen:** ja, Warteschlange auf dem Gerät wie in A6 (kein Geheimnis,
  Eintrag nach Bestätigung gelöscht).
- **E8 — Wer hat erfasst:** jedes Gerät gehört einem Backend-Benutzer, der es im Backend
  koppelt; das Journal zeigt «Handy <Benutzer> · <Gerätename>».
- **E9 — KI/OCR** (Betrag, Datum aus dem Foto): später, immer nur Vorschlag — bestätigt
  offenen Entscheid 6.
- **E10 — «Bezahlt mit» statt festem Haben** (ändert E2/E3): eine Belegvorlage kennt nur
  Soll und MWST-Code. Beim Erfassen wählt das Handy zusätzlich die **Zahlungsart**
  (Kasse, Firmenkarte, privat ausgelegt …) — eigene kleine Stammdaten mit je einem
  Haben-Konto, Muster wie die Vorlagen. Vollständig = Soll, Haben und MWST gesetzt.
- **E11 — Verbuchen mit Stichtag:** «Journal verbuchen» nimmt einen Stichtag (vorbelegt:
  letzter Tag des Vormonats); nur Belege bis dahin. Ein unvollständiger Beleg bis zum
  Stichtag blockiert den ganzen Lauf (wie das Faktura-Journal).
- **E12 — Ein MWST-Code pro Beleg.** Ein seltener gemischter Beleg wird im Journal
  **aufgeteilt**: zwei Belege, dasselbe Foto, Beträge zusammen = Original.
- **E13 — Gerät sperren ist endgültig.** Wiederverwenden = neu koppeln; kein «aktiv»-Schalter.
- **E14 — Wer koppelt (bestätigt 2026-11-12):** jeder Backend-Benutzer ab EDITOR koppelt,
  benennt und sperrt seine eigenen Geräte; ADMIN und höher sieht und verwaltet alle (nie für
  einen Besitzer mit höheren Rollen) — wie gebaut (`api.md` «backend screen «Geräte»»).
  **Ersetzt durch [ADR-058](../02-decisions/adr-058-app-users-email-code-login.md) A9
  (2026-11-12):** es wird nicht mehr gekoppelt; nur ein ADMIN legt App-Benutzer an und verwaltet
  sie (nie für eine Person mit höheren Rollen).
- **E15 — Koppel-Code 10 Zeichen** (`ABCDE-FGHJK`, ADR-057) statt der 6 des Boards —
  bestätigt 2026-11-12; der QR-Code ist der Normalfall. **Hinfällig durch
  [ADR-058](../02-decisions/adr-058-app-users-email-code-login.md) A7 (2026-11-12):** Koppeln samt
  Code und QR ist entfernt; die Anmeldung läuft über einen 6-stelligen Code per E-Mail.
- **E16 — «Journal verbuchen» als Fenster, Stichtag vorgeschlagen = heute** (Owner 2026-11-12,
  nach dem ersten Test: «Ende Vormonat» liess das Fenster leer wirken; ändert den Vorschlag
  von E11, die Regel «nur bis zum Stichtag, ein unvollständiger blockiert» bleibt). Das
  Fenster zeigt bei Fehlern bis zum Datum NUR die Fehler (welcher Beleg, was fehlt, Link
  zum Beleg), sonst kurz was gebucht wird (Anzahl, Summe, MWST) und «Jetzt buchen»; das
  Datum bleibt änderbar. Das Faktura-Journal bekommt dasselbe Fenster (ohne Datum) — ein
  gemeinsamer Baustein (Regel 9; löst DEBTOR-WIN-002).
  *Gebaut 2026-11-12:* `Z77\Shared` `partials/postConfirm` + core.js `form[data-window-form]`
  (`fetch.md` WIN-GETFORM-001); `defaultCutOff()` = heute. Von DEBTOR-WIN-002 ist damit der
  core.js-Teil gelöst; «Fakturieren …» als Fenster bleibt offen (`debtor.md`).
- **Boards abgenommen 2026-11-12:** die vier Boards «Belege per Handy» auf dem
  Developer-Canvas (Belegvorlagen, Belegjournal, Geräte, z77 App) — «passt soweit alles».
  Mit E10 kommt «Bezahlt mit» dazu (Stammdaten-Liste wie Belegvorlagen; Auswahl auf dem
  Erfassen-Bildschirm des Handys; im Belegjournal statt der festen Haben-Spalte).

Noch offen (technisch): Entscheide 1–3 und 7 oben; Ort und Form des Belegjournals —
Vorschläge im nächsten Abschnitt, vom Owner freigegeben 2026-11-12.

## Technische Vorschläge 2026-11-12 (zur Freigabe)

> **Approved by the owner 2026-11-12.** T1, T3, T5 and the security rules of A5 are binding
> as [ADR-057 — The device channel of the API gateway](../02-decisions/adr-057-api-device-channel.md);
> the gateway part (Umsetzungsschritte 2 and the model/pairing/guard of 3) is built, see
> [`topics/api.md`](../topics/api.md).
>
> T2 and T4 (wave 1, part B) are built as `packages/module-receipt` — templates, payment methods,
> the receipt journal, capture without a session, correction, split, «Journal verbuchen» up to a
> cut-off (E1–E12), no screens and no API target yet; see [`topics/receipt.md`](../topics/receipt.md).
>
> Wave 2, part E (2026-11-12): the receipt targets `receipt-templates`, `payment-methods` (GET) and
> `receipts` (POST) and the app shell `/app` (T6; generic shell in module-api, the receipt screen
> contributed by module-receipt) are built — [ADR-057 addendum](../02-decisions/adr-057-api-device-channel.md),
> [`topics/api.md`](../topics/api.md) «app shell». B4's `accounts` / `vat-codes` are replaced by
> templates and payment methods (E2, E10). Open: the live test on iPhone and Android.

Grundlage: Code-Stand `edce35b`. Korrektur zu A3 Zeile 7: das Framework setzt bereits eine
CSP (`SecurityHeaders::DEFAULTS`: `frame-ancestors 'self'` erzwungen, `default-src 'self'`
… als Report-Only, nur für HTML) — für die App-Seiten wird sie **erzwungen**, nicht neu
erfunden.

- **T1 — Gerät im Ziel (offener Entscheid 1) erledigt sich weitgehend durch E1.** Das Handy
  bucht nicht mehr, es erfasst ins Belegjournal; `LedgerService` (Actor) läuft erst beim
  «Journal verbuchen» im Backend, mit der Session des Owners. Das Ziel braucht nur noch den
  Geräte-Principal als **Daten**: `ApiPrincipal` erhält optional `deviceId`, `ownerId`,
  `ownerName`, `scopes` (additiv; der Maschinen-Kanal bleibt `tenantId`/`keyRef`), und
  `ApiRequest` erhält `principal`, `method`, `body`, `files`, `idempotencyKey`. Kein
  «run as user», kein AuthService auf dem stateless Pfad.
- **T2 — Foto ablegen ohne Session:** `DocumentService::forModule('receipt')` →
  `ModuleDrive::folder('Belege', '2026', '11')` → `saveGenerated()` (der vertrauenswürdige
  Systempfad des DMS). Weil dieser Pfad nicht sniffst: das Ziel prüft selbst —
  `UploadedFile::sniffMime()`, nur `image/jpeg`, `image/png`, `image/heic`?, `application/pdf`,
  Grössenlimit. (HEIC: offen — iPhone liefert es; das Handy rechnet vor dem Upload auf JPEG
  um, B3, dann entfällt HEIC.)
- **T3 — Geräteverwaltung (offener Entscheid 2) in `module-api`.** Das Gateway besitzt die
  Credentials; ein Gerät ist ein zweites Credential neben dem Bearer-Key. Muster von
  `module-member` `DeviceKeys` (sha256, `hash_equals`, rollende Gültigkeit, Kappung,
  UA-Label), aber nicht die Klasse (sie hängt an `MemberAccount`). Cookie
  `z77_api_device`: HttpOnly, Secure, **SameSite=Strict, Pfad `/api`**. Speicher: Datei
  `framework/api/devices.json` wie die Backend-Benutzer (Geräte pro Installation: wenige);
  `last_used_at` höchstens einmal pro Stunde geschrieben. Guard: `ApiKeyGuard` wird eine
  Kette — Bearer → Tenant-Resolver, sonst Geräte-Cookie → Geräte-Resolver, sonst 401.
  Backend-Maske «Geräte» (koppeln per QR/Code, Scopes, sperren) unter System.
- **T4 — Beleg-Ziel (offener Entscheid 3): eigenes Modul `module-receipt`.** Es braucht
  financial (Konten, MWST, Buchen), dms (Foto) und api (Ziel); `module-financial` hängt
  heute weder an dms noch an api und soll es nicht müssen. Inhalt: Entitäten
  `ReceiptTemplate` (Belegvorlagen, E3) und `Receipt` (Belegjournal: Datum, Text, Betrag,
  Vorlage?, Soll?, Haben?, MWST-Code?, `document_id`, `device_id`, erfasst von, Zustand
  `captured` → `posted`, `journal_entry_id` nach dem Verbuchen); Ziel-Services
  `receipt-templates` (GET), `receipts` (POST); Backend: Stammdaten «Belegvorlagen»,
  Maske «Belegjournal» (Liste, bearbeiten, «Journal verbuchen» → je Beleg ein
  `PostingRequest::generated(…, sourceType 'receipt', sourceRef <id>, Idempotenz-Schlüssel
  'receipt:<id>')`, eine Unit of Work, alles oder nichts — das Muster von ADR-054, nicht
  dessen Klassen, die sind rechnungsspezifisch). Die Verknüpfung Beleg ↔ Buchung liegt am
  `Receipt` (`document_id` + `journal_entry_id`) — **`JournalEntry` bleibt unverändert**
  (ersetzt B5).
- **T5 — Envelope-Doku (offener Entscheid 7):** additiv ein Abschnitt «Geräte-Kanal»:
  Cookie nur auf `/api`, Pflicht-Header `X-Z77-App: 1`, `Origin`-Prüfung, `Idempotency-Key`
  bei POST; v1 bleibt gültig.
- **T6 — App-Hülle:** HTML unter `/app` (Manifest mit `start_url`/`scope` `/app/`, Service
  Worker nur für `/app/`, erzwungene CSP `default-src 'self'`), Kacheln aus
  `receipt-templates`. Kopplung: QR im Backend → `/app/pair?code=…` → `POST
  /api/v1/devices/pair` setzt das Cookie.

**Aufwand (grob):** Gateway-Erweiterung (POST, Body, Dateien, Scopes, Idempotenz, CSRF)
3–4 Tage · Geräte (Modell, Kopplung, Maske) 3 Tage · `module-receipt` (Vorlagen, Journal,
Verbuchen, Ziele) 4–5 Tage · App-Hülle + Offline-Warteschlange 3 Tage · Doku, Tests,
Live-Test iPhone/Android 2 Tage — **rund 3 Wochen**.

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
