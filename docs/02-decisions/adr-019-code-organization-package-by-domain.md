# ADR-019 — Code organization: package by domain, not by layer

**Status:** `[APPROVED]`
**Date:** 2026-07-01

> **Revision 2026-07-01:** `UploadedFile` stays in `shared/ValueObjects` (NOT `module-dms`, contrary to
> the target list below). It is a domain-less HTTP VO produced by `core/Http/Request` — moving it into
> `module-dms` would make `core → module-dms` cyclic (forbidden by the dependency-direction constraint
> of this ADR). Everything else in the DMS target moves as specified. See
> [`../03-development/dms-extraction-bauplan.md`](../03-development/dms-extraction-bauplan.md).

> **Revision 2026-07-09 (ADR-023):** `core`, `shared` and `persistence` now ship as a single
> Composer package `z77/kernel` (three PSR-4 namespaces — `Z77\Core`/`Z77\Shared`/`Z77\Persistence` —
> separated by directory only). The domain-by-vertical-slice rule below is unchanged; this only removes
> the circular Composer dependency among the three foundation packages, which had no acyclic ordering.
> See [`adr-023-kernel-package-core-shared-persistence.md`](adr-023-kernel-package-core-shared-persistence.md).

---

## Context

`packages/shared` is turning into a god-package. It is organized **by technical
layer** — `Entities/`, `Repositories/`, `Services/`, `Validators/` — with every
domain's classes piled into the same layer folders. Each new feature sprinkles one
file into `Entities/`, one into `Repositories/`, one into `Services/`, so the pile
grows without bound, nothing that belongs together sits together, and everything is
coupled to a single package.

Evidence (2026-07-01):

```
shared/src/Entities/      → Document, Folder, AccessControlEntry   (DMS)
                            Content, MetaData                       (Content)
                            Navigation, NavigationAlias, NavigationGroup (Navigation)
                            LoginUser                               (Auth)
shared/src/Repositories/  → one per entity, same four domains mixed
shared/src/Services/      → AclService, DocumentService, SaveService, UploadService  (DMS)
                            ContentService                          (Content)
                            AuthService, CurrentUserService         (Auth)
```

A single DMS domain is currently split across **four** top-level folders
(`Documents/`, `Entities/`, `Repositories/`, `Services/`) plus `ValueObjects/`
(`Principal`, `UploadedFile`) and `persistence/Blob/` (`BlobStorage`).

The direction to fix this is already latent in earlier decisions:

- **ADR-005** — "`group` is a UI/navigation namespace, **NOT** a business-domain
  boundary. **Modules already express domain boundaries.**"
- **ADR-012** — took content consumption out of the DI junk-drawer into factories
  and named the next step explicitly: "**module-owned service providers**."

This ADR formalizes the organizing rule and resolves where a domain physically
lives.

## Decision

Organize code **by domain (vertical slice), not by technical layer.**

### Rule 1 — global by domain, inside a domain by layer

Two levels, not one:

- **Global level → by domain.** The catch-all cross-domain layer folders
  (`Entities/`, `Repositories/`, `Services/`, `Validators/`) are dissolved; each
  domain is one folder (`Auth/`, `Content/`, `Navigation/`, …) or one package
  (`module-dms`).
- **Inside a domain → by layer.** Each domain keeps its own layer subfolders:
  `Entities/`, `Repositories/`, `Validators/`, `Services/` (+ domain-specific
  subfolders like `Content/Rendering/`). Namespace: `Z77\Shared\Content\Entities\Content`,
  `Z77\Shared\Content\Repositories\ContentRepository`, etc.

**Why a dedicated `Entities/` per domain (not a flat domain folder):** the planned
Doctrine ORM switch **registers entity *directories*** for mapping. Entities must
therefore sit in a domain-owned `Entities/` directory that Doctrine can be pointed
at (`shared/Content/Entities`, `shared/Auth/Entities`, `module-dms/src/Entities`).
Mixing entities with repositories/services in one folder is not Doctrine-mappable.

**Entity → repository → validator pairing.** A domain has **N entities**; each
entity has a `{Entity}Repository` (ADR-009 auto-wiring) and, where it is
user-writable, a `{Entity}Validator`. These live in the domain's `Repositories/` and
`Validators/` subfolders next to `Entities/`. (Server-controlled entities may have no
validator — e.g. DMS today.)

### Rule 2 — `shared` holds only domain-less primitives

`Attributes`, `Libraries` (Naming/Convention), `Traits`, the `Controller` trait, and
the `Tree` foundation. Kept deliberately small. Note: there is **no** generic
validator or value-object base today — every existing `Validator`/`ValueObject` is
domain-specific and moves into its domain (Rule 1), so those catch-all folders
disappear entirely.

### Rule 3 — placement ladder (which package a domain lives in)

- **(a) Clean, extractable slice → its own package** (a `Z77\Module\*` package).
  Criteria, ALL of them: narrow, stable public API; no host plug-in points; not
  depended on by `core`/the router; DAG-safe (consumers depend on it; it depends
  only downward on `core`/`shared`/`persistence`). → **DMS.** Hosts
  (`module-backend`, `module-frontend`) become pure consumers.
- **(b) Entangled domain → a domain folder inside `shared`** (not its own package).
  Triggers: coupled into `core`/the router (Navigation's URL resolution lives in
  `core/Services/NavigationUrlResolver`), or it requires hosts to plug in pieces
  (Content's block renderers exist per host — `module-frontend/Content/Renderer/*`),
  or it is otherwise not cleanly separable. → **Navigation, Content** stay in
  `shared`, but consolidated each into one domain folder.
- **(c) Domain-less primitive → `shared` root** (or `core`).

### Rule 4 — "cross-module" is NOT the placement criterion

Both DMS and Content are consumed by more than one host. That alone does not decide
placement. The criterion is **extractability** (clean slice vs entangled). This
refines ADR-012's incidental rationale ("shared is the layer both depend on").

### First concrete move

Extract the **DMS vertical** into `module-dms` (target structure below): the domain
(entities, repositories, services incl. `AclService`, image pipeline,
`Principal`/`UploadedFile`, `BlobStorage`) plus the **Drive UI fragment** currently
in `module-backend`. Hosts embed the `.dms` fragment (ADR-018) and consume
`DocumentService`.

```
module-dms/                           namespace Z77\Module\Dms
  src/
    Entities/        Document, Folder, AccessControlEntry          ← Doctrine registers this dir
    Repositories/    DocumentRepository, FolderRepository, AccessControlEntryRepository
    Validators/      (none yet — DMS entities are server-controlled; add per entity when needed)
    Services/        DocumentService, SaveService/SaveRequest, UploadService, AclService
    Images/          DocumentKind, ImageProcessor, GdImageProcessor, VariantSpec,
                     ImageProfile, ImageProfileRegistry, ProcessedVariant
    ValueObjects/    Principal   (UploadedFile stays in shared — core produces it, move = cycle; see Revision 2026-07-01)
    Blob/            BlobStorage, LocalBlobStorage                 (← from persistence)
    Delivery/        OutputController                              (already here)
    Ui/Controllers/  DriveController (+ Document/Folder)           (← from module-backend)
    App/Config/      dmsConfig                                     (already here)
  res/  scss/ (.dms tokens+components), assets/js/ (drive.js, upload.js), view/templates/ (Drive)
```

Navigation, Content and MetaData are **not** extracted (Rule 3b); they are
consolidated into per-domain folders inside `shared` in a later, separate step. The
target for `shared/src` (existing files sorted by domain, DMS removed):

```
shared/src/
  # Primitives (domain-less) — Rule 2
  Attributes/   Clean, Entity, Fetch, HttpMethod, Page
  Controller/   RouteInfoTrait
  Libraries/    Cleaner/StringCleaner, Convention/{LayoutDefaults, Naming}
  Traits/       ArrayMappable
  Tree/         AnchorViolation, ElementAnchorRules, TreeNode, TreeNodeTrait, TreeService

  # Domains — global by domain, inside by layer (Rule 1)
  Auth/
    Entities/      LoginUser
    Repositories/  LoginUserRepository
    Validators/    LoginUserValidator
    Services/      AuthService, CurrentUserService
    AuthUser.php, UserPreferences.php, PasswordPolicy.php, PasswordTier.php   (domain VOs/policies)
  Content/
    Entities/      Content
    Repositories/  ContentRepository
    Validators/    ContentValidator
    Services/      ContentService
    Rendering/     BlockRegistry, BlockRenderer, BlockView, ContentRenderer,
                   DefaultBlockRegistry, InlineMarkdown, Renderer/{Heading,Image,List,Text}Renderer
  MetaData/                                     ← own domain (SEO per page; keyed by nav id + lang)
    Entities/      MetaData
    Repositories/  MetaDataRepository
    Validators/    MetaDataValidator
  Navigation/
    Entities/      Navigation, NavigationAlias, NavigationGroup
    Repositories/  NavigationRepository, NavigationAliasRepository, NavigationGroupRepository
    Validators/    NavigationValidator, NavigationAliasValidator, NavigationGroupValidator
    (Services stay in core/: NavigationService, NavigationUrlResolver — router-coupled)
  Mail/           Attachment, MailTransport, Mailer, Message, MimeMessage, SmtpTransport   (no entity → flat)
```

The dissolved catch-all folders (`Entities/`, `Repositories/`, `Services/`,
`Validators/`, `ValueObjects/`, `Documents/`) disappear entirely.

## Relationship to ADR-005

ADR-005 defines a "module" as a Composer package + `Z77\Module\*` namespace with
optional URL `group`s. This ADR clarifies: a package may be a **domain library +
embeddable fragment** (`module-dms`) without being a full view-area with URL groups.
ADR-005's rules are unchanged — `group` stays UI-only, domain boundaries live at the
**package** level (exactly "modules express domain boundaries"), and any UI a domain
package exposes still follows ADR-005's group-nested namespace/template/config
conventions.

## Collisions with existing ADRs (resolve on approval)

1. **ADR-017 — direct collision (placement only).** ADR-017 explicitly puts
   `AclService` and the materialization job in `shared`, keeps `BlobStorage` in
   `persistence`, and gives `module-dms` only the `OutputController`/surface
   (its "implementation surface" list, ~lines 181/219/222/227). This ADR
   **supersedes those placement lines**: the whole DMS domain — services incl.
   `AclService` + materialization, entities, repositories, `BlobStorage` — moves
   into `module-dms`. **All ADR-017 domain decisions stay valid** (ownership, ACL
   model, `deliveryMode` ladder, structural `/media`, additive `Share`); only the
   physical location changes. *Action:* add a revision note to ADR-017 pointing
   here; do not touch its domain decisions.

2. **ADR-012 — soft collision (rationale refined, decision intact).** ADR-012
   justifies `ContentService` living in `shared` with "content is cross-module …
   shared is the layer both depend on." This ADR **refines the criterion** to
   extractability (Rule 4): cross-module alone is not sufficient. Content is
   *entangled* (host block renderers), so it correctly stays in `shared` — now as a
   domain folder. ADR-012's actual decision (consumption services are factories, not
   DI registrations; "module-owned service providers" as the future) **stands and is
   reinforced.** *Action:* no change to ADR-012's decision; this ADR supplies the
   finer placement rule it anticipated.

Supporting, no conflict: **ADR-005** (modules = domain boundaries), **ADR-018**
(`module-dms` as an embeddable fragment — moving the Drive UI in *completes* it).

## Constraints / to watch

- **`{Entity}Repository` auto-wiring (ADR-009).** The `UnifiedEntityManager` pairs
  `{Entity}` with `{Entity}Repository` by convention. With per-domain layer folders
  the pairing now crosses subfolders — `…\{Domain}\Entities\{X}` →
  `…\{Domain}\Repositories\{X}Repository`. The EM MUST derive the `Repositories`
  namespace from the entity's `Entities` namespace within the same domain, not from a
  fixed `Z77\Shared\Entities` / `…\Repositories` pair.
- **Doctrine entity-directory registration (planned ORM switch).** Each domain's
  `Entities/` directory is a registrable mapping path (`shared/Content/Entities`,
  `shared/Auth/Entities`, `module-dms/src/Entities`, …). This is the reason entities
  get a dedicated per-domain subfolder rather than sharing the domain root — see
  Rule 1.
- **Autoload + installer discovery.** Composer PSR-4 + the installer's
  `Z77\Module\*` autodiscovery must include the moved namespaces; `composer install`
  re-publishes assets and regenerates `moduleManager.inc.php`.
- **Dependency direction.** `module-backend`/`module-frontend` → `module-dms`
  (allowed). `module-dms` MUST NOT depend on any host module (no cycles); it may
  depend on `core`/`shared`/`persistence` only.
- **Incremental, not big-bang.** DMS first (clean slice). Navigation/Content only
  get the domain-folder consolidation, no extraction. One domain at a time, each
  step leaving the framework runnable.

## Consequences

- `shared` shrinks from "everything" to "a few primitives"; each domain is visible
  as one unit.
- Adding a feature: create/extend a domain folder or package; touch `shared` only
  for a genuine primitive. New business modules follow the same rule from day one.
- DMS becomes self-contained; "hosts are consumers" holds for **logic and UI**.
- To watch: risk of premature extraction — extract only slices that meet **all** of
  Rule 3a; when in doubt, keep it as a `shared` domain folder (Rule 3b).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Keep `shared`, only re-namespace internally | Doesn't break coupling — `shared` still depends on everything and the layer piles keep growing. Cosmetic. |
| Extract **every** domain to its own package now (incl. Navigation, Content) | Navigation's resolver is router-coupled in `core`; Content needs host-supplied block renderers. Forcing extraction creates dependency cycles or artificial seams. Extract only clean slices. |
| One shared UI/component base package (`module-ui`) for the DMS view | Already rejected by ADR-018 — couples every area to one component library; an embedded DMS would inherit host component styling. The `.dms` prefixed fragment is the chosen path. |
| Leave DMS logic in `shared` (status quo per ADR-017) | Keeps the god-package growing and splits DMS across four folders + two packages; contradicts "modules express domain boundaries" (ADR-005) and the "module-owned service providers" direction (ADR-012). |
