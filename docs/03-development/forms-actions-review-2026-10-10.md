# Forms review 2026-10-10 — where the actions stand, and what opens as a page

**Trigger:** two rules the owner set on 2026-10-10, looking at the e-mail settings modal
(«Abbrechen / Speichern» at the bottom of the dialog):

- **R1 — actions in a fixed row.** The possible actions of a form (Speichern, Abbrechen,
  Löschen …) stand in a fixed row — the shell toolbar or a sticky bar at the TOP — like Word or
  Excel. Never at the bottom of a form or modal, never in the middle.
- **R2 — modal first.** An edit opens as a fetch modal or a controller-led window (ADR-047)
  wherever possible; a page of its own only where a reload is genuinely needed afterwards.

**Method:** two read-only inventories, every `<form>` and dialog in the backend, the DMS, the
finance / debtor / mandator / contact / VAT modules and the member shell — 110 rows, each
classified by container, action placement, transport and «does the save need a reload».
The two full tables are Part A and Part B below. This head is the synthesis.

## 1. The picture in numbers

| | Backend core + DMS (A) | Finance, debtor, mandator, contact, VAT, member (B) |
|---|---|---|
| Forms / dialogs inventoried | 48 (+3 kernel partials) | 62 (47 with a write action) |
| Actions in `.be-modal__footer` (bottom) | 31 forms + 9 action hubs | 22 (12 edit forms, 10 short confirms) |
| Actions in the toolbar / action cell (`form=`) | 8 | 5 |
| `.z77-form-actions` top (ADR-049) | **0** | 8 correct, 2 placed at the END by mistake |
| ADR-047 windows | **0** | 2 forms + 2 read views |
| Full pages for an edit | 2 (login, setup) | 6 (invoice editor, payment, two confirms, …) |
| Save changes one row / pane but answers `reload` | 36 of 43 fetch saves | 3 per-row page POSTs (bank import) |

Verdicts over the 47 write forms of Part B: R1 ok 14 · R1 violated 22 · R1 open 11 (short
confirms whose only issue is the bottom footer) · R2 ok 34 · R2 violated 9 · 8 not applicable
(the pre-auth member flow: login, register, resend).

**Reading:** both rules are already BUILT — in the journal (ADR-047 windows, ADR-049 bar, action
cell «Buchen») and partly in the debtor module. They have reached nothing else. The backend core
and the DMS carry neither a single action bar nor a single window, and 12 backend controllers
(33 call sites) answer a one-row save with `reload`, which throws away scroll, tree and filter
state — exactly what R2 wants to keep.

## 2. Two patterns carry almost everything

**P1 — the modal footer (53 dialogs).** `.be-modal` is a flex column; its `__footer` is the last
child and sits at the bottom by construction (`_modal.scss:84-135`). One CSS `order` on
header / footer / body moves EVERY dialog's action row under the title at once — no template
change, Enter unaffected (the first submit button in document order stays the same element).
That is the first-aid move. The real move is the same row as `.z77-form-actions` (the ADR-049
primitive, sticky inside the scrolling body, with the «N Fehler» link), rendered by ONE shared
partial so the 53 dialogs cannot drift again.

**P2 — `reload` after a one-row save (12 controllers).** The in-place answer exists in the same
module: `NavigationController` answers `update-fields` / `set-class` / `close-modal` /
`scroll-to` and `remove-element`; the DMS answers pane `replace-html` throughout. The missing
piece is a small `FetchResponse` helper («replace the row of entity X, close the modal») so the
right answer is one line and the lazy one is not shorter.

Everything else is one-offs, listed in Parts A and B with file and line: the DMS edit modal with
its footer in the MIDDLE (ACL section after it), the content editor as the one long form (the
owner's ADR-049 case, and the one screen where a window pays off), the two invoice confirm pages
with the bar placed last, the bank-import detail's per-row page POSTs, the member profile's
bottom dialogs, screen actions placed inside cards (Stats «Bericht jetzt senden», Import «Alle N
markieren», DMS «Papierkorb leeren» in a `<details>` at the modal's end).

## 3. The owner's decision (2026-10-10)

**ADR-049 §2 allowed `.z77-form-actions--end`** for «forms filled once from top to bottom, and
short dialogs». The owner decided the same day: the fixed row at the top is the rule for every
form and dialog; **the exception survives for the very short confirm only** — «Wirklich löschen?
Ja / Nein» or the like: one question and its answers, no input field, nothing to scroll. The test
is «no field», not «short». Written into ADR-049 as the Revision 2026-10-10; R2 went into ADR-047
as the Addendum 2026-10-10. Of the 21 short confirms in the inventory, those with a field (the
fiscal-year open/reopen dialogs hide an edit form among the confirms) are forms and go to the top.

## 4. Order of work (approved 2026-10-10 — «ADR revidieren und umbauen»)

1. **ADR-049 revision + ADR-047 addendum** — DONE 2026-10-10 (docs).
2. **P1 first aid** — DONE 2026-10-10: the `order` rule in `_modal.scss`, the shared partial
   `partials/modalActions`, and — more than planned — EVERY dialog of every module moved onto it
   the same day (five agents in parallel); `tests/form-actions.php` lints for a leftover
   `.be-modal__footer` (0 left). The DMS middle footer and the invoice confirms' bars included.
3. **P2** — DONE 2026-10-10 except where the reason to reload is real: `FetchResponse` row
   helpers, `core.js` wires inserted HTML (the hidden blocker: replaced rows arrived dead), the
   backend controllers and the modules answer in place, the bank-import rows post by fetch. The
   remaining `reload`s each carry a code comment (list in ADR-047 «Built 2026-10-10»).
4. **Pages → windows** — DONE for the invoice editor, credit note, payment form and payment
   delete (DEBTOR-WIN-001). OPEN: «Definitiv stellen» (DEBTOR-WIN-002) — the confirm is fed by a
   GET form with `form=` checkboxes, and `data-window-open` takes only a fixed URL; `core.js`
   needs a window opener for a GET form first.
5. **Screen actions out of the body** — DONE for Stats (hc2), Import (stale plan → hc2, group
   bar above the rows), DMS trash (top row), member profile (Konto / devices / 2FA remove by
   fetch). OPEN: the member 2FA setup card outside the shell, Jobs «Zeitplan setzen» as an inline
   cell edit (no owner ruling on cell edits yet).

Not built on purpose, waiting on a word: count badges beside a list («Zahlungsziele n», section
counts) do not follow an inserted / removed row — a small `update-text` per answer, or a helper
that names the count's hook, once the owner has seen the screens.

Not in scope: the pre-auth member cards (login, register, resend) — flow steps, not forms with
a fixed action set; the content editor's window is item 4's last entry, not its first.

---

# Part A — Backend core + DMS (agent inventory, 2026-10-10)


Read-only inventory, 2026-10-10. Measured against:
- **R1** actions in a FIXED row: shell toolbar (`hc2`) / action cell (`hc1`) via `form=`, or sticky `.z77-form-actions` at the TOP — never at the bottom or middle of a form/modal.
- **R2** an edit opens as a fetch modal or an ADR-047 window; a full page only where a reload is genuinely needed afterwards.

Vocabulary used in the table: *modal* = popup `<dialog class="be-modal">` (one per page, `html-shell-skeleton.tpl.php:114`); *window* = ADR-047 `data-window-open`; *inline* = a `<form>` inside a list row / page body / modal body.

Paths below are relative to `Z:\z77\z77-ch-framework-1.0.0\packages\`.

---

## 1. The table

| # | Screen (module › area › action) | Template file | Container | Actions placed | Transport | Save needs full reload? (what changes) | R1 | R2 | Suggested target |
|---|---|---|---|---|---|---|---|---|---|
| 1 | backend › Content › Inhalt löschen | `module-backend/res/view/templates/Content/ContentController/confirmDelete.tpl.php:6-21` | modal | `.be-modal__footer` (bottom) | fetch POST | No — one list row; answer is `close-modal`+`reload` (ContentController.php:670) | no | yes | footer → top row; answer `remove-element` instead of `reload` |
| 2 | backend › Content › Satz veröffentlichen | `…/ContentController/confirmPublish.tpl.php:11-31` | modal | `.be-modal__footer` | fetch POST | Yes-ish — set rows leave the list, live rows change (reload acceptable) | no | yes | footer → top row |
| 3 | backend › Content › Version wiederherstellen | `…/ContentController/confirmRestore.tpl.php:25-44` | modal | `.be-modal__footer` | fetch POST | Partly — 2 rows change (version ↔ live); `reload` today | no | yes | footer → top row |
| 4 | backend › Content › Variante anlegen | `…/ContentController/createVariant.tpl.php:11-43` | modal | `.be-modal__footer` | fetch POST (empty = source URL) | No — one new row; `reload` today | no | yes | footer → top row |
| 5 | backend › Content › Inhalt bearbeiten (block editor, 364 lines; also slot mode in iframe) | `…/ContentController/edit.tpl.php:204-364` | modal (slot mode: bare page in iframe) | `.be-modal__footer` at line 356 — the ONLY long form in scope | fetch POST | No — one row; `close-modal`+`reload` (ContentController.php:240) | **no** | yes (modal) — but a window (ADR-047) would fit better: long form, could stay open beside the list | `.z77-form-actions` top + `formErrorsLink`; later `data-window-open` |
| 6 | backend › Content › Metadaten löschen | `…/MetaDataController/confirmDelete.tpl.php:8-21` | modal | `.be-modal__footer` | fetch POST | No — one row; `reload` | no | yes | footer → top row |
| 7 | backend › Content › Metadaten bearbeiten | `…/MetaDataController/edit.tpl.php:27-91` | modal | `.be-modal__footer` | fetch POST | No — one row; `close-modal`+`reload` | no | yes | top row; `update-fields` |
| 8 | backend › Content › Alias löschen | `…/NavigationAliasController/confirmDelete.tpl.php:14-27` | modal | `.be-modal__footer` | fetch POST | No — one row; `reload` | no | yes | top row; `remove-element` |
| 9 | backend › Content › Alias bearbeiten | `…/NavigationAliasController/edit.tpl.php:15-84` | modal | `.be-modal__footer` | fetch POST | No — one row; `close-modal`+`reload` | no | yes | top row; `update-fields` |
| 10 | backend › Content › Navigation Eintrag löschen | `…/NavigationController/confirmDelete.tpl.php:14-27` | modal | `.be-modal__footer` | fetch POST | No — answer already in place: `remove-element`+`close-modal` (NavigationController.php:367) | no | yes | top row only |
| 11 | backend › Content › Navigation bearbeiten | `…/NavigationController/edit.tpl.php:21-153` | modal | `.be-modal__footer` | fetch POST + `data-check-url` blur validation | No — edit answers `update-fields`+`set-class`+`close-modal` in place (:262-273); NEW still `reload` (:246) | no | yes | top row; `reload` on create → insert row |
| 12 | backend › Content › Übersetzung löschen | `…/TranslationController/confirmDelete.tpl.php:12-26` | modal | `.be-modal__footer` | fetch POST | No — one row; `reload` | no | yes | top row; `remove-element` |
| 13 | backend › Content › Übersetzung bearbeiten | `…/TranslationController/edit.tpl.php:17-55` | modal | `.be-modal__footer` | fetch POST | No — one row; `close-modal`+`reload` | no | yes | top row; `update-fields` |
| 14 | backend › Service › Backup löschen | `…/Service/BackupController/confirmDelete.tpl.php:6-20` | modal | `.be-modal__footer` | fetch POST | No — one row; `reload` | no | yes | top row; `remove-element` |
| 15 | backend › Service › Backup «Daten sichern \| ▾» (4 forms: main + 3 menu items) | `…/Service/BackupController/list.act.tpl.php:28-50` | action cell (hc1) | the form's own submit IS the cell control | fetch POST | Yes — a new archive row appears; `reload` (BackupController.php:87) | yes | n/a (no edit) | keep; `replace-html` of the list would avoid the reload |
| 16 | backend › Service › E-Mail auf Config zurücksetzen | `…/Service/EmailSettingsController/confirmReset.tpl.php:12-26` | modal | `.be-modal__footer` | fetch POST | No — one row; `close-modal`+`reload` | no | yes | top row |
| 17 | backend › Service › E-Mail-Einstellungen bearbeiten | `…/Service/EmailSettingsController/edit.tpl.php:35-99` | modal | `.be-modal__footer` | fetch POST | No — one row; `close-modal`+`reload` | no | yes | top row; `update-fields` |
| 18 | backend › Service › Formular-Log Land sperren | `…/Service/FormLogController/confirmBlock.tpl.php:14-39` | modal | `.be-modal__footer` | fetch POST | Partly — a block row appears, log rows re-flag; `reload` | no | yes | top row |
| 19 | backend › Service › Formular-Log Sperre aufheben | `…/Service/FormLogController/confirmUnblock.tpl.php:12-31` | modal | `.be-modal__footer` | fetch POST | No — one row; `reload` | no | yes | top row |
| 20 | backend › Service › Import «Plan berechnen» (vendor) | `…/Service/ImportController/list.act.tpl.php:22-26` | action cell (hc1) | **toolbar via `form="import-start-vendor"`** — the reference pattern | fetch POST | Yes — the whole screen changes (plan appears, cell empties, hc2 fills) | yes | n/a | keep as the model |
| 21 | backend › Service › Import «Übernehmen» / «Verwerfen» | `…/Service/ImportController/list.hc2.tpl.php:29-36` | toolbar (hc2), 2 forms | the forms' own submits stand in the toolbar | fetch POST | Yes — plan disappears | yes | n/a | keep |
| 22 | backend › Service › Import stale plan «Plan verwerfen» | `…/Service/ImportController/listAction.tpl.php:36-38` | inline in an alert at the top of the body | end of form inline (inside the alert) | fetch POST | Yes — screen changes | borderline (top of page, but not the toolbar) | n/a | move to hc2 next to the status (one more toolbar form) |
| 23 | backend › Service › Import inbox file «Plan berechnen» + entity select | `…/listAction.tpl.php:102-110` | inline in list row | end of form inline, in the row | fetch POST | Yes — plan appears | ok as row-bound action (ADR-033) | n/a | keep (needs its select) |
| 24 | backend › Service › Import group «Alle N markieren» | `…/listAction.tpl.php:146-150` | inline, group head | end of form inline | fetch POST | No — N rows change their decision; `reload` (ImportController.php:308) | borderline (selection-bound action, but under the heading, not in a bar above) | n/a | bar above the group; answer `replace-html` of the group |
| 25 | backend › Service › Import per-row decide (6 form variants: Ist mein…, Zuordnen, neu anlegen, Anlegen/Kennung/Änderung, Ablehnen, Zurücksetzen) | `…/listAction.tpl.php:198-257` | inline in list row | buttons in the row (row-bound) | fetch POST | No — ONE row changes; `reload` every click (ImportController.php:264) | ok (row-bound) | n/a | answer `replace-html` of the row; `reload` is the cost here |
| 26 | backend › Service › Job «Jetzt einreihen» | `…/Service/JobController/listAction.tpl.php:101-104` | inline in list row | in the row | fetch POST | No — queue gets a row; `reload` | ok (row-bound) | n/a | `replace-html` of the queue |
| 27 | backend › Service › Job «Zeitplan setzen» (text input + submit) | `…/JobController/listAction.tpl.php:106-114` | inline in list row — a one-field edit form in the list | button right of the field, in the row | fetch POST | No — one row; `reload` (JobController.php:147) | ok (row-bound) | **no** — an edit inline in a list row, not a modal/window | small modal «Zeitplan» (or keep as capture if the owner counts it as a cell edit) |
| 28 | backend › Service › Job queue «Entfernen» | `…/JobController/listAction.tpl.php:162-165` | inline in list row | in the row | fetch POST | No — one row removed; `reload` | ok | n/a | `remove-element` |
| 29 | backend › Service › Job failed «Nochmals» | `…/JobController/listAction.tpl.php:197-200` | inline in list row | in the row | fetch POST | No — one row; `reload` | ok | n/a | `replace-html` row |
| 30 | backend › Service › Stats «Bericht … jetzt senden» | `…/Service/StatsController/listAction.tpl.php:84-88` | inline in page body (card) | end of form inline, mid-page | fetch POST | No — a flash only; `reload` (StatsController.php:121) | **no** (an action in the middle of the body) | n/a | toolbar (hc2) button via `form=`; answer flash only, no reload |
| 31 | backend › System › Benutzer löschen | `…/System/BackendUserController/confirmDelete.tpl.php:27-40` | modal | `.be-modal__footer` | fetch POST | No — already in place: `remove-element`+`close-modal` (:266) | no | yes | top row only |
| 32 | backend › System › Benutzer bearbeiten | `…/System/BackendUserController/edit.tpl.php:15-69` | modal | `.be-modal__footer` | fetch POST + `data-check-url` | No — one row; `close-modal`+`reload` (:177) | no | yes | top row; `update-fields` |
| 33 | backend › System › Login | `…/System/LoginController/loginAction.tpl.php:16-41` | page (guest layout, no toolbar) | «Anmelden» at the END of the form, `be-btn--full` | page POST + redirect | Yes — authentication, whole app | tolerated (one screen, never scrolls); strictly not R1 | yes (reload needed) | leave; at most `.z77-form-actions--end` for vocabulary consistency |
| 34 | backend › System › Erstes Setup | `…/System/SetupController/setupAction.tpl.php:46-94` | page (guest layout) | «Konto erstellen» at the END | page POST + redirect | Yes — one-time setup | tolerated, as 33 | yes | as 33 |
| 35 | backend › Contact › Kontakte suchen | `…/Contact/ContactController/list.hc2.tpl.php:15-17` | toolbar (hc2) | no button — Enter submits | page GET (full re-render) | Yes today (interim; becomes a Listing fetch region) | yes | n/a | migrate to `Z77\Shared\Listing` (`partials/listFind`) |
| 36 | backend › row action hubs «Aktionen — …» (8 dialogs, no form): Content, MetaData, NavigationAlias, Navigation, Translation, Backup, EmailSettings, BackendUser | `…/*/actions.tpl.php` (e.g. `Content/NavigationController/actions.tpl.php:14-26`) | modal | `.be-modal__footer` «Schliessen» only — the only visible close affordance (the dialog skeleton has no ×) | none (buttons `data-fetch-get`) | n/a | footer-bottom, but a menu — low weight | a × in the modal head; footer then goes |
| 37 | dms › Documents › Dokument löschen | `module-dms/res/view/templates/Documents/DocumentController/confirmDelete.tpl.php:14-26` | modal | `.be-modal__footer` | fetch POST | No — panes replaced in place (`paneRefresh`, DriveControllerTrait.php:210-213) | no | yes | top row only |
| 38 | dms › Documents › Dokument verschieben | `…/DocumentController/move.tpl.php:10-30` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 39 | dms › Drive › N Dokumente löschen (bulk) | `…/DriveController/_bulkConfirmDelete.tpl.php:16-33` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 40 | dms › Drive › N Dokumente verschieben (bulk) | `…/DriveController/_bulkMove.tpl.php:15-39` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 41 | dms › Drive › Ordner/Dokument bearbeiten (name, key, profile, alt/caption, mode) | `…/DriveController/_edit.tpl.php:42-146` | modal | `.be-modal__footer` at :142 — **in the MIDDLE of the modal**: the ACL section (:148-192) follows below it, and the wrapper `.dms-edit` breaks the flex chain so the footer is not pinned at all | fetch POST (`op=save`) | No — modal re-renders / panes in place | **no** (middle) | yes | one `.z77-form-actions` top row for the whole modal; ACL below |
| 42 | dms › Drive › Zugriffsrechte: «Entfernen» per rule + «Recht hinzufügen» | `…/DriveController/_edit.tpl.php:164-170, 175-191` | inline in modal body (below the save form) | per-row button; «Recht hinzufügen» at the END of its form (:190) | fetch POST (`op=revoke`/`grant`) | No — modal re-renders itself | no (end of form, mid-modal) | yes | grant row as a one-line capture: fields + button in ONE row |
| 43 | dms › Drive › Ordner verschieben | `…/DriveController/_folderMove.tpl.php:13-34` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 44 | dms › Drive › Papierkorb: «Wiederherstellen» / «Endgültig löschen» per row, «Papierkorb leeren» in `<details>` | `…/DriveController/_trash.tpl.php:25-36, 47-50` | modal, inline per row | row-bound buttons; footer «Schliessen» only; «leeren» hidden in a `<details>` at the end | fetch POST (`op=…`) | No — modal re-renders + panes (`:1095-1103`) | row buttons ok; «leeren» at the bottom: no | yes (a view, not an edit) | «Papierkorb leeren …» as a danger button in the TOP row (confirmation step stays) |
| 45 | dms › Drive › Dateien hochladen | `…/DriveController/_upload.tpl.php:47-68` → kernel `partials/upload` (shape `drop`) | modal hosting the shared upload form | footer «Schliessen» only; the component's no-JS «Hochladen» at the END of its form (removed when `upload.js` binds) | upload.js per file; no-JS: page POST | No — panes refreshed per file | ok with JS (no action button at all); no-JS fallback at the end | yes | leave; with the backend upload cell (`Documents/DriveController/list.act.tpl.php:15`) the modal is a second entrance to the same component |
| 46 | dms › Documents › Ordner löschen | `…/FolderController/confirmDelete.tpl.php:25-36` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 47 | dms › Documents › Ordner anlegen / bearbeiten | `…/FolderController/edit.tpl.php:10-41` | modal | `.be-modal__footer` | fetch POST | No — panes in place | no | yes | top row only |
| 48 | dms › Drive › row action hub (no form) | `…/DriveController/_actions.tpl.php` | modal | footer «Schliessen» only | none | n/a | as #36 | as #36 |
| K1 | kernel › `partials/upload` (shapes `drop` / `cell` / `field`) | `kernel/shared/res/view/templates/partials/upload.tpl.php:68-106` | wherever it is rendered (cell = action cell) | `cell`: the label-button IS the cell control (R1 ok); `drop`/`field`: no button with JS, fallback submit at the END without JS | upload.js / page POST fallback | per endpoint (DMS: panes) | ok | n/a | leave; the no-JS submit could move into a `.z77-form-actions--end` for vocabulary |
| K2 | kernel › `partials/listFind` (standard list search) | `…/partials/listFind.tpl.php:17-22` | list head (fetch region) | hidden submit; column inputs bound via `form=` | GET, region reload | No — the region | yes | n/a | model for #35 |
| K3 | kernel › `partials/formErrorsLink` («2 Fehler» label) | `…/partials/formErrorsLink.tpl.php` | part of `.z77-form-actions` | — | — | — | — | — | used only by financial/debtor; NOT by any form in this scope |

---

## 2. Counts

Forms (`<form>` elements, grouped as rows above; #15 counts 4 forms, #25 six, #42 two, #44 three):

| Action placement | Rows | Forms (approx.) |
|---|---|---|
| `.be-modal__footer` (bottom, pinned by the modal's flex column) | 31 (1-14, 16-19, 31-32, 37-41, 43, 46-47) | 31 |
| toolbar / action cell (`form=` or the form's own submit standing in hc1/hc2) | 4 (15, 20, 21, 35) + K1 cell | 8 |
| `.z77-form-actions` top | **0** | 0 |
| `.z77-form-actions--end` | 0 | 0 |
| inline in a list row / page body / modal body (button at the end of its own small form) | 12 (22-30, 42, 44, 45/K1 fallback) | ~22 |
| end of a page form (guest pages) | 2 (33, 34) | 2 |
| no action button (search, Enter) | 2 (35, K2) | 2 |

Containers:

| Container | Rows |
|---|---|
| popup modal `be-modal` with a form | 33 |
| popup modal, dialog without form (action hubs) | 9 (8 backend + 1 dms) |
| ADR-047 window (`data-window-open`) | **0** in scope |
| full page with its own form | 2 (login, setup) |
| inline in list / page body | 10 |
| toolbar / action cell | 4 |

Transport: 43 rows fetch POST; 2 page POST+redirect; 2 page GET; 1 upload.js.
"Save needs full reload?": **no** for 36 rows — yet every backend controller except Navigation-edit, Navigation-delete and BackendUser-delete answers `close-modal` + `reload` (`core.js:603` → `window.location.reload()`); DMS answers pane `replace-html` throughout.

---

## 3. The five findings that matter most

1. **Every form modal carries its actions in `.be-modal__footer` at the bottom — 31 forms, zero use `.z77-form-actions`.**
   `module-backend/res/scss/components/_modal.scss:128-135` (footer), `:84-93` (header), `:103-108` (body scrolls). The modal is a flex column: header pinned, body scrolls, footer pinned at the bottom. So the row is FIXED — but on the wrong edge for R1 ("never at the bottom of a form or modal"), and on a long form (#5 Content edit, #11 Navigation edit, #17 E-Mail, #41 DMS edit) the save is at the far end of the scroll area in the user's reading order.
   Smallest fix: CSS only, in `_modal.scss` — give the three parts flex `order` (`.be-modal__header{order:0} .be-modal__footer{order:1; border-top:0; border-bottom:1px solid var(--be-line)} .be-modal__body{order:2}`), optionally `justify-content:flex-start`. One change moves all 31 (+9 hubs) rows to the top under the title; DOM order (submit last, no Enter regression) and every template stay untouched. Second step, when touched: rename to `.z77-form-actions` and add `formErrorsLink` (ADR-049 §4) — today no backend/dms form shows the error count in its bar.

2. **Modal saves answer `reload` although only one row changes — the modal's point (no page load) is lost.**
   `ContentController.php:240-241, 603-604, 670-671, 727-728`, `MetaDataController.php:206-207, 269`, `NavigationAliasController.php:103-104, 169`, `TranslationController.php:130-131, 210`, `EmailSettingsController.php:209-210, 253-254`, `FormLogController.php:203, 242`, `BackendUserController.php:177-178`, `BackupController.php:87, 163`; and every inline form in Import/Job/Stats (`ImportController.php:222-403`, `JobController.php:116-223`, `StatsController.php:121`). R2's "full page only where a reload is genuinely needed" is met by the container but defeated by the answer: scroll position, open tree nodes and filters are lost on every save.
   Smallest fix: the in-place answers already exist in the same module — `NavigationController.php:255-273` (`update-fields` + `set-class` + `close-modal` + `scroll-to`) and `:367-370` / `BackendUserController.php:266-268` (`remove-element` + `close-modal`). Copy that shape per controller; for lists that are not yet `Listing` fetch regions, `replace-html` of the list container is the one-liner.

3. **DMS edit modal: the action row stands in the MIDDLE of the modal.**
   `module-dms/res/view/templates/Documents/DriveController/_edit.tpl.php:142-146` — the save form's footer, then `:148-192` a second `.be-modal__body` (ACL rules) with its own forms; «Recht hinzufügen» at `:190` is at the end of its form. The wrapper `.dms-edit` (`:41`) is the popup root, so the flex chain (`_modal.scss:37-43`) stops there: nothing is pinned, the body does not scroll, the footer is wherever the content puts it. Violates R1 twice (middle + end of form).
   Smallest fix: one `.z77-form-actions` row directly under the header for «Speichern / Abbrechen», the ACL section below it inside the same scrollable body; the grant form becomes one line (select, input, select, button). No controller change — the save form keeps `op=save`.

4. **Content block editor (#5) is a long form in the single popup with its save at the bottom.**
   `module-backend/res/view/templates/Content/ContentController/edit.tpl.php:204` (form) → `:356-363` (footer). 364-line template, block stream, JSON preview; a change in the title means scrolling past every block to «Speichern». This is exactly ADR-049's owner case. It is also the one screen in scope where an ADR-047 window pays off (the list stays visible, two documents side by side, `data-window-confirm-close` for unsaved blocks).
   Smallest fix: finding 1's CSS reorder covers it at once; when the editor is next touched, render it with `data-window-open` + `data-window="content-edit"` and answer `replace-html` of the row instead of `reload` (`ContentController.php:240`).

5. **Actions that stand in the body instead of the toolbar: Stats «Bericht jetzt senden», Import «Alle N markieren», Import stale «Plan verwerfen», DMS «Papierkorb leeren».**
   `Service/StatsController/listAction.tpl.php:84-88` (a screen action inside a card, mid-page, answers `reload` for a flash); `Service/ImportController/listAction.tpl.php:146-150` (a selection-bound action under the group heading — ADR-033/backend-screen §4 wants a bar ABOVE the list); `:36-38` (a screen action inside an alert); `module-dms/…/_trash.tpl.php:43-52` (a collection action hidden in a `<details>` at the END of the modal).
   Smallest fix: Stats → a `form=`-bound button in `list.hc2` (the Import `list.act.tpl.php:22-26` pattern: empty form + `button form=`), answer flash only. Import bulk → a `.z77-form-actions` bar per group (as debtor's `data-selection-bar`). Stale discard → second toolbar form in `list.hc2`. Trash «leeren …» → danger button in the modal's top row, the confirm step kept.

Not a finding, but to record: **no `data-window-open` and no `.z77-form-actions` exist anywhere in module-backend or module-dms.** Both standards (ADR-047, ADR-049) are used only by module-financial and module-debtor so far. The 2 guest pages (login, setup) keep the button at the end of a one-screen form — acceptable as the ADR-049 "short form filled once" case, not an R1 breach worth touching.

---

## 4. Pattern vs. one-off

**Patterns (one change fixes N):**

| Pattern | N | Where the single change goes |
|---|---|---|
| Form modal with `.be-modal__footer` at the bottom | 31 forms + 9 hubs | `module-backend/res/scss/components/_modal.scss` — flex `order` on header/footer/body (finding 1). Later: footer → `.z77-form-actions` per template, mechanical. |
| Save answer `close-modal` + `reload` for a one-row change | 11 modal saves + ~20 inline actions in Import/Job/Stats/Backup | per controller, but one shape: `update-fields` / `remove-element` / `replace-html` + `close-modal` (already in `NavigationController.php:255-273`). A small FetchResponse helper («closeAndReplaceRow($selector, $html)») would make it one line. |
| Row-bound inline forms with `style="margin:0"` in Import/Job (`listAction.tpl.php`) | ~16 forms | Fine for R1 (row-bound); the shared cost is the `reload` answer, same as above. Once these lists become `Z77\Shared\Listing` fetch regions, `refresh-region` replaces every `reload` without touching templates. |
| Action hub footer «Schliessen» as the only close affordance | 9 hubs | a × (`data-popup-close`) in `html-shell-skeleton.tpl.php:114-121` next to the fullscreen toggle; then the footers can go. |
| Delete-confirm modals missing a × in the head | 14 | same as above. |
| `formErrorsLink` unused in scope | 11 edit forms with validators | comes for free once the footer becomes `.z77-form-actions` (partial call + field ids). |

**One-offs:**

| One-off | File |
|---|---|
| DMS edit modal: footer in the middle, ACL forms below it, flex chain broken by `.dms-edit` wrapper | `module-dms/…/DriveController/_edit.tpl.php:41, 142, 148-192` |
| Content block editor: the only long form; window candidate | `module-backend/…/ContentController/edit.tpl.php:204-364` |
| Stats «Bericht jetzt senden» inside a card | `module-backend/…/StatsController/listAction.tpl.php:84-88` |
| Import stale-plan discard inside an alert | `module-backend/…/ImportController/listAction.tpl.php:36-38` |
| Job «Zeitplan setzen»: a one-field edit living in a list row (R2 grey zone) | `module-backend/…/JobController/listAction.tpl.php:106-114` |
| Trash «Papierkorb leeren» in a `<details>` at the end of the modal | `module-dms/…/DriveController/_trash.tpl.php:43-52` |
| Contact search as a page GET (interim, documented in the template) | `module-backend/…/ContactController/list.hc2.tpl.php:15-17` |
| Login / Setup: guest pages, button at the end — tolerated | `System/LoginController/loginAction.tpl.php:40`, `System/SetupController/setupAction.tpl.php:93` |


---

# Part B — Finance, debtor, mandator, contact, VAT, member (agent inventory, 2026-10-10)


Measured against the owner's two rules (2026-10-10):

- **R1** — a form's actions stand in ONE fixed row: the shell toolbar/action cell (`form="<id>"`) or a sticky `.z77-form-actions` at the TOP. Never at the end of a form or modal, never in the middle.
- **R2** — an edit opens as a fetch modal or an ADR-047 window; a page of its own only where a reload is genuinely needed afterwards.

Read-only inventory, nothing in the repo was changed. All paths relative to `packages/`. Line numbers are from the working tree on 2026-10-10.

Legend — Container: `page` · `popup` (the single `<dialog data-z77-popup>`, `be-modal__*` markup, opened by `data-fetch-get`) · `window` (ADR-047, `data-window-open` / `data-window-link`, root carries `data-window`) · `inline` (a form inside a list/page) · `card` (member `me-card`, pre-auth, no shell) · `me-dialog` (member server-rendered `<dialog>`). «Reload?» = does the save genuinely need a full page load, and what visibly changes.

Notes on R1 for confirm dialogs: R1 as worded forbids a footer at the bottom; ADR-049 §2 still allows `.z77-form-actions--end` for «short dialogs». Rows marked **R1 ⚠** are short confirms whose only problem is the bottom footer — the owner decides whether ADR-049 §2 survives R1. Rows marked **R1 no** are edit forms with fields.

## 1. The table

| # | Screen (module › area › action) | Template file | Container | Actions placed | Transport | Reload needed? (what changes) | R1 | R2 | Suggested target |
|---|---|---|---|---|---|---|---|---|---|
| 1 | financial › Konten › Konto anlegen/bearbeiten | `module-financial/res/view/templates/Backend/AccountController/edit.tpl.php` (form :34, footer :106) | popup | `.be-modal__footer` (bottom) | fetch POST | no — one list row | no | ok | window (or popup) with `.z77-form-actions` directly under the header |
| 2 | financial › Konten › KMU-Kontenrahmen übernehmen | `…/AccountController/confirmAdoptKmuChart.tpl.php` (:10, :20) | popup | `.be-modal__footer` | fetch POST | yes-ish — the whole (empty) list fills | ⚠ | ok | confirm; `--end` or top bar, owner's call |
| 3 | financial › Geschäftsjahre › eröffnen | `…/FiscalYearController/open.tpl.php` (:29, :81) | popup | `.be-modal__footer` | fetch POST | no — list row + rail year select | no | ok | bar at top (fields: dates, code) |
| 4 | financial › Geschäftsjahre › abschliessen | `…/FiscalYearController/confirmClose.tpl.php` (:44, :71; refusal :38) | popup | `.be-modal__footer` | fetch POST | no — row state | ⚠ | ok | confirm with checkbox → bar at top |
| 5 | financial › Geschäftsjahre › löschen | `…/FiscalYearController/confirmDelete.tpl.php` (:29, :41) | popup | `.be-modal__footer` | fetch POST | no — row removed | ⚠ | ok | confirm |
| 6 | financial › Geschäftsjahre › wieder öffnen | `…/FiscalYearController/confirmReopen.tpl.php` (:30, :44) | popup | `.be-modal__footer` | fetch POST | no — row state | no (has a required reason field) | ok | bar at top |
| 7 | financial › Journal › Buchen (one-line capture, new) | `…/JournalController/oneLine.tpl.php` (:63, `id="journal-capture"`), `captureAct.tpl.php:16` | inline (capture area on the page) | action cell via `form="journal-capture"` | page POST + 303 back to capture | yes — form resets, list region reloads, date kept | ok | ok (capture, not an edit) | **compliant — reference** |
| 8 | financial › Journal › Sammelbuchung (compound capture, new) | `…/JournalController/form.tpl.php` (:59; «Weitere Zeilen» :190) | inline | action cell via `form="journal-capture"`; «Weitere Zeilen» at the end (acts on the rows, ADR-033) | page POST + 303 | yes (as 7) | ok | ok | **compliant — reference** |
| 9 | financial › Journal › Buchung bearbeiten (one-line, window) | `…/JournalController/oneLine.tpl.php` (:82 bar) ← `listAction.tpl.php:136` `data-window-open`, `detail.tpl.php:115` `data-window-link` | window | `.z77-form-actions` top (Speichern · Als Sammelbuchung · Abbrechen · N Fehler) | window POST → envelope `open-window`(detail, replace) + `refresh-region journal-list` (`JournalControllerTrait.php:453-462`) | no | ok | ok | **compliant — reference** |
| 10 | financial › Journal › Buchung bearbeiten (compound, window) | `…/JournalController/form.tpl.php` (:82 bar) | window | `.z77-form-actions` top; «Weitere Zeilen» at the end (secondary) | window POST → same envelope | no | ok | ok | **compliant — reference** |
| 11 | financial › Journal › Buchung bearbeiten as PAGE (no-script / ctrl-click / from detail page) | same two templates, `$window=false` | page | `.z77-form-actions` top inside the content — not the toolbar | page POST + 303 to detail | no (fallback path) | ok (fixed top) — but ADR-049 §3 says toolbar on a page | n/a (fallback) | keep as fallback; optional `form=` button in hc2 |
| 12 | financial › Journal › Buchung löschen | `…/JournalController/confirmDelete.tpl.php` (:31, :43) | popup (from detail window/page `detail.tpl.php:116`) | `.be-modal__footer` | fetch POST | no — row gone from region | ⚠ | ok | confirm |
| 13 | financial › Journal › Buchung (read view) — «Bearbeiten» / «Löschen …» | `…/JournalController/detail.tpl.php` :111-120 | window / page | **end of the detail**, in a `<p class="be-form__hint">` | — (links) | — | no (window's actions at the bottom) | ok | move the action row to the top of the window as `.z77-form-actions` |
| 14 | financial › Journal › Suche (column search) | `…/JournalController/listAction.tpl.php:99` `#journal-find` | inline, fetch region | no button (Enter; hidden submit :103) | GET, region | no | n/a (filter) | n/a | — |
| 15 | financial › Berichte › Zeitraum / Konto | `…/ReportController/rangeForm.tpl.php` (:33, «Anzeigen» :57) | inline | button at the end of the grid | page GET | yes (report re-renders) | n/a (filter) | n/a | filters are tools → toolbar (ADR-033), low priority |
| 16 | financial › Änderungsprotokoll › Detail | `…/ChangeLogController/detail.tpl.php` (:27, :60) | window | no form; «Buchung anzeigen» `data-window-link` | — | — | n/a | ok | — |
| 17 | debtor › Zahlungseingänge › camt.054 einlesen | `module-debtor/res/view/templates/Backend/BankImportController/act.tpl.php` (cell upload) + list zone | action cell (UPLOAD-001) | action cell | upload.js per file | no | ok | ok | **compliant** |
| 18 | debtor › Zahlungseingänge › Meldung › Verbuchen | `…/BankImportController/detail.tpl.php` :35 bar, :37 form | page | `.z77-form-actions` top (sticky), inline POST form | page POST + redirect | acceptable — every row's state changes | ok | partial (detail is a page, opened by link) | detail as window from the list; «Verbuchen» answers `refresh-region` |
| 19 | debtor › Zahlungseingänge › Meldung › Zuordnen (per transaction) | `…/BankImportController/detail.tpl.php:92-97` | inline in list row | button beside the input, in the row (middle) | page POST + redirect | **no — one row** | no | no | `data-fetch-post` + `replace-html` of `[data-bank-transaction=id]` and the counter bar |
| 20 | debtor › Zahlungseingänge › Meldung › Ignorieren / Zurücknehmen | `…/BankImportController/detail.tpl.php:98-103` | inline in list row | button in the row | page POST + redirect | **no — one row** | no | no | same as 19 |
| 21 | debtor › Debitoren › Debitor anlegen/bearbeiten | `…/DebtorProfileController/edit.tpl.php` (:33, :92) ← `act.tpl.php:16` `data-fetch-get` | popup | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 22 | debtor › Debitoren › Suche | `…/DebtorProfileController/search.tpl.php:13` | hc2 (toolbar slot) | no button (Enter) | page GET | yes (list) | n/a | n/a | **compliant** (tool in the toolbar) |
| 23 | debtor › Mahnungen › Stichtag | `…/DunningController/listAction.tpl.php:29-35` | inline (content) | «Liste aktualisieren» beside the field | page GET | yes (list) | n/a (filter) | n/a | filter → toolbar (ADR-033) |
| 24 | debtor › Mahnungen › Mahnlauf starten | `…/DunningController/listAction.tpl.php` :39 form `#dunning-run`, :45 bar | inline (selection bar ABOVE the list) | `.z77-form-actions` top (selection bar) | page POST + redirect | acceptable — due list shrinks, runs list grows | ok | ok (batch, not an edit) | **compliant** |
| 25 | debtor › Mahnstufen › anlegen/bearbeiten | `…/DunningLevelController/edit.tpl.php` (:26, :89) | popup | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 26 | debtor › Rechnungen › Definitiv stellen … (selection bar) | `…/InvoiceController/listAction.tpl.php:56-58` (`#invoice-finalize`, `form=`) | inline (selection bar above the list) | `.z77-form-actions` top, submit via `form=` | GET → confirm page (27) | — | ok | leads to a page (27) | keep the bar; make 27 a window |
| 27 | debtor › Rechnungen › Definitiv stellen (confirmation) | `…/InvoiceController/confirmFinalize.tpl.php` :22 form, **:43 bar at the END** | page | `.z77-form-actions` placed LAST in the form (so it sits at the bottom; the class is the top variant → it never sticks usefully) | page POST + redirect | acceptable afterwards (list view changes) — but the confirm itself needs no page | **no** | **no** (a confirm as a full page) | window opened by the selection bar; bar first in the form |
| 28 | debtor › Rechnungen › Zahlung löschen (confirmation) | `…/InvoiceController/confirmPaymentDelete.tpl.php` :21 form, **:40 bar at the END** | page | `.z77-form-actions` LAST in the form | page POST + redirect to detail | no — the detail pane (payments, open amount) | **no** | **no** | confirm popup/window like #12; bar first |
| 29 | debtor › Rechnungen › Rechnung erstellen / Neu fakturieren / Gutschrift | `…/InvoiceController/form.tpl.php` :49 form `#invoice-form`, :57 bar, :187 «Weitere Zeilen» | page (← `act.tpl.php` link «+ Rechnung», `detail.tpl.php:59/61` links) | `.z77-form-actions` top (content, not toolbar) | page POST + redirect to detail | no — a new/changed document; the journal does the identical job (#10) in a window | ok (fixed top; ADR-049 §3 would want the toolbar) | **no** | window like #10: `'window' => invoiceIsFetch()`, `_origin`, answer `open-window` detail + `refresh-region` |
| 30 | debtor › Rechnungen › Zahlung erfassen / ändern | `…/InvoiceController/payment.tpl.php` :35 form, :42 bar (Speichern · Abbrechen · Löschen …) | page (← `detail.tpl.php:56/87` plain links) | `.z77-form-actions` top | page POST + redirect to detail | no — the detail pane | ok | **no** | window from the detail window (`data-window-link`), answer `open-window` detail |
| 31 | debtor › Rechnungen › Dokument (read view) — PDF · Zahlung erfassen · Neu fakturieren · Gutschrift | `…/InvoiceController/detail.tpl.php:52-62` | window / page | `.be-list__toggles` at the TOP (not sticky) | links WITHOUT `data-window-link` → a click inside the window loads a full page | — | ok-ish (top) | **no** (window → page jump) | add `data-window-link` once 29/30 are windows; row → `.z77-form-actions` |
| 32 | debtor › Zahlungsbedingungen › anlegen/bearbeiten | `…/PaymentTermsController/edit.tpl.php` (:31, :104) | popup | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 33 | debtor › Zahlungsziele › anlegen/bearbeiten | `…/PaymentTargetController/edit.tpl.php` (:42, :105) — long form (IBANs + creditor block) | popup | `.be-modal__footer` | fetch POST | no — one row | no (the owner's exact case: long form, save at the bottom) | ok | bar at top |
| 34 | mandator › Mandant › bearbeiten | `module-mandator/res/view/templates/Backend/MandatorController/edit.tpl.php:78` `#mandator-edit`, `editAct.tpl.php:16` | page (the record IS the area; radio tabs in hc2) | action cell via `form="mandator-edit"` | page POST + redirect | acceptable — single-record area re-renders itself | ok | ok (the area is the record) | **compliant** |
| 35 | contact › Kontakte › Kontakt anlegen/bearbeiten | `module-contact/res/view/templates/Backend/ContactController/edit.tpl.php` (:33, :120) | popup (← ⋮ hub `actions.tpl.php:26`) | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 36 | contact › Kontakte › Adresse hinzufügen/bearbeiten | `…/ContactController/editAddress.tpl.php` (:27, :49) | popup (replaces the hub in the single popup) | `.be-modal__footer` | fetch POST | no — hub list / row | no | ok (a window would keep hub + address side by side) | bar at top; window |
| 37 | contact › Kontakte › Adresse entfernen | `…/ContactController/confirmRemoveAddress.tpl.php` (:15, :25) | popup | `.be-modal__footer` | fetch POST | no | ⚠ | ok | confirm |
| 38 | contact › Kontakte › ⋮ Aktionen (hub) | `…/ContactController/actions.tpl.php` (:50 footer «Schliessen») | popup | footer (close only) | — | — | n/a (menu) | ok | — |
| 39 | contact › Adressarten › anlegen/bearbeiten | `…/AddressTypeController/edit.tpl.php` (:21, :54) | popup | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 40 | vat › MWST-Codes › anlegen/bearbeiten | `module-vat/res/view/templates/Backend/TaxCodeController/edit.tpl.php` (:23, :79) | popup | `.be-modal__footer` | fetch POST | no — one row | no | ok | bar at top |
| 41 | vat › MWST-Codes › Neuer Satz gültig ab | `…/TaxCodeController/addRate.tpl.php` (:24, :58) | popup | `.be-modal__footer` | fetch POST | no — hub list | no | ok | bar at top |
| 42 | vat › MWST-Codes › Satz entfernen | `…/TaxCodeController/confirmRemoveRate.tpl.php` (:29, :39; refusal :24) | popup | `.be-modal__footer` | fetch POST | no | ⚠ | ok | confirm |
| 43 | vat › MWST-Codes › ⋮ Aktionen (hub) | `…/TaxCodeController/actions.tpl.php` (:51) | popup | footer (close only) | — | — | n/a | ok | — |
| 44 | member (backend) › Mitgliederkonten › Freischalten | `module-member/res/view/templates/Backend/AccountsController/confirmActivate.tpl.php` (:22, :43) ← `listAction.tpl.php:114` | popup | `.be-modal__footer` | fetch POST | no — one row | ⚠ | ok | confirm |
| 45 | member (backend) › Mitgliederkonten › Ablehnen | `…/AccountsController/confirmReject.tpl.php` (:10, :21) | popup | `.be-modal__footer` | fetch POST | no — row gone | ⚠ | ok | confirm |
| 46 | member (backend) › Mitgliederkonten › 2FA zurücksetzen | `…/AccountsController/confirmTotpReset.tpl.php` (:11, :22) | popup | `.be-modal__footer` | fetch POST | no — one row | ⚠ | ok | confirm |
| **Member shell — a different host (`me-shell` / `me-card`), same two rules applied** | | | | | | | | | |
| 47 | member › Anmelden | `module-member/res/view/templates/Main/LoginController/indexAction.tpl.php` → shared `module-frontend/…/partials/publicForm.tpl.php:114` | card (pre-auth) | submit at the END of the form (`fe-form__submit`) | page POST + redirect | yes — a flow step | no (end of form) — one-field card | n/a (flow page, no shell) | leave; or give `publicForm` an action slot (one shared change for 47/48/50/51) |
| 48 | member › Registrieren (open) | `…/RegisterController/indexAction.tpl.php` → `publicForm` | card | end of form | page POST | yes — flow | no | n/a | as 47 |
| 49 | member › Einladung: Verwaltung hinzufügen / Ablehnen | `…/RegisterController/indexAction.tpl.php:77-81` | card | the two submits ARE the content (decision) | page POST | yes | ok (decision page) | n/a | — |
| 50 | member › Einladung annehmen (name form) | `…/RegisterController/indexAction.tpl.php` → `publicForm` | card | end of form | page POST | yes | no | n/a | as 47 |
| 51 | member › Link erneut anfordern | `…/ResendController/indexAction.tpl.php` → `publicForm` | card | end of form | page POST | yes | no | n/a | as 47 |
| 52 | member › Anmeldung bestätigen (redeem, other device) | `…/LoginController/redeemAction.tpl.php:63, :71` | card | buttons ARE the content (decision) | page POST | yes | ok | n/a | — |
| 53 | member › 2FA-Code (login) | `…/LoginController/totpAction.tpl.php:22, :29` | card | end of form | page POST | yes — flow | no (one field) | n/a | — |
| 54 | member › Profil › Konto bearbeiten | `…/ProfileController/indexAction.tpl.php:125-158` `<dialog class="me-dialog">` (opened by action cell `data-dialog-open`, `ProfileController.php:131`) | me-dialog | `.me-dialog__actions` at the BOTTOM (:154-157) | page POST + redirect (full reload) | **no — the `dl.me-field` on the same pane** | no | half (modal yes, fetch no) | bar at top as `.z77-form-actions` (member tokens exist: `member.scss:1478`); `data-fetch-post` + `update-html` of the pane |
| 55 | member › Profil › Konto löschen | `…/ProfileController/indexAction.tpl.php:182-215` | me-dialog | `.me-dialog__actions` bottom (:211-214) | page POST + redirect to login | yes — the session ends | ⚠ (confirm) | ok | confirm |
| 56 | member › Profil › 2FA entfernen | `…/ProfileController/indexAction.tpl.php:230-238` | inline in the pane | submit at the end of a 1-field form | page POST + redirect | no — the section's state | no | no | dialog with the code field, fetch POST, `replace-html` pane |
| 57 | member › Profil › Gerät abmelden (per device) | `…/ProfileController/indexAction.tpl.php:272-276` | inline in list row | button in the row | page POST + redirect | no — one row | per-row (ADR-033 content rule) | no | fetch POST + `replace-html` of the `.me-unit` |
| 58 | member › Profil › Alle abmelden | `partials/shell/action.tpl.php:42-46` (`method: post`, `ProfileController.php:142`) | action cell | action cell | page POST + redirect | acceptable — every row | ok | ok | **compliant** |
| 59 | member › Profil › 2FA einrichten | `…/ProfileController/totpAction.tpl.php:33, :40`; «Abbrechen» a link :43 | card (leaves the shell) | end of form; cancel as a text link below | page POST + redirect | no — the «Zwei-Faktor» pane | no | no (a setup page outside the shell) | render inside the shell pane; «Aktivieren» in the action cell via `form=` (the `submit` kind exists, `action.tpl.php:38`) |
| 60 | member › Shell › Verwaltung wechseln | `partials/shell/userMenu.tpl.php:74-80` (one POST form per tenant) | menu (details panel) | the rows ARE the buttons | page POST + redirect | yes — tenant context | n/a | n/a | — |
| 61 | member › Shell › toolbar tool `post` kind | `partials/shell/tools.tpl.php:54-60` | toolbar | toolbar | page POST (+ `data-confirm`) | per tool | ok (toolbar is the fixed row) | per tool | — |
| 62 | member › Shell › action cell `submit` kind | `partials/shell/action.tpl.php:38` (`form="<id>"`) | action cell | action cell via `form=` | the page form's | — | ok | — | **the mechanism R1 asks for — exists, unused by the framework's own member forms** |

## 2. Counts

Rows with a write action (filters, menus, read-only windows excluded → 47 of 62):

**By container**

| Container | n | rows |
|---|---|---|
| popup (`be-modal`, single dialog) | 24 | 1-6, 12, 21, 25, 32, 33, 35-37, 39-42, 44-46 (+ hubs 38/43 without a form) |
| page (own URL) | 6 | 18, 27, 28, 29, 30, 34 |
| window (ADR-047) | 2 forms (9, 10) + 2 read views with actions (13, 31) | |
| inline in page/list | 8 | 7, 8, 17, 19, 20, 24, 26, 56, 57 |
| member card (pre-auth / outside the shell) | 8 | 47-53, 59 |
| member `me-dialog` | 2 | 54, 55 |
| member action cell / toolbar / menu | 4 | 58, 60, 61, 62 |

**By action placement**

| Placement | n | rows |
|---|---|---|
| toolbar / action cell via `form=` or the cell's own form | 5 | 7, 8, 34, 58, 62 (+ 17 upload cell) |
| `.z77-form-actions` top — correct | 8 | 9, 10, 11, 18, 24, 26, 29, 30 |
| `.z77-form-actions` placed at the END of the form (misuse) | 2 | 27, 28 |
| `.z77-form-actions--end` | 0 | — |
| `.be-modal__footer` (bottom) | 22 | 1-6, 12, 21, 25, 32, 33, 35-37, 39-42, 44-46 — of which **12 are edit forms with fields** (1, 3, 6, 21, 25, 32, 33, 35, 36, 39, 40, 41) and 10 are short confirms |
| end of form, inline (no bar) | 9 | 13 (read view), 47, 48, 50, 51, 53, 56, 59, 15 (filter) |
| in the row / middle | 3 | 19, 20, 57 |
| `.me-dialog__actions` bottom | 2 | 54, 55 |

**R1 / R2 verdicts (47 write forms)**

- R1 ok: 14 · R1 no (edit forms, bottom/middle/end): 22 · R1 ⚠ (short confirms with a bottom footer): 11
- R2 ok: 34 · R2 no: 9 (19, 20, 27, 28, 29, 30, 31, 56, 57; 59 half, 54 half) · n/a (flow/pre-auth pages): 8

**The journal (reference implementation), precisely:** #7, #8 (capture: action cell `form=`), #9, #10 (edit windows: top bar, `_origin`, `open-window` + `refresh-region`) comply with both rules. #13 (the detail's «Bearbeiten»/«Löschen …» at the END of the window, `detail.tpl.php:111-120`) and #12 (delete confirm with a bottom footer) do not. #11 is the no-script fallback and fine as such.

## 3. The five findings that matter most

1. **Every popup edit form has its actions at the bottom** — 12 edit forms + 10 confirms, all `…<div class="be-modal__footer">` as the last child of `<form data-fetch-post>` (e.g. `module-financial/…/AccountController/edit.tpl.php:106`, `module-debtor/…/PaymentTargetController/edit.tpl.php:105` — the long one, the owner's exact case). Violates R1. Smallest change per template: move that `<div>` up to directly after `be-modal__header` and rename it `z77-form-actions` (Speichern first in the DOM → Enter saves, ADR-049 §5); one host rule in `module-backend/res/scss/components/_form-actions-host.scss`: `.be-modal .z77-form-actions { padding: .5rem 1.25rem; }` so it reads like the header. CSS is already loaded in the popup (kernel `_form-actions.scss`). The popup body (`_modal.scss:110`, `overflow-y:auto`) is the scroll area, so the bar must sit INSIDE `be-modal__body` as its first child to be sticky, or the body/footer order is swapped.

2. **Two invoice confirmations are full pages with the bar at the END** — `module-debtor/…/InvoiceController/confirmFinalize.tpl.php:43` and `confirmPaymentDelete.tpl.php:40`: `.z77-form-actions` (the TOP variant) placed as the last element, so it is neither sticky-top nor `--end`; both are pages for a yes/no (R1 and R2). Smallest change: move the `<div class="z77-form-actions">` to directly after the hidden inputs (two line moves). Right change: a confirm popup/window like `JournalController/confirmDelete.tpl.php`, the finalize one opened by the selection bar (#26) with `data-window-open` instead of a GET page.

3. **Invoice editor and payment form are pages although the journal does the same job in a window** — `module-debtor/…/InvoiceController/form.tpl.php:49` and `payment.tpl.php:35` (page POST + redirect to the detail; `InvoiceControllerTrait.php:244, :438, :492`). Nothing after the save needs a reload: the detail pane and one list row change. The detail already IS a window (`detail.tpl.php:34`, `'window' => $this->invoiceIsFetch()` at `InvoiceControllerTrait.php:189`), but its action links at `detail.tpl.php:54-61` carry no `data-window-link`, so «Zahlung erfassen …» / «Neu fakturieren …» throw the user out of the window onto a page. Smallest change: copy the journal pattern — `'window' => invoiceIsFetch()` + root `data-window="invoice-edit"`/`invoice-payment`, hidden `_origin`, `data-window-link` on the detail's links, and answer the save with `open-window` (detail, replace) + `refresh-region` for the list origin (`JournalControllerTrait.php:453-462` is the template).

4. **Bank-import detail: three page reloads per transaction** — `module-debtor/…/BankImportController/detail.tpl.php:92-103`: «Zuordnen» and «Ignorieren/Zurücknehmen» are `<form method="post">` in the row, each a full reload for ONE row's state (R2), buttons in the middle of the list (R1). Smallest change: `data-fetch-post` on the two forms; `assign`/`ignore` answer `replace-html` of `[data-bank-transaction="<id>"]` and `update-html` of the `[data-bank-unbooked]` bar. The top bar «Verbuchen» (:35-45) is already right.

5. **Member profile forms post the whole page for one pane, with the buttons at the bottom** — `module-member/…/ProfileController/indexAction.tpl.php`: the Konto dialog's `.me-dialog__actions` at :154-157 (bottom of a modal, page POST + reload for three `dd` values), «2FA entfernen» inline at :230-238, «Gerät abmelden» per row at :272-276, and the 2FA setup on a separate card outside the shell (`totpAction.tpl.php:33-43`, cancel as a text link). The host already defines the bar's tokens (`member.scss:1478`) and the action cell already supports `submit` (`partials/shell/action.tpl.php:38`) — both unused by these forms. Smallest change: in the Konto dialog move `.me-dialog__actions` under the title as `.z77-form-actions`; `data-fetch-post` on the Konto, 2FA-remove and device forms with `update-html` of the pane. The pre-auth cards (47-53) are flow steps on a different host — leave them, or give `publicForm.tpl.php` (module-frontend) an action slot if R1 is to reach them.

## 4. Patterns vs one-offs

**Patterns (one shared change fixes N forms)**

- **P1 — popup footer → top bar** (22 templates, 12 of them edit forms): identical markup `be-modal__header / be-modal__body / be-modal__footer` in every `data-fetch-post` template across financial, debtor, contact, vat, member-backend. One host CSS rule + a mechanical move per template; a shared partial `partials/modalActions` would stop the drift for good. Decide first whether short confirms keep `--end` (ADR-049 §2) or follow R1 strictly — the answer sets 10 of the 22.
- **P2 — popup → window** (same 22 + the two ⋮ hubs): triggers are `data-fetch-get` everywhere (`addButton`, `act.tpl.php`, hubs, list rows). `data-window-open` + root `data-window`/`data-window-entity` per template; the save answers already are envelopes. Side effect: the ⋮ hub (contact/vat) no longer gets replaced by the edit it opens.
- **P3 — journal window recipe → debtor** (29, 30, 31, 27, 28): `'window' => isFetch()`, `_origin`, `data-window-link`, `open-window` + `refresh-region`. Five screens, one recipe already proven in `JournalControllerTrait`.
- **P4 — per-row page POSTs** (19, 20, 57): `data-fetch-post` + `replace-html` of the row; one controller helper («answer with this row») serves bank import and member devices.
- **P5 — filters in content** (15, 23): ADR-033 puts list filters in the toolbar; low priority, not an R1/R2 case.
- **P6 — member action bar / action cell** (54, 56, 59): tokens and the `submit` kind exist; the member's own forms just do not use them.

**One-offs**

- #13 journal detail: the read view's «Bearbeiten»/«Löschen …» row at the end of the window — move it to the top (one `<p>` → one `<div class="z77-form-actions">`).
- #27/#28 bar at the END with the top-variant class — a two-line move each, independent of P3.
- #3 fiscal-year open and #6 reopen: edit-like popups with fields (dates / required reason) hiding among the confirms — treat as edit forms in P1.
- #59 2FA setup leaves the shell for a card — needs its own small redesign (QR inside the pane), not a pattern.
