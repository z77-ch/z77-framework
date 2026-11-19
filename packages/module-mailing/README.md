# z77/module-mailing

The **mail outbox** of z77. Business e-mails (invoices, credit notes, dunning notices, quotes,
order confirmations, delivery notes) are never sent inside the request: they are queued in the
outbox and a background job sends them in throttled batches, with retries, a visible state per
mail and a backend screen to watch and correct them. The shared base for the document mails of
the business modules and for the newsletter that is planned next.

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-mailing`); not yet a split target or on Packagist.

## What it does

- **`Outbox\OutboxService`** — `queue()` / `queueBatch()` (every draft validated first, one
  transaction, one batch id), `retry()`, `cancel()`, `counts()`. It never sends; it makes sure
  the job is queued.
- **`Entities\OutboxMail`** (Doctrine, table `mail_outbox`) — one row per recipient: the rendered
  subject, HTML and text, the attachments as `type:id` references, `source`, `batchId`, the
  sender identity («Senden als»: display name + Reply-To), state, attempts, last error.
- **`Jobs\MailOutboxJob`** (`mail-outbox`) — per pass up to `batchSize` due mails, then a pause;
  a failed attempt is retried after `retryMinutes`, after `maxAttempts` the mail is `failed`; an
  invalid address fails at once; a mail found still `sending` after a crash is marked failed and
  never re-sent automatically. Without a configured mail account the job waits and touches
  nothing.
- **Attachment providers** — a module registers `mailAttachments` → `type => class`
  implementing `Attachments\AttachmentProvider`; the job resolves the references at send time
  (the PDF is read when the mail leaves, the text was fixed when it was queued).
- **Dashboard** — `Dashboard\MailingDashboard`: failed mails (urgent) and mails waiting longer
  than an hour.
- **Settings** — module config `outbox`: `batchSize` 10, `pauseSeconds` 60, `maxAttempts` 3,
  `retryMinutes` [5, 15, 60]; a project records only its deviation in
  `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php`.

The sending account (From address, transport, SMTP) is the installation's
`config/client/mail.inc.php`, shared with every other mail of the kernel; the texts of the
document mails are edited in the backend under Service › E-Mail («Beleg-Mails», kernel +
`module-backend`).

## Screens and routes

| Where | What |
|---|---|
| Backend «Service › Postausgang» — `/backend/service/outbox/list` | Tabs Wartend · Gesendet · Fehlgeschlagen · Alle, search and sort, a band when sending is stuck (not configured / job not queued → «Versand starten» / cron not running), per mail a read-only window and the ⋮ «Erneut senden» (failed) / «Abbrechen» (waiting). Fragment `Ui\OutboxControllerTrait`, host controller in `module-backend`. Help: «Hilfe» in the toolbar. |
| `?batch=<id>` | The mails of one send run — what a send action links as «Postausgang ansehen». |

## Requirements

- PHP ≥ 8.4, `z77/kernel` ^1.0 (mail stack, job runner), `z77/persistence-doctrine` ^1.0
  (ADR-039) with a database.
- A cron that runs `vendor/bin/z77-run` every minute.

## Enabling it in a project

`composer require z77/module-mailing` (path repository while it is not on Packagist), then
`composer install` (registers the module, adds the navigation seed «Postausgang») and run the
module's database migrations (`res/migrations/`) with `php vendor/bin/z77-db migrate`. Configure the
mail account in `config/client/mail.inc.php`; on shared hosting use SMTP submission.

## Not built yet

- **Newsletter** (step 4 of the mail plan): one mail to many recipients, unsubscribe.
- A band for an empty `fromAddress` (the «not configured» band only checks the mail config as a
  whole).
- Split repository and Packagist registration.

## Documentation

- Topic: [`docs/topics/mailing.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/mailing.md);
  the mail stack, document mail texts and sender identities:
  [`docs/topics/mail.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/mail.md)
- Harness: `tests/module-mailing.php`

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
