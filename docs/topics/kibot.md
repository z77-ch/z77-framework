# kibot

2026-10-25

## entry

1. `packages/module-frontend/src/Ui/Kibot/KibotEndpoint.php` — the z77 side: reads `config/client/kibot.inc.php`, resolves paths against the project root, runs Guard → Bot → SSE frames
2. `packages/kibot/src/Bot.php` — the framework-free core: routing round (tool `lies_themen`), sources within the budget, streamed answer round
3. `packages/kibot/README.md` — the package reference in German: flow, API details, `topics.json`, standalone start, logs

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
SOURCE=/packages/module-frontend/src/Ui/Kibot/KibotEndpoint.php
SOURCE=/packages/module-frontend/src/Ui/Kibot/KibotSession.php
SOURCE=/packages/module-frontend/composer.json
SOURCE=/packages/kernel/core/src/Services/AccessGuard.php
SOURCE=/packages/kernel/core/src/Http/Security/CsrfService.php
SOURCE=/tests/kibot.php
SOURCE=/tests/kibot-adapter.php
RUNTIME=/skeleton/config/client/kibot.inc.php
RUNTIME=/skeleton/shared/kibot/topics.json

## mental model

`z77/kibot` is a standalone package (namespace `Z77\KiBot`, PHP 8.2, curl + json, no z77 import) that answers visitor questions from hand-maintained sources: a cheap routing model picks 1–3 topics from `topics.json`, the server reads their files within a character budget, and the answer model streams the reply. module-frontend carries the z77 adapter: `KibotController` maps two Fetch routes onto `KibotEndpoint`, which runs exactly the sequence of the standalone `public/bot.php` on z77's request, session and CSRF token. The package's `res/assets/` (widget JS + CSS) is published by the installer like any framework package's.

- **Opt-in twice.** module-frontend only *suggests* `z77/kibot`; without the package, or without `config/client/kibot.inc.php`, both routes answer 404 (`KibotEndpoint::fromConfig()` returns null).
- **Routes:** `GET /frontend/main/kibot/config` → widget texts, CTAs, max length + CSRF token (JSON); `POST /frontend/main/kibot/ask` → `{messages, token, website}` → SSE (`data: {"text"}` … `data: {"error"}` … `data: [DONE]`). Both `#[Fetch]`: a navigation is a 404, so no page cache stores a token.
- **One token.** The config action hands out `CsrfService::getToken()`. `bot.js` sends it as `X-CSRF-Token` (AccessGuard's global fetch-POST check) and in the body; `KibotSession` maps Guard's `kibotCsrfToken` read onto the same z77 token. Guard then adds honeypot, session + IP rate limits and length caps.
- **Config = one file** `config/client/kibot.inc.php` (machine/installation-owned, ADR-036; shared in the release layout). It returns KiBot's `Config` overrides unchanged — same shape as `packages/kibot/kibot.config.example.php`. Only deviations; lists (CTAs, prompt rules) replace as a whole.
- **Paths** resolve against `ABS_BASE_PATH`: `paths.sourceRoot` default `shared/kibot`, `paths.topicsFile` default `<sourceRoot>/topics.json`, `paths.logDir` default `logs/kibot`, rate files in `<logDir>/rate`. `paths.keyFile` has **no default** — outside `public/` and outside the repo (e.g. `HOSTING_ROOT/.z77/<project>/keys/anthropic.key`); missing → the `noApiKey` text as SSE error plus a line in `kibot-api-error.log`.
- **Streaming and Rule 3:** no response helper streams, so `askAction()` drops the output buffers, sets the SSE headers, writes the frames while it runs and returns `$this->void()`.
- Session keys used: `kibotRateLimit`, `kibotTopics` (topics of the last answer, for follow-up questions); the CSRF token is z77's `csrf.token`.

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

## rules

- When adapting the bot for a project → MUST change `config/client/kibot.inc.php` only; MUST NOT edit `packages/kibot/src/Config.php` defaults or copy them into the project file.
- When configuring the key → MUST set `paths.keyFile` to a file outside `public/` and outside the repository; MUST NOT put the key itself into any config, log or the widget config.
- When posting to the ask route from JS → MUST send the token as `X-CSRF-Token` header (AccessGuard checks only the header) and in the body (Guard); MUST NOT issue a second KiBot token in z77 (`KibotSession` throws on a write to `kibotCsrfToken`).
- When adding a route or action for the bot → MUST keep it `#[Fetch]` so a navigation never reaches a cached page with a token.
- When changing what the browser receives → MUST go through `Config::widget()` (texts, CTAs, max length); MUST NOT expose paths, models, limits or prompt.
- When the core needs something from z77 → MUST add it to the adapter in module-frontend; MUST NOT import a `Z77\Core` / `Z77\Shared` class into `packages/kibot/`.
- When changing the widget → MUST keep DOM-only rendering (`createElement` / `textContent`, no `innerHTML`, no inline script) so it runs under a CSP limited to the own origin (Rule 7).

## known issues

- KIBOT-SESSION-LOCK: don't assume a second request of the same visitor runs while an answer streams — the z77 file session stays locked until the ask action ends (same in the standalone endpoint).
- KIBOT-BODY-CAP: don't assume `limits.maxBodyBytes` caps the raw upload — `Request::getJsonBody()` reads the body unbounded; the adapter checks the re-encoded size, Guard caps count and length per message.
- KIBOT-CSRF-ENVELOPE: don't expect an SSE error when the `X-CSRF-Token` header is missing — AccessGuard answers with a FetchResponse envelope before the action runs; the widget then ends with an empty answer.
- KIBOT-STANDALONE-ASSETS: don't look for `bot.js` / `bot.css` in `packages/kibot/public/` — they live in `res/assets/` (installer convention); the standalone demo needs a copy into `public/` (gitignored).

## pending

- Live test with a real key (bin/ask.php, standalone widget, z77 project).
- KIBOT-SOURCE-ROOT: decide the source root for the release layout — `shared/kibot` relative to the release means a `target.shared` entry `shared/kibot` (→ `<root>/shared/shared/kibot`); `data/kibot` would ride on the already shared `data/`.
- KIBOT-STREAM-RESPONSE: consider a typed `StreamResponse` + `$this->stream()` helper (ADR-003 addendum) instead of streaming inside the action before `void()`.
- Packaging: `z77/kibot` is not a split target and not on Packagist yet; not in `skeleton/composer.json`.

## see also

- [`security.md`](security.md) — CSRF contract: AccessGuard checks every fetch POST via `X-CSRF-Token`
- [`fetch.md`](fetch.md) — `#[Fetch]` routes, FetchResponse envelope on a CSRF failure
- [`installer.md`](installer.md) — `res/assets` publication to `public/assets/{name}`
- [`packaging.md`](packaging.md) — path repositories, split targets, Packagist
