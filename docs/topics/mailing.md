# mailing

2026-10-23

## entry

1. `packages/module-mailing/src/Outbox/OutboxService.php` — the ONE way into the outbox: `queue()` / `queueBatch()` (validate all, one transaction, one batch id, queue the job), `retry()`, `cancel()`, `counts()`
2. `packages/module-mailing/src/Jobs/MailOutboxJob.php` — the `mail-outbox` job: one slice = up to `batchSize` due mails, then `again(…, notBefore: pauseSeconds)` or `done`
3. `packages/module-mailing/src/Attachments/AttachmentProviders.php` — the attachment-provider registry (`mailAttachments`, type => class), resolved at send time

## file map

SOURCE=/packages/module-mailing/composer.json
SOURCE=/packages/module-mailing/README.md
SOURCE=/packages/module-mailing/src/App/Config/mailingConfig.inc.php
SOURCE=/packages/module-mailing/src/Entities/OutboxMail.php
SOURCE=/packages/module-mailing/src/Repositories/OutboxMailRepository.php
SOURCE=/packages/module-mailing/src/Repositories/OutboxSearch.php
SOURCE=/packages/module-mailing/src/Outbox/OutboxService.php
SOURCE=/packages/module-mailing/src/Outbox/OutboxDraft.php
SOURCE=/packages/module-mailing/src/Outbox/OutboxSender.php
SOURCE=/packages/module-mailing/src/Outbox/OutboxSettings.php
SOURCE=/packages/module-mailing/src/Outbox/InvalidOutboxMailException.php
SOURCE=/packages/module-mailing/src/Outbox/OutboxStateException.php
SOURCE=/packages/module-mailing/src/Attachments/AttachmentProvider.php
SOURCE=/packages/module-mailing/src/Attachments/AttachmentProviders.php
SOURCE=/packages/module-mailing/src/Attachments/AttachmentReference.php
SOURCE=/packages/module-mailing/src/Jobs/MailOutboxJob.php
SOURCE=/packages/module-mailing/src/Dashboard/MailingDashboard.php
SOURCE=/packages/module-mailing/src/Ui/OutboxControllerTrait.php
SOURCE=/packages/module-mailing/src/Ui/OutboxListing.php
SOURCE=/packages/module-mailing/src/Ui/OutboxLayout.php
SOURCE=/packages/module-mailing/res/view/templates/Backend/OutboxController/listAction.tpl.php
SOURCE=/packages/module-mailing/res/view/templates/Backend/OutboxController/toolbar.tpl.php
SOURCE=/packages/module-mailing/res/view/templates/Backend/OutboxController/actions.tpl.php
SOURCE=/packages/module-mailing/res/view/templates/Backend/OutboxController/detail.tpl.php
SOURCE=/packages/module-mailing/res/migrations/Version20261022170000.php
SOURCE=/packages/module-mailing/res/migrations/Version20261023100000.php
SOURCE=/packages/module-mailing/data/framework/routing/navigation.d/module-mailing.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/OutboxController.php
SOURCE=/packages/module-backend/src/Ui/Config/Service/outboxControllerConfig.inc.php
SOURCE=/packages/kernel/shared/src/Mail/EmailService.php
SOURCE=/tests/module-mailing.php
SOURCE=/docs/02-decisions/adr-053-mail-outbox-and-attachment-providers.md

## mental model

The outbox is the shared base for every mail that goes out in numbers (step 2 of the mail plan, owner 2026-10-22; ~500 mails a customer sends once a week): a caller hands `OutboxService` drafts, the service validates and stores them as rows of `mail_outbox` and queues the job; the `mail-outbox` job sends them in throttled batches through the kernel `Mailer` — 10, a minute's pause, the next 10, as the old system did. Nothing is sent in a request. The backend screen Service › Postausgang shows what waits, what went out and what failed, and lets the operator retry or cancel. The decisions are ADR-053.

- **One row per recipient, written once** (`OutboxMail`). The content never changes after queueing; only the delivery columns move (`state`, `attempts`, `last_error`, `sent_at`, `next_attempt_at`).
- **States:** `queued` → `sending` → `sent`; after an error back to `queued` with `next_attempt_at` (backoff), `failed` after `maxAttempts`; `cancelled` by the operator. View «Wartend» = queued + sending.
- **Templates are rendered at QUEUE time** (`EmailService::renderBody()`, body template inside `emails/layout`, override-first) — the row holds what goes out; `template` keeps `Namespace:path` for traceability. A finished HTML (`OutboxDraft::html()`) is stored as given; a text-only draft stores no HTML. An empty `text_body` is derived with `HtmlToText` at SEND time.
- **The sending ACCOUNT is never in a draft** — the `Mailer` fills the From address from `config/client/mail.inc.php` (Service › E-Mail), as for the form mails. Reply-To is per mail (validated).
- **The person signs** (owner 2026-10-23): a mail a staff member triggers carries his SENDER IDENTITY — `OutboxDraft::withSender(SenderIdentity, userId)` (mail.md «sender identities»). The row keeps a SNAPSHOT: `sender_user_id` (the backend user, no foreign key — users are file records), `sender_name`, `sender_email`. At send time `OutboxSender` sets the From display name to `sender_name` (address still the account's) and Reply-To to «sender_name <sender_email>». `queueBatch()` checks the identity with `SenderIdentity::problems()` and refuses a draft that has an identity AND its own Reply-To. A row without identity (`''`) sends as before.
- **Attachments are references** `type:id` (`invoice-pdf:123`), resolved at send time by the provider a module registered for the type. The outbox knows no document module.
- **`source`** (opaque, `invoice:123`, `newsletter:5`) and **`batch_id`** (one `queueBatch()` = one batch, 32 hex) group the rows for the screen and for steps 3 / 4.
- **What went out for a source** (`OutboxMailRepository::deliveriesOf(list<source>)`, step 3, 2026-10-22): plain rows (`to`, `state`, `created_at`, `sent_at`, `batch_id`, `created_by`, since 2026-10-23 `sender` / `sender_email`) per source, one indexed query — how a document module shows «per E-Mail an … gesendet am …» without knowing the entity. Read-only; the state changes stay the transitions'.
- **State changes are conditional UPDATEs** (`OutboxMailRepository`): the row's state is the lock; a cancel and the job's claim cannot both win. Read a row again with `fresh()` after a transition.
- **The job is queued on write, not scheduled.** `OutboxService::ensureJobQueued()` after every write that adds work; no `defaultSchedule` (an idle schedule would print on every pass — cron mails output).
- **Settings in ONE place:** module config `outbox` → `batchSize` 10, `pauseSeconds` 60, `maxAttempts` 3, `retryMinutes` [5, 15, 60]; a project's deviation in `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php` (`OutboxSettings::fromModules()`, merged key by key, clamped).

## flow

```text
caller (step 3: a document send action; step 4: the newsletter)
  → OutboxDraft::html|template|text(to, subject, …)->withAttachments([...])->withSource('invoice:42')
  → OutboxService::queueBatch(drafts, actor)
       problems() per draft (Message rules, attachments: providers->refusal())  → InvalidOutboxMailException, nothing written
       render templates (EmailService::renderBody)
       transaction: persist rows (state queued, next_attempt_at = now, one batch_id)
       ensureJobQueued(actor) → JobQueue::enqueue('mail-outbox') unless an entry is open

cron → vendor/bin/z77-run → JobRunner → MailOutboxJob::run(context)   [job lock held]
  failInterrupted()            rows still 'sending' → failed («Versand unterbrochen …»)
  mailer not configured        → again(notBefore: 900), rows untouched
  dueIds(now, batchSize) → per id, while hasTimeLeft():
       claim (queued → sending, conditional) → Message (to, from-name + reply-to of the identity | reply-to, subject, text|derived, html)
       → attachments: AttachmentProviders::resolve(type:id) → Mailer::send (From from config)
       → markSent | markRetry(next = now + retryMinutes[n]) | markFailed (maxAttempts, or never sendable)
  waiting = 0   → done
  else          → again(totals, notBefore: pauseSeconds, or until the next retry ≤ 300 s)
```

## attachment-provider contract

```php
// module-debtor: debtorConfig.inc.php (step 3)
'mailAttachments' => ['invoice-pdf' => InvoicePdfAttachment::class],

final class InvoicePdfAttachment implements \Z77\Module\Mailing\Attachments\AttachmentProvider
{
    public function accepts(string $id): bool { /* queue time: does invoice $id exist? */ }
    public function resolve(string $id): \Z77\Shared\Mail\Attachment { /* send time: render the PDF */ }
}
```

| Rule | Detail |
|---|---|
| Registration | config key `mailAttachments` (type => class) or extension file `App/Config/mailAttachmentsConfig.inc.php`; type lower-case kebab; no-arg constructor; a type by two classes is refused |
| Queue time | `accepts($id)` false → the draft is refused («Anhang … nicht gefunden»), nothing written |
| Send time | `resolve($id)` returns the kernel `Attachment` (filename sanitised there); a throw = a retry of that mail with the message as `last_error` |

## screen — Service › Postausgang

| Piece | Detail |
|---|---|
| Route | `/backend/service/outbox/list` — host `module-backend` `Service/OutboxController` (`use OutboxControllerTrait`), layout `Service/outboxControllerConfig.inc.php` → `OutboxLayout::config()`; role ADMIN (module default, no deviation) |
| List | standard list `OutboxListing` (state icon → window · ⋮ · Erstellt · Empfänger · Betreff · Quelle · Status · Versuche · Fehler), tabs Wartend · Gesendet · Fehlgeschlagen · Alle (extra `view`), 50 per page, fetch region `outbox-find-list`; action cell empty. «Status» = the state badge, a sent mail with its time («gesendet 23.10. 08:05»); the queue time is «Erstellt», a failure's reason «Fehler» |
| One send run (owner 2026-10-23) | `?batch=<32 hex>` — the PATTERN extra `batch` (listing.md, `OutboxSearch::BATCH_PATTERN`, `m.batch_id = ?` on `idx_mail_outbox_batch`): exactly the mails of one `queueBatch()`. A send action links it as «Postausgang ansehen» through `OutboxListing::batchUrl($batch)` (`view=all`); the list shows the chip «Versand #<first 8 hex>» beside the title, its × a plain page link without the batch; the tabs keep the batch. A value off the pattern narrows nothing. The mount URL lives once: `OutboxListing::MOUNT` (the trait's `outboxBase()` default) |
| Pace text | `OutboxSettings::rateText()` — «10 pro Minute» (`batchSize` per `pauseSeconds`; «20 alle 5 Minuten», «5 alle 30 Sekunden», «50 je Durchgang»): what a send action says after queueing, read from the ONE setting |
| Bands (in the region) | sending not configured · job not queued → green «Versand starten» (`start`, POST) · cron heartbeat older than 15 min |
| ⋮ menu | `actions?id=` → `.be-menu`: «Anzeigen» (window), «Erneut senden» (failed, POST `retry`), «Abbrechen» (queued, POST `cancel`); answers: flash + `refresh-region` |
| Window | `detail?id=` — headers as text («Antwort an» = the identity when one signs; «Absender» = its name with the account's address; «Ausgelöst von» = `created_by` als «Name <email>» (Benutzer-Id n), 2026-10-23), attachments as references, the text half escaped in `<pre>`, the HTML half in `<iframe sandbox="" srcdoc>` |
| Navigation | seed `module-mailing.json`: key `outbox` «Postausgang» under `service`, sort 6 |
| Dashboard | `MailingDashboard`: «E-Mails fehlgeschlagen» (urgent, `?view=failed`), «E-Mails warten seit über einer Stunde» (todo) |

## developer test path

`config/client/mail.inc.php` with `'transport' => 'file'` (mail.md): the job writes every mail as `.eml` into `data/framework/mail/outbox/` (or the configured `outbox`) instead of delivering it. Queue mails, run `php vendor/bin/z77-run` (repeat after a minute for the next batch, or `--enqueue=mail-outbox` when the screen says the job is not queued), open the `.eml` files. `tests/module-mailing.php` section L runs exactly this path.

## rules

- When code needs to send mail in numbers or later (documents, newsletter, notifications to many) → MUST queue drafts through `OutboxService::queue()` / `queueBatch()`; MUST NOT loop over `Mailer::send()` / `EmailService::send()` in a request.
- When building a draft → MUST leave the sending account out (it is the mail config's identity); a mail a person triggers MUST carry his identity via `withSender()` (resolved through `SenderIdentities`, mail.md) instead of a Reply-To; and pass user/data text through the template context or as escaped HTML; MUST NOT put CR/LF into subject or names (refused) or hand-assemble MIME.
- When a module needs files in outbox mails → MUST implement `AttachmentProvider` and register it under `mailAttachments` in its own config (a project: `mailAttachmentsConfig.inc.php`); MUST NOT store attachment bytes or paths in `mail_outbox`, and module-mailing MUST NOT import a document module.
- When changing a row's delivery state → MUST go through a conditional transition of `OutboxMailRepository` (`claim`, `markSent`, `markRetry`, `markFailed`, `cancel`, `requeue`, `failInterrupted`); MUST NOT persist a state change on a loaded entity (a racing writer would be overwritten).
- When the throttle must change → MUST set it in ONE place: module config `outbox`, a project's deviation in `outboxConfig.inc.php`; MUST NOT add a second key elsewhere or `sleep()` in the job.
- When showing a stored mail in the backend → MUST render its HTML only inside `<iframe sandbox="" srcdoc>` (escaped) and everything else as escaped text; MUST NOT insert mail HTML into the page or add an `allow-*` token to the sandbox.
- When a mail is found `sending` at the start of a slice → MUST treat it as interrupted (`failed`, operator decides); MUST NOT re-send it automatically (it may have gone out).

## known issues

- **MAILING-CRON-OUT-001**: don't assume a sending outbox is quiet — every runner pass that executes a slice prints a line (`bin/z77-run`), so a cron that mails its output sends one mail per pass while the outbox works (~50 for 500 mails), and every ≤ 5 min while only retries wait. Discard stdout in the cron line if unwanted; failures still go to STDERR.
- **MAILING-NAME-001**: don't assume the recipient name reaches the header — the kernel `Message` takes bare addresses (`To: anna@…`); `recipient_name` is shown on the screen and kept for the newsletter.
- **MAILING-WAIT-001**: don't assume a mail queued while only retries wait goes out within a minute — the job then waits up to `MailOutboxJob::MAX_IDLE_WAIT` (300 s) between slices.
- **MAILING-PREVIEW-001**: don't assume the HTML preview loads nothing remote — the sandbox blocks scripts, forms and navigation, not images; a mail with a remote image fetches it when the window opens (`referrerpolicy="no-referrer"` keeps the backend URL out). Our own templates carry none.
- **MAILING-CLASSIFY-001**: don't assume a permanent SMTP refusal (5xx) fails at once — transport errors are not classified; every one is retried until `maxAttempts`. Only what `Message` refuses (an invalid address in a row written around the service) fails immediately.

## pending

- **Sender identity (built 2026-10-23, owner decision the same day) — owner's live look in -initial**: (1) System › Benutzer › own user › «Absender für E-Mails»: «+ Absender», × , a bad address → «Absender n: …», save, reopen; (2) booking print → «Alle per E-Mail senden …»: «Senden als» (select with two identities, read-only with one), a user WITHOUT identity sees «Erfasse zuerst eine E-Mail an deinem User.» and no button; (3) `php vendor/bin/z77-run` → the `.eml` in `data/framework/mail/outbox/`: `From: "<Anzeigename>" <fromAddress>`, `Reply-To: "<Anzeigename>" <identity>`, the greeting «Freundliche Grüsse / <Anzeigename> / <Mandant>»; (4) Service › Postausgang › the window: «Ausgelöst von», «Antwort an». Needs `config/client/mail.inc.php` with `fromAddress` set and `'transport' => 'file'`; server :8079.
- **Migration to apply** (framework side built, NOT run on any project DB): `Version20261023100000` (module-mailing — `mail_outbox.sender_user_id`, `sender_name`, `sender_email`) on z77ch_initial first, then z77.ch: `composer update`, `php vendor/bin/z77-db migrate`. Expand only; an older release keeps queueing (defaults).
- **Owner proposal pending (not built)**: refuse or warn when config `fromAddress` is empty — today every outbox mail then fails with «Message has no From address.» and is retried until `maxAttempts` (the screen's band only says «not configured» when the file is missing or `enabled = false`). Options: the confirm pages / the Postausgang band warn, the job pauses like an unconfigured mailer instead of burning attempts.

- **Owner's live look** (not verified in a browser): Service › Postausgang — tabs, bands, the ⋮ menu items by state, the window with the sandboxed preview, «Versand starten».
- **Send run filter — built 2026-10-23 (owner decision of the day), NOT committed, owner's browser look on z77.ch open**: from the booking print's result list «Postausgang ansehen» → Service › Postausgang `?view=all&batch=…`: only that run's mails, the chip «Versand #…» beside the title, × clears it (all mails again), the tabs keep the run, a sent row shows its time beside «gesendet». Harness `tests/module-mailing.php` B5, I9b, I9c, I21; `tests/listing.php` C7.
- **Step 3 — documents by e-mail: BUILT 2026-10-22 for invoice and credit note** (debtor.md «The booking print's result list and documents by e-mail»): the provider `invoice-pdf` (module-debtor, `mailAttachments`), the send action «Alle per E-Mail senden …» on the booking print's result list (one `queueBatch()` per click, source `invoice:<id>`), the mail kinds in Service › E-Mail › Beleg-Mails (mail.md). Still open for offer / order confirmation / delivery note / dunning: a provider per PDF and a send action (the mail kinds are declared already).
- **Owner to confirm (raised 2026-10-22, step 3)**: (1) the default subject «Rechnung {nummer} — {absender}» instead of «Rechnung {nummer}» (mail.md: a subject must not end in a number, KAM_NUMSUBJECT); (2) the booking print no longer opens the combined PDF in a new tab — it lands on the result list, the combined PDF is «Alle als ein PDF» there; (3) the confirm page's eye icon per order = `invoice-preview` (built on the owner's request, order.md).
- **Next session (state 2026-10-22 evening)**: try step 3 in -initial (migrations `Version20261022160000` + `Version20261022170000`, `config/client/mail.inc.php` `'transport' => 'file'`, an e-mail on a debtor's billing address, booking print → «Alle per E-Mail senden …» → `php vendor/bin/z77-run` → `.eml` in `data/framework/mail/outbox/`); then step 4 (newsletter, below).
- **Owner's live look of step 3**: the result list after a booking print, «Alle per E-Mail senden …», the `.eml` files in -initial (debtor.md pending).
- **Step 4 — newsletter** to selected contacts with an unsubscribe link: a newsletter entity, recipient selection, the unsubscribe token and page; drafts with `withSource('newsletter:<id>')`.
- Existing projects (z77.ch-initial first): PSR-4 entry `"Z77\\Module\\Mailing\\": ["override/z77/module/mailing/src/"]`, `composer update`, `php vendor/bin/z77-db migrate` (`Version20261022170000`).
- A list help (`list.help.tpl.php`, HELP-003) — no field needs explaining yet.

## see also

- [`mail.md`](mail.md) — the kernel mail stack the job sends through (`Message`, `Mailer`, the sender from `config/client/mail.inc.php`, `FileTransport` for development, `EmailService::renderBody()`)
- [`debtor.md`](debtor.md) — step 3: the provider `invoice-pdf`, the booking print's result list with «Alle per E-Mail senden …» (one batch per click, source `invoice:<id>`), the history read through `deliveriesOf()`
- [`jobs.md`](jobs.md) — the runner, `JobResult::again(cursor, notBefore)`, the job lock and the heartbeat the screen reads
- [`persistence-doctrine.md`](persistence-doctrine.md) — `doctrineEntities`, module migrations, the registry-by-config-key pattern `mailAttachments` follows
- [`backend.md`](backend.md) — the Service group and the dashboard registry (ADR-052) `MailingDashboard` contributes to
- [`listing.md`](listing.md) — the standard list `OutboxListing` is built on
- [`fetch.md`](fetch.md) — the row ⋮ context menu (MENU-001), windows, `refresh-region`
