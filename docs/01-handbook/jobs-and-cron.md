# Jobs and cron — background work

**Status:** `[CURRENT]` — job system, cron entry, shipped jobs, mail outbox, AI key setup documented
**Last updated:** 2026-11-19

---

Everything that runs outside a request (mail sending, cleanups, backups, PDF recognition) is a **job**, driven by **one cron line per installation**. The backend only *queues* jobs; execution happens only in the runner. Details: [`jobs.md`](../topics/jobs.md), [ADR-031](../02-decisions/adr-031-job-queue-and-cron-runner.md).

## The cron entry

One line, every minute. Two forms, pick by what the host's cron panel can do:

```text
* * * * *  php vendor/bin/z77-run                 # the panel can `cd` into the project
* * * * *  php /path/to/project/cron/run.php      # the panel takes one command and no `cd`
```

- `vendor/bin/z77-run` finds the project by walking **up** from the working directory (or `--project=<path>`). `cron/run.php` is seeded by the installer into the project; it `chdir()`s and hands over.
- Release layout ([`release-structure.md`](release-structure.md)): call it **through** `current` — `<domain>/current/cron/run.php`. Never `--project=…/current/..` (POSIX resolves the link first and names `releases/`).
- Never wire the cron to the copy inside the kernel package — that is only the template.
- Flags: `--list` (registry and queue overview), `--enqueue=<job-key>` (queue one job by hand).
- A healthy pass exits **silently**; only a failed job, a schedule problem or a boot failure prints (STDERR), so cron mails only on trouble.
- Proof that the cron runs: the heartbeat `data/framework/jobs/last-pass.json`; the job screen warns when it is stale.

Optional tuning in `config/jobs.inc.php` (seed-once; it deliberately sits at this flat path, see [`jobs.md`](../topics/jobs.md)): `maxParallel` (3), `timeBudget` (50 s), `staleAfter` (900 s), `keepRuns` (50).

## Registering a job

A module (or the project's override of a module config) declares it under `jobs`:

```php
'jobs' => [
    'member-cleanup' => [
        'class'           => MemberCleanupJob::class,   // implements Z77\Shared\Jobs\Job, no-arg constructor
        'label'           => 'Member-Bereinigung',
        'runAs'           => AuthRole::CRON_JOB,
        'maxAttempts'     => 3,
        'payload'         => [],                        // merged under the queue entry's payload
        'defaultSchedule' => 'daily@03:15',             // omit for anything that deletes
    ],
],
```

Schedule forms: `every:15m`, `every:2h`, `hourly@:20`, `daily@03:15`, `weekly@mon,03:15`, `monthly@1,06:00` (day 1–28). Not cron syntax.

Rules that matter when an AI writes a job ([`jobs.md`](../topics/jobs.md) `## rules`):

- A job checks `JobContext::hasTimeLeft()` and returns `JobResult::again($cursor, $notBefore)` to continue later — **never `sleep()`**.
- A job that **deletes installation data ships no `defaultSchedule`**; the operator switches it on. Three documented exceptions (JOBS-SCHED-001), each a legal or promised duty.
- A queue entry stores the job **key**, never a class or script path; keys are unique (a duplicate fails fast).
- An override of a module config replaces the file whole — carry the `jobs` entries along.

Operator screen: backend **Service › Jobs** (schedule switch, «Zeitplan setzen / ändern …», ▶ «Jetzt starten», queue and history). An existing project gets the navigation entry through Service › Import › «Framework-Standarddaten» ([`import.md`](../topics/import.md)).

A project's **own** job: see [`customize-business-module.md`](customize-business-module.md).

## Shipped jobs

| Key | Module | What it does | Default schedule |
|---|---|---|---|
| `backup-data`, `backup-db`, `backup-full` | backend | Backup of data / database / whole project ([`backup.md`](../topics/backup.md)) | none |
| `import-apply` | backend | Applies the current import plan (queued from the import screen) | none |
| `form-log-cleanup` | backend | Cleans the form log (deletes) | none |
| `password-reset-cleanup` | backend | Removes expired and used reset entries (deletes) | none |
| `stats-rollup` | backend | Folds raw web statistics into monthly aggregates, deletes raw after 7 days | `daily@04:40` |
| `stats-report-mail` | backend | Mails last month's statistics report (recipients: form key `statsReport`) | none (suggest `monthly@1,06:00`) |
| `geoip-update` | backend | Renews the GeoIP database (licence duty) | `weekly@mon,04:20` |
| `mail-outbox` | mailing | Sends queued mails in throttled batches | none — queued on write |
| `member-cleanup` | member | Deletes never-confirmed accounts older than `cleanupAfterDays` | none |
| `dms-chunk-cleanup` | dms | Removes leftovers of chunked uploads | none |
| `dms-zip-prepare` | dms | Builds a ZIP bundle, sliced across passes | none — queued per bundle |
| `dms-zip-cleanup` | dms | Removes expired ZIP manifests and leftovers (privacy duty) | `daily@03:50` |
| `creditor-intake` | creditor | Reads the intake folder, recognises invoices marked «Erkennung ausstehend» | `every:1m` |
| `debtor-bank-link` | debtor | Links camt.054 entries to their camt.053 collective credit | `every:1m` |

Keys, labels and schedules live in `jobs` of each module config (`packages/*/src/App/Config/*Config.inc.php`). A seeded schedule belongs to the operator afterwards; an update never switches it back on.

## Mail outbox (module-mailing)

Mail in numbers (document mails, later newsletters) is never sent in a request. A caller queues drafts through `OutboxService::queue()` / `queueBatch()`; the `mail-outbox` job sends them. Details: [`mailing.md`](../topics/mailing.md), [`mail.md`](../topics/mail.md), [ADR-053](../02-decisions/adr-053-mail-outbox-and-attachment-providers.md).

**Sending account** — one file, edited by hand, no screen: `config/client/mail.inc.php` (seed-once):

```php
return [
    'enabled'     => true,
    'transport'   => 'smtp',               // 'mail' (PHP mail(), shared hosting) | 'smtp' | 'file' (dev: .eml files)
    'fromAddress' => 'office@example.ch',  // must match the sending domain (SPF/DKIM/DMARC)
    'fromName'    => 'Muster GmbH',
    // smtp only: host, port, encryption ('tls' 587 | 'ssl' 465 | 'none'), username, password
];
```

The file is client-owned and lives on the server; do not commit real SMTP credentials. For development use `'transport' => 'file'` and read the `.eml` files in `data/framework/mail/outbox/`.

**Queue and failure behaviour** (table `mail_outbox`, one row per recipient):

- States: `queued` → `sending` → `sent`; after an error back to `queued` with a backoff, `failed` after `maxAttempts`; `cancelled` by the operator.
- Defaults (module config `outbox`): `batchSize` 10, `pauseSeconds` 60, `maxAttempts` 3, `retryMinutes` [5, 15, 60]. A project deviates in `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php`.
- A mail `Message` refuses (invalid address) fails at once; a mail still `sending` at the start of a slice is marked `failed` («Versand unterbrochen …») and never re-sent automatically.
- Mailer not configured → the job waits 15 minutes, rows untouched.
- Screen **Service › Postausgang** (`/backend/service/outbox/list`, ADMIN): tabs Wartend · Gesendet · Fehlgeschlagen · Alle; «Erneut senden» (failed), «Abbrechen» (queued), «Versand starten».
- Attachments are references (`invoice-pdf:123`) resolved at send time by a provider registered under `mailAttachments`.

## AI key (KI-Assistent and creditor recognition)

Both use the package `z77/kibot` and **one shared key file**. There is no environment variable: the config holds only the **path** to a file that contains the Anthropic API key.

```php
// config/client/kibot.inc.php
return [
    'paths'      => ['keyFile' => '/home/<hosting>/.z77/<project>/keys/anthropic.key'],  // no default
    'creditorAi' => ['enabled' => true],   // creditor PDF recognition only; default off
];
```

- **Where the secret lives:** a plain file outside `public/` **and** outside the repository. Never in a tracked file, log or widget config.
- **Backend «KI-Assistent»:** on when `z77/kibot` is installed, `config/client/kibot.inc.php` exists and `paths.keyFile` is readable; otherwise the shell shows the classic «Hilfe». See [`optional-modules.md`](optional-modules.md) and [`kibot.md`](../topics/kibot.md).
- **Creditor PDF recognition (AI step):** `creditorAi.enabled` is the *release* (the installer's decision); within it the owner toggles «KI-Erkennung» next to «PDF hochladen». Further keys: `model`, `effort`, `maxTokens`, `maxPdfBytes`, `maxPerDay` (50), `timeoutSeconds`, `serverSideFallback`. Recognition runs only in the `creditor-intake` job (it needs `proc_open()` for `pdftotext`, which web PHP may forbid), so the cron must be running. Before switching on, `AiInvoiceReader::PRIVACY_NOTE` belongs in the installation's privacy notes. Details: [`creditor.md`](../topics/creditor.md) (CREDITOR-RECOG-WEB-001, CREDITOR-AI-SWITCH-001).
