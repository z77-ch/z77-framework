# ADR-017 — Document management: ownership, ACL authorization, active state, delivery modes & structural media delivery

**Status:** `[APPROVED]` — supersedes ADR-016; scope model partly superseded by ADR-020
**Date:** 2026-06-17 (revised 2026-06-20)

> **Revision 2026-07-03 (see [ADR-021](adr-021-dms-drive-root-and-super-user-governance.md)).** The
> **admin bypass becomes a SUPER_USER bypass**: the ACL shortcut ("admin → `manage` everywhere") now
> applies only to `AuthRole::SUPER_USER` (level 100), not from `ADMIN` (80) upwards — an `admin` is a
> normal principal managed via grants. Additionally ONE mandatory drive-root folder now sits above the
> partitions (grants on it cascade to the whole DMS; root/partition-level acts are SUPER_USER-only).
> The ownership + ACL + `deliveryMode` model itself is unchanged.

> **Revision 2026-07-01 (see [ADR-020](adr-020-dms-scope-by-root-folder-not-area-label.md)).** The
> **ownership + ACL + `deliveryMode` model here stays fully valid** and becomes the core. What changes:
> the flat **`area` label is replaced by real root folders** in one tree — the top level (`parent_id =
> null`) is now a normal, owned/ACL'd folder, and the **Option-A "no documents in the null area root"
> rule is moot** (the root is an ordinary folder). Scope is the tree + ACL (a `grant` on a root applies
> to its whole subtree), not an `area` field; the `area` field is removed. Module addressing moves to a
> stable `key` on root folders. See ADR-020 for the full model.

> **Revision 2026-06-20.** The delivery design of the original 2026-06-17 version — "public is a
> GUEST `READ` ACE and everything is served through PHP" — is **replaced** by an explicit
> **`deliveryMode` ladder + static materialization**:
> - GUEST is **no longer** an ACL subject; the ACL subject is `user | role`.
> - Visibility/materialization is a per-resource **`deliveryMode` (`sealed | protected | public`)**,
>   not an ACE.
> - **Sharing** is an additive `Share` entity, not a `MANAGE` capability bolted on later.
>
> The **ownership**, **`active`**, **dedicated `dms` module** and **reserved-route** decisions are
> unchanged. Working derivation + open implementation details:
> [`../03-development/dms-umbauplan.md`](../03-development/dms-umbauplan.md).

> **Revision 2026-07-01 — code placement superseded by [ADR-019](adr-019-code-organization-package-by-domain.md).**
> This ADR's *implementation surface* places `AclService` and the materialization job in `shared`,
> keeps `BlobStorage` in `persistence`, and gives `module-dms` only the `OutputController`/delivery
> surface. ADR-019 (package-by-domain) **moves the entire DMS vertical into `module-dms`** — entities,
> repositories, services (incl. `AclService` + materialization), the image pipeline, `Principal`/
> `UploadedFile`, `BlobStorage`, and the Drive UI fragment. **Only the physical location changes; every
> domain decision in this ADR (ownership, ACL model, `deliveryMode` ladder, structural `/media`,
> additive `Share`, `active`) stands unchanged.** Where the text below says a DMS class lives in
> `shared`/`persistence`, read `module-dms` per ADR-019.

---

## Context

ADR-016 designed the DMS as an **admin-managed** framework foundation: `area` = module key,
visibility a global `private | public` flag plus a `publicPath` lookup key, public delivery routed
through the `/media` **NavigationAlias** (a seeded phantom navigation node, id 26). Phases 1–6 built
and verified the engine on that basis.

A broadened requirement set (User 2026-06-17, refined 2026-06-20) invalidates the access **and
delivery** model — not the engine. The DMS becomes a **multi-actor document platform** (Drive-like,
cf. proton.me), not an admin sub-tool:

- **Admin** — manages everything.
- **User / customer** — owns a private space, creates folders, uploads, manages — **without** the
  backend admin shell.
- **Supplier** — uploads invoices / submits offers into a designated dropbox (logged in).
- **API** — a token maps to an account and obeys the same policy.

Confidential documents (contracts, invoices) will live on the server. That makes a **real
access-control model mandatory**, and it makes the *storage location* a security property: such
bytes must **never** reach the docroot. At the same time, genuinely public assets (slider images,
public PDFs) must be served **statically** without a PHP worker per request. One uniform mechanism
("everything through PHP with a GUEST ACE") cannot satisfy both — hence the **`deliveryMode`
ladder** added in the 2026-06-20 revision.

The engine from ADR-016 Phases 1–6 **remains valid and is kept**: id-addressed `BlobStorage`
(layout B, per-`id` directory), eager GD derivatives + config image profiles, two-phase
`SaveService`, range/inline `FileResponse`, `DocumentKind` allowlist, the `Mailer` stack.

## Decision

### 1. Single source of truth

The source of truth is **`data/blobs/<id>/…` (outside `public/`) + the document metadata record**.
Any file that appears in the docroot is a **deterministically regenerable materialization** of that
source (a CMS-publish projection) — it carries no own state and is never a second source of truth.

### 2. Ownership

Every `Folder` and `Document` has an `ownerId` (a `LoginUser`). The owner has **implicit full
rights**. The ownership unit is a **single user** for now; an account/tenant grouping several users
is deferred.

### 3. Delivery mode — `sealed | protected | public` (the primary visibility axis)

`deliveryMode` lives on **both** `Folder` and `Document` (`null` = inherit). It is an ordered
openness ladder that decides where the bytes may live and how they are delivered:

| `deliveryMode` | bytes | `AccessControlService` runs | delivery |
|---|---|---|---|
| **sealed** | only `data/blobs` — **never** the docroot | per byte request | PHP `readfile`, `Cache-Control: private` |
| **protected** | only `data/blobs` | per byte request | PHP `readfile`, `private` |
| **public** | `data/blobs` **+ materialized** to `public/media/…` | **not at all** | static, by the web server (`public, immutable` + `ETag`) |

- **`sealed` is a structural lock (the vault):** `publish()` **and** `share()` throw; the UI does
  not offer the actions. A sealed document can never have bytes in the docroot — the guarantee that
  a contract is never exposed.
- **`protected` vs `sealed`** differ only in *may it ever be materialized*: protected may be shared
  (see §5), sealed may not. Byte delivery is identical (PHP + ACL from `data/blobs`).
- **`public`** is the only mode that materializes; the `AccessControlService` does **not** run for
  it (a public file is open to everyone, crawlers included). "Public" is therefore **not** a GUEST
  ACE — it is a `deliveryMode`.

**Inheritance (effective mode = own if set, else nearest ancestor folder):**

| ancestor mode | effect on descendants |
|---|---|
| **sealed** | **strict** downward cap (vault) — a descendant can never be opener than a sealed ancestor |
| **protected** | **none** — descendants are free in both directions |
| **public** | **soft, live default** — descendants inherit `public` unless they override **stricter**; a new file dropped into a public folder is automatically public |

**Root default (top-level, no ancestor) = `protected`** — safe (never open without an explicit
action) and practical; `sealed` is set deliberately where "must never leave" applies.

### 4. Authorization = ACL (only inside `protected`/`sealed`)

A new `AccessControlEntry` (ACE) holds one grant:

```
id, resourceType(folder|document), resourceId,
subjectType(user|role), subjectId(userId | roleName), rights(read|write|manage),
createdBy, createdAt
```

- **Subject = `user | role`.** Role reuses the existing `AuthRole` ladder (`member`, `visitor`, …);
  e.g. a "members area" folder is a folder with a `role:member` `READ` ACE. **GUEST is removed** as
  a subject — public access is `deliveryMode = public`, not an ACE.
- **Rights = `READ` / `WRITE` / `MANAGE`.** `MANAGE` covers rename/move/delete. No separate `SHARE`
  right.
- **Inheritance = additive**, over the ancestor-folder chain. No break / `isolated`.
- **Owner = implicit full; Admin role = bypass** (superuser sees/manages everything).
- The ACL is consulted **only** for `protected`/`sealed` delivery; `public` bytes are static and
  ungated.

Effective rights for a principal on a `protected`/`sealed` resource:
`owner OR admin-bypass ? full : union of ACEs on the document + every ancestor folder whose subject
matches the principal (a user id) OR one of the principal's roles`.

### 5. Sharing — an additive `Share` entity (only on `protected`)

Sharing is **additive**: a protected document can be shared **without** changing its
`deliveryMode`, and it can be in **0..n** shares at once (different keys, different recipients).

```
Share     : id, key(unique, random), createdBy, createdAt, expiresAt(null), active
ShareItem : id, shareId, documentId, relPath(path under public/share/<hash>/)
```

- **Two stages.** A *display gate*: the share URL carries the `key`; `AccessControlService`
  validates it against the stored share keys and renders the **template** listing the collection.
  And a *byte stage*: the shared files are **materialized** into `public/share/<hash>/` and served
  statically. The random `<hash>` directory is the obscurity boundary; whoever knows it (or a
  file's direct URL) has access — deliberately accepted for shares.
- **Layout** mirrors the folder hierarchy: single/multiple shared files land directly in
  `public/share/<hash>/<upload-name>`; a shared folder becomes
  `public/share/<hash>/<folder-name>/…`.
- **`sealed` forbids sharing; `public` needs no share** (already open).
- **Revoke = delete the share record AND its `public/share/<hash>/` directory.** Invalidating the
  key alone is not enough — the static byte URLs would otherwise remain reachable.

### 6. `active` (content state) — orthogonal to delivery mode and ACL

`active` (bool) on `Folder` and `Document` controls **display/output**, not permission and not
materialization mode. Public output and the normal listing require `active = true` (self +
ancestors) **AND** (for protected) effective `READ`; the management surface (owner / `MANAGE` /
admin) still shows inactive items so they can be toggled back on. Consistent with
`Navigation.active`.

### 7. `visibility` and `publicPath` are removed

Both are replaced by `deliveryMode` (visibility/materialization) + the ACL (who may, inside
protected). There is no `publicPath` lookup key — public URLs are structural (§8).

### 8. Media delivery — structural URL, static/PHP split

The byte URL is **structural** and built from the folder hierarchy + the file's (URL-sanitized)
upload name:

```
/media/{area}/{folder-path…}/{upload-name}      (+ image variant as a filename suffix)
```

Path segments are **URL-safe slugs** derived from the display/upload name on write (spaces, umlauts
sanitized) — never raw user input concatenated into a filesystem path; blob bytes stay id-addressed
(no traversal). One URL serves both modes via the web-server `!-f` switch:

- the file **exists** statically under `public/media/…` (→ `deliveryMode = public`) → the web server
  serves it directly, no PHP;
- it does **not** exist (→ `protected`/`sealed`) → falls through to `index.php` → a **reserved
  route** → the `dms` `OutputController` resolves the principal (session: user | guest) → `active`
  gate → effective `READ` → `FileResponse` from `data/blobs`, or **404** (existence never leaked).

Shares use a separate `/share/{key}` route (the display gate of §5).

The reserved-route tier (path-prefix → `{module,group,controller,action}`, declared in module
config) is evaluated **before** NavigationAlias. The phantom navigation node (id 26) and its alias
(id 8) are **deleted** — an infrastructure endpoint must not live in the navigation tree.

### 9. A dedicated `dms` module owns the feature

The domain (entities, repositories, `DocumentService`, the ACL/authorization policy, the
materialization job, `SaveService`/`UploadService`, `DocumentKind`, image profiles, GD) stays in
`shared`; `BlobStorage` stays in `persistence`. A new **`module-dms`** package owns the user-facing
surfaces (logged-in user/admin management = "drive"; supplier upload + API later), the authorized
**output controller**, the **share** controller, and their reserved-route registration.

**Open / deferred:** the precise surface decomposition and the logged-in "drive" shell (own layout
vs. reuse) are NOT decided here — only that the feature has one module owner and one authorization
policy.

## Reasoning

- **Two axes beat one flag.** `deliveryMode` (where/how) and the ACL (who, inside protected) are
  independent concerns. Folding "public" into a GUEST ACE (the 2026-06-17 design) conflated *making
  a file open* with *serving it through PHP*, and it could not express "members-only" (authenticated
  PHP+ACL) distinctly from "public" (static, crawlable). The ladder also gives the **`sealed`
  vault** a structural home — a guarantee a flag/ACE could not make.
- **Static public, PHP protected — driven by cost, not cacheability.** A browser caches a PHP
  response via `immutable`/`ETag` just fine; what static delivery saves is a **PHP worker per first
  hit + per revalidation**, which matters for heavily-requested public images. Protected bytes are
  low-volume, authenticated, and may not sit in the docroot — so they stay on PHP.
- **Materialization is a projection, not a second source.** Because the docroot copy is
  deterministically regenerable from blob + metadata and carries no own state, there is no sync
  conflict: a mode/`active` change adds or removes the materialization; an original change
  re-writes it (live, not a snapshot).
- **Structural `/media` + inheritance preserves ADR-016's core win.** Blobs stay id-addressed and
  move stays metadata-only; "folder public ⇒ children public" is just the public inheritance
  default. A denormalized `publicPath` would have reintroduced the N-write reparent problem ADR-016
  rejected (layout C).
- **Sharing as its own entity** matches reality (n shares per file, additive to protected, with key
  + expiry + revoke) — which a single `deliveryMode = shared` value or a `MANAGE` flag cannot model.
- **The reserved-route tier ends the navigation abuse;** **separating `active`** keeps content state
  independent of both delivery mode and authorization (mirrors `Navigation.active`); **a dedicated
  module** ends the scatter across `module-backend`/`module-frontend` + a phantom nav node.

## Consequences

- **Entities change.** `Folder` gains `ownerId`, `active`, `slug`, `deliveryMode`. `Document` gains
  `ownerId`, `active`, `slug`, `deliveryMode`, `width`, `height`; loses `visibility`, `publicPath`.
  New `AccessControlEntry`, `Share`, `ShareItem` entities + repositories.
- **New `AclService` / authorization policy** in `shared`: effective-permission resolution (owner /
  admin-bypass / ACE union over ancestors, subject `user|role`), the `deliveryMode` effective-mode
  + sealed-cap resolution, and the `active` gate — consulted only for protected/sealed delivery.
- **New materialization job** in `shared`: idempotent publish/share/unpublish to `public/media/…`
  and `public/share/<hash>/…`, regenerable from blob + metadata.
- **`DocumentService` is refactored:** drop `publish`/`unpublish`/visibility; add `setDeliveryMode`,
  ACL grant/revoke (user/role), ownership, `active` toggle, `share`/`unshare`, and a **structural
  `resolve(area, segments)`** (walk folder path → document, slug-based).
- **`MediaController` → `OutputController`** under `module-dms`, behind the reserved route; a
  separate share controller for `/share/{key}`. `servePublic`/visibility logic replaced by the
  `deliveryMode` + `active` + ACL gates.
- **Router gains a reserved-route precedence tier** (touches `routing.md`, core `Router`/`Request`).
  Seeds drop the phantom nav node id 26 + alias id 8. A web-server `!-f` rewrite serves
  `public/media` statically and falls through to `index.php` otherwise.
- **Doctrine-readiness (ADR-016) still holds.** The ACL/share tables are naturally relational; File
  driver lookups are O(n) but bounded until the planned Doctrine switch.
- **Engine retained unchanged:** `BlobStorage` id-layout, GD derivatives, two-phase `SaveService`,
  `FileResponse`, `DocumentKind`, image profiles, `Mailer`.
- **No production data migration:** the framework is pre-release and `skeleton/` is ephemeral; seeds
  are regenerated. The built-but-superseded `visibility`/`publicPath` paths are removed, not migrated.
- **Deferred:** account/tenant ownership; named user groups/teams as ACL subjects; share expiry
  policy + key-generation details; image-variant filename scheme; break-inheritance / deny ACEs;
  surface decomposition + drive shell; API auth (token → principal).
- **Rejected (2026-06-23):** web-server-accelerated byte delivery (`X-Sendfile`/`X-Accel-Redirect`/
  LiteSpeed) — see Rejected Alternatives. Byte transfer is always the portable PHP range-stream.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Public = a GUEST `READ` ACE, all bytes via PHP (the 2026-06-17 design) | Conflates "open" with "served by PHP"; cannot distinguish members-only (PHP+ACL) from public (static); no structural home for the `sealed` vault; a PHP worker per public-image hit. |
| Keep ADR-016 `visibility` flag + `publicPath` | Cannot express confidential multi-actor sharing; `publicPath` denormalization reintroduces the N-write folder-reparent problem (ADR-016 layout C). |
| `shared` as a third `deliveryMode` value | Sharing is additive and n-per-file (with key/expiry/revoke); an exclusive enum value cannot model a file that is protected *and* shared, or shared twice. |
| Materialized copy as a second source of truth | It is a regenerable projection of blob + metadata with no own state — so it is not a competing source and has no sync problem. |
| Static copy for shares with no PHP gate | The display/template gate (key) gives a managed entry point + revoke; the static `<hash>` dir is only the byte/obscurity layer beneath it. |
| Route `/media` via NavigationAlias (status quo) | Abuses the navigation tree with a phantom page node; navigation is site structure, not a routing table. |
| Web-server-accelerated delivery (`X-Sendfile` / `X-Accel-Redirect` / LiteSpeed) | Not portable — the target shared host (cyon) ships no `mod_xsendfile`, so it does not work where the framework must run. The protected bytes are low-volume + authenticated, so the portable PHP range-stream (with `ETag`/`Range`/`206`) is sufficient. `FileResponse` keeps a single delivery path; no `delivery`/`internalPath` config. |
| Separate `SHARE` ACL right | Sharing is its own `Share` entity; ACL rights stay `READ`/`WRITE`/`MANAGE`. |
| ACL with deny / break-inheritance now | Additive-allow is simpler and sufficient for the current scope; deny/isolation deferred. |
| Management UI in `module-backend` only | Contradicts the no-backend customer drive and the supplier/API actors. |
| `active` as an ACL right or a delivery mode | Conflates content state with authorization/materialization; three independent axes (mirrors `Navigation.active`). |
