# z77/kibot — AI bot with topic routing

A bot that takes a question, decides from a hand-maintained topic list which documents hold
the answer, reads them and streams the answer from the Anthropic Messages API. Plain PHP 8.2+,
only `ext-curl` and `ext-json`, no framework dependency (namespace `Z77\KiBot`).

It runs **standalone** (`public/bot.php`, `bin/ask.php`) and inside z77, where the kernel's adapter
serves two bots: the website widget (module-frontend) and the backend **KI-Assistent**, which answers
from all help texts of the installation (ADR-055). Developed in the
[z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo (`packages/kibot`).

## Flow

| Step | What happens |
|---|---|
| a | The widget shows `welcomeMsg` |
| b | `POST` with `messages`, CSRF token, honeypot; `Guard` checks |
| c | **Round 1 (routing):** `claude-haiku-5-5`, not streamed. System prompt with the topic list (only `id`, `titel`, `beschreibung`), history, question, tool `lies_themen(ids)` |
| d | The model calls `lies_themen` with 1 to 3 ids — or none when nothing fits |
| e | The server reads the sources, marks each with its title, cuts to the budget (40'000 chars in total) |
| f | **Round 2 (answer):** `claude-sonnet-5-5`, streamed. The same conversation goes on: the model's routing turn unchanged (incl. thinking blocks) plus a user turn with the `tool_result`. No second system prompt, no second question |
| g | Answer as SSE to the widget, one entry in the conversation log |

- **No topic fits:** round 2 gets only the history, no tool, and the system prompt says no source
  fits. The model says so and invents nothing.
- **Invalid tool input** (none, more than 3 or unknown ids, extra fields, broken JSON):
  `tool_result` with `is_error: true`; the model can correct itself.
- **The answer model asks for more topics:** they are loaded and the answer round repeats, at most
  `api.maxToolRounds` times (default 2). The budget covers the whole question.
- **Follow-up questions:** the topics of the last answer are kept in the session (`kibotTopics`) and
  named in the next question's system prompt; the model may reuse them or ask for others. History:
  at most 20 messages.
- **Context topic** (optional): `Bot::ask()` takes a topic id the question comes from; the routing
  prompt asks the model to read it plus at most `maxTopics − 1` more (`prompt.contextTopic`).
- **Model switch between the rounds** is allowed: the answer model ignores the routing model's
  thinking blocks.

### API details that matter

- `anthropic-version: 2026-10-21`; no `temperature`, `top_p`, `top_k` (else 400); no prefill.
- `tool_choice` always `{"type":"auto"}` (`any` / `tool` give a 400 on Sonnet 5.5), plus a clear
  instruction in the system prompt. The tool is `strict` with `additionalProperties: false`,
  `required: ["ids"]` and an `enum` of the topic ids.
- Assistant turns go back unchanged. Only adjustment: an empty tool input is sent as the JSON
  object `{}`, not as an array.
- The answer call uses the server-side fallback: header
  `anthropic-beta: server-side-fallback-2026-07-01` and `"fallbacks": "default"`
  (switch off with `api.serverSideFallback`).
- `stop_reason: refusal` → a friendly text from the config, plus a log entry.

## Files

```
packages/kibot/
  src/
    Bot.php              flow a to g (routing, tool, answer, budget)
    Topics.php           topics.json, id resolution, reading and cutting sources, HTML to text
    AnthropicClient.php  /v1/messages, streamed and not; errors -> ApiException with HTTP status
    StreamParser.php     SSE events assembled into one message
    Transport.php        HTTP interface (CurlTransport; a fake in the tests)
    KeyStore.php         key from a file; path via constructor, no default in code
    Guard.php            honeypot, CSRF, rate limit (session and IP file), lengths
    Log.php              conversation log (JSON lines, one file per month) and API error log
    Config.php           texts, CTAs, models, budgets, limits, paths, system prompt
  public/
    bot.php              standalone endpoint: GET ?config, POST = question (SSE)
    index.html           demo page
  res/assets/
    js/bot.js            widget
    css/bot.css          widget styles (z77 publishes res/assets to public/assets/kibot/)
  bin/ask.php            console, no browser
  example/               example sources (Markdown)
  topics.example.json    topic list for the example sources
  kibot.config.example.php
```

## Configuration

Every setting lives in `src/Config.php` with its default. A project passes only its deviations, as
an array to the constructor (standalone: `kibot.config.php`, see `kibot.config.example.php`). Lists
(CTAs, prompt rules) are replaced as a whole.

| Key | What matters |
|---|---|
| `paths.keyFile` | Path to the key file, outside `public/` and outside the repository, e.g. `HOSTING_ROOT/.z77/<project>/keys/anthropic.key`. No default. |
| `paths.sourceRoot` | Source root, default `shared/kibot` (fits the release layout) |
| `paths.topicsFile` | empty = `<sourceRoot>/topics.json` |
| `paths.logDir` | Logs; default `logs/kibot` (rate files in `<logDir>/rate`) |
| `api.answerModel`, `api.routingModel` | Model ids. **Anthropic retires models**; a retired model answers 404, and the error log then says `MODEL RETIRED? model=...` |
| `budget` | `maxChars` 40'000, `maxTopics` 3 |
| `limits` | 30 requests per session and hour, 60 per IP and hour, 2000 chars per message, 20 messages, 200'000 bytes per POST body |
| `log.ip` | `masked` (default, last IPv4 octet 0), `full` or `none` |
| `widget.debug` | widget console output, default off |

Relative paths resolve against a base directory: the package directory for the endpoint; for
`bin/ask.php` the directory of the config file or the current directory. In z77: the project root.

### topics.json

```json
{
  "version": 1,
  "themen": [
    {"id": "kontakt", "titel": "Öffnungszeiten und Kontakt",
     "beschreibung": "Öffnungszeiten, Adresse, Telefon, Anfahrt, Feiertage.",
     "quellen": ["kontakt/oeffnungszeiten.md", "kontakt/anfahrt.md"]}
  ]
}
```

- `id`: lowercase letters, digits, `_` and `-`; unique.
- `beschreibung`: 2 to 3 sentences on what is inside. It is ALL the model sees when routing.
- `quellen`: relative to the source root; `.md`, `.txt`, `.html` / `.htm` (converted to text).
  Absolute paths, `..`, schemes and paths that end outside the root are dropped and logged.
- A topic larger than its share of the budget is cut (`[... gekürzt]`) — then it belongs split.

## Running standalone

Create the key file (only the key, one line) outside the repository. Never write the key into a
config file or the repository.

**Console:**

```
php bin/ask.php --key=/path/to/anthropic.key --root=example --topics=topics.example.json "Wann habt ihr offen?"
php bin/ask.php --key=/path/to/anthropic.key --root=example --topics=topics.example.json
```

Without a question it starts a conversation (one question per line, an empty line ends it). The
answer goes to STDOUT, the chosen topics and errors to STDERR. Instead of the options:
`KIBOT_KEY_FILE`, `KIBOT_SOURCE_ROOT`, `KIBOT_TOPICS_FILE`, `KIBOT_CONFIG`.

**Browser:**

```
cp kibot.config.example.php kibot.config.php             # set keyFile
cp res/assets/js/bot.js res/assets/css/bot.css public/   # copies, gitignored
php -S 127.0.0.1:8080 -t public
```

Then open `http://127.0.0.1:8080/`. Embedding in your own page:

```html
<link rel="stylesheet" href="/kibot/bot.css">
<script src="/kibot/bot.js" data-endpoint="/kibot/bot.php" defer></script>
```

`data-endpoint` is the address for the question (POST), `data-config` the one for the config (GET);
without `data-config`, `<data-endpoint>?config` is used. The widget sends the CSRF token in the body
and as the header `X-CSRF-Token`. An element with `data-kibot-open` opens the chat. Colours via the
CSS variables `--kibot-*` on `#kibot-widget`.

## In z77

The adapter lives in the kernel (`Z77\Core\Kibot`: `KibotEndpoint`, `KibotSession`, `HelpKnowledge`);
this package stays free of z77. The kernel and module-frontend only *suggest* `z77/kibot`. Two bots
use the adapter:

- **Website widget** (module-frontend, `KibotController`): `GET /frontend/main/kibot/config`,
  `POST /frontend/main/kibot/ask`, sources hand-maintained under `shared/kibot/`.
- **Backend KI-Assistent** (module-backend, `AssistantController`, ADR-055): where the backend showed
  «Hilfe» it shows «KI-Assistent»; sources are generated from every `*.help.tpl.php` of the
  installation into `data/kibot/help/` (rebuilt automatically when a help template changes), together
  with the installation's own additions an admin writes in the help window («Hilfe ergänzen»,
  `data/help/`); widget in `data-mode="panel"`, the current screen's help as context topic
  (`data-kibot-context`). The backend loads `bot.js` / `bot.css` with `?v=<mtime>`, so a deploy
  reaches the browser without a hard reload.

Enabling the website widget:

1. `composer require z77/kibot` (path repository like the other z77 packages); `composer install`
   publishes `res/assets` to `public/assets/kibot/`.
2. `config/client/kibot.inc.php` returns the same deviations as `kibot.config.php` (at least
   `paths.keyFile`). Relative paths resolve against the project; sources under `shared/kibot/`, logs
   under `logs/kibot/`. Without the file the bot is off (both routes answer 404).
3. In the layout override:
   `<script src="/assets/kibot/js/bot.js" data-config="/frontend/main/kibot/config" data-endpoint="/frontend/main/kibot/ask" defer></script>`
   and `<link rel="stylesheet" href="/assets/kibot/css/bot.css">`.

The backend assistant needs the same file with a readable `paths.keyFile`; it takes only key, API,
budget, limits and log settings from it, never the website's texts or sources. Session and CSRF token
come from z77. Details: [`docs/topics/kibot.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/kibot.md),
[ADR-055](https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-055-ki-assistent-instead-of-help-button.md).

## Security

- The key comes from a file only — never in a log, an error message or the browser.
- Security chain: honeypot, CSRF (`hash_equals`), rate limit, lengths. The IP limit lives in files
  (IP hashed) and holds when the session changes.
- The widget builds everything with `createElement` / `textContent`: no `innerHTML`, no HTML strings.
  Links only for `http(s)` and paths of the site, CTAs additionally `mailto:` and `tel:`. Printing via a
  print stylesheet instead of a new window; runs under a CSP that limits scripts to the own origin.
- Only the question, the history and the selected sources are sent to Anthropic.

## Logs

- `kibot-chat_YYYY-MM.jsonl` (backend: `kibot-backend-chat_YYYY-MM.jsonl`): one JSON line per question
  (time, IP per `log.ip`, question, answer, topics, model, `stop_reason`, tokens, error). Appending keeps
  the cost per entry constant; files older than `log.retentionMonths` (12) are deleted.
- `kibot-api-error.log`: `[time] HTTP <status> | <type message> | <response, max. 500 chars>`.

## Tests

```
php tests/kibot.php
php tests/kibot-adapter.php
php tests/kibot-assistant.php
```

No key, no network: a fake transport replays recorded JSON and SSE responses in 7-byte chunks.
Covered: both rounds incl. request shape, budget, no topic, invalid tool input, further tool round,
error mid-stream, 404, 429, refusal, connection errors, path escapes, Guard, log format, widget
without `innerHTML`; the z77 adapter; the backend assistant's help index and wiring.

## Not built yet

- Live test with a real key (`bin/ask.php`, standalone widget, z77 website widget, backend assistant).
- No two-call fallback with JSON routing — tool use works cleanly in the stream, so it was not needed.
- No typed stream response in z77 yet: the ask actions stream inside the action and return `void()`.
- Publishing: not a split target, not on Packagist, not in the skeleton's `composer.json` — projects
  consume it through a `path` repository.

## License

MIT — Peter Ruepp, [z77.ch](https://z77.ch).
