# Bauplan — images from the DMS for content and project forms

**Status:** P1 + P2 built and committed 2026-11-11; P3 is the project's
**Owner decisions (2026-11-11):** customers manage texts and images themselves; new pages stay
developer work; the framework provides as much as possible, because every new site has the
same needs — a project's own backend screens (e.g. z77.ch «Webseiten › Projekte») are built
in its override on top of it.
**Request:** z77.ch (session z77-ch-48), answer of 2026-11-11: needs (b) user upload into a
folder, (a) a reusable picker, (c) «ensure public», (d) a `document` field kind + DMS image
block for content pages.
**Builds on:** the agreed design in [`content.md`](../topics/content.md) → pending
«DMS-referencing block + `document` field kind» (2026-07-02); ADR-047 (controller-led
windows); the shared upload component (`Z77\Shared` `partials/upload`, `UploadPolicy`).

---

## What exists (verified 2026-11-11)

| Piece | Where | Note |
|---|---|---|
| User upload path | `UploadService::save($file, $folderId, $profile, …, $createdBy)` | session principal, `authz->require('folder', id, 'write')`, allowlist on sniffed bytes, image profile, duplicate / name conflict. z77.ch's 2(b) «missing» is only the UI. |
| Upload component | `partials/upload` + `upload.js`, `UploadPolicy` | shapes `drop` / `cell` / `field` (one file inside a form), no-JS fallback, transports (chunked). |
| Drive | `DriveControllerTrait` (module-dms), used by module-backend `Documents/DriveController` | list, panes, `uploadAction` (fetch), folder tree, ACL, previews `s/m/l/xl`. No pick mode. |
| System write path | `DocumentService::forModule($key)` → `ModuleDrive` (`root()`, `folder()`, `saveGenerated()`) | trusted, no principal — for jobs, NOT for a user's upload. |
| Public URL | `DocumentService::publicUrl($doc, $variant)`, `mediaUrl($path)` | id-addressed, version token; 404 for a non-public doc. Delivery mode per folder: `setFolderDeliveryMode($folderId, $mode)`. |
| Image profiles | `ImageProfileRegistry` default `s/m/l/xl` (xl = w 2048); project profiles in `imageProfilesConfig` per partition | |
| Windows | ADR-047 WIN: several windows, the opener known, the controller answers with commands | the picker opens as such a window. |
| Block editor | `edit.tpl.php` renders a field per `kind` (`text`, `textarea`, `select`, `bool`, `url`, `list`); `content/editor.js` clones `<template>`s for blocks and list items; `BlockSchemaValidator` checks kinds | |
| Module blocks | module config `contentBlocks` (frontend does it), `contentBlueprints` | a DMS block lives in module-dms — `shared → module-dms` is forbidden. |

## Decisions in this plan (to confirm at review)

1. **ONE media field, two hosts.** A partial `partials/mediaField` (module-dms) renders: the
   thumbnail of the chosen document (or an empty frame), «Auswählen», «Entfernen», and a
   hidden input carrying the document id. Any backend form uses it — the content editor's
   `document` kind and a project form (z77.ch Projekte) alike.
2. **The picker is a DMS window, choosing AND uploading.** «Auswählen» opens a WIN window
   (`/backend/documents/drive/pick?…`): folder tree, image grid of the folder, and the upload
   component in `field`/`drop` shape that uploads into the shown folder through
   `UploadService::save()` (the user path). A click on an image answers with commands that
   fill the OPENER's media field (id + thumbnail) and close the window. Uploading and then
   choosing is one flow, not two widgets.
3. **Scope of the picker** is a parameter, not a second picker: `root` (a module partition
   or folder id — the project screen passes its own, the content editor the content
   partition), `accept` (`image` for now), `public` (only documents that are delivered
   publicly may be chosen).
4. **Public is shown and enforced, never switched implicitly.** With `public=1` a document in
   a non-public folder is shown disabled with the reason; uploading goes only into a public
   folder. Making a folder public stays an explicit act: `ModuleDrive::ensurePublic()` for a
   module's own partition (z77.ch 2(c)), the Drive's existing switch for everything else.
   Switching a folder public as a side effect of a pick would publish its other documents.
5. **Content blocks live in module-dms:** `media-image` (one image: `documentId`, `alt`,
   `caption`, optional `link`) and `media-gallery` (list of `{documentId, alt, caption}`).
   Storage by id (rename/move-safe), rendered with `publicUrl()` and `<img srcset>` over
   `s/m/l/xl`, `loading="lazy"`, `width`/`height` from the variant where known. A missing
   or non-public document renders nothing (block: `''`, gallery: that item skipped).
6. **The core `image` block stays URL-based** (external images, existing documents). No
   resolver seam in `shared` for now — revisit only if a second consumer appears.
7. **Alt text is required** in the media blocks (validator), because the customer is the
   author now and the page is public.
8. **`document` kind in the shared validator** checks only the SHAPE (positive int or
   empty); whether the id exists and is public is the renderer's and the picker's business —
   `shared` knows nothing of the DMS.

## Phases

### P1 — picker window + media field (module-dms, module-backend)

**P1 — built 2026-11-11 (not committed, owner review):** `Ui\MediaPickerTrait` next to
`DriveControllerTrait` in the backend's `Documents/DriveController` — `pickAction` (GET: the
window; POST `doc=<id>`: the choice, the article picker's pattern) and `pickUploadAction` (one
file through `DriveControllerTrait::storeUpload()` → `UploadService::save()`, the helper the
Drive's `uploadAction` now shares); `Ui\MediaField` (address, thumbnail, «may be chosen» —
one place); `partials/mediaField` + `partials/mediaFieldThumb` + `documents/media-field.js`;
`ModuleDrive::ensurePublic()`; core.js windows over a modal dialog (WIN-002); styles
`module-dms` `components/_media.scss` compiled into the backend bundle. Harnesses
`tests/dms-media-picker.php` (77/0), `tests/core-js-windows-browser.mjs` (18/0). Rules and the
contract for P2b: `documents.md` MEDIA-PICK-001. **Deviations:** no `pickListAction` /
`pickChooseAction` — a folder click reloads the window (`data-window-link`, so the upload is
bound again with the new folder), the choice is the POST of `pick`; the opener is addressed by
the field's DOM id (`target`), re-keyed by the script for a clone; `ensurePublic()` refuses a
`sealed` partition. **Open:** owner's look and a
click-through on a real installation.

**For P2b (the editor):** render `partials/mediaField` with `name` = the block field's input
name and `value` = the id; the field root's id is generated (or pass `id`). Clone the list
item's `<template>` as today — the duplicated id is re-keyed at the click; optionally run
`_Z77.scriptInit['dms-media-field'](clone)` after inserting. Load `documents/media-field`
(`Z77\Module\Dms`) with a `load-script` command in the editor's answer. The value arrives in
the hidden input `[data-media-field-input]` with a bubbling `change`.

- `DriveControllerTrait` (or a sibling `MediaPickerTrait` used by the same backend
  controller): `pickAction` (window: tree + grid + upload), `pickListAction` (fetch: one
  folder's grid), `pickChooseAction` (answer: commands to the opener), the upload inside the
  window reusing `uploadAction`'s path with a pick-mode answer.
- `partials/mediaField` + its JS hook (`_Z77.scriptInit`), thumbnail via
  `document/preview?id=&variant=s`.
- Works from a page form AND from inside the content editor's popup (verify window over the
  shared popup; fix in core.js if needed — fetch.md / ADR-047 rules apply).
- `ModuleDrive::ensurePublic()` (z77.ch 2(c)) — sets the partition root's delivery mode to
  public if it is not; idempotent.
- Tests: a harness for the pick actions (ACL: only readable folders, only images with
  `accept=image`, `public=1` disables non-public ones, upload lands in the shown folder with
  `createdBy`), `ensurePublic` idempotent.
- Docs: `documents.md` (rules + file map), `fetch.md` if the window contract grows.

### P2 — content: `document` kind + media blocks (shared, module-dms, module-backend)

**P2a — server side, built 2026-11-11 (not committed, owner review):** kind `document` in
`BlockSchemaValidator` (shape only; int or plain digit string, `documentId()` is the one
reading), `media-image` / `media-gallery` in module-dms `Content/Renderer/` (base
`MediaBlockRenderer`, registered in `dmsConfig` `contentBlocks`), the public check as ONE
predicate `DocumentService::isPubliclyDelivered()` (now also used by `materialize()` and
`publicFileForPath()`), default look `module-frontend` `components/_media.scss`, harness
`tests/content-media-blocks.php`, docs `content.md` / `block-types.md`. Deviations: srcset takes
every variant with the original's proportions (not only `s/m/l/xl` — a project profile has
no `l`/`xl`; crops stay out) and adds the original when the ladder stops below 1024 px
(preserved original); a non-image document renders nothing too.

**P2b — built 2026-11-11 (not committed, owner review):** `edit.tpl.php` renders kind
`document` as `partials/mediaField` (top level and in a gallery row `<template>`; hidden input
with `data-bf` / `data-bf-sub`, no `name`), object rows got ↑ / ↓ (`editor.js`, `editor.css`),
`documents/media-field` loads with the editor (popup and slot page). The asset store is a seam,
not a hard link: kernel interface `Content\DocumentReferences`, declared once in a module
config (`contentDocuments`, module-dms `Content\ContentDocuments`), read through
`ContentExtensions::documents()`. The save (editor + slot) judges a CHANGED id —
`ContentValidator::useDocuments()`: exists + readable, `accept` kind, publicly delivered,
else a German field error with the picker's wording; a STORED id is not asked again.
**Decision 2 (the owner may overrule):** a non-public image is REFUSED, not saved with a
warning — the page is public and would show nothing; a preview renders through the same
public renderer, so no flow needs it. **Decision 3:** the content partition is the module key
`content` through `forModule('content', 'Inhalte')`, remapped only via `moduleFolders`; made
public when created (`ModuleDrive::created()`), never afterwards. `MediaField::publicRefusal()`
now asks `isPubliclyDelivered()` first and only names the reason. Harness
`tests/content-media-editor.php` 31/0; end to end in Chrome 14/0. **Resolved after the review (owner 2026-11-11):** pick / pick-upload / preview are EDITOR;
a fresh `content` partition grants `editor` write, `admin` manage (`ModuleDrive::grantRole`). **Open:** `.min.js` of
`content/editor.js`, `core.js`, `media-field.js`.

- `BlockSchemaValidator`: kind `document` (shape only), `required` honoured.
- `edit.tpl.php` + `content/editor.js`: kind `document` renders `partials/mediaField`; works
  inside a list item `<template>` (gallery) — the opener reference must survive cloning.
- module-dms config `contentBlocks`: `media-image`, `media-gallery` with `schema()` and
  renderers (srcset, lazy, skip missing).
- Tests: validator, renderers (srcset, missing doc skipped, non-public skipped, alt
  escaped), editor template renders the field incl. inside a gallery item.
- Docs: `content.md` (pending item → resolved, rules), `block-types.md`.

### P3 — z77.ch adopts (project, not this repo)

z77.ch switches `ProjectImages::store()` from `saveGenerated` to `UploadService::save()`
(possible today) and its project form to `partials/mediaField` + the picker. Reported to
z77-ch-48 after P1 is committed.

## Out of scope

- New pages / page creation by customers (owner: developer work).
- Collections with teasers (blog, sub-pages — the wdv «Teaser» idea): own bauplan later.
- A toolbar for inline formatting in the editor, the editor-side menu view (active / name):
  separate items.
- Image cropping / focal point in the browser.

## Open points

- ~~Does a WIN window open cleanly over the content editor popup and over the slot editor's
  iframe page?~~ Answered 2026-11-11: over the popup NO — it stood behind the modal dialog,
  unreachable; fixed in core.js (fetch.md WIN-002). Over the bare page yes, unchanged (the
  window fills the small iframe).
- ~~Which partition do content images go to by default?~~ Answered 2026-11-11 (P2b): module
  key `content` (partition «Inhalte», public when created), remapped through
  `dmsConfig['moduleFolders']` — no second config place.
- ~~Who may pick~~ Answered 2026-11-11: an editor — pick, pick-upload and preview are EDITOR; the DMS ACL
  decides the rest.
