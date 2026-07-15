# Cache Invalidation

## Decision: always clearAll()

PageContent is not 1:1 mapped to a cache file. The same content (identified by subject)
can appear on multiple pages. Granular invalidation would require tracking which subjects
were rendered on which page — overengineering for the current use case.

Rule: **any state change that could affect rendered output invalidates the entire page cache.**

## Three Triggers

### 1. Entity save / delete (runtime)

`FileRepository::save()` and `FileRepository::delete()` call `pageCache->clearAll()` after
every persist operation. This covers all content changes made by an admin in the backend.

DoctrineRepository (if introduced later) follows the same pattern.

No listener dispatch, no event system, no reflection. Direct call.

### 2. Clear-Cache-Button (backend UI)

A button in the backend admin panel calls `pageCache->clearAll()` explicitly.

Use case: after deploying new templates or PHP files via FTP/VS Code sync, the developer
clicks the button once to flush stale HTML from the cache.

### 3. Debug toggle (backend UI)

A button in the backend toggles `DEBUG` in `config/bootstrap.inc.php` between `true`
and `false`, then calls `pageCache->clearAll()`.

Why clearAll() here: switching DEBUG off (false → production mode) activates the cache.
Switching DEBUG on (true → dev mode) disables cache writes. In both cases stale cache
files must not survive the toggle.

Note: when DEBUG=true, PageCachePolicy skips all cache writes — the cache directory
stays empty during development regardless of TTL settings.

## What does NOT need explicit invalidation

| Change | Why no invalidation needed |
|---|---|
| CSS / JS assets | Asset URLs include filemtime → browser fetches new version automatically |
| PHP templates (via VS Code sync) | Covered by Clear-Cache-Button (manual, developer-triggered) |
| Module config (`cache.enabled`, `ttl`) | Covered by Clear-Cache-Button |

## Scope

`clearAll()` deletes all files under `cache/pages/`. It does not touch DataCache (APCu /
local request cache). Config and navigation data cached in DataCache are unaffected.
