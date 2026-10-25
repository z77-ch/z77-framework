# ADR-055 — KI-Assistent instead of the help button

**Status:** `[APPROVED]` — owner decision 2026-10-25 (decisions 1–3 below); built 2026-10-25, not committed.
**Date:** 2026-10-25
**Amends:** [ADR-048](adr-048-help-service.md) (what the trigger opens in the backend),
[`backend-screen.md`](../01-handbook/backend-screen.md) §1 / §5 (the toolbar and top bar show
the assistant).
**Builds on:** `z77/kibot` (topic routing, `lies_themen`, [`kibot.md`](../topics/kibot.md)), the
HelpService (ADR-048), the FileFinder lookup order (CE principle, Rule 1).

---

## Context

Every backend screen that needs explaining carries a help template (`*.help.tpl.php`, ADR-048):
a general part plus one section per field. The user opens it with «Hilfe» (list toolbar, top
bar, a window's title bar) and reads. Two weaknesses: the help of ONE screen answers only
questions about that screen, and a user has to find the paragraph himself. Since 2026-10-25
the framework carries a KI-Bot (`z77/kibot`) that routes a question to the right sources and
answers from them.

## Decision

1. **Knowledge source = the help texts only** (owner). The assistant reads every
   `*.help.tpl.php` of the installation, field sections included — not `docs/topics`, not
   the code, not data. The help texts stay the single source: they are written for users
   and keep being written; the assistant only reads them. Nothing is maintained twice.
2. **The classic help stays reachable, very discreetly** (owner): a small link «Hilfetext
   anzeigen» under the input of the assistant panel opens the help window of the screen as
   before. F1 in a form field keeps opening the classic help.
3. **Every logged-in backend user may use it** (owner): no extra right — the routes follow
   the lowest backend role (EDITOR), like `save-preferences`.
4. **Where «Hilfe» stood, «✦ KI-Assistent» stands** — list toolbar, top bar, window title bar —
   and opens a chat panel docked at the right edge. No help attached → no button, as before.
5. **Context = the current screen's help topic.** Each help template is a topic with the id
   `HelpService::topicIdFor($path, $nameSpace)` (`module-order-backend-order-controller-edit`);
   the help block carries it as `data-help-topic`, the opener as `data-kibot-context`. The bot
   gets it as the preselected topic and may add up to 2 more (budget 3). The greeting names
   the screen («Du bist bei «Aufträge» …»).
6. **The knowledge index is generated, never hand-maintained.** `Z77\Core\Kibot\HelpKnowledge`
   walks the template roots of every FileFinder namespace in lookup order (an override under
   `override/` wins, a project's own help joins), renders each help to plain text and writes a
   KiBot topic set to `data/kibot/help/` (`topics.json` + one `.txt` per topic; `data/` is
   shared in the release layout and not public). Title = the first heading of the general
   part, description = the opening sentences + the section headings + the screen.
   **Rendering:** with an empty context, notices turned into exceptions; a template that needs
   its controller's variables falls back to its static HTML (read through the tokenizer, not
   executed); one that does not parse or yields no text is skipped and logged.
   **Freshness:** rebuilt lazily before a question when a help template is newer than the index
   or the set of templates changed (signature) — a deploy with changed help texts updates the
   knowledge on its own; `bin/assistant-index.php` rebuilds on demand.
7. **Fallback to the classic help** whenever the assistant cannot answer: z77/kibot not
   installed, no `config/client/kibot.inc.php`, no readable key file → the shell renders the
   classic «Hilfe» (decided server-side per page). API error or no answer → the error text in the
   panel, which names «Hilfetext anzeigen». bot.js not loaded → the button still opens the help.
8. **No business data is sent.** Only the question, the history of the conversation and the
   help texts reach Anthropic — no record, no session value, no user name. The system prompt
   says so, and the assistant says it has no access to data when asked about a concrete
   record.
9. **One configuration file, one widget code base.** The backend reads the same
   `config/client/kibot.inc.php` as the website bot but takes from it only what holds for both
   (`paths.keyFile`, `paths.logDir`, `paths.rateDir`, `api`, `budget`, `limits`, `log`); its own
   texts and prompt are defaults in `BackendAssistant`, a project changes them under the key
   `backend`. The panel is `bot.js` / `bot.css` with `data-mode="panel"`; the backend maps the
   colours onto its palette (`_assistant-host.scss`). The z77 adapter (`KibotEndpoint`,
   `KibotSession`) moved from module-frontend into the kernel (`Z77\Core\Kibot`) so both bots
   share it (Rule 9).
10. **Cost:** about 2–3 Rp. per question (a cheap routing round + a streamed answer over at most
    40'000 characters of help text). Rate limits of KiBot apply (per session and IP).

## Reasoning

- The help texts are already precise, field by field, and maintained with the screens. Reading
  them through the assistant adds an index over ALL screens without a second text base.
- Generated and lazily refreshed: a module that ships a new help, or a project that overrides
  one, is in the knowledge after the next deploy without anyone touching an index.
- The screen's help as context keeps short questions («Was heisst gebucht?») on the screen the
  user is looking at, while the routing still finds the answer on another screen.
- Rendering with an empty context gives the cleanest text for most templates; the static fallback
  keeps the conditional ones (journal, invoice form) instead of losing them.

## Consequences

- `backend-screen.md`: «help with field sections» stays — it is the assistant's knowledge; the
  toolbar / top bar show «KI-Assistent» when configured. A screen without help gets no button.
- A help template is now also prompt material: write it so a single section can stand alone
  (heading + text), name buttons and fields exactly as on screen. A general heading (`<h3>`)
  before the first field section gives the topic its title.
- `core.js` builds the window title-bar button as the assistant opener when bot.js is ready
  (`data-kibot-ready`); `core.min.js` must be re-saved (js-min-markers marker
  `data-kibot-ready`).
- New route `/backend/system/assistant/{config,ask}`; new data directory `data/kibot/help/`;
  conversation log `logs/kibot/kibot-backend-chat_YYYY-MM.jsonl`.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| `docs/topics` as additional knowledge | Owner decision 1: written for developers, not users; would answer with internals |
| A hand-maintained `topics.json` for the backend | A second text base that drifts from the help templates |
| Index built at `composer install` only | Misses project overrides edited later and needs a hook in every deploy; the lazy mtime check costs one directory walk |
| Sending the current form's values for better answers | Business data would leave the installation (decision 8) |
| Replacing the help window entirely | Owner decision 2: the classic help stays as a discreet fallback (no key, API down, preference) |
