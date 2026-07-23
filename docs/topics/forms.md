# forms

2026-07-20

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
SOURCE=/packages/kernel/shared/src/Controller/PublicFormCheckTrait.php
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
- When a form field needs a rule that does not exist → MUST add it to `PublicFormValidator` plus a `form.error.{rule}` key in every shipped dictionary; MUST NOT smuggle validation into the template, the controller or the definition.
- When installing this into an existing project → MUST add the `form.*` keys to `data/framework/i18n/{lang}.json` by hand — incl. `form.error.check` and `form.flash.sent` (the `*.default.json` are seed-once) — and MUST deploy `public-form.js` into `public/assets/{module}/js/`; MUST NOT assume `composer install` copies assets (it only reports the diff).

## known issues

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

## see also

- [`mail.md`](mail.md) — `EmailService::sendForm()` is what the handler calls; recipients, subject and routing per form key live there (config + backend override)
- [`security.md`](security.md) — `FormGuard` mechanics, the CSRF contract (`#[Csrf]` vs. in-action) and why the blur endpoint needs no in-action check
- [`view-layer.md`](view-layer.md) — how the partial is resolved override-first, and the template helpers (`e()`, `t()`) the form templates rely on
- [`translation.md`](translation.md) — `t()` with `{$placeholder}` params and where the dictionaries live (backend editor included)
