# ADR-034 — Disposable runtime state lives under `lib/`

Date: 2026-08-25 · Status: accepted

## Context

The framework writes three different things to disk, and until now two of
them shared a directory.

`data/` holds the installation's records — accounts, tenants, tokens,
navigation, the document store. A backup exists so this tree can come back.

`lib/` held the page cache, and nothing else. It was never described as a
category; it was simply where `cacheDir` happened to point.

Between the two sat a third kind that had no home and defaulted into
`data/`: throttle counters. A counter is a time window and a number.
Deleting it starts the running window at zero and nothing else happens —
yet `data/framework/member/throttle` sat next to `accounts.json`, and 52 of
those files were in every archive of the axo3 installation (measured
2026-08-24).

The same misplacement had already been patched once from the other side:
`BackupService::DATA_EXCLUDES` carves `framework/jobs` and
`framework/import` out of the data archive, because a restore must not
resurrect a queue of work. That exclude list is the symptom of a missing
category — state that lives in `data/` and is then subtracted from every
archive is state that was filed in the wrong place.

## Decision

**Everything below `lib/` may be deleted at any moment without losing
information.** That sentence is the definition of the directory, and the
only test for whether something belongs there.

```
lib/
  cache/          page cache, FileFinder
  throttle/
    member/       address throttle (registration, login, resend)
    totp-guard/   TOTP brute-force counters
    widget/       project: delivery per snippet and minute (AXO3)
    zeichnen/     project: uploads per IP and hour (AXO3)
```

Three consequences follow, and all three are binding:

1. **`lib/` is excluded from the `full` backup AS A TREE** — `fullExcludes`
   names `lib`, never a member of it. A directory added under `lib/`
   tomorrow is covered the day it appears, with no edit anywhere.
2. **A project files its disposable state under `lib/` too**, not under
   `data/project/{name}/`. The rule is the framework's, the directory is
   shared.
3. **New disposable state gets NO exclude entry of its own.** If it needs
   one, it is not disposable and does not belong under `lib/`.

### There are three categories, not two

The tempting sentence — «`data/` is what a restore brings back, `lib/` is
what it must not» — is wrong, and it is wrong on day one. `framework/jobs`
must not survive a restore either, and it stays under `data/`.

| | Restore brings it back | May be deleted mid-flight | Location |
|---|---|---|---|
| Records | yes | no | `data/` |
| Transient state bound to a running operation | no | **no** | `data/` + `DATA_EXCLUDES` |
| Disposable state | no | **yes** | `lib/` |

The middle row is the one that keeps `lib/` honest. A job queue, an import
staging area, a lock file: deleting them between two runs is harmless,
deleting them DURING a run loses work or corrupts an operation. They are
not disposable, so they do not move — `DATA_EXCLUDES` remains their carrier
and remains not configurable.

Deciding where something goes is therefore one question, not two: *may this
be deleted while the installation is serving requests?* Yes → `lib/`. No →
`data/`, and if a restore must not bring it back, `DATA_EXCLUDES`.

## Reasoning

**The filing must state what the content is worth.** A counter next to the
account store reads as important. It survives into archives, it is copied
between environments, and nobody dares delete it — because everything
around it would hurt to lose. The directory is the only label the file
has.

**One deletable directory is an operational tool.** «Wipe `lib/` and the
installation resets its runtime state without touching a record» is a
sentence an operator can act on under pressure. It only holds if the rule
has no exceptions, which is why the definition is a hard test and not a
guideline.

**A named tree does not need maintaining.** `fullExcludes` named
`lib/cache`, so `lib/throttle` was in the archive the moment it existed.
The list was correct when it was written and wrong the day after. Naming
`lib` removes the class of error rather than the instance
(BACKUP-LIB-001, [`../topics/backup.md`](../topics/backup.md)).

## Consequences

- The location question has a decision procedure, so it stops being decided
  case by case (which is how the counters ended up in `data/`).
- Every disposable directory creates itself on first write
  (`mkdir(..., recursive)` in each throttle). Wiping `lib/` needs no repair
  step and no installer involvement.
- `lib/` is NOT in the full archive. Anything filed there is gone after a
  restore — which is the point, and which is why the hard test matters:
  a mistake here loses data silently.
- **A changed default does not reach an existing installation.**
  `config/backup.inc.php` is seed-once; every installation carries its own
  copy. Moving `fullExcludes` to `lib` required a manual pass over axo3 and
  zihlundsee, servers included. Any future change to a seed-once default
  carries the same cost and stays silent until someone opens an archive.
- Projects give up `data/project/{name}/` for scratch files. AXO3's widget
  and drawing throttles moved on 2026-08-25.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep the counters in `data/` and extend `DATA_EXCLUDES` | Grows the list that already signals a missing category, and does nothing for the `full` archive — that one reads the project root, not `data/`. Treats the symptom. |
| Add `lib/throttle` to `fullExcludes` next to `lib/cache` | Correct on the day it is written, wrong when the next directory appears. The list has to be maintained by someone who remembers it exists. |
| Name the directory `var/` (FHS, Symfony, Laravel `storage/`) | Semantically the better name — `lib` says «library, so code» in every other PHP project, and this framework is written to be handed to a successor. Rejected because `lib/cache` is the established path in `bootstrap.inc.php` of every installation, and renaming it buys a word at the price of touching every installed config. The name is a deliberate local redefinition, recorded here so the next reader does not take it for an oversight. |
| Move `framework/jobs` / `framework/import` / project locks to `lib/` as well | They fail the test. Deleting them mid-run loses queued work or breaks a running import — «disposable» would have to be softened to «usually disposable», and then the directory means nothing. They are the middle category. |
