# Review result — ADR-039 (Doctrine driver)

**Date:** 2026-09-21
**Subject:** [`adr-039-doctrine-driver-behind-unified-entity-manager.md`](../02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md)
**How:** independent review by a second model (Fable) in a read-only agent without context from the
working session, as for the plan on 2026-09-20. Its code claims were checked against the source
before anything was accepted.

## Verdicts and outcome

| # | Finding | Outcome |
|---|---|---|
| F1 | One `UnifiedEntityManager::flush()` over File and Doctrine is not atomic; File writes inside a transaction are not rolled back | **accepted** — decision 9 and 10 |
| F2 | «Switching backend = only `#[Entity]`» already fails for entities with a specific repository; the draft repeated the claim | **accepted** — decision 7; the topic doc's contradicting rules get fixed |
| F3 | Flush scope, `remove()` timing, `reorder()` and the closed EntityManager after a rollback differ between drivers | **accepted** — decision 9; the driver replaces the closed EntityManager (decision 10) |
| F4 | Nesting and «`LedgerService` never commits» were conventions the port could not enforce | **accepted** — nesting joins; the port answers whether a transaction is open |
| F5 | `PhpFilesAdapter` files sit in OPcache; deleting the directory is not enough | **accepted** — `opcache_invalidate()` per file; the migrate command clears as well |
| F6 | Driver map, connection config and entity announcement were deferred although cheap and blocking | **accepted** — driver map names `doctrine`; **`config/client/database.inc.php`** shared with the backup (owner decision 2026-09-21); `doctrineEntities` like `importEntities` |
| F7 | Missing: migrations per module, one database under two releases, a test strategy | **accepted** — decisions 13, 14, 16 |

Where the review confirmed the draft: own package for ADR-001, lazy boot, one API for the mixed case,
no second connection, no retry loop, native lazy objects, migrations only, DBAL reports as a
documented deviation.
