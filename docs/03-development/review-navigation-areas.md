# Review: navigation areas per view area (render-slot model)

2026-07-05

> **Status (2026-07-05): IMPLEMENTED via ADR-022 / Modell A.** R001 (config slots +
> fail-fast `getBySlot`), R003 (env is config, not entity), R005 (env-delete moot) are
> resolved; R002 (slug convention) + R004 (sandbox cleanup) were handled in the
> migration. See [`navigation-slots-config-bauplan.md`](navigation-slots-config-bauplan.md)
> + [`../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md`](../02-decisions/adr-022-view-areas-and-nav-slots-in-module-config.md).

Scope: is the layered model **view area → group → navigation hierarchy** the right
solution for the stated goal — *one view area holding several navigation areas
(main, meta, meta-2, footer, …)*? Analysis of `NavigationGroup` (tree), the
`navigation_group_id` FK, and how templates consume slots. Read
[`../topics/navigation.md`](../topics/navigation.md) + ADR-007 first.

## the three layers as built

| Layer | Entity / field | Meaning | Binding |
|---|---|---|---|
| 0 — view area / environment | `NavigationGroup`, `parent_id: null` | `frontend`, `backend` | `name` === view-area module key (`viewArea: true`), enforced by `NavigationGroupValidator::validateParentId` |
| 1 — **navigation area** (render-slot) | `NavigationGroup`, `parent_id: <env>` | `frontend-main`, `frontend-meta`, `frontend-secondary`, `backend-main`, `backend-auth` | free `name`; slug is the code contract |
| 2 — navigation tree | `Navigation` | tree-roots + children | root → slot via `navigation_group_id`; child → parent via `parent_id` |

The group tree is capped at exactly **two levels** (env → slot); `validateParentId`
rejects a slot under a slot. A navigation tree-root attaches to a **leaf** slot only
(`ElementAnchorRules`, `GROUP_NOT_LEAF`).

## verdict

**The layer-1 render-slot IS the "navigation area" concept — the model matches the
goal and is clean.** Live data proves it: `frontend` already carries three areas
(`frontend-main` = Hauptnavigation, `frontend-meta` = Fusszeile, `frontend-secondary`
= Zusatznavigation); `backend` carries `backend-main` (Sektionen) + `backend-auth`.
Adding "meta-2" = add one `NavigationGroup` under the env. The FK + `TreeService`
foundation is relationally correct and ORM-ready. No redesign needed.

The reservations below are about **where the model is only half data-driven** and
**data hygiene**, not about the core shape.

## findings

### AREA-R001 — an area is declared in data but PLACED in code (the real limit)

The env switcher / backend topbar are data-driven over slots
(`resolveViewAreaUrl` iterates `getGroupChildren(env)` in order —
[`NavigationService.php:354`](../../packages/kernel/core/src/Services/NavigationService.php#L354)).
But the **actual page layout hardcodes the slot slug**:

```
header.tpl.php:4   getByGroupSlug('frontend-main')
footer.tpl.php:4   getByGroupSlug('frontend-main')
footer.tpl.php:5   getByGroupSlug('frontend-meta')
```

Consequence: creating a navigation area in the backend does **not** make it appear.
A developer must add a `getByGroupSlug('<slug>')` render call at the chosen spot in
the layout. Proof it already drifted: **`frontend-secondary` exists as a group but is
rendered by no template** — a declared-but-dead area.

This is partly legitimate — *where* an area sits in the HTML is inherently a layout
decision. But there is no link between "declared slot" and "rendered slot", so the
drift is silent (no warning, no lint). For the goal "admin adds areas freely" the
honest statement is: **admin can add the area's entries; a developer still wires the
render point once.**

Options (pick per how self-service this should be):
- **Accept + document** — a new area is a two-step (data + one render line); note it in
  `navigation.md` rules. Cheapest, keeps layout control explicit.
- **Convention loop** — layout iterates a known set (e.g. render every slot of the
  current env into named regions); still needs region↔slot mapping somewhere.
- **Registry/lint** — flag slots that no template consumes (and slugs consumed by a
  template but missing as a group). Closes the silent-drift gap without changing the
  model.

### AREA-R002 — slot naming convention (`{env}-{area}`) is unenforced

`validateName` checks alphanumeric + uniqueness only. The `frontend-`/`backend-`
prefix that makes `frontend-meta` readable is soft convention. A slot's slug is its
**code contract** (`getByGroupSlug`), yet nothing guarantees it is namespaced to its
env, and two envs could grow confusingly similar area slugs. Low severity; worth a
validator note or an explicit "slug = `{env}-{area}`" rule if areas multiply.

### AREA-R003 — env vs area are the same entity, distinguished only by depth

Both layers are `NavigationGroup` rows; "environment" vs "area" is positional
(`parent_id` null-ness) + validator logic, not a type. Consistent with ADR-007's
layered model and fine in practice, but the entity does not self-describe its role —
worth keeping in mind for the next reader.

### AREA-R004 — runtime data hygiene (sandbox cruft, not a framework bug)

`skeleton/data/framework/routing/navigation.json` is a dev sandbox and has dangling
data that would embarrass a demo:
- **id 14 "opener" → `parent_id: 13`, but no entry 13 exists** → orphaned subtree
  (14/15/16 hang off a missing parent).
- test cruft: id 16 "test unte rnav", id 26 "test".
- runtime groups **dropped `backend-meta`** (id 5, present in
  `navigation_groups.default.json` as "Stammdaten") → default and runtime diverge.

Cleanup before this file is used as a demo seed. Note the delete path is safe by
design: `NavigationGroupController::removeAction` detaches the FK
(`setNavigationGroupId(null)`) rather than deleting entries, so a removed area dumps
its tree-roots into "Nicht zugeordnet", it does not destroy them.

### AREA-R005 — env-delete protection still open (known)

Already tracked (ADR-007 consequences / NAV-GROUPLIST-001 pending): deleting a
top-level env orphans its render-slots; there is no env-delete guard and deliberately
no env-delete button in the group list. Restated here because it is the same
area-lifecycle surface.

## recommendation

Keep the model. Decide **AREA-R001** explicitly — it is the only point that touches
the "different areas per view area" goal directly: either accept the two-step
(data + one render line) and document it, or add the registry/lint that ties declared
slots to rendered slots. Everything else is naming clarity (R002/R003) and a data
cleanup (R004) plus the already-known env-delete guard (R005).

## see also

- [`../topics/navigation.md`](../topics/navigation.md) — full model (env → render-slot → tree, FK, leaf rule)
- [`../topics/tree.md`](../topics/tree.md) — `TreeService` / `ElementAnchorRules` foundation
- [`review-navigation.md`](review-navigation.md) — earlier review (tag overload, ORM-readiness)
- [`../02-decisions/adr-007-navigation-tree-model.md`](../02-decisions/adr-007-navigation-tree-model.md) — binding layered-model decision
