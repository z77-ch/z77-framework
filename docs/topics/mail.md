# mail

2026-07-18

## entry

1. `packages/kernel/shared/src/Mail/EmailService.php` — the app-facing façade (`DI::getEmailService()`): `sendForm()` / `send(EmailMessage)` / `getLastErrors()`; renders templates, derives plain text, maps onto `Message`
2. `packages/kernel/shared/src/Mail/Mailer.php` — the transport façade: reads `config/mail`, builds the configured transport (`mail`|`smtp`), holds the default sender, sends a `Message`; built on demand via `Mailer::create()`
3. `packages/kernel/shared/src/Mail/SmtpTransport.php` — the dependency-free SMTP client (the only place that talks the wire protocol)
4. `packages/kernel/shared/src/Config/emailConfig.inc.php` — app-level defaults (subjectPrefix, replyTo, `forms` map); projects override the whole file at `override/z77/shared/src/Config/emailConfig.inc.php`

## file map

SOURCE=/packages/kernel/shared/src/Mail/Message.php
SOURCE=/packages/kernel/shared/src/Mail/Attachment.php
SOURCE=/packages/kernel/shared/src/Mail/MimeMessage.php
SOURCE=/packages/kernel/shared/src/Mail/MailTransport.php
SOURCE=/packages/kernel/shared/src/Mail/SmtpTransport.php
SOURCE=/packages/kernel/shared/src/Mail/PhpMailTransport.php
SOURCE=/packages/kernel/shared/src/Mail/Mailer.php
SOURCE=/packages/kernel/shared/src/Mail/EmailService.php
SOURCE=/packages/kernel/shared/src/Mail/EmailMessage.php
SOURCE=/packages/kernel/shared/src/Mail/HtmlToText.php
SOURCE=/packages/kernel/shared/src/Config/emailConfig.inc.php
SOURCE=/packages/kernel/shared/src/Entities/EmailFormSetting.php
SOURCE=/packages/kernel/shared/src/Repositories/EmailFormSettingRepository.php
SOURCE=/packages/kernel/shared/src/Validators/EmailFormSettingValidator.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/EmailSettingsController.php
SOURCE=/packages/module-backend/res/view/templates/Service/EmailSettingsController/listAction.tpl.php
SOURCE=/packages/module-backend/res/view/templates/Service/EmailSettingsController/edit.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/emails/layout.tpl.php
SOURCE=/packages/kernel/core/src/Config/mail.default.inc.php
SOURCE=/packages/module-dms/src/Images/DocumentKind.php
SOURCE=/packages/module-dms/src/Services/DocumentService.php

## mental model

The framework's own e-mail capability (DMS Phase 6, ADR-016 / OPEN-5 — built in-house, no
Composer mail dependency). Four layers: a {@see Message} value object (fluent recipients /
subject / bodies / {@see Attachment}s), a {@see MimeMessage} builder (Message → RFC 5322 /
MIME wire bytes), a {@see MailTransport} interface with two implementations
({@see SmtpTransport} = the SMTP conversation; {@see PhpMailTransport} = PHP `mail()` over
the local MTA for shared hosting like cyon), and the {@see Mailer} façade that wires config →
transport and sends. Consumers: `DocumentService::send()` (attaches a document's blob bytes)
and the **EmailService** on top.

**EmailService** (the final mail-service step, 2026-07-17 — see
[`../03-development/email-service-bauplan.md`](../03-development/email-service-bauplan.md))
is the app-facing façade for template mails: an {@see EmailMessage} builder carries intent
(`to/from/cc/replyTo/subject`, `template(tpl, ns, context)` OR `text()` for plain mails,
`attach(absPath, name)`); the service renders the body template inside the shared
`emails/layout` (both override-first via FileFinder), derives the plain-text alternative
with {@see HtmlToText} (template contract: `<tr data-str="new-line">` / closing block tags →
line breaks), maps everything onto `Message` and sends via `Mailer::create()`.
`sendForm(formKey, context, replyTo)` resolves recipient/subject/template from the
override-first `emailConfig`; the resolver is the single attachment point for the planned
v2 backend-editable settings entity.

- **Config-driven, fail-loud-when-absent.** `Mailer::create()` reads `config/mail.inc.php`
  (`getBaseConfig('config/mail', throwError: false)`; installer-seeded seed-once with
  `enabled=true`, `transport='mail'`, empty `fromAddress` to fill per project). Missing file
  or `enabled = false` → the mailer is *unconfigured* and `send()` throws a clear
  `RuntimeException` instead of failing deep in the socket layer. `isConfigured()` lets the
  UI warn up front.
- **Two error contracts.** `Mailer::send()` throws (consumers surface it, e.g. DMS flash).
  `EmailService::send()`/`sendForm()` return `false` + `getLastErrors()` and `error_log` the
  cause — a public form submit must never 500 on a transport/config problem. Programmer
  errors still throw there: unknown form key, missing template.
- **The only user-controlled header is Reply-To** (form sender): `EmailService` validates it
  with `filter_var` and silently drops it when invalid. Everything else (recipients, from,
  subject) comes from config; `Message` rejects CR/LF regardless.
- **Injection is closed at the VO boundary, not at build time.** `Message` rejects CR/LF in
  the subject and any non-RFC address on the way in (throws), and `Attachment` strips
  control chars + path from the filename. So a crafted display name / filename can never
  smuggle extra headers or recipients.
- **MIME structure follows the content:** text-only → `text/plain`; text+html →
  `multipart/alternative`; any body + attachments → `multipart/mixed` wrapping the body
  part. Bodies + attachments are base64 (76-col wrapped), CRLF throughout; non-ASCII
  subjects / display names are RFC 2047 B-encoded.
- **Envelope ≠ headers.** `MimeMessage::build()` returns `sender` + `recipients` (drives
  `MAIL FROM` / `RCPT TO`) separately from the `data` blob, so Bcc recipients are in the
  envelope but never in the visible headers.
- **SMTP dot-stuffing lives in the transport,** not the builder: `SmtpTransport` doubles a
  leading `.` on any DATA line. With base64 bodies this rarely triggers, but it is correct
  for any payload.
- **Encryption modes:** `tls` (plain connect + `STARTTLS` upgrade + re-`EHLO`, port 587),
  `ssl` (implicit TLS from connect, port 465), `none` (plaintext — local relay / tests).
  `AUTH LOGIN` runs only when a username is configured.
- Not DI singletons (placement decision B, like `DocumentService`): `Mailer` is built
  on demand via `create()`; the transport is injectable for isolated testing.

## flow

```text
DocumentService::send(id, recipients, subject, body, variant?)
  → get(id) (live) → DocumentKind::mailable() gate
  → BlobStorage::get(id, variant, ext)         (the bytes)
  → Message(from=config, to=recipients, subject, text) + Attachment(originalName, mime, bytes)
  → Mailer::create()->send(Message)
       → fill default From (config) if unset
       → MimeMessage::build(Message) → {sender, recipients, data}
       → transport 'smtp': SmtpTransport::send(sender, recipients, data)
            220 → EHLO → [STARTTLS → EHLO] → [AUTH LOGIN] → MAIL FROM → RCPT TO* → DATA(dot-stuffed) → QUIT
         transport 'mail': PhpMailTransport::send(sender, recipients, data)
            split data at first CRLFCRLF → extract To/Subject (mail() writes those itself)
            → leftover envelope recipients → Bcc header → mail(to, subject, body, headers, -f sender)
```

```text
Controller → DI::getEmailService()->sendForm('contactForm', ['form' => $form], $form->getEmail())
  → resolveFormSettings('contactForm')       emailConfig forms map (override-first, Z77\Shared)
  → EmailMessage: to/subject(+subjectPrefix)/template from config, replyTo = call parameter
  → send(EmailMessage)
      → render body tpl (FileFinder, override-first) into emails/layout → $html
      → HtmlToText → $text                    (or text-only: EmailMessage::text(), no template)
      → Message: addresses validated (throws→false), replyTo filter_var'd (invalid→dropped)
      → Mailer::create()->send(Message)
  → true | false + getLastErrors() (+ error_log)
```

## developer setup — configuring email sending

End-to-end checklist to make mail work in a project (query anchor: «how do I
configure email sending / a contact form»). Each step links to the section or
topic that owns the detail. Deep architecture is in «mental model» / «flow».

### A. once per project — sender identity + transport

`config/mail.inc.php` (base config, installer-seeded seed-once, hand-editable —
NOT `emailConfig`, and NOT per-form; the sender is one installation identity):

| Key | Meaning |
|---|---|
| `fromAddress` / `fromName` | the From — MUST match the sending domain (SPF/DKIM/DMARC), set once per project. Empty → send fails with a clear error. `Mailer` fills it into any `Message` that has no own From |
| `transport` | `'mail'` (PHP `mail()` over the local MTA — cyon) or `'smtp'` (then fill `host`/`port`/`encryption`/`username`/`password`) |
| `enabled` | `true` to send; `false` → `Mailer::send()` throws the "not configured" error |

### B. per form mail — add a form key

1. **Declare the form** as a `FormDefinition` (fields + `formKey()`) and drive it
   with `PublicFormHandler` — that is the standard path, and it calls `sendForm()`
   for you. → [`forms.md`](forms.md)
2. **Body template:** the generic `['emails/publicForm', 'Z77\\Shared']` renders
   whatever the definition declares — only write an own
   `res/view/templates/partials/emails/{key}.tpl.php` when the mail must look
   different (escape with `e()`; use `<tr data-str="new-line">` rows / closing
   block tags so the plain-text alternative derives cleanly, see «mental model»).
3. **Declare the key** in the project's `emailConfig.inc.php` override `forms`:
   `template` (required), `to` (developer test inbox — see «seed-address
   convention»), `subject`, optional `cc` / `routes`.
4. **Sending** happens inside the handler. Only when a mail is NOT a declared
   public form (dynamic recipients, a backend action) call the service directly —
   never hand-assemble mail:

   ```php
   DI::getEmailService()->sendForm('{key}', ['form' => $form], $replyTo);
   // optional 4th arg — subject/option-driven routing:
   //   ..., routeKey: $validatedChoice
   ```

### C. public-facing form — hardening

| Concern | Do | Owner |
|---|---|---|
| Bot / rate limit | `FormGuard::forKey('{key}')` — `armTimeTrap()` on render, `isTooFast()` + `isRateLimited()` on submit, `disarmTimeTrap()` when the submit completed | [`security.md`](security.md) |
| Honeypot | hidden fields on the form DTO; on trip pretend success, send nothing | [`security.md`](security.md) |
| CSRF | in-action `CsrfService::validate` (friendly re-render) OR the `#[Csrf]` attribute; a JS `fetch` MUST send the `X-CSRF-Token` header | [`security.md`](security.md) |
| Page cache | disable it for the form controller (module `cache.controllers.{segment}.enabled = false`) — the CSRF token + per-user form state must never be shared | [`cache.md`](cache.md) |

### D. go-live / handover

1. Set the **production recipient** in the backend (Service → E-Mail) — it overrides the config dev address.
2. Check: no form still runs on origin «Config» with a dev address (visible in the settings list).
3. Verify real delivery on the host (cyon; the local dev box has no MTA → always the graceful `false` path, so delivery is untestable locally).

### E. operator, ongoing (no deploy)

Backend **Service → E-Mail** (`EmailSettingsController`, [`backend.md`](backend.md)):
edit to/cc/subject/routes per form key, active toggle (temporarily disable an
override without losing it), reset (back to the config seed).

## form-mail settings v2 (backend-editable, 2026-07-18)

Recipients, CC, subject and recipient **routing** per form key are operator-
editable in the backend (Service → E-Mail, `EmailSettingsController`, role
ADMIN) — built per
[`../03-development/email-settings-v2-bauplan.md`](../03-development/email-settings-v2-bauplan.md)
(owner decisions E1–E4 recorded there).

- **Resolution (single point: `EmailService::resolveFormSettings`):** the form
  key + body template MUST exist in `emailConfig` `forms` (throw otherwise —
  templates/keys are code). to/cc/subject/routes come from the backend
  {@see EmailFormSetting} record when one exists **and is active** (wins
  **completely**), else from config (config = seed/fallback,
  `navigation.default.json` pattern). «Zurücksetzen» in the UI deletes the
  record → config applies again.
- **Active flag (`EmailFormSetting::active`, default true):** an override applies
  only while active. Toggling it off (the list switch, like the navigation
  `active` toggle) keeps the record but makes resolution fall back to config —
  the operator can disable an override temporarily (e.g. during a form change)
  without losing the entered recipients. The list shows the effective state:
  origin «Backend» (active) / «Backend (inaktiv)» (dormant → config) / «Config»
  (no record).
- **Routing:** `sendForm(formKey, context, replyTo, routeKey)` — the controller
  passes a **server-validated form option value** as `routeKey`; it picks a
  `routes` map entry that replaces the recipients and optionally the subject.
  Unknown key → defaults + error_log. User input only ever selects a key from
  the server-defined map — never an address, never subject text.
- **Kundenstamm seam (v3):** recipient entries are literal addresses in v2; the
  reserved `ref:{source}:{id}` format (e.g. `ref:customer:1042`) is rejected by
  the validator on save and dropped (error_log) at send time.
  `EmailService::resolveRecipients()` is the single method v3 extends.
- **Storage:** `data/framework/mail/emailFormSettings.json`
  (`#[Entity('file', …)]`, collection mode, `invalidatesCache: false` — mail
  settings never render into cached pages). Multiple recipients are first-class
  lists; v1 config comma-strings keep working (normalized on read).
- **No recipient resolvable** (empty config `to` + no entity, or only `ref:`
  entries) → normal `false` failure path + `getLastErrors()`, not a throw.

### seed-address convention (config `to` = developer test inbox)

The config `forms[*].to` is the **developer's world**, the backend override is
the **operator's world** — do not collapse them by seeding the client's real
address in config:

- **Config `to` = a deliverable, developer-controlled address** (e.g.
  `webmaster@{project-domain}`). The developer is responsible that it exists
  (mailbox or forwarding) — otherwise pre-launch delivery tests on the staging
  host (cyon; the local dev box has no MTA and always takes the graceful `false`
  path) prove nothing. It must NOT be the client's production recipient — every
  dev/staging test would otherwise mail the client before launch, and a project
  that is never configured in the backend would silently send production mail to
  a stale config address.
- **Production recipient lives in the backend override**, set at handover.
- **Go-live check:** no form may go live with origin still «Config» on a
  developer address — the `EmailSettingsController` list shows the origin
  («Config» vs. «Backend») + effective recipient per key, so this is a visible,
  checkable state, not a hidden one.
- Deliberately a **convention, not a mechanism** (no framework enforcement — the
  address's existence is operational/DNS, outside the app). A DEBUG-only global
  redirect (`mail.redirectAllTo`, reroute every outgoing mail to one dev inbox
  regardless of `to`) is the structural alternative that would let config `to`
  be the real recipient from day one — deferred until a second consumer justifies
  it (see pending).

## rules

- When sending mail → MUST build a `Message` and pass it to `Mailer::send()` (`Mailer::create()`); MUST NOT hand-assemble MIME or open an SMTP socket outside `SmtpTransport`.
- When putting any user/data-derived text into a header (subject, display name, attachment filename) → MUST route it through `Message`/`Attachment` (which reject CR/LF and sanitise); MUST NOT concatenate raw input into a header line.
- When mailing a document → MUST go through `DocumentService::send()` (it enforces the `DocumentKind::mailable()` policy + reads bytes via `BlobStorage`); MUST NOT read the blob and build the attachment in a controller.
- When mail might be unconfigured → MUST treat `Mailer::send()` throwing `RuntimeException` as expected (surface it as a flash); MUST NOT assume `config/mail.inc.php` exists or `enabled = true`.
- When adding a transport → MUST implement `MailTransport::send(string $sender, array $recipients, string $data)` and assert reply codes; MUST NOT trust the visible `To:`/`From:` headers for the envelope (use the `MimeMessage::build()` envelope).
- When a public form should send its mail → MUST declare it as a `FormDefinition` and let `PublicFormHandler::process()` call `sendForm()` ([`forms.md`](forms.md)); MUST NOT call `sendForm()` from a hand-written form cascade in the controller.
- When sending a form/notification mail from app code → MUST go through `DI::getEmailService()`. Recipient ownership (owner decision 2026-07-18, review-email-service-usage.md §2): **static, operator-defined recipients** (contact-form class) → `sendForm()` + emailConfig form key (backend-editable in v2); **dynamic, data-driven recipients** (mail to a user, an entity owner) → `send(EmailMessage)` with `->to()` in the controller. MUST NOT hardcode a static operator recipient in a controller.
- When setting a sender → MUST leave From to the installation identity (`config/mail.inc.php`, SPF/DKIM/DMARC-bound); the per-mail "sender" is Reply-To. `EmailMessage::from()` stays the exception for verified same-domain identities (a From control system is planned — see pending).
- When passing user input into a mail → MUST hand it to the template context (templates escape via `e()`); the only user-controlled header is Reply-To (validated, silently dropped when invalid); MUST NOT feed user input into subjects, recipients, or template paths.
- When a form mail fails → MUST treat `sendForm() === false` as the normal failure path (generic user message; cause is in `getLastErrors()` + `logs/php-error.log`); MUST NOT let a transport/config problem escalate to a 500 on a public form.
- When routing a form mail by a user choice → MUST pass a server-validated option value as `sendForm()`'s `routeKey` (it selects an entry of the server-defined `routes` map); MUST NOT derive recipients or subject text from user input directly.
- When reading form-mail settings anywhere → MUST go through `EmailService::sendForm()` (entity-first resolution); MUST NOT read `emailConfig` `forms` directly in app code — a backend override would be silently ignored. (The `EmailSettingsController` list is the one legitimate direct reader — it displays both tiers.)
- When declaring a form key in `emailConfig` `forms` → the `to` MUST be a deliverable, developer-controlled test address (e.g. `webmaster@{domain}`), NOT the client's production recipient (which is set in the backend override at handover); the developer MUST ensure that address exists (mailbox/forwarding) on the staging host. Before go-live no form may still show origin «Config» on a dev address (visible in the `EmailSettingsController` list).

## known issues

- **MAIL-V2-001 — built 2026-07-18.** Backend-editable form-mail settings (see «form-mail
  settings v2» section): `EmailFormSetting` entity (incl. `active` flag) +
  `EmailSettingsController` (Service → E-Mail, navigation seed id 27) + entity-first
  resolution (override applies only while active), `routeKey` routing and the `ref:`
  Kundenstamm seam in `EmailService`. Backend layout mirrors navigation/login-user
  (`be-tree--hub` + inline active switch + ⋮ actions hub → edit / confirm-reset). Verified
  via CLI harness (24 checks: config fallback, active override, dormant override → config +
  routeKey ignored, route hit with/without subject, unknown routeKey → defaults, `ref:`
  dropped / only-`ref:` → false, config-route normalization, validator matrix incl. CR/LF
  subject + `ref:` rejection, entity round-trip). Open: visual UI pass by the owner (list,
  active toggle, edit modal incl. route rows, reset). Existing projects: add the «E-Mail»
  navigation entry under Service manually (seed is seed-once).
- **MAIL-SERVICE-001 — resolved 2026-07-17.** The «mail is finalised LAST» reservation
  (2026-06-29) is closed: EmailService v1 built per
  [`../03-development/email-service-bauplan.md`](../03-development/email-service-bauplan.md)
  (EmailService / EmailMessage / HtmlToText / PhpMailTransport / `Mailer` transport switch /
  emailConfig / `emails/layout` / DI registration / installer `writeMailConfig()` seed-once).
  Verified 2026-07-17: 33-check e2e harness against a fake transport (real project templates +
  configs — multipart, RFC 2047 subject, Reply-To handling incl. injection attempt dropped,
  plain-text derivation, attachments, `mail()` arg splitting with Bcc), plus the live
  zihlundsee contact-form submit exercising the graceful `false` path (no local MTA on the
  Windows dev box — expected). Real delivery **confirmed on cyon 2026-07-21** (zihlundsee
  contact form → `webmaster@z77.ch`: recipient + forwarding, Reply-To = visitor address,
  RFC 2047 subject, `multipart/alternative` with readable plain-text alternative, DKIM +
  SPF pass, `PhpMailTransport` via `mail()`). The
  `RUNTIME=/skeleton/config/mail.inc.php` file-map line stays out until the next clean-install
  regenerates `skeleton/` (the linter requires listed paths to exist; the installer now seeds
  the file).
- `PhpMailTransport` relies on the platform mailer honouring `Bcc:` in additional headers
  (sendmail `-t` on Linux does; PHP's win32 SMTP mailer does) — v1 consumers don't use Bcc.
- Live SMTP delivery against a real relay was NOT exercised — there is no SMTP server in the dev env. The full stack (Message → MimeMessage → SmtpTransport conversation + dot-stuffing) IS verified e2e against a loopback fake-SMTP server (2026-06-15, all green), and the unconfigured path throws cleanly. Remaining manual check: configure a real relay and send.
- `SmtpTransport` does no connection pooling / retry and `STARTTLS` uses default peer verification — fine for a transactional "send one document" flow; a bulk/queue sender is out of scope (not planned).
- Long non-ASCII subjects are emitted as a single RFC 2047 encoded-word (no folding) — works with common MTAs; folding is not implemented.
- `DocumentKind::mailable()` excludes `video`/`audio` only (size); everything else is attachable. There is no per-size byte cap on attachments yet — a very large attachable document would build a large message.

## pending

- **DMARC record for zihlundsee.ch (operational/DNS):** the 2026-07-21 cyon delivery passed
  SPF + DKIM but the receiver reported `DMARC_NA` (no DMARC record). Not a blocker — the mail
  was delivered as ham — but a DMARC record hardens deliverability for the `noreply@zihlundsee.ch`
  From. Outside the app (DNS), tracked here as the go-live follow-up.
- Manual check: configure a real SMTP relay in `config/mail.inc.php` (`transport='smtp'`, `enabled = true`) and send a document from the backend `documents` UI.
- Phase 7 (integration): a module example (Fakturen) that generates a PDF → `saveGenerated()` → `DocumentService::send()`.
- **v3 Kundenstamm:** resolve `ref:{source}:{id}` recipient entries against the customer
  master (extend `EmailService::resolveRecipients()`, add the UI picker + lift the
  validator rejection) — see the v2 bauplan's «Bewusst NICHT» section. Blocked until the
  Kundenstamm exists.
- **DEBUG mail redirect (`mail.redirectAllTo`):** reroute every outgoing mail to one
  developer inbox while `DEBUG` is on, regardless of the resolved `to` — the structural
  alternative to the seed-address convention (would let config `to` be the real recipient
  from day one). Deferred (YAGNI): build when a second form/project makes accidental
  pre-launch client mail a real risk. See the «seed-address convention» section.
- From control system (owner note 2026-07-18): a later mechanism guarantees any
  `EmailMessage::from()` is domain-conform (SPF/DKIM/DMARC) — until then From stays the
  `config/mail.inc.php` installation identity.
- Re-add `RUNTIME=/skeleton/config/mail.inc.php` to the file map after the next clean-install
  regenerates `skeleton/` (installer seeds it now).

## see also

- [`forms.md`](forms.md) — the public-form standard: a project declares fields + template, the framework owns validation, the submit cascade and the call into `sendForm()` (see «developer setup» step B)
- [`security.md`](security.md) — `FormGuard` (public-form abuse protection: time-trap, rate limit) + the CSRF contract (`#[Csrf]` / `X-CSRF-Token`) every mail-sending form endpoint needs (see «developer setup» step C)
- [`backend.md`](backend.md) — `EmailSettingsController` (Service → E-Mail): the backend editor for the per-form to/cc/subject/routes override + active toggle («developer setup» step E)
- [`cache.md`](cache.md) — disabling the page cache for a form controller (CSRF token + per-user state must not be shared — «developer setup» step C)
- [`documents.md`](documents.md) — `DocumentService::send()` is the first consumer; the DMS façade owns the mailable-document policy
- [`../03-development/dokumentenverwaltung-bauplan.md`](../03-development/dokumentenverwaltung-bauplan.md) — OPEN-5 (own SMTP build) + the Phase-6 plan
