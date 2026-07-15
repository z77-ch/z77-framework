# mail

2026-06-15

## entry

1. `packages/kernel/shared/src/Mail/Mailer.php` — the façade: reads `config/mail`, holds the default sender, sends a `Message`; built on demand via `Mailer::create()`
2. `packages/kernel/shared/src/Mail/SmtpTransport.php` — the dependency-free SMTP client (the only place that talks the wire protocol)
3. `skeleton/config/mail.inc.php` — the SMTP/sender config the `Mailer` reads (RUNTIME; **created last** — see known issues; absent by design until the mail service is finalised)

## file map

SOURCE=/packages/kernel/shared/src/Mail/Message.php
SOURCE=/packages/kernel/shared/src/Mail/Attachment.php
SOURCE=/packages/kernel/shared/src/Mail/MimeMessage.php
SOURCE=/packages/kernel/shared/src/Mail/MailTransport.php
SOURCE=/packages/kernel/shared/src/Mail/SmtpTransport.php
SOURCE=/packages/kernel/shared/src/Mail/Mailer.php
SOURCE=/packages/module-dms/src/Images/DocumentKind.php
SOURCE=/packages/module-dms/src/Services/DocumentService.php

## mental model

The framework's own e-mail capability (DMS Phase 6, ADR-016 / OPEN-5 — built in-house, no
Composer mail dependency). Four layers: a {@see Message} value object (fluent recipients /
subject / bodies / {@see Attachment}s), a {@see MimeMessage} builder (Message → RFC 5322 /
MIME wire bytes), a {@see MailTransport} interface with the {@see SmtpTransport}
implementation (the SMTP conversation), and the {@see Mailer} façade that wires config →
transport and sends. The first consumer is `DocumentService::send()`, which attaches a
document's blob bytes and mails them.

- **Config-driven, fail-loud-when-absent.** `Mailer::create()` reads `config/mail.inc.php`
  (`getBaseConfig('config/mail', throwError: false)`). Missing file or `enabled = false` →
  the mailer is *unconfigured* and `send()` throws a clear `RuntimeException` instead of
  failing deep in the socket layer. `isConfigured()` lets the UI warn up front.
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
       → SmtpTransport::send(sender, recipients, data)
            220 → EHLO → [STARTTLS → EHLO] → [AUTH LOGIN] → MAIL FROM → RCPT TO* → DATA(dot-stuffed) → QUIT
```

## rules

- When sending mail → MUST build a `Message` and pass it to `Mailer::send()` (`Mailer::create()`); MUST NOT hand-assemble MIME or open an SMTP socket outside `SmtpTransport`.
- When putting any user/data-derived text into a header (subject, display name, attachment filename) → MUST route it through `Message`/`Attachment` (which reject CR/LF and sanitise); MUST NOT concatenate raw input into a header line.
- When mailing a document → MUST go through `DocumentService::send()` (it enforces the `DocumentKind::mailable()` policy + reads bytes via `BlobStorage`); MUST NOT read the blob and build the attachment in a controller.
- When mail might be unconfigured → MUST treat `Mailer::send()` throwing `RuntimeException` as expected (surface it as a flash); MUST NOT assume `config/mail.inc.php` exists or `enabled = true`.
- When adding a transport → MUST implement `MailTransport::send(string $sender, array $recipients, string $data)` and assert reply codes; MUST NOT trust the visible `To:`/`From:` headers for the envelope (use the `MimeMessage::build()` envelope).

## known issues

- **Mail is finalised LAST, as its own service (project decision, 2026-06-29).** The Mailer
  code stack (Message / MimeMessage / SmtpTransport / Mailer) exists from DMS Phase 6, but the
  runtime config `config/mail.inc.php` + durable installer seeding + a real-relay go-live are
  deliberately deferred to the very end of the build. Until then the file is **absent by design**
  (regenerating the skeleton drops it), and that is expected — not a defect: `Mailer::create()`
  treats a missing config as *unconfigured* and `send()` throws a clear `RuntimeException`. The
  `RUNTIME=/skeleton/config/mail.inc.php` line was removed from the `## file map` for now (the
  linter requires listed paths to exist) and is re-added when the mail service is created.
- `config/mail.inc.php` durable installer seeding (a `mailConfig` writer like `writeAuthConfig`) is part of that final mail-service step — pending.
- Live SMTP delivery against a real relay was NOT exercised — there is no SMTP server in the dev env. The full stack (Message → MimeMessage → SmtpTransport conversation + dot-stuffing) IS verified e2e against a loopback fake-SMTP server (2026-06-15, all green), and the unconfigured path throws cleanly. Remaining manual check: configure a real relay and send.
- `SmtpTransport` does no connection pooling / retry and `STARTTLS` uses default peer verification — fine for a transactional "send one document" flow; a bulk/queue sender is out of scope (not planned).
- Long non-ASCII subjects are emitted as a single RFC 2047 encoded-word (no folding) — works with common MTAs; folding is not implemented.
- `DocumentKind::mailable()` excludes `video`/`audio` only (size); everything else is attachable. There is no per-size byte cap on attachments yet — a very large attachable document would build a large message.

## pending

- Installer support: write `config/mail.inc.php` from composer extra (mirror `writeAuthConfig`) so a clean install ships a disabled mail config durably.
- Manual check: configure a real SMTP relay in `config/mail.inc.php` (`enabled = true`) and send a document from the backend `documents` UI.
- Phase 7 (integration): a module example (Fakturen) that generates a PDF → `saveGenerated()` → `DocumentService::send()`.

## see also

- [`documents.md`](documents.md) — `DocumentService::send()` is the first consumer; the DMS façade owns the mailable-document policy
- [`../03-development/dokumentenverwaltung-bauplan.md`](../03-development/dokumentenverwaltung-bauplan.md) — OPEN-5 (own SMTP build) + the Phase-6 plan
