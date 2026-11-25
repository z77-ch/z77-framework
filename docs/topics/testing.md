# testing

2026-11-25

## entry

1. `tests/run.php` — the ONE entry for every harness: groups, `--changed`, side by side, per-section timing, watchdog, tree guard, summary table, `result.json`
2. `tests/groups.php` — the group map (area → harnesses) and the runner's settings; every harness must be in a group
3. `tests/support/HarnessCheckpoint.php` — `--from=<SECTION>` for the long DB harnesses (`module-debtor`, `financial-vat-return-ledger` and `financial-vat-return-posting` have checkpoints)

## file map

SOURCE=/tests/run.php
SOURCE=/tests/groups.php
SOURCE=/tests/support/runner/HarnessCatalog.php
SOURCE=/tests/support/runner/HarnessProcess.php
SOURCE=/tests/support/runner/TreeGuard.php
SOURCE=/tests/run-selftest.php
SOURCE=/tests/support/HarnessCheckpoint.php
SOURCE=/tests/support/real-flow-server.php
SOURCE=/package.json

## mental model

A harness is a plain script — `tests/<name>.php` (PHP CLI) or `tests/<name>.mjs` (node) — that prints one line per check and ends with a summary line and an exit code. The runner starts the selected harnesses as separate processes, up to `--jobs` at once, longest first (by the durations of earlier runs), and writes each one's output to a log under `<temp>/z77-test-runs/run-<date>-<pid>/` — never into the repository. It reads the logs while they grow and timestamps each line, so it knows the seconds per section without any code in the harness. At the end it prints one table, repeats the failed checks, writes `result.json` and exits ≠ 0 when anything is red, hung or left behind in the tree.

- **Parallel is safe by design.** Every DB harness creates its own throwaway schema (`z77test_<random>`) and its own temp installation (`…/z77-<harness>-<pid>`), both dropped at shutdown; servers take a free port (`realFlowServer()` probes port 0). Two harnesses never share state — two BUILDERS in one working tree do (see rules).
- **Coverage is read from the harness source, not typed twice.** `--changed` and `--list` use the `packages/<name>` paths a harness names, the namespaces it uses (mapped through each package's composer.json PSR-4 prefixes), the `tests/support/*.php` it requires (and what those name), the `tests/fixtures/<dir>` it reads and `.releases/` / `scripts/` files. `groups.php` `covers` adds only what a source cannot show.
- **`--changed` = working tree + commits not on `origin/main`**, matched as path prefixes against each harness's coverage; Markdown is ignored. It is package-level: a change in `packages/kernel/*` or in a module the backend harnesses reference picks many harnesses (TEST-RUN-001).
- **Section = a line in the standard header shape** at column 0: one to three capitals, optional digits, a dot, a space (`M. Database …`, `SM. Backend …`, `A. Migration …`). `D. … a sub-case` stays in section D. Lines before the first header are `(start)` (autoload, set-up). A harness without headers gets only its total time.
- **Watchdog.** No new output for `idleSeconds` or more than `limitSeconds` in all → the process TREE is killed (`taskkill /F /T` on Windows; a harness may run a PHP server or a browser) and reported `hung` with its last lines.
- **Tree guard.** `git status` of `packages/*/res/migrations/` (any status) and of untracked files under `packages/` and `tests/`, before and after: new, changed or gone entries are named with the harnesses that were running at the file's mtime. It never deletes.
- **State per harness:** `ok`; `no-db` (green, its DB sections said `SKIPPED (no z77test …)`); `skipped` (exit 2 with no check — the harnesses' «a tool is missing», e.g. no browser — or node missing for a `.mjs`); `red` (a failed check, a fatal error, exit ≠ 0, or no check at all); `hung`.
- **`vendor/z77/*` are absolute links onto `packages/`.** The runner refuses to run when they point outside this tree's `packages/` (a worktree with a borrowed `vendor/`), because every harness would test the other tree's code (TEST-WT-001).
- **Tree guard «gone»** means the file no longer exists. A watched untracked file that was COMMITTED during the run leaves the list but is still on disk — not reported.

## timings

First measured run, 2026-11-17, `php tests/run.php finance --jobs=4` (MariaDB local, Windows 11; the C2 agent committed in the same tree during the run): wall 2 482 s, sum 5 239 s.

| Harness | Seconds | Slowest section |
|---|---|---|
| `module-debtor` | 2 476 | N. fiscal-year close via open-work registry 293 s (36 sections, migration 20 s) |
| `module-creditor` | 1 435 | Z. bank-driven «Bezahlt am» 260 s (21 sections, M. Database 82 s) |
| `module-financial` | 546 | EB. year opening 127 s (migration 11 s) |
| `financial-bank` | 504 | C. states, patterns, «Geschäftlich buchen …» 296 s |
| `module-receipt` | 158 | SM. backend screens 68 s |
| `module-mandator` | 98 | E. account settings 33 s |
| the other six | ≤ 15 each | — |

The `kernel` + `js` groups (15 harnesses, Chrome present): wall 11 s. Reading: the wall time of `finance` is `module-debtor` alone; the time is spread over many DB sections (≈ 70 s each), not in the set-up.

## api

| Command | Effect |
|---|---|
| `php tests/run.php <group\|harness …>` | runs the selection side by side; `npm run test -- <same>` |
| `php tests/run.php --changed` | the harnesses whose coverage has a changed path (combinable with names) |
| `php tests/run.php <harness> --from=<SECTION>` | one harness from its checkpoint (only one harness allowed) |
| `php tests/run.php --list` | groups, each harness with its groups and coverage, orphans; exit 1 on an orphan |
| `php tests/run.php --changed --list` | what `--changed` WOULD run and which path picked each harness |
| `--jobs=N` · `--idle=S` · `--limit=S` | parallelism and watchdog for this run (defaults in `tests/groups.php`) |
| `php tests/<name>.php` | one harness directly — no table, no watchdog, no tree guard |

Groups (`tests/groups.php`): `finance`, `sales`, `dms`, `content`, `auth`, `member`, `api`, `backend`, `kernel`, `persistence`, `installer`, `mail`, `kibot`, `js`, `runner`, and the implicit `all`.

## harness contract

What the runner relies on — every NEW harness follows it; existing deviations are parsed but listed in `## pending`.

| Part | Shape |
|---|---|
| check line | `  ok   <label>` / `  FAIL <label> — <detail>` (two spaces in front) |
| section header | `X. Title` at column 0 (`[A-Z]{1,3}[0-9]{0,2}[a-z]?\. `); `X. … sub-case` continues X |
| summary (last line) | `N passed, M failed` |
| exit code | 0 green · 1 a failed check · 2 a tool is missing / nothing could be verified |
| DB without password | prints `X. Database — SKIPPED (no z77test password …)`, exit by the offline checks |
| state | own schema `z77test_<random>`, own temp dir with the pid, free port, all removed at shutdown; nothing written into `packages/` |

## rules

- While building → MUST run `php tests/run.php --changed` or the single harness of the code being changed (`--from=<SECTION>` where the harness has checkpoints); MUST NOT run a whole area group after every edit
- Before reporting work as done or before a commit → MUST run the area group(s) of the change through the runner ONCE (`php tests/run.php finance`), side by side, and MUST paste the summary table into the report; MUST NOT report «green» from harnesses run one by one by hand
- When several agents build in parallel → by default each MUST work in its own git worktree with its own `composer install` (TEST-WT-001), and the main session MUST merge and run the runner on the merged tree; MUST NOT have two builders in one tree running DB harnesses against each other's half-built code
- When a worktree is not possible (e.g. the repository lies on a substituted drive) → the builders MUST share ONE working tree with exclusive file ownership: every path is written by exactly one builder of the wave, shared files (`docs/**`, `CHANGELOG.md`, `README*`, `tests/groups.php`, the cross-module count checks) belong to the main session; each builder MUST run only its own harness (never a group, never `--changed`), MUST report its harness table and MUST NOT commit; the main session MUST check ownership with `git status --short` against the ownership table, integrate and commit, and run the area group ONCE on the merged, frozen tree; MUST NOT let two builders write the same file
- When a harness needs minutes → MUST start it through the runner (watchdog, table); MUST NOT start a harness in the background and wait blind for it
- When adding a harness → MUST register it in a group in `tests/groups.php` (`--list` and `run-selftest` fail on an orphan), MUST follow `## harness contract` (section headers, check lines, `N passed, M failed`, exit codes) and MUST create only throwaway state (own schema, own temp dir, no fixed port)
- When a harness asserts counts (entities, tables, migrations, templates, classes) → MUST count only its OWN module's; MUST NOT assert an absolute number that another module's stage changes
- When to run the full `php tests/run.php all` (owner 2026-11-20) → MUST run it before every tag (a patch too, [`packaging.md`](packaging.md) → «Release rhythm»), after a `composer update` that lifts third-party packages (2026-11-20: doctrine/orm 3.8 broke `module-financial` I13b, DOCTRINE-VERSION-001) and after a platform change (PHP version, MariaDB major). Not needed for ordinary commits (area group / `--changed`) nor for deploys into the own projects (the manual check list of the project's CLAUDE.md)
- When the tree guard names a file → MUST find the harness that wrote it and fix the harness; MUST NOT delete the file before the developer has seen the report
- When the runner refuses with «vendor/z77/… points to …» → MUST run `composer install` in that tree; MUST NOT copy or link another tree's `vendor/`

## known issues

- TEST-RUN-001: don't assume `--changed` is small — it is package-level; one file in `packages/kernel/shared` picked 74 of 101 harnesses (2026-11-17), the long finance DB harnesses among them. Use `--changed --list` first; while building prefer the one harness of the code being changed.
- TEST-RUN-002: don't trust section seconds below the poll interval (0.2 s) — a line is timed when the runner reads it. A harness that buffers its output (none today: PHP CLI and node `console.log` to a file write through) would show its time in the last section.
- TEST-RUN-003: don't read the tree guard's «running then» as proof — with several harnesses running, every one active at the file's mtime is named, and a writer outside the runner (another agent in the same tree) is reported too.
- TEST-WT-001: don't assume a git worktree tests its own code with a copied or linked `vendor/` — `vendor/z77/*` are absolute links onto the original tree's `packages/`; the runner refuses such a tree. `composer install` in the worktree creates its own links.
- TEST-SHAPE-001: don't assume every harness prints the standard summary — of 101 (2026-11-17) 63 print `N passed, M failed`, 16 `N ok, M failed`, 13 `PASS — N checks` / `FAIL — M of T checks`, 9 `ALL GREEN` / `ALL OK` / nothing (counted from their `OK` / `ok` / `FAIL` check lines). The runner reads all of them. Most small harnesses print no section header (total time only).
- Run directories are named with PHP's default time zone (UTC unless `date.timezone` is set); they are removed after 7 days.

## pending

- **TEST-DB-VERSION-001 — the database versions the harnesses run against** (owner 2026-11-19, after the install test on a fresh PC failed on MariaDB 13: `bank_statement.to_date` is reserved there; dev ran 10.6.28, production runs 11.4.13). Decided: (1) the maintainer's machine moves from MariaDB 10.6 (end of support July 2026) to **11.4 LTS** — the production version — as THE database every normal run uses — **done 2026-11-19 on PC 2: MariaDB 11.4.14** (ZIP, `C:\mariadb`; 10.6.28 kept as rollback, runbook in `docs/_local/`; PC 1 still 10.6). First run on 11.4.14: `financial-bank` 156/0 and `schema-reserved-words` green; `persistence` 5 of 6 green, `persistence-doctrine-tx` O10 red — a real behaviour change, persistence-doctrine.md DOCTRINE-TX-008 (fixed the same day, the harness green on 11.4.14); `module-financial` 2 of 574 red, both from 11.4 and not from the TX-008 fix (red on HEAD without it): CL4 a real bug, a case-sensitive change-log search (fixed, financial.md FIN-CHANGELOG-001), P5 a plan assumption only 10.6 met (check rewritten, financial.md FIN-REPORT-007); (2) **MariaDB 13 as a second instance** on another port (e.g. 3307) that the suite runs against **only on demand**, NOT on every run (owner: no doubled test time) — before a release, after a MariaDB major, or when a schema change is suspicious; the reserved-word guard covers the rest on every run. Open before building: how the harnesses pick the instance (one env var or runner flag for host/port, read where they read `~/.z77/mariadb.txt` today), the runner option's name (e.g. `php tests/run.php --db=13 <group>`), the release checklist step (release-2.0.0-bauplan.md), and the supported range the docs state (INSTALL-AI.md, dev-environment.md: «tested with 11.4 and 13» instead of «≥ 10.6»; minimum 11.4?).
Phase 2 of the test concept (owner 2026-11-17). Ordered by the first measured run (`## timings`); efforts are estimates.

- TEST-P2-01 (owner 2026-11-20: «after the release, MEASURE first»): measure where a DB section's time goes before deciding; candidates: `innodb_flush_log_at_trx_commit=2` for the test DB, one template database per run plus a copy per section. Find where a DB section's time goes — profile one slow section (`module-debtor` N, 293 s; `financial-bank` C, 296 s) with plain timers around `$wire()`, flush, migrate up/down and the HTTP doubles. Migration from zero is NOT it (10–20 s per harness) — decides whether P2-05 is worth it (2–3 h)
- TEST-P2-02: shards for the two long harnesses — `module-debtor` (41 min) and `module-creditor` (24 min) set the wall time of `finance`; checkpoints (`HarnessCheckpoint`) at 3–4 section boundaries plus a runner option to start one harness as N parts side by side would bring the group towards ~10 min (4–6 h per harness)
- TEST-P2-03: cross-module absolute counts / whitelists made relative — `module-financial` A0/A5/A10/A11, `module-debtor` I6/I16, `module-mandator` H4 (the list of files allowed to read an account went red on C2's new `BankPostingService`) — each module checks only its own (2–3 h)
- TEST-P2-04: `diff` in the DB harnesses against a temp migrations folder (or remove what it produced) — no stray migration in `packages/*/res/migrations` (1–2 h)
- TEST-P2-05: `--offline` gate — the no-DB sections (O, S) first as a fast run, DB sections only when green (2–3 h per harness)
- TEST-P2-09: schema dump instead of migrating from zero, fewer `$wire()` — only if P2-01 shows them as the cost (4–6 h, medium risk)
- TEST-P2-06: checkpoints for `module-financial` (9 min) and `financial-bank` (8 min) so a late fix costs seconds (3–4 h each)
- TEST-P2-10: harness contract applied to the deviating harnesses (summary line, section headers in the long ones that have none) — mechanical (1–2 h)
- TEST-P2-07: `--changed` finer than package-level for `packages/kernel/*` (class-level: the harnesses and packages that name the changed class) — only if TEST-RUN-001 costs real time
- **TEST-COVERAGE-001** (2026-11-20): the runner's coverage is per package, not per file — `module-debtor` runs on every `module-article` change (e.g. a template change); make it per file; plus `--from` to the order section.
- TEST-P2-08: try one parallel stage with worktrees (`git worktree add`, `composer install` there) and write the merge routine into this topic

## see also

- [`js-build.md`](js-build.md) — `tests/js-min-markers.php` (group `js`) after every `*.js` change
- [`persistence-doctrine.md`](persistence-doctrine.md) — migrations and the `diff` command the DB harnesses run (TEST-P2-01)
