# ADR-053 — Mail outbox: queued mails, one throttled job, attachments by reference

**Status:** `[APPROVED]` — owner decisions 2026-10-22 (mail plan step 2); built the same day,
the owner's live look is open (`docs/topics/mailing.md` pending)
**Date:** 2026-10-22
**Builds on:** [ADR-031](adr-031-job-queue-and-cron-runner.md) (job queue and runner),
[ADR-039](adr-039-doctrine-driver-behind-unified-entity-manager.md) (Doctrine, module migrations,
registries by config key), [ADR-052](adr-052-backend-dashboard-registry.md) (dashboard registry),
the fragment mount («ADR-018 pattern» in the host controllers: logic and templates in the module, a thin host controller in module-backend)

---

## Context

Customers send about 500 e-mails, at most once a week. The old system sent them in batches —
10 mails, one minute's wait, the next 10 — and that worked with the providers (owner
2026-10-22: «Wir brauchen einen Job, der den E-Mail-Versand überwacht»). Two later steps need the
same base: (3) documents (offer, order confirmation, delivery note, invoice) by e-mail in batches
to the billing address, (4) a newsletter to selected contacts with an unsubscribe link.

The kernel mail stack sends ONE mail synchronously (`Mailer::send()`, `EmailService::send()`); its
known issues said «a bulk/queue sender is out of scope». 500 synchronous sends in a request time
out, hammer the SMTP server and leave no record of what went out or failed.

## Decision

1. **A module of its own, `z77/module-mailing`** (`Z77\Module\Mailing`, key `mailing`): Doctrine
   entity `OutboxMail` (table `mail_outbox`, migration `Version20261022170000`), the write side
   `OutboxService`, the job `mail-outbox`, the screen Service › Postausgang (fragment, host in
   module-backend), a dashboard contributor. Headless: no routes, no view area.
2. **One row per recipient, written once.** `OutboxService::queueBatch()` validates every draft
   first (the kernel `Message` rules through a throwaway `Message`), then writes all rows in ONE
   transaction under one `batch_id`; nothing of a refused batch is written. A body template is
   RENDERED AT QUEUE TIME (`EmailService::renderBody()`, new public method), so the row holds
   exactly what goes out and what the screen shows; `template` keeps the reference. The service
   never sends.
3. **The sender is the installation's identity** (`config/client/mail.inc.php`, filled in by the
   `Mailer`), as for the form mails. A draft has no From.
4. **One job, throttled by `JobResult::again()`.** `mail-outbox` sends up to `batchSize` due mails
   per slice and returns `again(cursor, notBefore: pauseSeconds)` while mails wait, `done` when the
   outbox is empty; it never sleeps. Per mail: success → `sent`; a row `Message` refuses →
   `failed` at once; any other error (transport, an attachment provider) → `queued` with
   `next_attempt_at` from `retryMinutes`, `failed` after `maxAttempts`. A row still `sending` at
   the start of a slice (the job lock is held, so its process died) becomes `failed` with a
   question — a second unasked delivery is worse than asking the operator.
5. **No schedule; the job is queued when there is work.** `OutboxService` enqueues the job after
   every write that adds work (unless an entry is open); the screen offers «Versand starten» when
   mails wait without one. An idle schedule would print on every pass, and cron mails output.
6. **State transitions are conditional UPDATEs.** `… SET state = ? WHERE id = ? AND state = ?`
   in `OutboxMailRepository`, the answer = «one row changed». The row's state is the lock: the
   job's claim (`queued → sending`) and the operator's cancel cannot both win. No version column
   (the content never changes).
7. **Attachments by reference, resolved at send time.** A row stores `type:id` references
   (`invoice-pdf:123`). A module registers an `AttachmentProvider` per type under its config key
   `mailAttachments` (type => class), or a project through the extension file
   `App/Config/mailAttachmentsConfig.inc.php` — the registry pattern of `dashboard` /
   `openWorkChecks`. `accepts(id)` is asked at queue time, `resolve(id): Attachment` at send time;
   a throw there is a retry. A type declared by two classes is refused.
8. **Settings at ONE place:** module config `outbox` (`batchSize` 10, `pauseSeconds` 60,
   `maxAttempts` 3, `retryMinutes` [5, 15, 60]); a project records only its deviation in
   `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php`, merged key by key.
9. **The screen never renders a mail's markup into the backend page.** Subject, addresses and
   errors are escaped text; the text half is escaped `<pre>`; the HTML half is an
   `<iframe sandbox="" srcdoc="…">` (escaped attribute, empty sandbox: opaque origin, no script,
   no form, no navigation of the backend; `referrerpolicy="no-referrer"`).

## Reasoning

- The job runner already owns locking, time budget, retries of a crashed slice and the
  heartbeat; `again(notBefore)` is exactly the old «10, wait a minute» without a sleep that would
  hold the lock and burn the pass (jobs.md rule).
- Rendering at queue time makes the outbox a record: what the screen shows is what was sent,
  even when a template changes later. A newsletter of 500 rows × a few KB is no storage concern.
- References instead of stored PDFs keep the outbox small and module-agnostic (Rule 9): the
  outbox never imports debtor or order, and the document is produced as it is at sending.
- Conditional UPDATEs are the smallest correct guard for a two-writer race (job vs. operator)
  on a status column; a lock table or optimistic versions would protect content that never
  changes.
- `srcdoc` + an empty `sandbox` shows the mail as the recipient sees it without letting its
  markup into the backend DOM (Rule 7); escaped-text-only would hide what the mail looks like.

## Consequences

- Step 3 (document mails) = a provider per document type in the document module + a send action
  that builds drafts (`withAttachments(['invoice-pdf:42'])`, `withSource('invoice:42')`) and calls
  `queueBatch()`. Step 4 (newsletter) = drafts per contact with `withSource('newsletter:<id>')`;
  the unsubscribe link is the newsletter's job (its template), not the outbox's.
- While the outbox sends, every runner pass executes a slice and prints a line (runner
  behaviour); a project whose cron mails its output sees one mail per pass — discard stdout in
  the cron line if that is unwanted (mailing.md known issues).
- An installation without the module keeps the synchronous kernel paths unchanged.
- Existing projects: add the PSR-4 entry, `composer update` (module + navigation entry),
  `php vendor/bin/z77-db migrate`.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Send synchronously in the request, in a loop | Times out at a few hundred mails, no record, no retry |
| `sleep(60)` between batches inside one job run | Holds the job lock and burns the runner's pass; jobs.md forbids it |
| A `defaultSchedule` (`every:1m`) instead of queueing on write | Prints on every pass (cron mail per minute) and runs a slice even when nothing waits |
| Store the attachment bytes in the row | 500 × a PDF in the database; the document as of queueing, not of sending; couples the outbox to every document module |
| Render the template at send time | The screen could not show what went out; the context (objects) is not serialisable |
| Optimistic `version` column for the state | Protects content that never changes; a conditional UPDATE says the same in one statement |
| Show the HTML half by inserting it into the page | Injects foreign markup and styles into the backend (Rule 7) |
