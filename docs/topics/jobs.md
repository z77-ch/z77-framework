# jobs

2026-08-07

## entry

1. `packages/kernel/shared/src/Jobs/JobRunner.php` — one cron pass: schedules → queue → execution
2. `packages/kernel/shared/src/Jobs/JobResult.php` — the return type that carries both throttling and slicing
3. `packages/kernel/bin/z77-run` — the single cron entry per installation

## file map

SOURCE=/packages/kernel/shared/src/Jobs/Job.php
SOURCE=/packages/kernel/shared/src/Jobs/JobContext.php
SOURCE=/packages/kernel/shared/src/Jobs/JobResult.php
SOURCE=/packages/kernel/shared/src/Jobs/JobOutcome.php
SOURCE=/packages/kernel/shared/src/Jobs/JobRunner.php
SOURCE=/packages/kernel/shared/src/Jobs/JobQueue.php
SOURCE=/packages/kernel/shared/src/Jobs/JobLock.php
SOURCE=/packages/kernel/shared/src/Jobs/JobSchedules.php
SOURCE=/packages/kernel/shared/src/Jobs/ScheduleExpression.php
SOURCE=/packages/kernel/shared/src/Jobs/BackupJob.php
SOURCE=/packages/kernel/shared/src/Entities/JobRun.php
SOURCE=/packages/kernel/shared/src/Entities/JobSchedule.php
SOURCE=/packages/kernel/bin/z77-run
SOURCE=/packages/kernel/core/cron/run.php
SOURCE=/packages/kernel/core/src/Services/ModuleManager.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Service/JobController.php
SOURCE=/packages/module-backend/res/view/templates/Service/JobController/listAction.tpl.php
SOURCE=/packages/module-member/src/Jobs/MemberCleanupJob.php
SOURCE=/packages/kernel/shared/src/GeoIp/GeoIpUpdateJob.php

RUNTIME=/skeleton/data/framework/routing/navigation.json

## mental model

Cron calls `vendor/bin/z77-run` once a minute — one line per installation, everything else configured in the application. The runner finds the project by walking UP from the working directory; where the host's cron panel takes one command and no `cd` (cyon), the installer-seeded `cron/run.php` is the entry — it sits physically in the project, `chdir()`s into it and hands over (in the release layout it is called through the `current` switch, see [`release-structure.md`](../01-handbook/release-structure.md)). A pass boots `Bootstrap::__construct()` + `pullUpServices()` (no request, no router, no session), turns due schedules into queue entries, works the queue until its time budget is spent, and exits. An idle pass exits SILENTLY (since 2026-08-28): cron mails every run that produces output, so only a pass that executed, queued, reclaimed or pruned something — or hit a problem — prints; proof that the cron runs is the heartbeat, not a mail. A job returns `JobResult::again($cursor, $notBefore)` to continue later; the same value covers throttling (delay in the future) and slicing (delay zero). Execution happens ONLY in the runner — the backend queues, it never runs.

- **One entry gets at most one slice per pass.** `again()` is the job stating it is finished for this pass; restarting it would spin hundreds of no-op slices.
- **The cursor is opaque.** An offset, a last-seen id, a batch number — only the job knows. The runner stores it verbatim.
- **The time budget is advisory.** PHP cannot interrupt a job without `pcntl`, so `JobContext::hasTimeLeft()` is a question the job must ask itself.
- **Two locks, different holding times.** Short on the queue file (one writer), long per job key (a mailing and a backup run at the same time). `maxParallel` caps concurrency, default 3.
- **`flock()`, never a state field.** An OS lock dies with its process, so a crashed run frees it. `JobRun::state = running` is display and crash detection only.
- **A queue entry stores a KEY, not a class or a script path** — resolved against `ModuleManager::getJobs()`, so a backend form can only pick from what a module offered.
- **`AuthRole::CRON_JOB` is an identity, not a gate** (realm `AuthUser::REALM_CRON`). It authorizes nothing: whoever starts the runner can already do anything the process can.
- **The heartbeat is the operator's proof.** Without `data/framework/jobs/last-pass.json` being fresh, a missing cron line and an idle queue look identical on screen.

## flow

```text
php vendor/bin/z77-run
  1. project root (getcwd() upwards, or --project=)
  2. new Bootstrap()            → config, DI, DEBUG, timezone, CANONICAL_BASE_URL
  3. pullUpServices()           → modules, i18n, translation, persistence, mail
  4. reclaim abandoned          → 'running' + no lock held → back to 'queued', attempts++
  5. [claim lock] seed schedules from defaultSchedule, enqueue the due ones
  6. loop while budget and maxParallel allow:
       [claim lock] pick a due entry not yet handled this pass
       → take jobs/{jobKey}.lock, or skip
       → state = running, startedAt
       → Job::run(JobContext)          [job lock held]
       → done   : finish
          again : store cursor, availableAt = now + notBefore, back to queued
          failed: attempts++, backoff, or failed at maxAttempts
       → release the job lock
  7. prune finished entries, write the heartbeat, exit 0
```

## registering a job

```php
// in a module config
'jobs' => [
    'member-cleanup' => [
        'class'           => MemberCleanupJob::class,
        'label'           => 'Member-Bereinigung',
        'runAs'           => AuthRole::CRON_JOB,
        'maxAttempts'     => 3,
        'payload'         => [],          // merged UNDER the entry's payload
        'defaultSchedule' => 'daily@03:15',  // omit for anything that deletes
    ],
],
```

## schedule expressions

| Form | Meaning |
|---|---|
| `every:15m` / `every:2h` | interval measured from the last run |
| `hourly@:20` | every hour at minute 20 |
| `daily@03:15` | every day at 03:15 |
| `weekly@mon,03:15` | every Monday at 03:15 |

Only `every:` consults the last run; the wall-clock forms do not. Deliberately not cron syntax — see ADR-031 for the reasoning.

## config — `config/jobs.inc.php` (optional, seed-once)

`maxParallel` (3) | `timeBudget` (50 s) | `staleAfter` (900 s) | `keepRuns` (50)

## rules

- When writing a job → MUST implement `Z77\Shared\Jobs\Job`, MUST be constructible with no arguments and no HTTP context, and MUST tolerate being called again with the cursor it returned
- When a job may run longer than the time budget → MUST check `JobContext::hasTimeLeft()` and return `JobResult::again($cursor)`; a job that never asks overruns the pass and only the job lock limits the damage
- When a job needs to pause between batches → MUST express it as `JobResult::again($cursor, $notBefore)`; MUST NOT `sleep()` inside the job (it holds its lock and burns the pass)
- When registering a job → MUST declare it under a module's `jobs` key with a unique key (duplicate = fail-fast); MUST NOT put a class name or script path into a queue entry
- When a job deletes data → MUST NOT ship a `defaultSchedule`; the operator switches it on. ⚠️ This is about the INSTALLATION's data, not a job's own downloaded artefact: `geoip-update` replaces the file it fetched itself and does ship a schedule, because keeping it current is a licence obligation. A duty that waits to be switched on is not a duty being met — a job that both deletes and must run is two jobs (the split `member-cleanup` / `geoip-update` is the worked example)
- When guarding against a double start → MUST use the job lock (`JobLock`); MUST NOT rely on `JobRun::state`, which survives a crashed process
- When storing anything about a run → MUST put it in the queue entry or a lock file under `data/framework/jobs`; MUST NOT put transient runtime state into `systemConfig` (a restore would resurrect it)
- When the backend triggers a job → MUST enqueue and let the runner execute it; MUST NOT run a job inside the request
- When a job needs more rights than the cron default → MUST raise `runAs` in the module config, MUST NOT bypass an ACL inside the job
- When the host's cron panel cannot `cd` → MUST call the seeded `cron/run.php` (through `current` in the release layout); MUST NOT reach the project as `--project=…/current/..` — POSIX resolves the symlink component first, so that path names `releases/`, and the failure surfaces as a job-lock mkdir error far from the cause
- When a data or full backup is taken → MUST keep `data/framework/jobs` excluded (`BackupService::DATA_EXCLUDES`); it moves while the archive is written (backup.md BACKUP-JOBS-001)

## see also

- [`../02-decisions/adr-031-job-queue-and-cron-runner.md`](../02-decisions/adr-031-job-queue-and-cron-runner.md) — the binding decisions and the rejected alternatives
- [`../02-decisions/adr-028-cli-entry-point.md`](../02-decisions/adr-028-cli-entry-point.md) — why the trigger is CLI and not a URL
- [`bootstrap.md`](bootstrap.md) — `pullUpServices()`, the HTTP-free half the runner boots
- [`backup.md`](backup.md) — the three backup jobs and why the job directory is excluded from archives
- [`member.md`](member.md) — `member-cleanup`, the first ported job
- [`packaging.md`](packaging.md) — PKG-005: a new binary needs `composer update` in the project, not just a deploy

## known issues

- **JOBS-001**: don't assume the runner can stop a job at its deadline — it cannot. `pcntl` is absent on shared hosting, so the budget is cooperative. A job ignoring `hasTimeLeft()` runs to completion and delays the next pass for its own key only.
- **JOBS-002**: don't assume `JobRun::state = running` means a process is alive. It survives a fatal, a kill and a reboot. The job lock is the evidence; `JobQueue::abandoned()` requires both an old `startedAt` AND no held lock.
- **JOBS-003**: don't assume a backend click runs a job. It queues one. On an installation without the cron line nothing is ever picked up — which is what the heartbeat banner on the job screen exists to reveal.
- **JOBS-004** — resolved 2026-08-08. The job list's action controls (queue / set schedule / toggle) overlapped: they sat in a `.be-tree__row` of a `.be-tree--hub` container, which is an explicit 6-column grid (`1rem 2.4rem 1.6rem …`), so each form auto-placed into a narrow icon column. Action rows are plain flex divs now; the `remove`/`retry` forms that DO belong into a row carry `grid-column: 6`. Same defect and same fix as the import screen ([`import.md`](import.md) IMP-005). Verified by a DOM audit over both templates (no `form`/`select` under a `.be-tree__row` without an explicit column).

## pending

- Existing projects do not get the «Jobs» navigation entry automatically (navigation data is seed-once, node id 28). The fix is BUILT (ADR-032): backend → Service → Import → «Framework-Standarddaten» proposes the missing entries; assign the keyless containers once, accept, done — see [`import.md`](import.md). Manual creation via the navigation UI remains the fallback on projects whose framework predates the import screen (chicken-and-egg: the «Import» entry itself is seed-once).
- `JobSchedules::enqueueDue()` moves a schedule on even when the previous entry is still open, so a run that is permanently stuck silently skips its slots. Acceptable while the job screen shows the open entry; revisit if a "missed run" report is ever wanted.
