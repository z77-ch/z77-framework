# z77/kibot — KI-Bot mit Themen-Routing

Ein Bot, der eine Frage entgegennimmt, anhand einer von Hand gepflegten Themenliste
entscheidet, welche Dokumente die Antwort enthalten, diese liest und die Antwort von
Anthropic als Stream ausgibt. Reines PHP 8.2+, nur `ext-curl` und `ext-json`, keine
Framework-Abhaengigkeit. Vorlage ist der Chatbot von poolmanufaktur.ch (wdv 6.2.2).

Stand: Schritte 1 bis 5 des Bauplans: standalone und mit z77-Adapter (siehe «In z77»).

## Ablauf

| Schritt | Was passiert |
|---|---|
| a | Widget zeigt `welcomeMsg` |
| b | `POST` mit `messages`, CSRF-Token, Honeypot; `Guard` prueft |
| c' | **Runde 1 (Routing):** `claude-haiku-5-5`, ohne Stream. System-Prompt mit der Themenliste (nur `id`, `titel`, `beschreibung`), Historie, Frage, Werkzeug `lies_themen(ids)` |
| d' | Die KI ruft `lies_themen` mit 1 bis 3 Ids auf, oder keines, wenn nichts passt |
| e | Server liest die Quellen, markiert jede mit ihrem Titel, kuerzt auf das Budget (40'000 Zeichen gesamt) |
| f | **Runde 2 (Antwort):** `claude-sonnet-5-5`, mit Stream. Dieselbe Konversation geht weiter: der Routing-Zug der KI unveraendert (inkl. Thinking-Bloecke) plus ein User-Zug mit dem `tool_result`. Kein zweiter System-Prompt, keine zweite Frage |
| g | Antwort als SSE ans Widget, Eintrag im Gespraechslog |

**Kein Thema passt:** Runde 2 bekommt nur die Historie, ohne Werkzeug, und im System-Prompt
den Hinweis, dass keine Quelle passt. Die KI sagt das und erfindet nichts.

**Ungueltige Werkzeug-Eingabe** (keine, mehr als 3 oder unbekannte Ids, zusaetzliche
Felder, kaputtes JSON): `tool_result` mit `is_error: true`, die KI kann korrigieren.

**Verlangt die Antwort-KI weitere Themen**, werden sie nachgeladen und die Antwortrunde
wiederholt, hoechstens `api.maxToolRounds` mal (Vorgabe 2). Das Budget gilt fuer die ganze
Frage.

**Folgefragen:** Die Themen der letzten Antwort liegen in der Session (`kibotTopics`) und
stehen im System-Prompt der naechsten Frage; die KI kann sie wiederverwenden oder neue
verlangen. Historie hoechstens 20 Nachrichten.

**Modellwechsel zwischen den Runden** ist zulaessig: das Antwortmodell ignoriert die
Thinking-Bloecke des Routing-Modells. Der Rueckfall des Bauplans (zwei getrennte Aufrufe
mit JSON-Routing) ist nicht gebaut, weil Tool-Use auch im Stream sauber laeuft.

### API-Details, die zaehlen

- `anthropic-version: 2026-10-21`; kein `temperature`, `top_p`, `top_k` (sonst 400); kein Prefill.
- `tool_choice` immer `{"type":"auto"}` (`any`/`tool` geben auf Sonnet 5.5 einen 400),
  dazu eine klare Anweisung im System-Prompt. Das Werkzeug ist `strict` mit
  `additionalProperties: false`, `required: ["ids"]` und einem `enum` der Themen-Ids.
- Assistenten-Zuege gehen unveraendert zurueck. Einzige Anpassung: eine leere
  Werkzeug-Eingabe wird als JSON-Objekt `{}` statt als Array gesendet.
- Antwortaufruf mit Server-seitigem Fallback: Header
  `anthropic-beta: server-side-fallback-2026-07-01` und `"fallbacks": "default"`
  (abschaltbar mit `api.serverSideFallback`).
- `stop_reason: refusal` → freundlicher Text aus der Konfiguration, Logeintrag.

## Dateien

```
packages/kibot/
  src/
    Bot.php              Ablauf a bis g (Routing, Werkzeug, Antwort, Budget)
    Topics.php           topics.json, Ids aufloesen, Quellen lesen und kuerzen, HTML zu Text
    AnthropicClient.php  /v1/messages, mit und ohne Stream; Fehler -> ApiException mit HTTP-Status
    StreamParser.php     SSE-Ereignisse zu einer Nachricht zusammensetzen
    Transport.php        Schnittstelle fuer HTTP (CurlTransport; in Tests ein Fake)
    KeyStore.php         Key aus Datei; Pfad per Konstruktor, kein Default im Code
    Guard.php            Honeypot, CSRF, Rate-Limit (Session und IP-Datei), Laengen
    Log.php              Gespraechslog (JSON-Zeilen, Datei je Monat) und API-Fehlerlog
    Config.php           Texte, CTAs, Modelle, Budgets, Limits, Pfade, System-Prompt
  public/
    bot.php              Standalone-Endpunkt: GET ?config, POST = Frage (SSE)
    index.html           Demo-Seite
  res/assets/
    js/bot.js            Widget
    css/bot.css          Widget-Styling (z77 publiziert res/assets nach public/assets/kibot/)
  bin/ask.php            Konsole, ohne Browser
  topics.example.json    Themenliste zu den Beispielquellen in example/
  kibot.config.example.php
```

## Konfiguration

Alle Einstellungen stehen in `src/Config.php` mit Vorgaben. Ein Projekt gibt nur die
Abweichungen an, als Array an den Konstruktor (standalone: `kibot.config.php`, siehe
`kibot.config.example.php`). Listen (CTAs, Prompt-Regeln) werden ganz ersetzt.

| Bereich | Wichtig |
|---|---|
| `paths.keyFile` | Pfad zur Key-Datei, ausserhalb `public/` und ausserhalb des Repos, z. B. `HOSTING_ROOT/.z77/<projekt>/keys/anthropic.key`. Kein Default. |
| `paths.sourceRoot` | Quellenwurzel, Vorgabe `shared/kibot/` (passend zur Release-Struktur) |
| `paths.topicsFile` | leer = `<sourceRoot>/topics.json` |
| `paths.logDir` | Logs; Vorgabe `logs/kibot` |
| `api.answerModel`, `api.routingModel` | Modell-Ids. **Anthropic schaltet Modelle ab**; ein abgeschaltetes Modell liefert 404, im Fehlerlog steht dann `MODEL RETIRED? model=...` |
| `budget` | `maxChars` 40'000, `maxTopics` 3 |
| `limits` | 30 Anfragen pro Session und Stunde, 60 pro IP und Stunde, 2000 Zeichen pro Nachricht, 20 Nachrichten |
| `log.ip` | `masked` (Vorgabe, letztes IPv4-Oktett 0), `full` oder `none` |
| `widget.debug` | Konsolenausgabe des Widgets, Vorgabe aus |

Relative Pfade gelten relativ zum Basisordner: beim Endpunkt der Paketordner, bei
`bin/ask.php` der Ordner der Konfigurationsdatei bzw. das aktuelle Verzeichnis.

### topics.json

```json
{
  "version": 1,
  "themen": [
    {"id": "kontakt", "titel": "Oeffnungszeiten und Kontakt",
     "beschreibung": "Oeffnungszeiten, Adresse, Telefon, Anfahrt, Feiertage.",
     "quellen": ["kontakt/oeffnungszeiten.md", "kontakt/anfahrt.md"]}
  ]
}
```

- `id`: Kleinbuchstaben, Ziffern, `_` und `-`; eindeutig.
- `beschreibung`: 2 bis 3 Saetze, was drinsteht. Nur das sieht die KI beim Routing.
- `quellen`: relativ zur Quellenwurzel; erlaubt `.md`, `.txt`, `.html`/`.htm` (wird zu Text).
  Absolute Pfade, `..`, Schemata und Pfade, die ausserhalb der Wurzel enden, werden
  verworfen und im Fehlerlog vermerkt.
- Ein Thema, das groesser ist als sein Anteil am Budget, wird gekuerzt (`[... gekürzt]`).
  Dann gehoert es aufgeteilt.

## Standalone starten

Key-Datei anlegen (nur der Key, eine Zeile), ausserhalb des Repos. Den Key nie in eine
Konfigurationsdatei oder ins Repo schreiben.

**Konsole:**

```
php bin/ask.php --key=/pfad/zu/anthropic.key --root=example --topics=topics.example.json "Wann habt ihr offen?"
php bin/ask.php --key=/pfad/zu/anthropic.key --root=example --topics=topics.example.json
```

Ohne Frage startet ein Gespraech (eine Frage pro Zeile, leere Zeile beendet). Die Antwort
geht nach STDOUT, gewaehlte Themen und Fehler nach STDERR. Statt der Optionen gehen auch
`KIBOT_KEY_FILE`, `KIBOT_SOURCE_ROOT`, `KIBOT_TOPICS_FILE`, `KIBOT_CONFIG`.

**Browser:**

```
cp kibot.config.example.php kibot.config.php    # keyFile anpassen
cp res/assets/js/bot.js res/assets/css/bot.css public/   # Kopien, gitignored
php -S 127.0.0.1:8080 -t public
```

Dann `http://127.0.0.1:8080/` oeffnen. Einbinden in eine eigene Seite:

```html
<link rel="stylesheet" href="/kibot/bot.css">
<script src="/kibot/bot.js" data-endpoint="/kibot/bot.php" defer></script>
```

`data-endpoint` ist die Adresse fuer die Frage (POST), `data-config` die fuer die
Konfiguration (GET); ohne `data-config` gilt `<data-endpoint>?config`. Das Widget sendet
den CSRF-Token im Body und als Header `X-CSRF-Token`. Ein Element mit `data-kibot-open`
oeffnet den Chat. Farben ueber die CSS-Variablen
`--kibot-*` auf `#kibot-widget`.

## In z77

Der Adapter liegt im Kernel (`Z77\Core\Kibot`: `KibotEndpoint`, `KibotSession`), der Kern
bleibt frei von z77. Zwei Bots nutzen ihn: das Website-Widget (`module-frontend`,
`KibotController`) und der KI-Assistent im Backend (`module-backend`, ADR-055: Quellen aus den
Hilfetexten generiert, Widget mit `data-mode="panel"`, Kontext-Thema per `data-kibot-context`).
Kurz fuer das Website-Widget:

1. `composer require z77/kibot` (Path-Repository wie die anderen Pakete); `composer install`
   publiziert `res/assets` nach `public/assets/kibot/`.
2. `config/client/kibot.inc.php` gibt dieselben Abweichungen zurueck wie
   `kibot.config.php` (mindestens `paths.keyFile`). Relative Pfade gelten relativ zum
   Projekt; Quellen unter `shared/kibot/`, Logs unter `logs/kibot/`. Ohne Datei ist der Bot aus.
3. Im Layout:
   `<script src="/assets/kibot/js/bot.js" data-config="/frontend/main/kibot/config" data-endpoint="/frontend/main/kibot/ask" defer></script>`
   und `<link rel="stylesheet" href="/assets/kibot/css/bot.css">`.

Session und CSRF-Token kommen von z77. Alles Weitere: `docs/topics/kibot.md`.

## Sicherheit

- Key nur aus Datei, nie im Log, nie in einer Fehlermeldung, nie im Browser.
- Sicherheitskette wie im Pool-Bot: Honeypot, CSRF (`hash_equals`), Rate-Limit, Laengen.
  Das IP-Limit liegt in Dateien (IP gehasht) und greift auch, wenn die Session wechselt.
- Das Widget baut alles mit `createElement`/`textContent`: kein `innerHTML`, keine
  HTML-Strings. Links nur fuer `http(s)` und Pfade der Seite, CTAs zusaetzlich `mailto:`
  und `tel:`. Drucken ueber ein Print-Stylesheet statt eines neuen Fensters; laeuft mit
  einer CSP, die Skripte auf die eigene Herkunft beschraenkt.

## Logs

- `kibot-chat_YYYY-MM.jsonl`: eine JSON-Zeile pro Frage (Zeit, IP gemaess `log.ip`, Frage,
  Antwort, Themen, Modell, `stop_reason`, Tokens, Fehler). Anhaengen statt Neuschreiben
  haelt die Kosten pro Eintrag konstant; Dateien aelter als `log.retentionMonths` (12)
  werden geloescht.
- `kibot-api-error.log`: `[Zeit] HTTP <status> | <typ meldung> | <antwort, max. 500 Zeichen>`.

## Tests

```
php tests/kibot.php
php tests/kibot-adapter.php
php tests/kibot-assistant.php
```

Ohne Key und ohne Netz: ein Fake-Transport spielt aufgezeichnete JSON- und SSE-Antworten
in 7-Byte-Stuecken ab. Abgedeckt: beide Runden samt Request-Form, Budget, kein Thema,
ungueltige Werkzeug-Eingabe, weitere Werkzeugrunde, Fehler mitten im Stream, 404, 429,
Refusal, Verbindungsfehler, Pfad-Ausbrueche, Guard, Logformat, Widget ohne `innerHTML`.

## Offen

- Live-Test mit echtem Key (bin/ask.php und Widget) steht aus.
