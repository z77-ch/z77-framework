# Bauplan — Geo-Guard für öffentliche Formulare

**Status:** `[UMGESETZT]` — freigegeben und umgesetzt 2026-08-28; Abnahme: `tests/form-geo-guard.php` 38/38, `tests/country-blocklist.php` 33/33, `docs:check` grün
**Date:** 2026-08-28

Ziel: Missbrauchsabwehr nach Herkunftsland wird vom Registrierungsformular gelöst und
zu einer **Fähigkeit des `PublicFormHandler`** — jedes öffentliche Formular schaltet sie
per Opt-in ein, protokolliert dadurch, und alle Protokolle laufen in **einer** zentralen
Backend-Übersicht zusammen. Sperrliste und Protokoll liegen im Kernel, nicht im
Member-Modul.

Anlass: Im Vorgängerprojekt (spirit-studio, wdv-6.2.2) kamen tausende Versuche aus
Russland, China und Südamerika auf mehrere Formulare gleichzeitig — Kontakt, Login,
Registrierung, Fragebogen. Die aktuell im Arbeitsverzeichnis liegende Lösung
([`country-blocklist-review-2026-08-28.md`](country-blocklist-review-2026-08-28.md))
löst das nur für das Registrierungsformular.

## Ausgangslage

Uncommitted auf `feat/public-form-standard`, funktionsfähig und getestet
(`tests/country-blocklist.php`, 33/33) — aber an der falschen Stelle:

| Was | Wo heute | Problem |
|---|---|---|
| Länder-Gate | `RegistrationFlow::register():247` | nur ein Formular |
| Protokoll | `RegistrationLog`, `FORM_REGISTER` fest verdrahtet | nur ein Formular; `FORM_LOGIN`/`FORM_INVITE` sind tote Konstanten |
| Sperrliste | `MemberBlockedCountry`, `data/framework/member/` | Member-Modul besitzt Daten, die alle Formulare brauchen |
| Oberfläche | `RegistrationLogControllerTrait` in `module-member`, per ADR-018 im Backend gemountet | Umweg, der nur nötig ist, solange die Daten im Member-Modul liegen |
| Sweep | in `MemberCleanupJob` | Member-Job räumt fremde Daten |

Bereits vorhanden und **unverändert übernehmbar**: `CountryLookup` (Kernel-Seam,
wirft nie, unbekanntes Land = null), `GeoIpUpdateJob`, die drei Lizenzpflichten der
GeoLite-EULA, `FormGuard` (Honeypot, Zeitfalle, Drossel), die `withObserver()`-Naht.

## Entscheide (2026-08-28, Owner)

1. Das Gate gehört in den `PublicFormHandler`, nicht in einen Flow.
2. Protokoll und Sperrliste werden **zentral** verwaltet — Kernel, nicht Member.
3. Die Sperre gilt **global**: ein Land ist gesperrt oder nicht. Welche Formulare die
   Regel anwenden, sagt das Opt-in. Keine Formular×Land-Matrix.
4. Wer den Guard einschaltet, wird dadurch auch protokolliert — ein Schalter, nicht zwei.
5. Protokolliert werden **standardmässig nur technische Fakten**. Ein identifizierendes
   Feld nur, wenn die `FormDefinition` es ausdrücklich deklariert (Datensparsamkeit als
   Grundzustand).
6. Formular-Schlüssel im Protokoll ist `FormDefinition::guardKey()` — existiert, ist
   eindeutig, und der Drossel-Zähler hängt bereits daran.
7. Keine Request-Ebene. Geo-Blocking der ganzen Site gehört vor PHP (nginx/CDN) — dort
   kostet es nichts und stoppt die Last wirklich. Hier geht es um Formularmissbrauch.

## Architektur

```
Z77\Shared\Forms\
    PublicFormHandler      + withGeoGuard()   Gate + Protokollschreiben
    FormDefinition         + identityField()  Opt-in fürs identifizierende Feld
    FormLog                                   ersetzt RegistrationLog
    CountryBlocklist                          aus module-member, Namespace neu
    FormLogSweepJob                           aus MemberCleanupJob herausgelöst
Z77\Shared\GeoIp\
    CountryLookup                             unverändert (wie MmdbReader, GeoIpUpdateJob)
Z77\Shared\Entities\
    BlockedCountry                            aus MemberBlockedCountry

Z77\Module\Backend\Ui\Controllers\Service\
    FormLogController                         besitzt Logik UND Templates
```

`CountryBlocklist` und `FormLogSweepJob` liegen bewusst in `Forms`, nicht in `GeoIp`:
`geoip.md` trennt «the lookup is a fact, the country RULE is a policy» — `GeoIp` bleibt
die Infrastruktur (IP → Land), die Sperrliste ist Formular-Policy (Daten unter
`framework/forms/`, Konsument ist der Handler, Oberfläche der `FormLogController`),
und der Sweep räumt das Formular-Protokoll. Der Namespace sagt, wem es gehört.

**Der ADR-018-Mount entfällt.** Sobald nichts mehr aus `module-member` kommt, braucht
der Backend-Controller weder Trait noch `RegistrationLogLayout` noch die
Layout-Config-Delegation. `registrationLogControllerConfig.inc.php` und
`RegistrationLogLayout.php` fallen ersatzlos weg — der Controller hält seine Templates
selbst, wie `BackupController` und `JobController`.

**Ablage:**

| | alt | neu |
|---|---|---|
| Protokoll | `logs/registration-YYYY-MM.jsonl` | `logs/form-YYYY-MM.jsonl` |
| Sperrliste | `data/framework/member/blocked-countries.json` | `data/framework/forms/blocked-countries.json` |

Die Sperrliste kommt bewusst **nicht** nach `data/framework/geoip/` — dort liegt die
MaxMind-Datenbank, die laut EULA weder weitergegeben noch mitgesichert werden darf.
Betreiberdaten und lizenzgebundene Fremddaten teilen sich kein Verzeichnis.

## Voraussetzung: der Länder-Datenbestand ist Handarbeit

`withGeoGuard()` ist ein Ein-Zeilen-Opt-in — **die Länderdaten kommen damit nicht mit.**
Die GeoLite-Datenbank gehört der Installation, nie dem Repository (Lizenz), und ihre
Beschaffung ist in jeder Installation eine manuelle Pflicht des Entwicklers:

1. MaxMind-Konto anlegen und einen GeoLite-Lizenzschlüssel erzeugen (kostenlos, aber
   ein Konto — das macht niemand automatisch).
2. Schlüssel in `config/geoip.inc.php` eintragen — die Datei ist **maschinenlokal,
   gitignored und wird nicht deployt**: sie muss auf jedem Server von Hand entstehen.
3. `geoip-update`-Job registriert und aktiv — er holt auch den **Erstdownload** und
   erfüllt die EULA-Pflicht «aktuell halten» (Registrierung: siehe S5).

Wer den Guard aktiviert, ohne das zu tun, bekommt keinen Fehler: alle Länder lesen sich
als unbekannt, unbekannt blockt nie (Fail-Open), das Protokoll zeigt `??` — die Regel
ist still wirkungslos. Sichtbar wird das nur auf der Backend-Seite («Kein
Länder-Datenbestand installiert»), die ein Entwickler beim Aktivieren im Code nie sieht.
Deshalb gehört der Hinweis an **beide** Stellen, an denen aktiviert wird: in den
Docblock von `withGeoGuard()` (S2) und als Checkliste in `forms.md` (S6). Details zu
Konto, Schlüssel, Job und den drei EULA-Pflichten bleiben in `geoip.md`.

## Ablauf

**Kaskade im `PublicFormHandler::process()`** — das Gate kommt nach der Bot-Falle und
vor der Validierung:

```
CSRF ungültig            → OUTCOME_CSRF
Honeypot / zu schnell    → OUTCOME_BOT       (Erfolg vortäuschen)
▶ Land gesperrt          → OUTCOME_GEO       ← NEU
Validierung scheitert    → OUTCOME_INVALID
Drossel erreicht         → OUTCOME_LIMITED
dispatch()               → OUTCOME_SENT | OUTCOME_FAILED
```

Begründung der Position: **nach** der Bot-Falle, weil die kostenlos ist (String-Vergleich)
und ein Skript keinen mmdb-Lookup wert ist. **Vor** der Validierung und der Drossel, weil
das Gate eine flache Abweisung ist und keinen Drosselplatz verbrauchen soll — dieselbe
Begründung, die heute in `RegistrationFlow::register()` steht.

**Sichtbarkeit** spiegelt `withRateLimit()`: standardmässig sichtbar (Versand-Fehler
im Formular), weil eine Länderregel einen echten Kunden treffen kann und der es merken
muss. `withGeoGuard(silent: true)` für Formulare, deren Seite ununterscheidbar bleiben
muss (Member-Login, MEM-005) — dann verhält sich der Treffer wie der Bot-Pfad.

**Protokoll** schreibt der Handler selbst, sobald der Guard an ist — nach jedem Submit,
mit jedem Ausgang. `withObserver()` bleibt bestehen, hat aber keinen Log-Auftrag mehr;
der Observer in `RegisterController` entfällt.

**IP und Lookup — einmal pro Submit.** Der Handler liest die IP an einer Stelle und
schlägt das Land **einmal** nach; Gate und Protokollzeile verwenden dasselbe Ergebnis
(F4-Fix). ⚠️ Der Lookup hängt am **Guard**, nicht an der Sperrliste: auch mit leerer
Liste braucht die Zeile das Land — sie ist die Evidenz, aus der überhaupt erst gesperrt
wird (der Normalfall beginnt mit leerer Liste). Die leere Liste schaltet nur den
**Vergleich** kurz, nicht den Lookup; Guard aus heisst: kein Lookup, keine Zeile.

**Zeile:**

```json
{"at":"…","form":"register","outcome":"failed","detail":"throttled","ip":"…","country":"RU","ua":"…","identity":"a@b.ch","origin":"angebot-x"}
```

- `identity` nur, wenn `FormDefinition::identityField()` ein Feld nennt. Default `null` →
  das Feld fehlt in der Zeile. `RegisterFormDefinition` überschreibt mit `'email'`, ein
  Fragebogen deklariert nichts.
- `detail` kommt weiter über `FormLog::note()` aus dem Flow (`new`/`known`/`throttled`/
  `throttled-ip`) — der Ordnungsvertrag (Flow läuft im dispatch, die Zeile entsteht
  danach) bleibt wie heute. `blocked-country` als Detail entfällt: das ist neu der
  Ausgang `geo`.
- `origin` (und künftige formularspezifische Fakten) kommen als `extra`-Map am Schalter
  mit: `withGeoGuard(extra: ['origin' => $origin])` — der Aufrufer kennt sie beim Bauen
  des Handlers, sie reiten auf **jeder** Zeile dieses Handlers (heute leistet das der
  `$extra`-Parameter des Observer-Writes). Technische Fakten, kein Identifizierendes —
  dafür ist `identityField()` da (Entscheid 5).

**Backend** — Service → **Formular-Protokoll** (`/backend/service/form-log/list`):

```
├─ Filter: Formular (guardKey) · alle | einzeln
├─ Auszählung «Woher»      Land → Anzahl   [Land sperren]
├─ Auszählung «Was daraus wurde»
├─ Gesperrte Länder        Code · Grund · wann · durch wen   [Aufheben]
└─ Protokoll               Zeit · Formular · Land · Herkunft · Ausgang · Identität
```

Der vorausgefüllte Sperrgrund bleibt das Herzstück: er nennt die Zahlen, die die Sperre
begründen, und benennt neu das Fenster, über das gezählt wurde (F3 aus dem Review).

## Umsetzung

### S1 — Kernel-Bausteine anlegen

- `Z77\Shared\Entities\BlockedCountry` — aus `MemberBlockedCountry`, `#[Entity('file',
  'framework/forms/blocked-countries.json')]`. Bleibt bewusst **ausserhalb**
  `importEntities`: eine Sperrliste ist installationsspezifische Evidenz, das Verschleppen
  in ein anderes Projekt wäre genau die Vermutung, die dieser Aufbau ablehnt.
- `Z77\Shared\Forms\CountryBlocklist` — aus `Module\Member\Services\CountryBlocklist`,
  unverändert bis auf Namespace und Entity-Klasse.
- `Z77\Shared\Forms\FormLog` — aus `RegistrationLog`. Änderungen: `write()` nimmt den
  `guardKey` statt einer `FORM_*`-Konstante, die drei Konstanten fallen weg, `identity`
  ersetzt `email`, Dateiname `form-YYYY-MM.jsonl`. **Neu bekommt `write()` auch `ip`
  und `country` als Parameter** — die eigene `clientIp()`-Lesung und der eigene Lookup
  entfallen, sonst blieben es zwei unabhängige Lesungen und F4 wäre nicht behoben
  (die `inet_pton`-Validierung zieht mit in den Handler). `note()`/`sweep()`/`recent()`
  bleiben; `sweep()`/`recent()` globben neu `form-*.jsonl`.
- `tests/country-blocklist.php` auf die neuen Namespaces ziehen; Prüfungen unverändert.

### S2 — Gate in den Handler

- `FormDefinition::identityField(): ?string` — default `null`, Docblock erklärt die
  Datensparsamkeit. Reiht sich neben `replyToField()` / `routeField()`.
- `PublicFormHandler::withGeoGuard(bool $silent = false, array $extra = []): self` —
  der Docblock nennt **beides**: die Datenbestand-Voraussetzung (MaxMind-Konto,
  Schlüssel, `geoip-update`-Job — Verweis auf `geoip.md`; ohne Datenbank ist die Regel
  still wirkungslos) und die `extra`-Semantik (technische Fakten auf jeder Zeile).
- `PublicFormHandler::OUTCOME_GEO = 'geo'`
- Gate in die Kaskade an der oben beschriebenen Stelle; die leere Sperrliste schaltet
  den **Vergleich** kurz — der Lookup läuft trotzdem, das Protokoll braucht das Land
  (siehe Ablauf). Guard aus = heutiges Verhalten, kein Lookup.
- Protokollschreiben am Ende jedes Submit-Pfads, wenn der Guard an ist.
- IP-Lesung an **einer** Stelle im Handler, einmal nachschlagen, an Gate und Protokoll
  weiterreichen — das behebt F4 aus dem Review (heute zwei unabhängige `REMOTE_ADDR`-
  Lesungen und zwei Lookups pro Submit).
- Handler-Docblock nachziehen: «returns state and does nothing else … its only
  cross-request effect is the FormGuard session state» stimmt mit Guard nicht mehr —
  die Protokollzeile ist ein zweiter, dokumentierter Cross-Request-Effekt. `FormLog`
  wirft weiterhin nie (eine Logzeile kostet nie eine Registrierung).

### S3 — Member-Modul zurückbauen

- `RegistrationFlow::blockedCountries()` / `blockedByCountry()` und der Gate-Aufruf in
  `register()` entfallen — der Handler macht es.
- `RegistrationLog` löschen; die `note()`-Aufrufe im Flow (`new`/`known`/`throttled`/
  `throttled-ip`) zeigen neu auf `FormLog::note()`.
- `RegisterController`: Observer entfernen,
  `->withGeoGuard(extra: ['origin' => $origin])` setzen.
- `RegisterFormDefinition::identityField()` → `'email'`.
- `LoginFormDefinition`: `->withGeoGuard(silent: true)` — Login-Seite muss
  ununterscheidbar bleiben. **Das schliesst zugleich die Lücke aus F2 des Reviews:**
  Login-Links aus gesperrten Ländern laufen heute durch.
- `MemberCleanupJob`: `RegistrationLog::sweep()` raus, Ergebnistext anpassen.
- `memberConfig`: der `geoip-update`-Eintrag zieht nach `backendConfig` um (S5) und
  fällt hier weg — der Kommentar im Config sagt es selbst voraus («a future consumer
  without this module registers the same class from its own config»); der Konsument
  ist jetzt die Kernel-Form-Schicht, nicht mehr das Member-Modul. ⚠️ Beides im
  **selben Commit**: ein Job-Key, den zwei Module deklarieren, ist fail-fast eine
  `RuntimeException` (`ModuleManager::getJobs()`), und ohne jeden Eintrag bietet
  niemand den Job an — es gibt keinen gültigen Zwischenstand.
- `MemberBlockedCountry`, `CountryBlocklist`, `RegistrationLogControllerTrait`,
  `RegistrationLogLayout` und die drei Templates löschen (Inhalt lebt in S1/S4 weiter).

### S4 — Backend-Oberfläche

- `Z77\Module\Backend\Ui\Controllers\Service\FormLogController` — aus dem Trait, ohne
  Mount-Umweg. Aktionen: `list`, `confirm-block`, `block`, `confirm-unblock`, `unblock`.
- Templates nach `module-backend/res/view/templates/Service/FormLogController/`.
- **F1 — sichtbarer Fehlerzustand.** `listAction()` fängt beim Lesen der Sperrliste,
  rendert den Abschnitt als *«Sperrliste unlesbar — die Regel ist derzeit AUS»* und
  schreibt `error_log()`. Rest der Seite (Protokoll, Auszählungen) rendert weiter.
  Schreibseite (`block()`/`unblock()`) wirft weiter — ein fehlgeschlagenes Speichern
  muss sichtbar sein. Ohne das schaltet eine kaputte Datei die Regel still ab **und**
  tötet die einzige Seite, die es zeigen würde (verifiziert, siehe Review).
- **F3 — Fenster benennen.** Der vorausgefüllte Grund schreibt neu «… in den letzten
  N Protokollzeilen …» statt einer nackten Zahl. Kein Filter über ein breiteres Fenster:
  eine Auszählung über mehr als die gezeigte Liste ist eine, die niemand prüfen kann.
- **F2 — Satz ins Sperr-Modal.** «Betrifft nur Formulare mit eingeschaltetem Geo-Guard.»
- Formular-Filter (`?form=<guardKey>`), Default «alle».
- `GATE_DETAILS` (ehrlicher Ausgang statt «Versand scheiterte» für Gates) behält
  `throttled`/`throttled-ip`, verliert `blocked-country` — das ist neu der
  erstklassige Ausgang `geo` und braucht keine Substitution mehr.
- Der Hinweis «Kein Länder-Datenbestand installiert» (`geoReady`/`geoBuilt`) bleibt —
  er ist die einzige Stelle, die einen aktivierten, aber datenlosen Guard sichtbar
  macht (siehe Voraussetzung oben).
- Kein neues JavaScript — die generische `data-fetch-get`/`data-fetch-post`-Verdrahtung
  in `core.js` trägt das bereits.

### S5 — Jobs in den Kernel

- `Z77\Shared\Forms\FormLogSweepJob`, ruft `FormLog::sweep()`.
- Registriert in `backendConfig` `jobs` als `form-log-cleanup` — Präzedenz: `BackupJob`
  liegt im Kernel und wird von dort registriert, weil `module-backend` die Service-Sektion
  besitzt, die ihn bedient.
- Kein `defaultSchedule`: der Job **löscht**. Ein Betreiber schaltet ihn ein (ADR-031-Regel,
  dieselbe wie bei `member-cleanup`).
- **`geoip-update` zieht mit um**: Eintrag (inkl. `defaultSchedule 'weekly@mon,04:20'` —
  Lizenzpflicht, die begründete Ausnahme der Löschjob-Regel) von `memberConfig` nach
  `backendConfig`, im selben Commit wie der Rückbau in S3. Ohne den Umzug hätte ein
  Projekt ohne `module-member`, das den Guard auf einem Kontaktformular aktiviert,
  keinen Job — kein Erstdownload, keine Aktualisierung, EULA-Pflicht unerfüllt.

### S6 — Navigation, Rolle, Doku

- `navigation.default.json`: Eintrag `id:30` bleibt, aber `key` → `form-log`,
  `name` → «Formular-Protokoll», `controller` → `form-log`.
- Rolle: keine Config-Zeile. ADMIN erbt aus `moduleRole` (AUTH-B003, Abweichungen-nur).
  Die Seite zeigt IPs und ggf. Adressen — ADMIN ist richtig.
- **Neues Topic** `docs/topics/form-guard.md`? Nein — `forms.md` bekommt den Guard,
  `geoip.md` behält Datenbank und Lizenz, verweist auf `forms.md` für die Sperrliste.
  `member.md` und `backend.md` ziehen nach. `npm run docs:check` muss grün sein.
- **`forms.md` nennt die Setup-Checkliste** aus «Voraussetzung» oben: MaxMind-Konto,
  Schlüssel pro Installation in `config/geoip.inc.php` (maschinenlokal, nicht deployt),
  `geoip-update` aktiv — und den Effekt des Weglassens (Regel still wirkungslos, nur
  die Backend-Seite zeigt es). Ein Guard-Opt-in ohne diese Zeilen daneben ist die
  Falle, die dieser Abschnitt verhindert.
- **`geoip.md` zieht bei den Regeln nach**: die Ganzdatei-Override-Regel («memberConfig
  jobs MUSS geoip-update tragen») dreht sich um — der Eintrag gehört jetzt nach
  `backendConfig`, ein Override, der ihn noch trägt, kollidiert fail-fast (Migration).
- **Datenschutz-Hinweis erweitern** (`geoip.md` pending «Say it in the privacy
  policy»): jedes Guard-Opt-in weitet das Protokoll aus — mit dem Login schreibt neu
  auch ein Formular bestehender Mitglieder IP/Land/UA für 90 Tage. Der Satz gehört
  in die Checkliste in `forms.md`, der Text selbst bleibt Sache der Installation.
- **Altlast:** die fünf gestageten Test-Löschungen bereinigen — Löschung bleibt, die
  `SOURCE=`-Zeilen in `navigation.md:35` und `packaging.md:24` fallen mit weg.
  (Blockiert heute `docs:check`.)

## Was nicht gebaut wird

- **Kein Geo-Check auf Request-Ebene.** Anderes Ausmass an Personendaten (IP + Land über
  den gesamten Traffic), Kosten pro Request, und die falsche Schicht — das gehört vor PHP.
- **Keine Whitelist**, nirgends. Sie sperrt den Kunden im Ferien-WLAN, hinter VPN oder bei
  einem Anbieter aus, der über das Ausland routet. Eine Blocklist kann nur zu klein sein,
  das kostet einen Versuch, den wir ohnehin gehabt hätten.
- **Kein Blocken bei unbekanntem Land.** Keine Datenbank, privater Bereich, unbekannter
  Range, kaputte Datei — alles heisst «wir wissen es nicht», und das blockt nie. `??` wird
  in der Oberfläche gar nicht erst zum Sperren angeboten.
- **Keine Sperre pro Formular** (Entscheid 3).
- **Kein `Request::getClientIp()`** in diesem Schritt. Der Handler liest `REMOTE_ADDR`
  weiterhin direkt, aber neu an **einer** Stelle. Ein sauberer Request-Seam inkl.
  Reverse-Proxy-Behandlung ist ein eigener Kernel-Umbau — als Pendenz nach `forms.md`.

## Migration

Der heutige Stand ist **uncommitted** — keine Installation trägt ihn. Es gibt also nichts
zu migrieren, und die Ablage kann frei gewählt werden. Statt zu committen und danach zu
verschieben wird direkt die Kernel-Version gebaut; rund 80 % des vorhandenen Codes wandert
nur das Paket.

Drei Dinge betreffen bestehende Installationen trotzdem:

- `memberConfig['blockedCountries']` ist tot und wird **still ignoriert** (GEOIP-003).
  Ein Projekt mit Ganzdatei-Override muss seine Länder auf der neuen Oberfläche neu
  erfassen und den Restschlüssel löschen.
- **Ganzdatei-Override von `memberConfig` mit `jobs`-Sektion:** der `geoip-update`-Eintrag
  muss dort **raus** (er kommt neu aus `backendConfig`). Bis dahin deklarieren zwei
  Module denselben Job-Key → `ModuleManager::getJobs()` wirft fail-fast. Laut, nicht
  still — gewollt, aber es gehört in die Release-Notiz.
- Der Navigationseintrag `id:30` erreicht ein bestehendes Projekt über Service → Import.

## Nicht Teil dieses Bauplans

Der zweite uncommittete Block — `FormGuard::sendCount()` und der Spam-Ordner-Hinweis auf
der Warteseite (MEM-012) — ist davon unabhängig und geht als **eigener Commit** vor
diesem Umbau raus. Er berührt weder Gate noch Protokoll noch Sperrliste.

## Reihenfolge und Abhängigkeiten

```
S1 Kernel-Bausteine
 └─ S2 Gate in den Handler
     ├─ S3 Member-Rückbau      ⟵ erst wenn S2 läuft, sonst Lücke im Schutz
     └─ S4 Backend-Oberfläche
S5 Jobs                         Sweep jederzeit; geoip-update-Umzug im S3-Commit
S6 Navigation/Rolle/Doku        zuletzt
```

`S3` erst nach verifiziertem `S2`: zwischen «Gate aus `RegistrationFlow` entfernt» und
«Gate im Handler aktiv» darf kein Zustand liegen, in dem das Registrierungsformular
ungeschützt ist. Der `geoip-update`-Umzug (S5) hängt am `memberConfig`-Rückbau (S3):
ein Commit, kein Zwischenstand (Doppel-Deklaration wirft, fehlende Deklaration lässt
die Datenbank altern).

## Abnahme

- `tests/country-blocklist.php` grün (Fail-Open in jedem kaputten Zustand).
- Neuer Harness `tests/form-geo-guard.php`: Gate-Position in der Kaskade, sichtbar vs.
  silent, Protokollzeile mit und ohne `identityField()`, `extra` und `note()`-Detail
  reiten auf der Zeile mit, **eine** IP-Lesung und **ein** Lookup pro Submit; Guard
  aus → kein Lookup, keine Zeile; Sperrliste leer → Zeile **mit** Land, Gate blockt
  nicht.
- Manuell: kaputte `blocked-countries.json` → Formular läuft, Backend zeigt den
  Fehlerzustand statt 500.
- Manuell: Guard an ohne `.mmdb` → Formular läuft, Zeilen mit `country: null`, Backend
  zeigt «Kein Länder-Datenbestand installiert».
- `npm run docs:check` grün.
