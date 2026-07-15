# ADR-022 — View Areas and Navigation Slots in Module Config

**Status:** `APPROVED`
**Date:** 2026-07-05

---

## Context

ADR-007 introduced a layered navigation model where **tags form a tree**: a
top-level tag is an environment (view area), its child tags are render-slots, and
navigation tree-roots attach to a render-slot. The tag entity was later renamed to
`NavigationGroup` (ADR-009) and the element→group reference moved from a slug to an
int FK (`Navigation.navigationGroupId`, NAV-FK-001). Groups became a managed,
editable entity with their own controller, validator, repository, JSON store, and a
drag & drop management screen.

A review of the area model ([`../03-development/review-navigation-areas.md`](../03-development/review-navigation-areas.md))
found that this editable-entity treatment does not match what the two group layers
actually are:

- The **environment** is not content — its `name` MUST equal a `viewArea` module key
  (ADR-007 §2), so the row duplicates a fact that already lives in the module config,
  and the only free field is a display label. It cannot be freely created or deleted
  without breaking the invariant; an env-delete guard was a standing follow-up.
- A **render-slot** only produces output where a layout template renders it
  (`getByGroupSlug('frontend-meta')`). Creating a slot in the backend does **not**
  make it appear — a developer must add a render call. The review found a
  declared-but-never-rendered slot (`frontend-secondary`) proving the silent drift:
  the slug is a magic string with no link between "declared" and "rendered", and a
  typo (`getByGroupSlug('frontnd-meta')`) returns an empty list, not an error.

In short: both group layers are **architecture bound to code (module + layout)**, not
free-form data. Modelling them as an editable entity created redundancy (env), silent
drift (slots), and a whole CRUD stack maintaining structure that is not editable in
any meaningful way.

ADR-007 had explicitly rejected a "separate Environment/ViewArea registry" on the
grounds that every field it would carry already exists or is derivable in the tag
structure. That reasoning holds **against a second data table** — but it overlooked
that the `NavigationGroup` entity *is itself* the redundant table (its env rows
duplicate the module key). The resolution is not a second data store; it is to stop
storing the structure as data at all and read it from the config that already owns it.

---

## Decision

**The environment and render-slot layers move out of the `NavigationGroup` entity
into module config, read through `ModuleManager`. `NavigationGroup` is removed.**
After this change the navigation subsystem has exactly two entities: `Navigation`
(structure) and `NavigationAlias` (public URL).

### 1. View area (environment) is declared in `{module}Config.inc.php`

A module that owns a layout keeps `'viewArea' => true` and adds its display label and
its navigation slots:

```php
'viewArea'      => true,
'viewAreaLabel' => 'Frontend',
'navSlots'      => [               // ordered map: key → label (array order = sort)
    'main' => 'Hauptnavigation',
    'meta' => 'Fusszeile',
],
```

The environment identity is the module key (unchanged invariant); the label and slot
set are config, held by the already-cached `ModuleManager`. There is no environment
row to add, edit, or delete — the env-delete-orphan risk disappears by construction.

### 2. Render-slot is a config entry; its full slug is `{moduleKey}-{key}`

A slot's stable identifier stays the full slug (`frontend-meta`) used in data and
templates. `ModuleManager` aggregates the **slot registry** — the set of all valid
slugs across view-area modules. This registry is the single source of "which slots
exist".

### 3. Slot access is fail-fast

UI chrome reads a slot's tree-roots through `NavigationService::getBySlot(string $slot)`,
which validates the slug against the registry and **throws** on an unknown slug
(`UnknownNavigationSlotException`) instead of returning an empty list. A typo or a
removed slot fails loudly at the point of use. This is the "declared ↔ used" link the
old magic string lacked; no generated constants/enums (that would duplicate the config).

### 4. Navigation references its slot by slug string

A tree-root carries `slot: string` (the full slug), replacing the int FK
`navigationGroupId`. This reverts NAV-FK-001 **for this field only**: `parentId`/`ref`
still point at real `Navigation` rows (int FKs), but the slot now points at a config
constant, not a row — a string/enum-valued reference is the correct shape for that
(ORM: a `varchar` with a check constraint, not a foreign key). The `tag`/`slot` XOR
`parentId` invariant is unchanged; the leaf-group rule becomes **registry membership**
(the slug must be a known slot).

### 5. Slots are code, placed in code — by design

Adding a navigation area is a deliberate two-step: declare the slot in the module
config **and** add one `getBySlot('{slug}')` render call at the chosen spot in the
layout. Where an area appears in the HTML is a layout decision and stays in the layout.
The backend manages the *entries* of an area, never the area's existence or placement.

---

## Reasoning

**Why config, not a data table (revising ADR-007's rejection)?**
ADR-007 rejected a *second* registry because it would duplicate the tag structure. But
the env rows already duplicated the module key, and the slot rows carried nothing that
made them independently editable (a slot without a template render point never works).
Config is not a parallel data store — it is the code that already owns "which modules
are view areas". Moving the structure there removes duplication instead of adding it.

**Why keep the full slug as the identifier and not introduce constants?**
The slug is already the readable contract in data and templates. A generated enum would
have to be kept in sync with the config — the exact double-maintenance we are removing.
A fail-fast accessor over the config registry gives the "typo → boom" safety without a
second declaration.

**Why revert the slot reference from int FK back to a string?**
NAV-FK-001 aligned the slot reference with `parentId`/`ref` because all three pointed at
rows. Once the slot is no longer a row, that alignment no longer applies: the slot is a
config value, and referencing a config value by its stable key is normal (and ORM-clean
as an enum/check-constrained column).

**Why is a dead slot better prevented here than before?**
Before, a slot lived in data with no link to any template — drift was invisible. Now a
slot is one config line, the accessor throws if a template names an unknown slot, and a
slot the config declares but no template renders is visible in one place (the config),
not scattered across a JSON store.

---

## Consequences

**Easier:**

- Two navigation entities total (`Navigation`, `NavigationAlias`) — the group entity,
  its repository, validator, controller, four templates, DnD screen, and JSON store are
  gone.
- No env-delete-orphan risk; no "top-level tag name must be a view-area key" runtime
  check (config makes it structurally true).
- Slot typos and removed slots fail loudly at the render site.
- Adding an area is an explicit, documented two-step (config + one render line).

**Harder / to keep in mind:**

- Adding or reordering a navigation area is now a code change (config), not a backend
  action. This is intentional — a slot always required a layout render point (code)
  anyway; the illusion of full self-service is removed.
- `AbstractTreeEntityController` and `ElementAnchorRules` lose their `NavigationGroup`
  consumer; the tree foundation (`TreeService`) keeps `Navigation` as its consumer.
- Data migration must map every `navigation_group_id` to the correct slot slug
  losslessly; cached serialized `Navigation` objects must be invalidated on deploy.

---

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep `NavigationGroup` as an editable entity, only validate the slot name against a registry (review Modell B) | Double maintenance: every slot must exist as both a config entry and a row, needing a seed/sync step. Keeps a CRUD stack for structure that is not meaningfully editable. |
| Generate PHP constants/enum for slot slugs | Duplicates the config; must be kept in sync. A fail-fast accessor over the config registry gives the same safety without the second declaration. |
| Leave the slot reference as an int FK to a config-seeded row | Requires seeding config → rows (the Modell-B sync problem); the FK points at a synthetic row that only mirrors config. |
| Keep environments as data, move only slots to config | The environment row is the clearest duplication (name === module key); splitting the two layers across data and config is less coherent than moving both. |

---

## Implementation Summary

Ordered build plan + full footprint (removed / changed files, per-phase pauses):
[`../03-development/navigation-slots-config-bauplan.md`](../03-development/navigation-slots-config-bauplan.md).

| Area | Files |
|---|---|
| Config | `module-frontend/.../frontendConfig.inc.php`, `module-backend/.../backendConfig.inc.php` (`viewAreaLabel`, `navSlots`) |
| Config reader | `core/.../Services/ModuleManager.php` (`getViewAreaLabel`, `getNavSlots`, slot registry) |
| Slot access | `core/.../Services/NavigationService.php` (`getBySlot` fail-fast; `getViewAreas`/`resolveViewAreaUrl` on config; group methods removed) |
| Entity | `shared/.../Entities/Navigation.php` (`navigationGroupId` → `slot`) |
| Validation | `shared/.../Validators/NavigationValidator.php` (XOR + registry membership) |
| Removed | `NavigationGroup` entity/repository/validator/controller + templates + JS + `navigation_groups*.json` |
| Data | `core/data/.../navigation.default.json`, `skeleton/data/.../navigation.json` (`navigation_group_id` → `slot`) |

**Migration mapping (from the current `navigation_groups.json`):**
`7 → frontend-main`, `2 → frontend-meta`, `8 → backend-main`, `6 → backend-auth`.
The unused groups `frontend-secondary` (id 3, review R001 dead slot) and `backend-meta`
(id 5, default only, absent at runtime) are **not** carried into the config — no
navigation entry references them.

Supersedes/revises: ADR-007 §1–3 (the group layer is config, not a tag tree) and its
"separate registry" rejection; ADR-009 (the `NavigationGroupController` split is void).
Touches ADR-008 (`TreeService` keeps only the `Navigation` consumer). Operational SSOT
after implementation: [`../topics/navigation.md`](../topics/navigation.md).
