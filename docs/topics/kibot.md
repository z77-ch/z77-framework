# kibot

2026-10-25

## entry

1. `packages/kernel/core/src/Kibot/KibotEndpoint.php` — the z77 side, shared by both bots: reads `config/client/kibot.inc.php`, resolves paths against the project root, runs Guard → Bot → SSE frames
2. `packages/kibot/src/Bot.php` — the framework-free core: routing round (tool `lies_themen`), sources within the budget, streamed answer round
3. `packages/module-backend/src/Ui/Assistant/BackendAssistant.php` — the backend KI-Assistent (ADR-055): config from the shared file, help index, on/off

## file map

SOURCE=/packages/kibot/README.md
SOURCE=/packages/kibot/composer.json
SOURCE=/packages/kibot/autoload.php
SOURCE=/packages/kibot/src/Bot.php
SOURCE=/packages/kibot/src/Config.php
SOURCE=/packages/kibot/src/Guard.php
SOURCE=/packages/kibot/src/SessionStore.php
SOURCE=/packages/kibot/src/Topics.php
SOURCE=/packages/kibot/src/AnthropicClient.php
SOURCE=/packages/kibot/src/KeyStore.php
SOURCE=/packages/kibot/src/Log.php
SOURCE=/packages/kibot/public/bot.php
SOURCE=/packages/kibot/res/assets/js/bot.js
SOURCE=/packages/kibot/res/assets/css/bot.css
SOURCE=/packages/kibot/kibot.config.example.php
SOURCE=/packages/kibot/topics.example.json
SOURCE=/packages/module-frontend/src/Ui/Controllers/Main/KibotController.php
SOURCE=/packages/kernel/core/src/Kibot/KibotEndpoint.php
SOURCE=/packages/kernel/core/src/Kibot/KibotSession.php
SOURCE=/packages/kernel/core/src/Kibot/HelpKnowledge.php
SOURCE=/packages/kernel/core/src/Services/HelpService.php
SOURCE=/packages/kernel/core/src/Libraries/FileFinder.php
SOURCE=/packages/kernel/composer.json
SOURCE=/packages/module-frontend/composer.json
SOURCE=/packages/module-backend/src/Ui/Assistant/BackendAssistant.php
SOURCE=/packages/module-backend/src/Ui/Controllers/System/AssistantController.php
SOURCE=/packages/module-backend/src/Ui/Controllers/BackendAbstractController.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-backend/bin/assistant-index.php
SOURCE=/packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php
SOURCE=/packages/module-backend/res/view/templates/partials/shell/topbar.tpl.php
SOURCE=/packages/module-backend/res/scss/components/_assistant-host.scss
SOURCE=/packages/kernel/shared/res/view/templates/partials/helpTrigger.tpl.php
SOURCE=/packages/kernel/shared/res/assets/js/core.js
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/docs/02-decisions/adr-055-ki-assistent-instead-of-help-button.md
SOURCE=/tests/kibot.php
SOURCE=/tests/kibot-adapter.php
SOURCE=/tests/kibot-assistant.php
RUNTIME=/skeleton/config/client/kibot.inc.php
RUNTIME=/skeleton/shared/kibot/topics.json
RUNTIME=/skeleton/data/kibot/help/topics.json

## mental model

`z77/kibot` is a standalone package (namespace `Z77\KiBot`, PHP 8.2, curl + json, no z77 import) that answers questions from a topic set: a cheap routing model picks 1–3 topics from `topics.json`, the server reads their files within a character budget, and the answer model streams the reply. The z77 adapter lives in the kernel (`Z77\Core\Kibot`): `KibotEndpoint` runs exactly the sequence of the standalone `public/bot.php` on z77's request, session and CSRF token, and two bots use it — the website widget (module-frontend `KibotController`, hand-maintained sources) and the backend KI-Assistent (module-backend `AssistantController`, sources generated from the help templates, ADR-055). The package's `res/assets/` (widget JS + CSS) is published by the installer like any framework package's.

- **Opt-in twice.** module-frontend only *suggests* `z77/kibot`; without the package, or without `config/client/kibot.inc.php`, both routes answer 404 (`KibotEndpoint::fromConfig()` returns null).
- **Routes:** `GET /frontend/main/kibot/config` → widget texts, CTAs, max length + CSRF token (JSON); `POST /frontend/main/kibot/ask` → `{messages, token, website}` → SSE (`data: {"text"}` … `data: {"error"}` … `data: [DONE]`). Both `#[Fetch]`: a navigation is a 404, so no page cache stores a token.
- **One token.** The config action hands out `CsrfService::getToken()`. `bot.js` sends it as `X-CSRF-Token` (AccessGuard's global fetch-POST check) and in the body; `KibotSession` maps Guard's `kibotCsrfToken` read onto the same z77 token. Guard then adds honeypot, session + IP rate limits and length caps.
- **Config = one file** `config/client/kibot.inc.php` (machine/installation-owned, ADR-036; shared in the release layout). It returns KiBot's `Config` overrides unchanged — same shape as `packages/kibot/kibot.config.example.php`. Only deviations; lists (CTAs, prompt rules) replace as a whole.
- **Paths** resolve against `ABS_BASE_PATH`: `paths.sourceRoot` default `shared/kibot`, `paths.topicsFile` default `<sourceRoot>/topics.json`, `paths.logDir` default `logs/kibot`, rate files in `<logDir>/rate`. `paths.keyFile` has **no default** — outside `public/` and outside the repo (e.g. `HOSTING_ROOT/.z77/<project>/keys/anthropic.key`); missing → the `noApiKey` text as SSE error plus a line in `kibot-api-error.log`.
- **Streaming and Rule 3:** no response helper streams, so `askAction()` calls `KibotEndpoint::stream()` (drops the output buffers, sets the SSE headers, writes the frames while it runs) and returns `$this->void()`.
- **Context topic:** the optional body field `context` (a topic id, from the opener's `data-kibot-context`) goes to `Bot::ask()` as `$contextTopic`; the routing prompt asks the model to read it plus at most `maxTopics − 1` more (`prompt.contextTopic`). An unknown id is ignored.
- Session keys used: `kibotRateLimit`, `kibotTopics` (website: topics of the last answer), `kibotBackendTopics` (assistant); the CSRF token is z77's `csrf.token`.

```php
// config/client/kibot.inc.php — never the key itself, only its path
return [
    'paths'  => ['keyFile' => '/home/<hosting>/.z77/<project>/keys/anthropic.key'],
    'widget' => ['botName' => 'Muster-Assistent', 'welcomeMsg' => 'Grüezi, wie kann ich helfen?'],
    'prompt' => ['intro' => 'Du bist der Assistent der Website der Muster GmbH.'],
    'contactCtas' => [['label' => 'Kontakt', 'url' => '/kontakt']],
];
```

```html
<link rel="stylesheet" href="/assets/kibot/css/bot.css">
<script src="/assets/kibot/js/bot.js" data-config="/frontend/main/kibot/config" data-endpoint="/frontend/main/kibot/ask" defer></script>
```

## enable in a project

1. `composer require z77/kibot` (path repository next to the other z77 packages while they ship from `path` repos) — `composer install` publishes `res/assets` to `public/assets/kibot/`.
2. Put the key file outside the repo; write `config/client/kibot.inc.php` with at least `paths.keyFile`.
3. Create `shared/kibot/topics.json` and the source files (Markdown, text, HTML); format in the package README.
4. Add the two widget lines to the frontend layout override (`override/…/templates`), never in `vendor/`.

## backend KI-Assistent

ADR-055 (owner 2026-10-25): where the backend showed «Hilfe», it shows «✦ KI-Assistent» — a chat panel answering from ALL help texts, starting with the current screen's.

- **On** when z77/kibot is installed, `config/client/kibot.inc.php` exists and `paths.keyFile` is readable (`BackendAssistant::enabled()`, checked per page load in `BackendAbstractController::html()` → context `kibotAssistant`). Off → the shell renders the classic «Hilfe» trigger. Every logged-in backend user (EDITOR).
- **Config:** the same file; the backend takes only `paths.keyFile|logDir|rateDir`, `api`, `budget`, `limits`, `log` from it — never the website's `widget`, `prompt`, `contactCtas`, `messages`, sources. Its texts and prompt are `BackendAssistant::DEFAULTS` (du, Swiss German, no ß, names screens/fields as in the help, no invented menu paths, no data access); a project deviates under the key `backend`. Sources: `data/kibot/help/` (generated). Log: `kibot-backend-chat_YYYY-MM.jsonl`.
- **Routes:** `GET /backend/system/assistant/config`, `POST /backend/system/assistant/ask` (`{messages, token, website, context}`), both `#[Fetch]`.
- **Knowledge index** (`HelpKnowledge`): every `*.help.tpl.php` over the FileFinder namespaces in lookup order (override wins); id = `HelpService::topicIdFor()`; rendered with an empty context, static HTML (tokenizer) when it needs its context, skipped + logged when it does not parse. `topics.json` carries a `signature` of the template set; `ask` calls `BackendAssistant::refresh()` → `ensureFresh()` rebuilds when a template is newer than the index or the set changed. On demand: `php vendor/z77/module-backend/bin/assistant-index.php [--check]`.
- **UI:** the help trigger partial with `assistant` → `data-kibot-open data-kibot-fallback data-kibot-context(-label)`, still `data-help-open`. The skeleton loads `bot.css` + `bot.js data-mode="panel"`; bot.js takes the opener's click in the capture phase; «Hilfetext anzeigen» closes the panel and re-dispatches the click with the bot bypassed → core.js opens the classic help. core.js builds a window's title-bar button as the opener once `<html data-kibot-ready>` is set. Esc closes. Colours: `_assistant-host.scss`.
- **Privacy / cost:** only question, history and help texts are sent; ~2–3 Rp. per question.

```php
// config/client/kibot.inc.php — the assistant needs only the key path; texts only when deviating
return [
    'paths'   => ['keyFile' => '/home/<hosting>/.z77/<project>/keys/anthropic.key'],
    'backend' => ['widget' => ['botName' => 'Muster-Assistent']],
];
```

## rules

- When adapting the bot for a project → MUST change `config/client/kibot.inc.php` only; MUST NOT edit `packages/kibot/src/Config.php` defaults or copy them into the project file.
- When configuring the key → MUST set `paths.keyFile` to a file outside `public/` and outside the repository; MUST NOT put the key itself into any config, log or the widget config.
- When posting to the ask route from JS → MUST send the token as `X-CSRF-Token` header (AccessGuard checks only the header) and in the body (Guard); MUST NOT issue a second KiBot token in z77 (`KibotSession` throws on a write to `kibotCsrfToken`).
- When adding a route or action for the bot → MUST keep it `#[Fetch]` so a navigation never reaches a cached page with a token.
- When changing what the browser receives → MUST go through `Config::widget()` (texts, CTAs, max length); MUST NOT expose paths, models, limits or prompt.
- When the core needs something from z77 → MUST add it to the adapter in `Z77\Core\Kibot` (shared by both bots); MUST NOT import a `Z77\Core` / `Z77\Shared` class into `packages/kibot/`.
- When adding a bot or changing a bot's ask action → MUST go through `KibotEndpoint` (`stream()` / `ask()`); MUST NOT copy the SSE or Guard sequence into a controller.
- When the backend assistant needs knowledge → MUST come from the help templates via `HelpKnowledge`; MUST NOT hand-edit `data/kibot/help/` (it is regenerated) nor add `docs/` or data as sources (ADR-055 decisions 1 and 8).
- When writing a help template → MUST name buttons and fields exactly as on screen (the assistant quotes them) and put a general `<h3>` before the first field section (it becomes the topic title).
- When sending anything to the assistant from the browser → MUST stay with question, history and the help topic id; MUST NOT add record data or form values.
- When changing the widget → MUST keep DOM-only rendering (`createElement` / `textContent`, no `innerHTML`, no inline script) so it runs under a CSP limited to the own origin (Rule 7).

## known issues

- KIBOT-SESSION-LOCK: don't assume a second request of the same visitor runs while an answer streams — the z77 file session stays locked until the ask action ends (same in the standalone endpoint).
- KIBOT-BODY-CAP: don't assume `limits.maxBodyBytes` caps the raw upload — `Request::getJsonBody()` reads the body unbounded; the adapter checks the re-encoded size, Guard caps count and length per message.
- KIBOT-CSRF-ENVELOPE: don't expect an SSE error when the `X-CSRF-Token` header is missing — AccessGuard answers with a FetchResponse envelope before the action runs; the widget then ends with an empty answer.
- KIBOT-STANDALONE-ASSETS: don't look for `bot.js` / `bot.css` in `packages/kibot/public/` — they live in `res/assets/` (installer convention); the standalone demo needs a copy into `public/` (gitignored).
- KIBOT-ASSIST-MIN: don't assume a window's title-bar button is the assistant in production yet — that code is in `core.js`; until `core.min.js` is re-saved (VS Code), production windows keep «Hilfe» (the top bar / toolbar trigger is the assistant already). `tests/js-min-markers.php` fails on `data-kibot-ready` until then.
- KIBOT-ASSIST-LABEL: don't assume «KI-Assistent» on the trigger means the panel opens — the label is decided server-side (configured + key); if bot.js fails to load or its config fetch fails, the click opens the classic help.
- KIBOT-ASSIST-TITLE: don't expect a human title for every help topic — a template without a heading before its first field section gets one from the path (`Invoice – form`, `Invoice Journal – list`); fix it in the help template, not in the index.
- KIBOT-ASSIST-ASSETS: don't expect cache busting on `/assets/kibot/js/bot.js` — it is loaded directly by the skeleton (not through JavascriptManager), no version query, no `.min.js`.

## pending

- Live test with a real key (bin/ask.php, standalone widget, z77 project) — and of the backend assistant (panel, context, «Hilfetext anzeigen», window button).
- Re-save `core.js` in VS Code (→ `core.min.js`, KIBOT-ASSIST-MIN).
- Board for the assistant panel on the developer canvas (backend-screen recipe) — built without one by the owner's direct order.
- Decide whether the assistant also stands on screens WITHOUT attached help (today: only where «Hilfe» stood).
- General heading for `InvoiceController/form.help` and `InvoiceJournalController/list.help` (KIBOT-ASSIST-TITLE).
- KIBOT-SOURCE-ROOT: decide the source root for the release layout — `shared/kibot` relative to the release means a `target.shared` entry `shared/kibot` (→ `<root>/shared/shared/kibot`); `data/kibot` would ride on the already shared `data/`.
- KIBOT-STREAM-RESPONSE: consider a typed `StreamResponse` + `$this->stream()` helper (ADR-003 addendum) instead of streaming inside the action before `void()`.
- Packaging: `z77/kibot` is not a split target and not on Packagist yet; not in `skeleton/composer.json`.

## see also

- [`security.md`](security.md) — CSRF contract: AccessGuard checks every fetch POST via `X-CSRF-Token`
- [`fetch.md`](fetch.md) — `#[Fetch]` routes, FetchResponse envelope on a CSRF failure
- [`installer.md`](installer.md) — `res/assets` publication to `public/assets/{name}`
- [`packaging.md`](packaging.md) — path repositories, split targets, Packagist
