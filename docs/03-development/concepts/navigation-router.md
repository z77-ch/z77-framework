# Concept: Navigation & Router

**Status:** `[SUPERSEDED]` — früher Entwurf, **nicht mehr der aktuelle Stand.**
**Date:** 2026-04-28 (updated from 2026-04-20)

> **Überholt — gilt nicht mehr.** Dieser Entwurf beschreibt ein 3-Segment-Modell (`module/controller/action`), ein `tags`-Array und rekursiv verschachtelte `children`. Der aktuelle Stand weicht grundlegend ab: 4-Segment-URLs + `group` (ADR-005), ein **einzelner** `tag`-String pro Tree-Root, flache Records mit `parentId`-FK am Kind (kein verschachteltes `children`), und `sortKey` für die Reihenfolge. **Single Source of Truth: [`../../topics/navigation.md`](../../topics/navigation.md).** Dieses Dokument bleibt nur als historischer Ausgangspunkt erhalten (Rückroll-Wert: das ursprüngliche Problem & der erste Lösungsansatz).

---

## Problem

z77 uses convention-based routing: URL structure maps directly to `module/controller/action`.
This works, but has two limitations:

1. **No custom URLs** — `/leistungen` cannot point to `content/page/show` without a workaround
2. **No menu structure** — there is no central place that defines which pages exist, what they are called, and where they appear in the UI

The old framework (wdv-622) solved both with a Navigation entity in the database using
the Nested Set Model for hierarchy. z77 replaces this with a JSON file.

---

## Decision

Navigation is stored as a **JSON file** (`data/framework/routing/navigation.json`).
Each entry defines a page: its URL, its routing target, and which navigation areas it belongs to via tags.

The Router consults this data during URL resolution — before the convention fallback.
The CacheManager handles loading, caching, and persistence automatically.

Navigation is treated as **Entity data**, not configuration. It is loaded via the
Persistence layer (`FileStorage` → `FileRepository` → `UnifiedEntityManager`), the same
way any other Entity would be loaded. Configuration is for framework behaviour (timezone,
debug, htmlRoot). Navigation describes pages — that is data.

---

## Why JSON, Not Database

Navigation changes rarely (a few times per year at most).
A database is the right tool for transactional, relational, or frequently mutating data —
not for a list of pages that barely changes.

| | JSON + CacheManager | Database |
|---|---|---|
| Speed | APCu (nanoseconds after first load) | DB query per request |
| Git history | Full change history | No history |
| DB dependency | None — works on static sites | Required |
| Drag & drop | Backend rewrites JSON file | Backend updates rows |
| Server restart | JSON file reloads into APCu | No impact |

---

## JSON Structure

```json
[
    {
        "name": "Home",
        "url": "/home",
        "module": "frontend",
        "controller": "index",
        "action": "index",
        "tags": ["frontend"]
    },
    {
        "name": "Leistungen",
        "url": "/leistungen",
        "module": "frontend",
        "controller": "index",
        "action": "index",
        "tags": ["frontend"],
        "children": [
            {
                "name": "Web",
                "url": "/leistungen/web",
                "module": "frontend",
                "controller": "index",
                "action": "index",
                "tags": []
            }
        ]
    }
]
```

### Entry fields

| Field | Required | Description |
|---|---|---|
| `name` | yes | Display name — used in menu rendering |
| `url` | yes | URL path without language prefix — used for routing lookup and link generation |
| `module` | yes | Target module |
| `controller` | yes | Target controller |
| `action` | yes | Target action |
| `tags` | yes | Navigation areas this entry belongs to (empty array for children) |
| `children` | no | Nested entries for dropdown menus — same structure, recursive |

---

## Tags

Tags define which navigation area an entry belongs to.
An entry can have multiple tags — it appears in multiple areas.
There are no fixed tag names. New areas are created by using a new tag — no code changes needed.

**Typical tags in client projects:**

| Tag | Area |
|---|---|
| `frontend` | Main navigation (header) |
| `meta` | Meta navigation (footer, legal links) |
| `second-meta` | Second meta area (language switch, help) |
| `backend` | Admin sidebar |

---

## Caching — 3 Tiers via CacheManager

```
Request:
  1. Local Request Cache   — nanoseconds, in-memory, current request only
  2. APCu                  — nanoseconds, shared memory, survives across requests
  3. JSON file fallback    — milliseconds, read once after server restart → fills APCu again
```

After drag & drop (backend save):
```php
$cacheManager->clear('NavigationRepository');  // invalidate — next request reloads from JSON
```

---

## Routing

Language prefix is stripped from the URL **before** navigation lookup by
`Request::extractLanguage()`. The Router always receives a language-free URL.

```
/de/leistungen  → extractLanguage() removes 'de' → Router gets /leistungen
/leistungen     → no language prefix              → Router gets /leistungen
```

Navigation lookup fires only when segments are present after language extraction.
Bare language-only URLs (`/de`) produce empty segments and go directly to defaults.

```
Request::runParsing()
  → extractLanguage()
  → mode === Fetch?  YES → parsePathSegments() directly
  → segments present?
      NO  → parsePathSegments() (defaults)
      YES → Router::match(url)
               → NavigationRepository::findByUrl(url)   recursive, searches children too
                  → HIT:  module/controller/action from Navigation entity
                  → MISS: parsePathSegments() — convention fallback
```

---

## Menu Rendering

Navigation entries serve dual purpose: routing AND menu display.
A "Home" entry with `url: "/home"` will never be matched via routing when visiting `/`
(no segments after language extraction), but it is still available for menu rendering via tag lookup.

Templates request a navigation area by tag:

```php
$nav->getByTag('frontend')   // all entries tagged 'frontend', in order, including children
$nav->getByTag('meta')       // all entries tagged 'meta'
```

`getByTag()` is not yet implemented — see open work below.

---

## Data Directory Structure

```
data/
  framework/              ← framework-internal entities
    routing/
      navigation.json     ← this file
    auth/
      users.json
  project/                ← project-specific entities, per module
    frontend/
      slides.json
```

The file is created by the installer (`Install::writeNavigationConfig()`), not checked
into the skeleton. Source template: `packages/kernel/core/src/Config/navigation.default.json`.

---

## Implementation

| File | Role |
|---|---|
| `packages/kernel/shared/src/Entities/Navigation.php` | Entity — `#[Entity('file', 'framework/routing/navigation.json')]` |
| `packages/kernel/core/src/Routing/NavigationRepository.php` | Loads via UnifiedEntityManager, caches via CacheManager, `findByUrl()` |
| `packages/kernel/core/src/Routing/Router.php` | Thin layer — `match(url)` → NavigationRepository |
| `packages/kernel/core/src/Http/Request.php` | `extractLanguage()` + `runParsing()` with navigation guard |
| `packages/kernel/core/src/Installer/Install.php` | `writeNavigationConfig()` writes default to `data/framework/routing/` |
| `data/framework/routing/navigation.json` | Runtime data file — created by installer |

---

## Open Work

| Task | Status |
|---|---|
| Navigation-Service `getByTag(string $tag): array` | `[OPEN]` — needed for menu rendering in templates |
| Active-state detection | `[OPEN]` — which entry is currently active? |
| Backend drag & drop for navigation.json | `[OPEN]` — v1.1 |
| Update this doc after getByTag() implementation | `[OPEN]` |

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Database (Nested Set Model) | Complex to maintain, DB dependency for simple sites, overkill for rarely-changing data |
| Database (Adjacency List) | Simpler than Nested Set but still requires DB for data that rarely changes |
| PHP array config file | Writing back from backend is messy. JSON is cleaner for programmatic rewriting |
| Static code-defined routes | Developer must maintain two separate systems. JSON unifies both |
| NavigationConfig (old approach) | Config is for framework behaviour, not page data. Replaced by Entity + Persistence layer |
