# listing

2026-10-08

## entry

1. `packages/kernel/shared/src/Listing/ListDefinition.php` — a list declared once: columns, default sort, page size, extras; reads its state, names its form and region, computes its tracks
2. `packages/kernel/shared/res/view/templates/partials/listHead.tpl.php` — the head every standard list renders: sort links, magnifiers, the collapsed search inputs
3. `tests/listing.php` — the harness

## file map

SOURCE=/packages/kernel/shared/src/Listing/Column.php
SOURCE=/packages/kernel/shared/src/Listing/ListDefinition.php
SOURCE=/packages/kernel/shared/src/Listing/ListState.php
SOURCE=/packages/kernel/shared/src/Listing/Parsers.php
SOURCE=/packages/kernel/shared/src/Paging/Paging.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/listHead.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/listFind.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/pager.tpl.php
SOURCE=/packages/module-backend/res/scss/components/_list.scss
SOURCE=/packages/module-backend/res/scss/components/_pagination.scss
SOURCE=/packages/module-debtor/src/Ui/InvoiceListing.php
SOURCE=/packages/module-debtor/src/Ui/OpenItemListing.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/InvoiceController/listAction.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/Backend/DebtorController/listAction.tpl.php
SOURCE=/packages/module-financial/src/Ui/ChangeLogListing.php
SOURCE=/packages/module-financial/res/view/templates/Backend/ChangeLogController/listAction.tpl.php
SOURCE=/packages/module-financial/src/Ui/JournalFilter.php
SOURCE=/packages/module-financial/res/view/templates/Backend/JournalController/listAction.tpl.php
SOURCE=/tests/listing.php

## mental model

Every table in the backend is a STANDARD LIST (owner 2026-10-08, after the journal list `finance/journal/list`): the column titles sort, the magnifier in a title opens the search field for that column, the search runs in the database over the whole data set, the rows are paged by server links, and the list is a fetch region — sort, page and search reload only the list. One shared block carries the mechanics (`Z77\Shared\Listing`, Rule 8), a module declares its columns ONCE and writes only its rows. Exceptions exist (a short fixed list, an upload page) but are the exception, not the pattern.

- **`ListDefinition`** — the list: an `id` (the search form's id, the prefix of the inputs `{id}-{key}`, the fetch region `{id}-list`), the `Column`s in order, the default sort, the page size, and the EXTRAS the address carries beside the columns (`'view' => ['open', 'overdue', 'all']` — the first value is the default; a flag is `['', '1']`). `read($query)` / `readRequest($request)` give the `ListState`; `tracks()` / `style()` the grid tracks per drop stage, computed from the columns; `isFetch($request)` says whether only the list is asked for.
- **`Column`** — three shapes: `search()` (a magnifier, a parser, a sort when given), `plain()` (a title, a sort optional), `slot()` (a cell without a title — the state icon, a checkbox, a row action — with an `aria-label`). `priority` '3' / '2' is the drop stage (css-backend.md LIST-DROP-STAGES-001); `descendingFirst` the direction a sort starts in (numbers, dates and amounts newest / largest first, names A–Z).
- **`ListState`** — the state IS the address: `sort`, `descending`, `page`, the search values as typed and as parsed, the invalid ones, the extras. `query($changes)` builds every link (defaults left out, the page travels only when it is the change), `sortQuery()` flips the current column, `resetQuery()` drops the fields, `hiddenState()` is what the find form carries, `paging($total)` the `Paging`.
- **`Parsers`** — the strict readers: `integer()`, `dateRange()` («15.11.2032» a day, «11.2032» a month, «2032» a year), `amount($currency)` (Swiss grouping, a comma, at most eleven digits), `text()`. An unreadable value marks the field INVALID and does not narrow the search — a typo must not look like «nothing found».
- **The partials** (`Z77\Shared`): `partials/listHead` renders one head cell per column — a `.be-list__find` with the sort link, the magnifier (`<label for>`) and the collapsed input of the GET form for a searching column, a title for a plain one, an empty cell for a slot; `partials/listFind` is that GET form (hidden state, `data-fetch-region-form`); `partials/pager` the pages. The CSS is module-backend's `_list.scss` (`.be-list__find`, `__sort`, `__find-icon`, `__find-input`, the drop stages) and `_pagination.scss` — nothing new.
- **The rows** stay in the module template: every searchable cell is a `<label for="{inputId}">` of its column's input (a click opens that search, no JavaScript), the first cell a state icon that opens the row as a window (ADR-047), the amounts through `AmountFormat`.
- **The repository** gets a search object built from the state (`InvoiceListing::search($state)`): bound values, the sort from a fixed map — never from input. `countSearch()` for the pager, `search($offset, $limit)` for the page.
- **The controller**: `$definition->readRequest($request)` → `search` → `count` → `$state->paging($total)` → the page; the toolbar slot (view tabs, buttons) only when it is not a fetch of the region.
- No JavaScript of the module's own: core.js «fetch regions» (fetch.md FETCH-REGION-001) reload the region from the same URLs; without the script every link and the form are plain page loads.

## rules

- When a backend screen shows a table of rows → MUST build it as a standard list: a `ListDefinition` in the module's `Ui/` (`{X}Listing::definition()` + `{X}Listing::search($state)`), `partials/listFind` + `partials/listHead` from `Z77\Shared`, the rows as `<label for>` of the column inputs, `partials/pager`; MUST NOT hand-write head cells, a search form, a filter class or a track triple of its own. A list that is not one (a short fixed list, a form page) says so in its template header.
- When a column searches → MUST parse the value strictly with a `Parsers` closure (or one of the same shape: `string → mixed|null`); MUST NOT pass the typed text to the repository unparsed, except through `Parsers::text()` for a LIKE.
- When the repository sorts → MUST map the sort key to an ORDER BY in a fixed `match`; MUST NOT interpolate the key.
- When a list carries a view or a scope flag → MUST declare it as an extra of the definition with its allowed values; MUST NOT read it from `$_GET` or the request beside the definition.
- When the action adds a toolbar slot → MUST skip it when `ListDefinition::isFetch($request)` is true — a fetch of the region wants the list alone.
- When a project overrides a list's look → overrides the module's row template or the kernel's partials under `override/z77/shared/…` (CE); MUST NOT edit the kernel's partials in place.

## known issues

- None documented.

## pending

- **The journal list still carries its own `JournalFilter`** (`module-financial/src/Ui/JournalFilter.php`) and head markup — the reference the standard was cut from (2026-10-08). Migrate it onto `ListDefinition` / the partials: the `keep` of the capture state (`mode`, `date`) is already supported by both partials, the year scope (`all`) becomes an extra; the deleted-numbers rows are gone since 2026-10-08 (the change log is its own standard list, `ChangeLogListing`). `tests/module-financial.php` has the list cases.
- The bank-import message list and the dunning due list (module-debtor) are short fixed lists without paging or column search — standard lists when they grow.

## see also

- [`css-backend.md`](css-backend.md) — `.be-list` v2, `.be-list__find`, the drop stages (LIST-DROP-STAGES-001), `.be-pagination`
- [`fetch.md`](fetch.md) — FETCH-REGION-001: how the region reloads from the same URLs
- [`financial.md`](financial.md) — the journal list, the first consumer of the column search (FIN-JOURNAL-CAPTURE-001); the change log screen (`ChangeLogListing`, a year scope as the extra `all` driven by the rail-top year selection)
- [`debtor.md`](debtor.md) — the document list and the open-item list, the first standard lists on the shared block
- [`money.md`](money.md) — `AmountFormat` for the amount cells
