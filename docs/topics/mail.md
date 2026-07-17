# mail

2026-07-17

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

## rules

- When sending mail → MUST build a `Message` and pass it to `Mailer::send()` (`Mailer::create()`); MUST NOT hand-assemble MIME or open an SMTP socket outside `SmtpTransport`.
- When putting any user/data-derived text into a header (subject, display name, attachment filename) → MUST route it through `Message`/`Attachment` (which reject CR/LF and sanitise); MUST NOT concatenate raw input into a header line.
- When mailing a document → MUST go through `DocumentService::send()` (it enforces the `DocumentKind::mailable()` policy + reads bytes via `BlobStorage`); MUST NOT read the blob and build the attachment in a controller.
- When mail might be unconfigured → MUST treat `Mailer::send()` throwing `RuntimeException` as expected (surface it as a flash); MUST NOT assume `config/mail.inc.php` exists or `enabled = true`.
- When adding a transport → MUST implement `MailTransport::send(string $sender, array $recipients, string $data)` and assert reply codes; MUST NOT trust the visible `To:`/`From:` headers for the envelope (use the `MimeMessage::build()` envelope).
- When sending a form/notification mail from app code → MUST go through `DI::getEmailService()` (`sendForm()` with an emailConfig form key, or `send(EmailMessage)`); MUST NOT hardcode recipient addresses in controllers/services — they live in the project's emailConfig override.
- When passing user input into a mail → MUST hand it to the template context (templates escape via `e()`); the only user-controlled header is Reply-To (validated, silently dropped when invalid); MUST NOT feed user input into subjects, recipients, or template paths.
- When a form mail fails → MUST treat `sendForm() === false` as the normal failure path (generic user message; cause is in `getLastErrors()` + `logs/php-error.log`); MUST NOT let a transport/config problem escalate to a 500 on a public form.

## known issues

- **MAIL-SERVICE-001 — resolved 2026-07-17.** The «mail is finalised LAST» reservation
  (2026-06-29) is closed: EmailService v1 built per
  [`../03-development/email-service-bauplan.md`](../03-development/email-service-bauplan.md)
  (EmailService / EmailMessage / HtmlToText / PhpMailTransport / `Mailer` transport switch /
  emailConfig / `emails/layout` / DI registration / installer `writeMailConfig()` seed-once).
  Verified 2026-07-17: 33-check e2e harness against a fake transport (real project templates +
  configs — multipart, RFC 2047 subject, Reply-To handling incl. injection attempt dropped,
  plain-text derivation, attachments, `mail()` arg splitting with Bcc), plus the live
  zihlundsee contact-form submit exercising the graceful `false` path (no local MTA on the
  Windows dev box — expected). Real delivery check happens on cyon (pending). The
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

- **cyon go-live check (acceptance 1+2 of the bauplan):** deploy, submit the zihlundsee
  contact form on the real host, confirm delivery (recipient + Reply-To + readable plain
  text), then remove the dead `logFallback` path in the project's `ContactMailer` (bauplan
  acceptance 4).
- Manual check: configure a real SMTP relay in `config/mail.inc.php` (`transport='smtp'`, `enabled = true`) and send a document from the backend `documents` UI.
- Phase 7 (integration): a module example (Fakturen) that generates a PDF → `saveGenerated()` → `DocumentService::send()`.
- v2 (after EmailService v1): backend-editable form-mail settings — file-persistence entity
  overriding `emailConfig` (config stays seed/fallback, `navigation.default.json` pattern),
  editor under the backend «Service» section. The formKey→settings resolver in `EmailService`
  is the single attachment point.
- Re-add `RUNTIME=/skeleton/config/mail.inc.php` to the file map after the next clean-install
  regenerates `skeleton/` (installer seeds it now).

## see also

- [`documents.md`](documents.md) — `DocumentService::send()` is the first consumer; the DMS façade owns the mailable-document policy
- [`../03-development/dokumentenverwaltung-bauplan.md`](../03-development/dokumentenverwaltung-bauplan.md) — OPEN-5 (own SMTP build) + the Phase-6 plan
