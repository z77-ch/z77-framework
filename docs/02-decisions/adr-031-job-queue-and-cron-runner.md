# ADR-031 — Job Queue: One CLI Runner, Cooperative Slicing, Cron Actor

**Status:** `[APPROVED]`
**Date:** 2026-08-07

---

## Context

[ADR-028](adr-028-cli-entry-point.md) gave the framework CLI entry points — one
binary per task, booting only what it needs. Two exist: `z77-backup` and
`member-cleanup.php`. Both are single-shot: cron calls them, they run to
completion, they exit.

Three requirements break that model:

1. **Throttled work.** A mailing sends 20 messages, waits 10 minutes, sends the
   next 20. One process cannot hold that — it would occupy a cron slot for
   hours.
2. **Long work.** A job that outlasts a cron interval must stop and resume where
   it left off, rather than being killed or blocking the next run.
3. **Runtime-enqueued work.** The application decides at request time that
   something must happen later (send a mailing, re-render images). A binary per
   task cannot express "do this once, with this payload".

Additionally each new task currently costs a cron line at the hoster. On shared
hosting the operator often cannot edit those, while a backend screen is always
reachable.

The predecessor framework (wdv-6.2.2) solved this with an HTTP-triggered main
controller guarded by an `AuthRole::CRON_JOB` gate.

## Decision

1. **The trigger stays CLI.** One binary, `vendor/bin/z77-run`, invoked by a
   single cron line per installation. The HTTP trigger is rejected again, on
   the same grounds as ADR-028 (timeouts, web-reachable secret, token
   lifecycle) — and more so here, because queue work is by definition
   long-running.
2. **The runner boots the framework's service layer, not "only what it needs".**
   A generic runner cannot know what an arbitrary job requires. The HTTP-free
   half of `Bootstrap::pullUp()` is extracted into `Bootstrap::pullUpServices()`
   and called by both `pullUp()` (unchanged web behaviour) and the runner. The
   runner stops there: no `Request`, no `Router`, no `SessionManager`, no
   `AccessGuard`, no `Dispatcher`.
   This refines ADR-028 point 2 rather than overturning it — for a single-purpose
   binary like `z77-backup` "boot only what you need" still holds, and those
   binaries are not changed.
3. **Slicing is cooperative, never preemptive.** A job receives a deadline via
   its `JobContext` and returns `JobResult::again($cursor, $notBefore)` when it
   wants to continue later. The runner stores the cursor opaquely and never
   interprets it. The same return value serves both throttling (`$notBefore` in
   the future) and slicing (`$notBefore` = now) — one mechanism, two uses.
   **One entry gets at most one slice per pass.** `again()` is the job saying it
   is done for this pass, and the runner does not overrule it — even when the
   entry is immediately due again and budget is left.
4. **Schedules produce queue entries; they are not a second execution path.**
   A due schedule enqueues one entry, then the queue is the only thing the
   runner executes. Their timing is expressed in four small forms
   (`every:15m`, `hourly@:20`, `daily@03:15`, `weekly@mon,03:15`), not cron
   syntax. A module may ship a `defaultSchedule`, which is seeded ONCE — from
   then on the record belongs to the operator, so switching one off survives
   an update. A job that deletes data ships no default at all.
5. **Two lock levels, so unrelated jobs run in parallel.** A short exclusive
   lock guards the queue file while an entry is read or written
   (`FileStorage::withExclusiveLock()`, already in place). A separate long lock
   per job key is held for the whole execution. A mailing and a backup therefore
   run at the same time, while only ever one process writes the store.
   Concurrency is capped (`maxParallel`, default 3) because shared hosting
   limits simultaneous PHP processes.
6. **`AuthRole::CRON_JOB` becomes an actor identity, not an authorization gate.**
   The runner puts a synthetic `AuthUser` into the `JobContext` so services that
   evaluate ACLs (DMS) or write audit fields have a subject. A job may declare a
   different `runAs` role in its module config. This grants nothing at the CLI
   boundary: whoever can start the runner can already do everything the PHP
   process can. The role is for attribution and for service-internal ACL
   evaluation only.

## Reasoning

- Points 1 and 3 are the same argument from two sides: work that must pause for
  ten minutes has no business holding an HTTP request or a PHP process open.
  Cooperative slicing keeps every runner invocation short and bounded, which is
  what makes a one-minute cron interval safe.
- A preemptive time limit is not implementable in PHP without signals or
  `pcntl` (absent on shared hosting), so the deadline must be advisory and the
  job must honour it. Making that explicit in the `JobContext` API is honest;
  pretending the runner can interrupt a job would not be.
- The cursor stays opaque because the runner has no way to understand every
  job's notion of progress. An offset, a last-seen ID, and a batch number are
  all valid; only the job knows which.
- One slice per entry per pass is not a throughput compromise. A job may work
  for as long as `hasTimeLeft()` says yes; returning before that is its own
  statement that this pass is over. Restarting it anyway produces a tight loop
  of no-op slices — measured at 231 restarts in a 4-second budget before the
  rule was added — each paying a full read-modify-write on the store.
- Point 4 removes an entire class of bugs: with two execution paths, retry,
  locking, logging, and actor handling all need doubling, and the two drift.
- Point 5: a single runner-wide lock would be simpler but serialises everything,
  so one long backup starves every other job for its whole duration. Splitting
  by duration — short for the store, long for the work — buys parallelism
  without ever exposing the store to two writers.
  The lock also has to be a `flock()`, not a state field in the record: an OS
  lock dies with its process, so a crashed run frees it automatically, whereas a
  flag written into JSON survives the crash and blocks that job forever. The
  record's `running` state is therefore display only, never the guard.
- Point 6 finally gives `AuthRole::CRON_JOB` a use without reopening the HTTP
  trigger ADR-028 closed. Framing it as identity rather than permission keeps
  the security story truthful — a CLI process is not restricted by a role.

## Consequences

- `Bootstrap` gains a third public entry point. The split must keep `pullUp()`
  behaviourally identical, so the extraction is mechanical and verified by the
  existing web request path.
- Job classes must be constructible without HTTP context and must tolerate being
  called repeatedly with a cursor. A job that ignores its deadline will overrun
  the runner's budget — the runner logs the overrun but cannot prevent it.
- Anything a job enqueues must be serializable into the payload; passing live
  objects is not possible across runs.
- Completed queue entries must be pruned, or the store grows without bound
  (see [ADR-010](adr-010-file-per-record-storage.md) for the storage trade-off).
- Existing binaries (`z77-backup`, `member-cleanup.php`) keep working. Their
  logic moves into job classes and the binaries become thin wrappers that run
  that job once, synchronously — so the manual call survives without duplicated
  code.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| HTTP-triggered runner with `AuthRole::CRON_JOB` gate (wdv-6.2.2 model) | Rejected in ADR-028 already; queue work is long-running by design, which makes the timeout and secret-exposure objections stronger, not weaker |
| One cron line per job | Does not scale, and on shared hosting the operator often cannot edit cron lines while a backend screen is always reachable |
| Preemptive time limit (kill the job at the deadline) | Needs `pcntl`/signals, unavailable on typical shared hosting; a killed job leaves partial writes with no cursor to resume from |
| Separate execution paths for schedules and queue | Doubles retry, locking, logging and actor handling; the two copies drift |
| Single runner-wide lock | Serialises everything — a three-minute backup starves the mailing for its whole duration |
| `running` state field as the guard instead of a lock | Survives a crashed process and blocks that job forever; an OS lock is released by the kernel |
| Free script path in the job record | Whoever may edit a job entry would execute arbitrary code — the backend form becomes a way in. A key resolved against module config is a fixed list |
| `module/controller/action` as the job target (wdv-6.2.2 model) | `AbstractBaseController::run()` needs `ControllerHandler` (only valid after routing `lock()`) and `MessageService` (needs the session), pulling both into a context that has neither; actions also return a `Response` nobody receives |
| Long-running daemon / worker process | No process supervision on shared hosting; a crashed daemon stays dead until someone notices |
| Cron syntax for schedules | A parser is a few hundred lines with its own edge cases, and its mistakes are silent — `15 3 * * 7` looks right and fires on the wrong day. Four named forms cover what an installation actually schedules and map onto a backend select box; a cron form can be added later as a fifth case |
| Re-applying `defaultSchedule` on every boot | Would switch a schedule back on that an operator deliberately turned off, and silently undo a changed time. Seeding is one-way |
