# ADR-044 — Content blueprints: a fixed, keyed block structure per document

**Status:** `[APPROVED]`
**Date:** 2026-09-21 · addendum 2026-09-22 (variants and preview)

> **Note 2026-09-21 — first consumer (zihlundsee.ch, all pages).** Link target `tel`
> added (default targets now page, media, external, mailto, tel); `links.newTab`
> opens external links in a new tab (`target="_blank" rel="noopener"`); `break`
> emits exactly `<br>` (the newline is dropped) so converted copy renders byte-equal.

---

## Context

The content system (ADR-010, ADR-011, [`topics/content.md`](../topics/content.md))
stores a document per `(slug, language)` as an ordered array of typed blocks; each
`BlockRenderer` declares its field `schema()`, and the backend editor generates its
forms from it. The editor gives the author full control over the block stream:
add, remove, reorder blocks of any registered type.

Client sites with a designed layout need the opposite. The page layout is fixed by
the designer (intro, FAQ table, CTA — in that order, once each); the client may
change text and, within a section, add a paragraph or a list item — never delete
the intro or drop a second CTA in. This is the problem the `[IDEA]` doc
`03-development/ideas/content-template-system.md` names ("prescribe structure,
don't permit it"), and the reason zihlundsee.ch still keeps its page copy in
controller arrays instead of content documents.

Four gaps block that use today:

1. **No fixed structure.** A document is a free stream; nothing says which blocks a
   document must have, in what order, or that they are not removable.
2. **No named slots.** Bespoke templates read blocks by type (`Content::block($type)`);
   two blocks of one type are reachable only by index (pending item "keyed blocks").
3. **No bounds in the schema.** Descriptors have no `required`, `maxLength`,
   `min`/`max` for lists, and the server does not validate field values against
   the schema at all (`ContentValidator` checks JSON validity and known types only).
4. **Inline formatting is all-or-nothing.** `InlineMarkdown` applies bold, italic
   and links to every field rendered through `html()`; a heading cannot be limited
   to plain text, a body cannot get a line break.

A fifth, independent gap: `ContentController` saves without an optimistic lock, so
two editors (two tabs, or the backend and a file-level edit) overwrite each other
silently, although `EntityStateHash` + `EntityValidator::guardStoredState()` exist
and `NavigationController` already uses them.

## Decision

### 1. Blueprints are code, keyed by slug

A module declares **blueprints** in its config (`contentBlueprints`, next to
`contentBlocks`): a slug and an ordered list of **slots**.

```php
'contentBlueprints' => [
    'faq' => [
        ['key' => 'intro', 'type' => 'intro',    'label' => 'Einleitung'],
        ['key' => 'rows',  'type' => 'faqTable', 'label' => 'A–Z'],
        ['key' => 'cta',   'type' => 'cta',      'label' => 'Aufruf'],
    ],
],
```

A blueprint applies to every language of that slug. It ships with the release;
the document values stay in `data/content/` and are never touched by a deploy.
Content remains slug-addressed and page-independent — a blueprint describes a
document, not a page (the `topics/content.md` rule against a page/section field
on `Content` stands).

### 2. Blocks carry an optional `key`

A block may carry `key`; blueprint slots bind to blocks by key. New accessor
`Content::keyed(string $key): BlockView` (null-object when absent, like
`block()`). This closes the "keyed blocks" pending item for blueprint documents
and for free-stream documents alike.

### 3. The editor honours the blueprint

For a document whose slug has a blueprint:

- blocks are shown in slot order, one per slot, labelled with the slot label;
- no block add, remove or reorder — the stream controls are not rendered, and
  the server re-imposes the slot list on save (a crafted body cannot add a block);
- a slot with no stored block starts from the type's defaults;
- a stored block whose key is in no slot is preserved verbatim (as unknown types
  are today) and flagged in the editor, never silently dropped.

Documents without a blueprint keep today's free-stream editor unchanged.

### 4. Descriptors gain bounds and an inline profile (additive to ADR-011)

- `required` (bool), `maxLength` (int) — scalar fields.
- `min`, `max` (int) — `list` fields: the editor hides "add" at `max` and
  "remove" at `min`.
- `inline` — what inline formatting the field allows, as a list of
  `bold | italic | link | break`. Absent = **plain text** (escaped) on the
  schema-aware read path (section 5).
- `links` — for fields with `link`: `targets` (`page | media | external | mailto |
  action`, default all but `action`) and `localize` (bool, default false); see
  "Resolved questions".

What may be added in the backend is therefore decided per field in code, at the
time the blueprint is written, and can be widened later by a release — the
backend itself never decides structure.

### 5. Values are validated and rendered against the schema

- On save, the server validates every block against its type's schema:
  required, length, list bounds, URL scheme. Errors surface per field.
- Rendering gates formatting on a **schema-aware read path**:
  `ContentService::view($slug, $language)` returns a `ContentView` (document +
  schemas + project actions + document language); `ContentView::keyed($key)`
  yields a `BlockView` that knows its field descriptors, and `html()` applies only
  the features the field's profile allows — everything else stays literal text.
  The gate is on the render side, so stored data never becomes HTML beyond the
  profile, whatever the input path (backend, file edit, import).
- The legacy path is unchanged: `InlineMarkdown::toHtml($text)` without a profile,
  a `BlockView` without schema and the existing renderers keep bold, italic and
  links as before — no migration for existing sites.
- `break`: a newline in the value renders as `<br>`.

### 6. Optimistic lock on save

`ContentController` renders `EntityStateHash::of($content)` as a hidden field and
calls `guardStoredState()` before hydration, like `NavigationController`. A
changed document yields "neu laden", not an overwrite.

## Reasoning

- **Structure belongs to the designer, values to the client.** The blueprint is
  the designer's decision and has to agree with the templates and CSS that render
  it — so it lives in code, in the same release. Values are the client's and live
  in data. That split is also what makes a deploy safe: it can ship a new
  blueprint without touching a word of content.
- **Extends, does not replace.** One storage, one editor, one render pipeline.
  Free-stream documents keep working; blueprints are an opt-in constraint on top.
- **The IDEA doc's goal without its template language.** The idea prescribes
  structure via HTML templates with placeholders stored as data. Blueprints reach
  the same result with the existing schema (ADR-011): no second description of
  the same fields, no parser, and the structure cannot be edited into a broken
  state because it is not data.
- **Escape-first stays the only path.** No raw HTML in content, no sanitizer; the
  per-field inline profile narrows `InlineMarkdown`, it never widens it.

## Consequences

- A project converts a designed page by: writing its block renderers (type +
  schema + render), declaring the blueprint, seeding the documents, and switching
  the template to `Content::keyed()` reads.
- `ContentValidator` grows real field validation against the schema.
- Existing descriptors without the new keys behave exactly as before; the
  plain-text default applies only where a template reads through `ContentView`.
  A template that wants the gate reads `ContentView::keyed()`; one that reads
  `Content::block()` keeps the legacy formatting.
- `InlineMarkdown` now rejects protocol-relative targets (`//host`), which the
  content rule already required and the code let through.
- The backend list should group or mark blueprint documents (a tree per page is a
  separate, later concern).
- File-level editing outside the backend (e.g. an AI assistant pulling and pushing
  a document) needs the same lock at file level — compare the file's checksum at
  pull time with the server's before writing. That is tooling, not this ADR.

## Resolved questions (2026-09-21)

1. **Action links.** Running text links to an action with an explicit target
   scheme, e.g. `[unser Kontaktformular](action:contact)`. A module declares the
   actions once in its config (`contentActions`: name ⇒ `href` fallback page +
   `attributes`, e.g. zihlundsee's `data-apply-open`); a field allows them with
   the `action` target. Unknown actions render as literal text.
2. **Localised page links: a flag per field in the schema**, e.g.
   `'links' => ['targets' => ['page', 'media', 'action'], 'localize' => true]`.
   With the flag, site-relative page links are stored as the canonical alias
   (`/kontakt`, the same in every language) and rendered through
   `localizedUrl()` in the document's language — a slug rename in `route-slugs.*.json` then needs no content
   change. The flag lives in the schema (code), not on each link in the data: it
   is read where the structure is read, and the client never has to know it.
   Media (`/media/…`) and external links are never localised.
3. **Blueprint per slug.** No slug patterns: two matching patterns need a
   precedence rule nobody sees. Several slugs sharing one structure reference
   the same definition in code — explicit, one line per slug.

## Addendum 2026-09-22 — Variants and preview (text release)

**Problem.** A save in the editor is live at once; `active` only hides a whole
document. A writer delivery touches every page and must not stand half live, and
the client wants to see it before it goes out.

**Decision (Peter, zihlundsee session 2026-09-22).** No separate release
mechanism, no config file: the variant is part of the document's name.

- **Identity** is (slug, language, variant). `variant` is an optional key
  (`#[Entity(optionalKeys: ['variant'])]`): the live copy keeps its file
  `<slug>.<lang>.json`, a variant is `<slug>.<lang>.<key>.json`. Existing files
  are not renamed.
- **A set** is every document with the same key (`herbst-a7f3k2` = a readable
  name + 6 random hex, `ContentPreview::newKey()`). A set only holds the documents
  it changes.
- **Preview** is the URL parameter `?preview=<key>`: each document is read in the
  variant where one exists, live otherwise (lookup: variant → live in the
  request language, then the same in the default language). Callers pass the
  key explicitly (`ContentService::view($slug, $lang, ContentPreview::key())`);
  the service never reads the request.
- **Carried by `localizedUrl()`.** Every URL it builds gets the request's
  `preview` parameter, so a preview stays a preview from page to page. The state
  is in the URL only — no cookie, no session (the "no hidden cross-request
  state" rule). A form posted from a preview is a real submit.
- **Never cached, never indexed.** `PageCachePolicy` returns NewPage for any
  preview request (stated explicitly, not left to the query-string rule), and the
  frontend head emits `noindex, nofollow`. The frontend skeleton has a body slot
  `preview` for a project's notice ("this is not the live text").
- **Publish** a set in the backend: each live copy is archived as a variant of
  one archive set `alt-YYYYMMDD-HHMM`, the variant becomes the live copy, the
  variant file is removed. Rollback = publish the archive set. Not atomic across
  files (each file write is); a document that had no live copy before is not
  removed by a rollback.
- **Cleanup is the user's.** The editor lists every variant and archive; nothing
  expires on its own.

**Rejected:** *a content-set release with a working copy of all documents* (heavier;
copies documents that do not change) · *door-based switch* (`next` reads a
different file than `current`) — both doors read the same `shared/data`, and the
hostname would couple a code release to a text preview; on zihlundsee `zihl.z77.ch`
even answers from `next` · *a cookie that remembers the preview* — hidden
cross-request state.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Free stream + editor discipline | The client can delete or duplicate designed sections; exactly the failure the IDEA doc describes. |
| Raw HTML fields + allow-list sanitizer | No sanitizer in the framework (HTMLPurifier rejected in the IDEA doc); escape-first rendering is the established rule ("MUST NOT store raw HTML in block text"). |
| `ContentTemplate` entity with HTML placeholders (IDEA doc) | Structure as editable data can be edited into a broken state; duplicates the field description `schema()` already holds (ADR-011). |
| A separate project-level content system | Would sit beside the framework's content system and need merging later; decided against in the zihlundsee discussion of 2026-09-21. |
| Blueprint stored in `data/` | Structure must agree with templates and CSS of the same release; a data-side blueprint can drift from the code that renders it. |
