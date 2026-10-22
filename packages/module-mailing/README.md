# z77/module-mailing

The **mail outbox** (step 2 of the mail plan, owner decisions of 2026-10-22). E-mails are queued,
never sent in the request; the `mail-outbox` job sends them in throttled batches — 10, a minute's
pause, the next 10 — with retries and backoff. The shared base of the document mails and the
newsletter that follow. Depends on `z77/kernel` (the mail stack, the job runner) and
`z77/persistence-doctrine` (ADR-039). Developed in the
[z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo (`packages/module-mailing`);
not yet a split target or on Packagist.

- **`OutboxService`** — `queue()` / `queueBatch()` (all drafts validated first, one transaction, one
  batch id), `retry()`, `cancel()`, `counts()`. Never sends; queues the job instead.
- **`OutboxMail`** (Doctrine, table `mail_outbox`) — one row per recipient: the rendered mail, the
  attachments as `type:id` references, `source` and `batchId`, the delivery state.
- **`MailOutboxJob`** (`mail-outbox`) — one slice = up to `batchSize` due mails, then
  `JobResult::again(…, notBefore: pauseSeconds)` while mails wait.
- **Attachment providers** — a module registers `mailAttachments` → `type => class` implementing
  `AttachmentProvider`; the job resolves the references at send time.
- **Settings** — module config `outbox` (`batchSize` 10, `pauseSeconds` 60, `maxAttempts` 3,
  `retryMinutes` [5, 15, 60]); a project overrides through `outboxConfig.inc.php`.
- The sender is the installation's identity from `config/client/mail.inc.php`, as for the form mails.

Backend: Service › Postausgang, `/backend/service/outbox/list` (fragment `Ui/OutboxControllerTrait`,
host in module-backend). Harness: `tests/module-mailing.php`. Topic: `docs/topics/mailing.md`.
