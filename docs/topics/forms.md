# forms

2026-09-01

## entry

1. `packages/kernel/shared/src/Forms/FormDefinition.php` — what a project writes: the declarative field map + form key (everything else is framework)
2. `packages/kernel/shared/src/Forms/PublicFormHandler.php` — the submit flow (CSRF → bot → validate → rate limit → send → PRG); a controller action is `create()` + `process()` + `viewContext()`
3. `packages/module-frontend/src/Ui/Controllers/Main/IndexController.php` — the reference implementation (`contactAction` / `thanksAction` / `checkAction`) projects copy

## file map

SOURCE=/packages/kernel/shared/src/Forms/FormDefinition.php
SOURCE=/packages/kernel/shared/src/Forms/PublicForm.php
SOURCE=/packages/kernel/shared/src/Forms/PublicFormValidator.php
SOURCE=/packages/kernel/shared/src/Forms/PublicFormHandler.php
SOURCE=/packages/kernel/shared/src/Forms/FormGuard.php
SOURCE=/packages/kernel/shared/src/Forms/FormLog.php
SOURCE=/packages/kernel/shared/src/Forms/CountryBlocklist.php
SOURCE=/packages/kernel/shared/src/Forms/FormLogSweepJob.php
SOURCE=/packages/kernel/shared/src/Entities/BlockedCountry.php
SOURCE=/packages/kernel/shared/src/Controller/PublicFormCheckTrait.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/FormLogController.php
SOURCE=/packages/module-backend/res/view/templates/Service/FormLogController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/FormLogController/confirmBlock.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/FormLogController/confirmUnblock.tpl.php
SOURCE=/tests/form-geo-guard.php
SOURCE=/tests/country-blocklist.php
SOURCE=/packages/kernel/persistence/src/Validation/EntityValidator.php
SOURCE=/packages/module-frontend/src/Ui/Form/ContactFormDefinition.php
SOURCE=/packages/module-frontend/src/Ui/Controllers/Main/IndexController.php
SOURCE=/packages/module-frontend/res/view/templates/partials/publicForm.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/Main/IndexController/contactAction.tpl.php
SOURCE=/packages/module-frontend/res/view/templates/Main/IndexController/thanksAction.tpl.php
SOURCE=/packages/module-frontend/res/assets/js/public-form.js
SOURCE=/packages/module-frontend/res/scss/components/_public-form.scss
SOURCE=/packages/kernel/shared/res/view/templates/emails/publicForm.tpl.php
SOURCE=/packages/kernel/shared/src/Config/emailConfig.inc.php
SOURCE=/packages/kernel/core/data/framework/i18n/de.default.json
SOURCE=/packages/kernel/core/data/framework/i18n/fr.default.json
SOURCE=/docs/03-development/public-form-bauplan.md
SOURCE=/docs/03-development/form-geo-guard-bauplan.md

RUNTIME=/skeleton/data/framework/forms/blocked-countries.json

## mental model

A public form (contact-form class) is **declared, not coded**: a project writes one
{@see FormDefinition} — field name → label, type, options, rules — plus the emailConfig
form key, and owns its template. Everything else is framework: {@see PublicForm} is the
generic DTO built from the POST, {@see PublicFormValidator} runs the declared rules,
{@see PublicFormHandler} owns the submit cascade, {@see FormGuard} the session mechanics
and `PublicFormCheckTrait` the per-field blur endpoint. Because values are addressed by
field name, the form partial and the notification-mail body can both be generic.

- **The project writes two things:** the declaration and (optionally) the template.
  `IndexController::contactAction` + `ContactFormDefinition` in `module-frontend` are the
  reference to copy — the same pair migrated the zihlundsee contact form and removed a DTO,
  a validator class, a JS file and a mail template from that project.
- **The session rate limit is 3 successful sends per hour, and it is visible** — hitting
  it renders the send-error banner. That is right for a contact form («nothing was sent»
  is the honest answer) and wrong for a form whose page must stay indistinguishable
  across submits. Adjust with `withRateLimit($maxPerHour, silent: true)`: silent makes
  the limit behave like the bot path (nothing sent, caller redirects as on success). The
  counter lives in the session and survives sign-out — `session_regenerate_id(true)`
  keeps the data, deliberately, so the limit cannot be reset by logging out. The member
  login runs it silently at 5/h (member.md MEM-010).
- **A silent limit still leaves the page something honest to say — `FormGuard::sendCount()`.**
  The VERDICT (`isRateLimited()`) must stay invisible on such a form: acting on it tells a
  stranger that this submit was treated differently from the last. The COUNT is different
  knowledge — it names no account, no address and no limit, only that THIS browser asked
  again, which the visitor already knows. A page may therefore branch on it without
  becoming an oracle. The member waiting page does exactly that (member.md MEM-012): from
  the second request it drops «Erneut anfordern» and advises the spam folder instead. It
  saturates at the limit (only a successful send is recorded), so read it as «asked once»
  vs. «asked again», never as a total.
- **`withGeoGuard()` — country rule + form log in ONE switch.** A form that opts in
  refuses submits whose origin country is on the installation's blocklist AND writes one
  `FormLog` line per submit, every outcome (`logs/form-YYYY-MM.jsonl`, 90 days). One
  switch, not two: a refusal needs its evidence, and the evidence (the log) is what a
  block is later decided from — the list starts empty, so the guard starts as pure
  observation. The gate sits after the bot trap and before validation, and it FAILS OPEN:
  empty list, unknown country — no database, private range, broken store — never block.
  Visible by default (send-error banner: a country rule can hit a real customer, and the
  customer must notice); `withGeoGuard(silent: true)` behaves like the bot path for pages
  that must stay indistinguishable (member login). The handler reads the IP ONCE
  (`REMOTE_ADDR`, one lookup) and shares it between gate and line, so the log can never
  show a different country than the one the gate decided on. The blocklist
  (`Z77\Shared\Entities\BlockedCountry`, `data/framework/forms/blocked-countries.json`)
  is installation DATA, edited on Backend → Service → Formular-Protokoll
  (`FormLogController`) — the tally and the block button share a page, and the prefilled
  reason names its counting window.
- **The log is data-minimal by default.** A line carries technical facts — form key,
  outcome, ip, country, user agent, the flow's `FormLog::note()` detail. An identifying
  value appears ONLY where the definition declares `identityField()` (the register form
  says `'email'`; a questionnaire says nothing), and `withGeoGuard(extra: [...])` pins
  additional technical facts (e.g. the register form's `origin`) — never identifying
  ones. ⚠️ Every guard opt-in widens what the installation stores about visitors: the
  privacy policy needs a sentence for the log (IP, country, UA, declared identity;
  90-day retention — swept by the `form-log-cleanup` job, operator-switched).
- **⚠️ The country DATA never comes with the switch — it is per-installation handwork.**
  `withGeoGuard()` without a GeoLite database is silently inert: every country reads as
  unknown, unknown never blocks, and only the backend page says so («Kein
  Länder-Datenbestand installiert»). The developer's checklist per installation: a
  MaxMind account + licence key, the key in `config/geoip.inc.php` (machine-local,
  gitignored, NOT deployed — it must be created on every server), and the `geoip-update`
  job active (registered by `backendConfig`; it performs the initial download and keeps
  the EULA's keep-current duty). Details and licence terms: [`geoip.md`](geoip.md).
- **Rules are an associative array**, not a string mini-language: `['required' => true,
  'min' => 2, 'max' => 80, 'email' => true, 'accepted' => true]`. A declared `options` map
  is the whitelist for that field's value. The set is deliberately small — new rules only
  when a real form needs one.
- **One message per field, translated.** Texts come from `form.error.{rule}` with
  `{$label}` / `{$min}` / `{$max}` placeholders and can be overridden per field via the
  spec's `messages` map — that value is a translation KEY (an unknown key surfaces
  verbatim). Labels also run through `t()`, so a declaration may use a key or a literal.
- **Blur check == submit check.** The trait validates one field via `isValid([$field])` on
  the same validator, so the two paths cannot drift. The checkable fields are the declared
  fields — no separate whitelist.
- **The controller keeps the Response.** `process()` returns `bool` ("you must redirect
  now"), never a Response object (ADR-003). `true` means a real success *or* a bot being
  shown a fake one — the controller cannot tell them apart, which is the point.
- **The confirmation is a PAGE, not a state.** The PRG target is a thank-you action with
  its own URL; the handler keeps no "was sent" flag and the form template renders the form
  and nothing else. It used to redirect the form page onto itself and let a session flag
  decide which of two bodies the template showed — invisible in the controller, unreachable
  by URL, and it hid a bug for a while (PUBLIC-FORM-003). The thank-you page is directly
  reachable; guarding it would reintroduce the flag it replaced.
- **`process($onValid)` is the project's go-ahead:** the callback receives the validated
  `PublicForm` and returns `true`/`false` instead of the default `sendForm()`. That is the
  seam for dynamic recipients (`EmailService::send()`) or any other action on a valid submit.
- **Validation reuses `EntityValidator`'s error infrastructure** (`isValid(?array $only)`,
  `addFieldError()`, `getFieldErrors()`) but runs its own checks: the inherited fluent
  checks carry hard-wired German texts and stay reserved for single-language backend
  entities.
- Not DI singletons — `PublicFormHandler::create()` / `FormGuard::forKey()` like
  `Mailer::create()` (placement decision B).

## flow

```text
GET /kontakt
  → PublicFormHandler::create(new ContactFormDefinition())
  → process()  → not a POST → armTimeTrap() → false
       armTimeTrap() is idempotent: the window starts on the FIRST render of a
       cycle and survives every re-render; completeSubmit() disarms it.
  → $this->html([...page content...] + $handler->viewContext())
       viewContext: form, fields, errors, formError, checkUrl

blur on a field  (public-form.js, transport only)
  → POST {field, value} + X-CSRF-Token  →  checkAction()
  → blurCheck(definition): declared field? → PublicFormValidator::isValid([$field])
  → {valid, message} → JS toggles error class + hint + aria-invalid

POST /kontakt
  → process()
      CSRF invalid                → formError (friendly re-render)
      honeypot OR isTooFast()     → completeSubmit() → TRUE  (fake success, no mail)
      country blocked (geo guard) → formError — or TRUE like the bot path when silent
      validation failed           → errors + formError banner (form.error.check), values kept
      isRateLimited()             → formError
      onValid($form) ?? sendForm(formKey, ['form'=>$form], replyTo, routeKey)
          true  → recordSend() + disarmTimeTrap() → TRUE  (PRG redirect)
          false → formError

  TRUE is the handler's last word. The controller decides what happens next —
  where to redirect, and whether to push a flash there.

GET /kontakt/danke   ← the PRG target: a page of its own
  → dankeAction()    plain render, no handler, no form state
```

## rules

- When building a public form → MUST declare it as a `FormDefinition` (fields + `formKey()`) and drive it with `PublicFormHandler`; MUST NOT hand-write a per-form DTO, a validator class or the CSRF/honeypot/rate-limit/PRG cascade in the controller.
- When a controller action uses `PublicFormHandler` → MUST treat `process() === true` as "redirect now" and return `$this->redirect(...)` itself; MUST NOT expect a Response from the handler (ADR-003) and MUST NOT distinguish the bot path from a real success.
- When wiring the PRG target → MUST redirect to a thank-you ACTION of its own (a convention route like `/frontend/main/contact/danke` needs no navigation entry); MUST NOT redirect the form page onto itself and MUST NOT let a session flag switch the form template between form and confirmation.
- When a valid submit must do something other than the configured form mail → MUST pass a callback to `process($onValid)` and return `true`/`false` from it; MUST NOT bypass the handler and send from the action.
- When surfacing FAILURE feedback → the handler owns the TEXTS and returns them: per-field `errors` plus a form-level `formError` (`form.error.check` / `form.error.csrf` / `form.error.send`), both through `viewContext()`. Where they appear is the project's call — rendering `formError` inline and/or pushing it as a flash (`pushFlash('error', $state['formError'])`, delivered with the same response) are both fine; showing it twice on one screen is not. MUST NOT re-implement per-project failure texts; override the wording via `withMessageKeys()` or the i18n keys.
- When surfacing SUCCESS feedback → the controller owns it: the thank-you page is the message, and a flash on top of it is an explicit `$this->messageService->pushFlashAfterRedirect('success', t('form.flash.sent'))` next to the redirect. MUST NOT expect the handler to emit messages — it returns state and nothing else, so what the visitor sees is readable in the action.
- When adding a per-field blur endpoint → MUST `use PublicFormCheckTrait` and return `$this->blurCheck($definition)`; MUST NOT add an in-action CSRF check (AccessGuard validates the `X-CSRF-Token` header, CONTACT-CHECK-001) and MUST NOT maintain a separate list of checkable fields.
- When a form needs its own wording for a validation error → MUST put a translation KEY in the field spec's `messages` map and the text in the i18n dictionaries; MUST NOT put a literal there (an unknown key surfaces verbatim) and MUST NOT hard-code German text in a definition.
- When a project template replaces the generic form partial → MUST keep the data-attribute contract (`data-public-form`, `data-check-url`, `data-validate`, `data-form-row`, `data-hint-for="{field}"`, `data-error-class` for a custom error class); MUST NOT couple the framework JS to project CSS class names.
- When adding a form-level hint to the partial (required note, privacy line, …) → MUST gate it on the condition that makes it TRUE for the declaration at hand; MUST NOT print it unconditionally — the partial renders every form in the framework, from one field to a dozen (PUBLIC-FORM-005).
- When a form field needs a rule that does not exist → MUST add it to `PublicFormValidator` plus a `form.error.{rule}` key in every shipped dictionary; MUST NOT smuggle validation into the template, the controller or the definition.
- When installing this into an existing project → MUST add the `form.*` keys to `data/framework/i18n/{lang}.json` by hand — incl. `form.error.check` and `form.flash.sent` (the `*.default.json` are seed-once) — and MUST deploy `public-form.js` into `public/assets/{module}/js/`; MUST NOT assume `composer install` copies assets (it only reports the diff).
- When a form should refuse or observe submits by origin country → MUST opt in via `withGeoGuard()` (silent where the page must stay indistinguishable); MUST NOT build a per-form gate in a flow, a controller or an observer — the handler owns gate AND log, and a second gate would produce a second, disagreeing read of the origin.
- When switching the geo guard on → the country DATA is the developer's per-installation duty (MaxMind account, key in `config/geoip.inc.php`, `geoip-update` job — see the mental-model checklist); MUST NOT assume the switch alone does anything, and MUST add the log's sentence to the installation's privacy policy.
- When the log should carry who submitted → MUST declare it as the definition's `identityField()` (one field, explicit); MUST NOT smuggle identifying values through `withGeoGuard(extra: ...)` — extras are technical facts on every line, the identity is the audited exception.
- When restricting by country → MUST be a blocklist and MUST fail OPEN (empty list, unknown country never block); MUST NOT block on `??`/unknown and MUST NOT build a whitelist — the backend surface does not even offer either.
- When reading which countries are blocked → MUST go through `CountryBlocklist::codes()`; MUST NOT read a config key — the list is installation data, written only on the backend surface (with a mandatory reason).

## known issues

- **PUBLIC-FORM-005 — resolved 2026-08-11.** `partials/publicForm` printed the note
  «Bitte alle Felder ausfüllen» unconditionally, so the member login — one e-mail field
  plus an optional «angemeldet bleiben» checkbox — asked the visitor to fill in *all*
  fields. The note now renders only when there is more than one field AND every one of
  them is mandatory (`required` or `accepted`), which is exactly when the sentence is
  true. A MIXED form gets no note instead of a wrong one; marking the individual
  mandatory fields would be its own feature and is deliberately not in this line.
  Verified live: register (4 mandatory) shows it, login and resend do not.
- **PUBLIC-FORM-004 — rebuilt 2026-07-22.** The confirmation became a page. Before, a
  successful submit redirected the form page onto itself and `FormGuard::markSent()` /
  `consumeSent()` carried a one-shot session flag that made the template render a
  thank-you instead of the form. Nothing of that was visible in the controller — the
  redirect line read as "back to the form" — and the state was unreachable by URL, which is
  also what hid PUBLIC-FORM-003 for a while. Now the PRG target is a thank-you ACTION
  (`thanksAction`, project: `dankeAction` at the convention route
  `/frontend/main/contact/danke` — no navigation entry needed), the flag is gone from
  `FormGuard` and `sent` is gone from `viewContext()`. Removed with it: the `$sent` branch
  in `publicForm.tpl.php` (and the project's), `.fe-form__done` styles.

  Same pass, one step further (owner call): the handler no longer pushes the success
  flash either. An opt-out (`withoutSuccessFlash()`) was the wrong shape — a default that
  acts invisibly, cured by a magic word you only know from reading the framework. A flash
  is a UI decision and belongs where the redirect is, so `confirmSuccess()`,
  `successFlashKey` and the `successFlash` parameter of `withMessageKeys()` are gone and a
  project that wants one writes the `pushFlashAfterRedirect()` line itself. The boundary
  is now: **the handler returns state (`viewContext()`) and emits nothing.** Failure texts
  stay with it — they travel through the return value, visible in the data flow.
  `form.flash.sent` stays in the dictionaries as the ready text for that one-liner.

  Verified over HTTP: form → blur check → field error → corrected resubmit → send; bot
  path redirects to the thank-you URL without a flash; the thank-you page renders
  standalone in `de` and `fr`; `/kontakt` afterwards shows the form with no ghost
  confirmation.
- **PUBLIC-FORM-003 — fixed 2026-07-22.** The time-trap swallowed corrected submits.
  `armTimeTrap()` restarted the window on EVERY render, including the re-render after a
  validation error — so a visitor who fixed the flagged field and pressed Send within the
  3-second window was classified as a bot, and the bot reaction is a SILENT fake success:
  thank-you page, no mail, no trace (locally reproducible: submit without the privacy tick,
  tick it, resubmit → 302 + confirmation, no send attempt in the log). `armTimeTrap()` is
  now idempotent (a running window is kept) and `completeSubmit()` disarms it, so the window
  means "time since the form was first handed out" and every re-render inherits it. Same
  pass: `process()` no longer consumes the PRG `sent` flag on a POST — a rejected submit
  showed a pending confirmation instead of its own error. Verified over HTTP against the
  zihlundsee form: corrected fast resubmit reaches the send stage; direct POST without a
  render, honeypot, and a genuine sub-3s first submit still fake-succeed; CSRF failure after
  a pending confirmation shows the error and keeps the flag for the next GET.
- **PUBLIC-FORM-002 — built 2026-07-21, success half REVERSED 2026-07-22 (see
  PUBLIC-FORM-004).** The `formError` banner below is current; the automatic success flash
  is not — the handler no longer pushes anything, a project pushes its own flash beside the
  redirect. Kept for the record: Standard form feedback via the shared
  {@see MessageService}, backend-consistent: `PublicFormHandler` pushes a `success` flash
  (`form.flash.sent`, `pushFlashAfterRedirect`) on every confirmed/bot-faked submit — rendered
  by the module `flashMessages` partial on the PRG page, same channel as the backend "Sie sind
  eingeloggt" — and sets a form-level `formError` banner (`form.error.check`) on validation
  failure beside the per-field errors (so the reason is visible without scrolling). Wording
  overridable per form via `withMessageKeys()`; keys added to `de/fr.default.json`. Existing
  projects: add `form.error.check` + `form.flash.sent` to `data/framework/i18n/{lang}.json` by
  hand (seed-once) — zihlundsee done. Verified via a bootstrapped render harness against the
  real zihlundsee templates (both keys resolve; top banner + inline field error render
  together); the live PRG flash rides the same session-backed channel proven by the cyon
  delivery run. Frontend flash has no JS (no auto-dismiss / close-button action) — a static
  banner, acceptable per Rule 7. Open: owner visual pass on the live flash.
- **PUBLIC-FORM-001 — built 2026-07-20.** Framework building blocks + reference form per
  [`../03-development/public-form-bauplan.md`](../03-development/public-form-bauplan.md);
  zihlundsee migrated in the same pass (ContactForm/ContactFormValidator/contact-form.js/
  the project mail body deleted, controller 161 → 92 lines). Verified with an 87-check CLI
  harness (DTO normalization, rule matrix incl. per-rule messages and fr texts, blur ==
  submit, the full handler cascade against fakes, generic partial + mail body rendering incl.
  HtmlToText) plus a live run of the zihlundsee form (render, blur 400/valid/invalid,
  invalid submit re-render, honeypot and time-trap fake success, send-failure path). Open:
  visual pass by the owner and the reference form in a browser — there is no installed
  skeleton on the dev box, so `module-frontend`'s own `/contact` was verified by rendering
  its templates in the harness, not through HTTP.
- Don't assume the generic mail body reproduces a hand-written one: it lists every declared
  non-textarea field as a table row and every textarea as a paragraph, with no headline
  (the subject carries that). A project that needs a different mail keeps its own template.
- Don't assume error texts exist after a framework update: `de/fr.default.json` are
  installer seeds (seed-once). A project that misses the `form.*` keys renders the key
  itself as the error message.
- Only `de` and `fr` dictionaries ship. A project running `en` falls back to the default
  language for every `form.*` text.
- `Translator::t()` HTML-escapes placeholder values (`replacePlaceholders`), so a label
  containing `&` or `<` would be double-escaped by the template's `e()`. Harmless for
  normal labels, but don't put markup in a label.

## pending

- Visual pass by the owner over the zihlundsee contact page (panels, spacing, blur hints,
  confirmation) — the markup was rewritten onto `$fields`/data-attributes.
- Verify the `module-frontend` reference form over HTTP once a skeleton installation exists
  on the dev box (currently only harness-rendered).
- Consider a `phone`/`tel` format rule — the reference form only length-checks the number.
- No `en.default.json` exists; add one when a project ships English.
- `Request` has no client-ip accessor, so the handler reads `REMOTE_ADDR` itself — at ONE
  place (`PublicFormHandler::origin()`), deliberately. A clean seam
  (`Request::getClientIp()` incl. reverse-proxy/trusted-header handling) is its own
  kernel change; when it exists, that one call site moves onto it.
- **FORM-LOG-001 — `FormLog` writes records into `logs/`, which is a log directory.**
  `FormLog::DIR = 'logs'` (`logs/form-YYYY-MM.jsonl`) holds submitted enquiries — they are
  records: a restore must bring them back, and today they do, because `logs/` is in the
  `full` archive. That is the only reason the misfiling has been harmless. It surfaced on
  2026-09-01 while deciding ADR-035: `logs/` was to move under `var/`, a tree defined as
  «may be deleted at any moment» and excluded from the archive as a whole — the enquiries
  would have dropped out of every backup, silently. `logs/` therefore stayed a top-level
  shared directory and the FHS-correct `var/log` was rejected for it.
  The clean fix is to move `FormLog` under `data/framework/forms/` (a record belongs to
  `data/`, ADR-034's first category) and migrate the existing `form-*.jsonl` in axo3 and
  zihlundsee. Own change, own risk, deliberately not folded into ADR-035. Until then:
  `logs/` MUST NOT be moved under `var/`, and this entry is the reason.

## see also

- [`mail.md`](mail.md) — `EmailService::sendForm()` is what the handler calls; recipients, subject and routing per form key live there (config + backend override)
- [`security.md`](security.md) — `FormGuard` mechanics, the CSRF contract (`#[Csrf]` vs. in-action) and why the blur endpoint needs no in-action check
- [`geoip.md`](geoip.md) — the country lookup the geo guard asks (database, licence duties, `geoip-update` job, per-installation setup)
- [`backend.md`](backend.md) — `FormLogController` (Service → Formular-Protokoll): the surface that shows the form log and edits the country blocklist
- [`jobs.md`](jobs.md) — `form-log-cleanup` (operator-switched, deletes) and `geoip-update` (scheduled, licence duty): the two jobs behind the guard
- [`view-layer.md`](view-layer.md) — how the partial is resolved override-first, and the template helpers (`e()`, `t()`) the form templates rely on
- [`translation.md`](translation.md) — `t()` with `{$placeholder}` params and where the dictionaries live (backend editor included)
