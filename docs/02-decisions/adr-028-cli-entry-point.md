# ADR-028 — CLI Entry Points: One Composer-bin Binary per Task

**Status:** `[APPROVED]`
**Date:** 2026-07-16

---

## Context

The BackupService needs a cron trigger. Until now the framework had **no CLI
entry point at all** — everything runs through `public/index.php` (HTTP). Cron
work could be triggered three ways: a token-gated URL, a generic console
framework (`bin/console` with subcommands, Symfony-style), or a small dedicated
binary per task exposed via Composer's `bin` mechanism.

## Decision

1. CLI tasks get a **dedicated binary per task**, shipped in the owning package
   under `bin/` and exposed via Composer `bin` — the first one is
   `vendor/bin/z77-backup` (kernel).
2. A CLI binary boots **only what it needs** — for backup that is the Composer
   autoloader plus a plain `require` of its config file. No `Bootstrap`, no
   router, no session, no HTTP auth context.
3. The **project root** is resolved from the working directory (walking
   upwards) or an explicit `--project=` option — never from the script's own
   location, because in monorepo development `vendor/z77/kernel` is a path-repo
   symlink and would resolve to the framework instead of the project.
4. Authorization model: **shell access = permission**. Whoever can execute PHP
   against the project files can read them anyway; a CLI login ritual would add
   ceremony, not security. (`AuthRole::CRON_JOB` remains reserved for
   HTTP-triggered automation, which this ADR deliberately avoids.)

## Reasoning

- A URL trigger would push long-running work through the web server (timeouts,
  web-reachable secrets, token management) — the weakest option for backups.
- A generic console framework is infrastructure without a second consumer:
  one task does not justify command registration, kernel-boot layering, and a
  new dependency (YAGNI). If the framework ever carries a handful of CLI
  tasks, promoting them into one console binary is a mechanical refactor.
- Composer `bin` gives every project the same stable call
  (`php vendor/bin/z77-backup …`) without installer work, on shared hosting too
  (cyon cron: `cd /path/to/project && php vendor/bin/z77-backup data`).
- Boot-what-you-need keeps cron runs independent of HTTP-only services
  (session, router, request parsing) and fast to start.

## Consequences

- Services that CLI binaries consume must stay constructible without the HTTP
  bootstrap (plain constructor arguments; `BackupService::fromProjectRoot()` is
  the pattern).
- Each new CLI task ships its own `bin/` script in the owning package and adds
  it to that package's composer `bin` list.
- Cron documentation lives with the feature (docs/topics/backup.md), not in a
  central CLI handbook — there is no central CLI.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Token-gated URL trigger | Long-running work over HTTP (timeouts), web-reachable secret, token lifecycle to manage — all avoidable by not using HTTP |
| Generic `bin/console` framework | One consumer today; command registry + full boot layering is overengineering (YAGNI). Revisit when several CLI tasks exist |
| Booting the full `Bootstrap` in CLI | Pulls HTTP-only services (router, session, request) into a context that has none; slow and fragile for cron |
