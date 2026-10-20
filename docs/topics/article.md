# article

2026-10-14

## entry

1. `packages/module-article/src/Services/ArticleService.php` — the one write path of the article master: one save writes product (in its group), default variant and price; the A4 price rule; the group defaults for a NEW article; deactivate vs delete
2. `packages/module-article/src/Services/ProductGroupTree.php` — the product-group tree as one read model: tree order, path, subtree, inherited defaults, the cycle question, the picker
3. `packages/module-article/src/Stock/StockLedger.php` — THE stock seam: the contract every source (module-order) reaches stock through, `bringTo(StockRequest)`
4. `tests/module-article.php` — the harness: validator incl. the description and `lineText()` (A), product-group tree (G), stock seam (K) offline; migration, price history, conflict, deactivate / delete, the group service and filter (B) against a throwaway schema

## file map

SOURCE=/packages/module-article/composer.json
SOURCE=/packages/module-article/README.md
SOURCE=/packages/module-article/src/App/Config/articleConfig.inc.php
SOURCE=/packages/module-article/src/Entities/AbstractProduct.php
SOURCE=/packages/module-article/src/Entities/Product.php
SOURCE=/packages/module-article/src/Entities/AbstractVariant.php
SOURCE=/packages/module-article/src/Entities/Variant.php
SOURCE=/packages/module-article/src/Entities/Price.php
SOURCE=/packages/module-article/src/Entities/ProductGroup.php
SOURCE=/packages/module-article/src/Repositories/ProductRepository.php
SOURCE=/packages/module-article/src/Repositories/VariantRepository.php
SOURCE=/packages/module-article/src/Repositories/PriceRepository.php
SOURCE=/packages/module-article/src/Repositories/ArticleSearch.php
SOURCE=/packages/module-article/src/Repositories/ProductGroupRepository.php
SOURCE=/packages/module-article/src/Services/ArticleService.php
SOURCE=/packages/module-article/src/Services/ArticleDraft.php
SOURCE=/packages/module-article/src/Services/ArticleException.php
SOURCE=/packages/module-article/src/Services/InvalidArticleException.php
SOURCE=/packages/module-article/src/Services/ArticleConflictException.php
SOURCE=/packages/module-article/src/Services/ArticleInUseException.php
SOURCE=/packages/module-article/src/Services/ProductGroupService.php
SOURCE=/packages/module-article/src/Services/ProductGroupTree.php
SOURCE=/packages/module-article/src/Services/ProductGroupDraft.php
SOURCE=/packages/module-article/src/Services/InvalidProductGroupException.php
SOURCE=/packages/module-article/src/Services/ProductGroupConflictException.php
SOURCE=/packages/module-article/src/Services/ProductGroupInUseException.php
SOURCE=/packages/module-article/src/Validators/ArticleValidator.php
SOURCE=/packages/module-article/src/Validators/ProductGroupValidator.php
SOURCE=/packages/module-article/src/Stock/StockLedger.php
SOURCE=/packages/module-article/src/Stock/StockRequest.php
SOURCE=/packages/module-article/src/Stock/StockLineTarget.php
SOURCE=/packages/module-article/src/Stock/StockConfirmation.php
SOURCE=/packages/module-article/src/Stock/StockResult.php
SOURCE=/packages/module-article/src/Stock/StockReasonRequiredException.php
SOURCE=/packages/module-article/src/Stock/PendingStockLedger.php
SOURCE=/packages/module-article/src/Stock/StockLedgers.php
SOURCE=/packages/module-article/src/Ui/ArticleControllerTrait.php
SOURCE=/packages/module-article/src/Ui/ArticleLayout.php
SOURCE=/packages/module-article/src/Ui/ArticleListing.php
SOURCE=/packages/module-article/src/Ui/ArticlePicker.php
SOURCE=/packages/module-article/src/Ui/ArticlePickerTrait.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticlePicker/pick.tpl.php
SOURCE=/packages/module-article/res/view/templates/partials/productGroupPicker.tpl.php
SOURCE=/packages/module-backend/res/scss/components/_pick-list.scss
SOURCE=/packages/module-article/src/Ui/ProductGroupControllerTrait.php
SOURCE=/packages/module-article/src/Ui/ProductGroupLayout.php
SOURCE=/packages/module-article/res/migrations/Version20261014130000.php
SOURCE=/packages/module-article/res/migrations/Version20261014150000.php
SOURCE=/packages/module-article/res/migrations/Version20261014150001.php
SOURCE=/packages/module-article/res/migrations/Version20261014150002.php
SOURCE=/packages/module-article/res/migrations/Version20261018120000.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/listAction.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/_row.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/act.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/edit.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/edit.help.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/confirmDelete.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/groupPicker.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/partials/treePicker.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/listAction.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/_row.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/act.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/edit.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/edit.help.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ProductGroupController/confirmDelete.tpl.php
SOURCE=/packages/module-article/res/view/templates/partials/productGroupOptions.tpl.php
SOURCE=/packages/module-article/data/framework/routing/navigation.d/module-article.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Order/ArticleController.php
SOURCE=/packages/module-backend/src/Ui/Config/Order/articleControllerConfig.inc.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Order/ProductGroupController.php
SOURCE=/packages/module-backend/src/Ui/Config/Order/productGroupControllerConfig.inc.php
SOURCE=/packages/kernel/shared/src/Tree/TreeService.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/packages/module-mandator/src/Services/LedgerAccountCheck.php
SOURCE=/packages/module-vat/res/view/templates/partials/taxCodeSelect.tpl.php
SOURCE=/tests/module-article.php
SOURCE=/docs/03-development/p7-order-plan.md
SOURCE=/docs/03-development/order-debtor-financial-bauplan.md
SOURCE=/docs/02-decisions/adr-040-business-module-cut.md
SOURCE=/docs/02-decisions/adr-043-order-status-and-stock-movements.md
SOURCE=/docs/02-decisions/adr-039-doctrine-driver-behind-unified-entity-manager.md

## mental model

`z77/module-article` is the article master an order line resolves to and the home of the ONE stock write path (ADR-040 decision 8, ADR-043 decision 9). Built 2026-10-14 as **P6 slim** with P7 slim (plan `p7-order-plan.md` steps 1 and 2, owner go «Alles ok los»): an article is a product (name, its ONE product group — the tree of A1, since 2026-10-14 —, tax code, revenue account, «führt Bestand») with a hidden DEFAULT variant (number, unit) and a price history — two levels in the schema, ONE flat mask (owner Q2). The stock seam exists, its journal does not (P7b): every order write already calls `StockLedger::bringTo()`, and P7b changes article only. Depends on kernel, persistence-doctrine, module-vat and module-mandator (the soft account check); suggests module-financial; knows no order.

- **Schema** (`Version20261014130000`, expand only, no data step): `article_product` (`name` 120, `tax_code` 8 BY CODE into module-vat, `revenue_account` 10 BY NUMBER into the chart — no foreign keys, ADR-043 decision 19 —, `stock_relevant`, `active`, actor stamps, `version`), `article_variant` (`product_id` FK, `number` VARCHAR(32) UNIQUE `uniq_article_variant_number`, `label` — '' = the default variant —, `unit` 16, `active`), `article_price` (`variant_id` FK, `valid_from` DATE, `amount` money, `price_mode` net / gross; UNIQUE `uniq_article_price_day` on variant + day). No number range: the article number is typed (A2 — free, never derived).
- **Description** (owner 2026-10-18, `Version20261018120000`, schema only — existing products ''): `article_product.description` (LONGTEXT NOT NULL, '' = none) — a multi-line text beside the name, on the PRODUCT, so every variant shares it. Optional, at most `AbstractProduct::DESCRIPTION_LENGTH` (1500) characters (`ArticleValidator::validateDescription()`), line breaks normalized to `\n` by `ArticleDraft::fromArray()`; a textarea «Beschreibung» in the article window with its help section. **THE line text of an article** is `AbstractVariant::lineText()`: `displayName()` (name + the label of a non-default variant) on the FIRST line, the description from the second line on (none = the name alone). Every source builds a line's text through it — module-order's fill (`OrderService::articleDefaults()`) and its save of an article line with an empty text; module-debtor has no article lines. A copy: the line keeps it editable, a later change of the description does not reach existing lines. The documents print the first line bold (kernel `pdf/table` row option `lead`, pdf.md).
- **Extendable master data** (ADR-039 addendum 2026-10-08 (b), the reference example): `AbstractProduct` / `AbstractVariant` are `#[ORM\MappedSuperclass]` with every field and the logic; `Product` / `Variant` are EMPTY entities. A project overrides only the empty class under `override/z77/module/article/src/Entities/` (same FQCN, repeats the class attributes incl. the unique constraint) and migrates its columns in `override/z77/project/res/migrations/`. A mapped superclass owns no one-to-many: variants are read through `VariantRepository` (`defaultOf()`, `allOf()`, `defaultsFor()`, and — since P7 step 7, 2026-10-14 — `selectable()`: the active variants of active products by number, the article picker a SOURCE asks for its `<datalist>`; module-order's order window is the first).
- **One save** (`ArticleService::create()` / `update($id, $version, ArticleDraft)`): the `ArticleDraft` (typed strings, no request) is validated FIRST (`ArticleValidator` — number required, no blank, unique; name; unit ≤ 16, may be empty; price not negative, two decimals, «'» grouping; net / gross; «gültig ab» a date; tax code known and ACTIVE, or the one the article keeps; revenue account digits + the soft check), then product, default variant and price are written in ONE unit of work. Update needs the version the form saw (stale → `ArticleConflictException`); `touch()` stamps every save so the version moves even on a price-only change.
- **Price history (A4, owner Q3)**: one field in the mask with «gültig ab» (default today). A price row starting that day is REPLACED; else a NEW row is written when the price valid that day says something else (amount or mode); an unchanged save writes nothing. `PriceRepository::validAt(variant, day)` is the price an order line proposes at its order date; `shownAt()` is the list's (today's, else the next future one, «ab …»).
- **Revenue account**: `ArticleService::accountProblem()` asks `LedgerAccountCheck` — postable, type «Ertrag», «auf Belegen wählbar» (the invoice line's rule and wording, mandator.md); `null` = financial not registered → stored unverified (SOFT). The datalist is `documentAccounts()`. No keep-exception (like an invoice draft): an account the chart switched off must be replaced at the next save.
- **Deactivate vs delete**: `setActive()` switches product and every variant; `delete($id, $version)` removes prices, variants, product — and a row elsewhere that references a variant (module-order's line has a foreign key on `article_variant`) makes the DATABASE refuse; the refusal becomes `ArticleInUseException` («deaktivieren»). Article never asks who references it.
- **Screen** (Stammdaten › Aufträge › Artikel, `/backend/order/article/list`, host `Order/ArticleController`, group `order`): a standard list (`ArticleListing`: state icon, Nr., Bezeichnung, Einheit, Preis, MWST, Konto, Bestand, Aktiv; Nr. / Name / MWST / Konto search, Nr. / Name sort), action cell «+ Artikel». The article is a WINDOW (ADR-047; a window, not a modal popup, because it carries help — ADR-048) with the action row Anlegen / Speichern · Löschen … · Abbrechen, the price history read-only below the form; the save closes the window and reloads the list region (an edit also replaces its row). «Löschen …» loads the confirmation into the same window. Without the script: plain page forms (`#[Csrf]`), redirect to the list.
- **Navigation**: seed `module-article.json` — `warengruppen` «Warengruppen» (sort 11) and `artikel` «Artikel» (sort 12, was 11 until 2026-10-14) under debtor's `stammdaten-auftraege` (module-order's «Auftragsstatus» takes 10). Article does not require debtor: without it the entries wait (ADR-050). An installation that already has «Artikel» gets «Warengruppen» appended behind its siblings (NAV-SEED-002) — re-sort by hand (z77.ch: done 2026-10-14, Warengruppen before Artikel).

## article picker (built 2026-10-19)

Owner 2026-10-19: «Artikel wählen …» — a searching WINDOW over the editor that lets a document line choose its article, the customer picker's model (debtor.md «The customer picker»). A building block (Rule 9): article offers it, a SOURCE mounts it; module-order's order window is the first. **Where it lives — decided at build:** in module-article, because the list, the search and the group filter are the article master's; article still knows no order (module-order requires module-article, never the reverse — harness Q6, K13). What only the source knows comes in through the mounting trait.

- **Mount**: `Ui\ArticlePickerTrait` (`use ArticlePickerTrait;` inside the source's controller trait — as `CustomerPickerTrait`), action `article-pick` at the source's root. The source implements `articlePickBase()` (its URL root), `articlePickContextKeys()` (the parameters its price needs — the order: `order_date`, `service_from`, `price_mode`), `articlePickPrices()` (head + one price per shown variant, the source's rule — the order: `OrderService::articlePriceAt()`, the fill's price) and `articlePickedCommands()` (what a choice does in the editor).
- **The window** (`Backend/ArticlePicker/pick`, mask `article-pick`, 52rem): action row «Schliessen»; below ONE fetch region (`ArticlePicker::REGION`): the toolbar — the product-group filter (the module's shared `partials/productGroupPicker`, the same field as the article list's toolbar; inside a region its links reload only the region, core.js «tree picker») and ONE search field (every word a part of the number, the name or the description — `ArticleSearch::$text`, at most five words) — and the COMPACT list Nr. · Bezeichnung (+ the first description line, muted) · Einheit · Preis (the source's head, e.g. «Preis brutto»; «–» without a price that day). Only what a NEW line may name: active product, active default variant (`ArticleSearch::$activeOnly`, `ArticlePicker::isChoosable()`); by number, at most `LIMIT` 50 rows («Die ersten 50 von N — Suche verfeinern», no pager). The state is the address: `q`, `group` (a group of the tree WITH its sub-groups), `target` (the DOM id of the field the choice goes to — a plain id or '', never a selector), `_origin` and the context keys — every link and the search form carry them.
- **Keys** (core.js «pick list»): the search field has `autofocus`; typing searches after a short pause (the region reloads, the field keeps what was typed meanwhile), ↑/↓ move the active row, Enter picks it, Esc closes the window (an Esc in the open group popover closes only the popover). A click anywhere on a row picks it: the number is the submit button, the other cells are its `<label>`s.
- **The choice** (POST `article=<number>`, `#[Csrf]`): judged again (`isChoosable()`); fetched → `close-modal` (the picker), then the source's commands into the origin window. Without the script the picker is a page (the order opens it in a NEW TAB, so the half-typed order stays); a choice there answers with the number to type.
- **Not built**: variants with options (the list shows the DEFAULT variant per product — the only one there is), a pager, per-customer prices.

## product-group tree (A1, built 2026-10-14)

Owner 2026-10-14: «Der Baum reicht vorerst, ich muss ja irgendwie Waren gruppieren können.» — plan §4b A1, the accounting classification of the article master (not the shop navigation).

- **Entity** `ProductGroup` (table `article_product_group`): parent (self foreign key, NULL = a root), name (80), `sort_key` among the siblings, active, `default_tax_code` / `default_revenue_account` ('' = none — by code / number, no foreign key, ADR-043 decision 19), `version`. ONE tree of free depth. Implements the shared `TreeNode`, so the shared `TreeService` sorts, appends (`nextSortKey()`) and renumbers; the parent is a RELATION (the foreign key keeps the tree whole), so a move goes through `moveUnder()` — `setParentId()` refuses a change. NOT extendable (no abstract stage): backend-screen.md §7 «built when a module needs it, never in advance»; a shop's own group fields are a later decision.
- **Placement**: `AbstractProduct::$productGroup` → `article_product.product_group_id` NOT NULL, a foreign key — exactly ONE group per PRODUCT (not per variant), any node, not only a leaf. Moving a group moves its articles with it — placement by id, nothing is rewritten.
- **Coordinator defaults 2026-10-14** (the owner did not object; the owner may revise): the group is MANDATORY; an article may hang on ANY node; the data migration creates the root «Allgemein» (into an EMPTY tree, on every installation — a fresh one can create its first article right away) and places every existing product there (z77.ch: 1001 «Beratung»).
- **Defaults for NEW articles**: each field from the NEAREST group on the way up that sets one, independently per field (`ProductGroupTree::defaultsFor()`). Two ways in, no script: (a) «+ Artikel» while the list is filtered by a group opens `add?group=<id>` — the window starts in that group with tax code and account PREFILLED; (b) `ArticleService::create()` fills a tax code / account left EMPTY from the chosen group's defaults BEFORE validating (`ArticleDraft::withDefaults()` — a typed value wins). An update never reads a default: existing articles never change when a group default changes. A group's defaults are validated like the article's fields (tax code known and active — or kept; account digits + the soft `accountProblem()`).
- **Write path** `ProductGroupService`: `create()` (at the end of its siblings), `update($id, $version, $draft)` (rename, defaults, MOVE: another parent → end of the new siblings, the old siblings renumbered; a parent inside the own subtree is refused — `ProductGroupValidator`, `ProductGroupTree::isWithin()`), `setActive()` (an inactive group is not offered when an article is placed; an article that hangs there keeps it — the reference rule), `delete($id, $version)` only an EMPTY group (no sub-group, no article — `ProductGroupInUseException` names what is left; the foreign keys decide under a race). Name unique among SIBLINGS only (case-insensitive).
- **Screen** Stammdaten › Aufträge › Warengruppen (`/backend/order/product-group/list`, host `Order/ProductGroupController`): the tree on the chart of accounts' rows (`.be-tree--hub be-tree--lead-switch`, `--node-depth`) — the one tree UI of the backend, deliberately not a standard list (a short tree read whole: no paging, no column search). Row: «Aktiv» switch · ⋮ opens the group WINDOW (with help) · name (bold with sub-groups) · «Vorgabe MWST …, Konto …» · tools: «N Artikel» (incl. sub-groups — a link to the article list filtered by the group) and «+ Untergruppe» (folder-plus icon → `add?parent=<id>`). Action cell «+ Warengruppe». The window: Name, Übergeordnete Gruppe (indented options without the own subtree — another choice MOVES the group), «Vorgaben für neue Artikel»: MWST-Code (the shared `taxCodeSelect`, its «none» option names what is inherited — `noneLabel`, added 2026-10-14) and Ertragskonto (placeholder «geerbt: …», the shared account datalist); «Löschen …» → confirmation in the same window (an occupied group says what is in it and offers no delete). The tree is a fetch region; every write answers close-window + `refresh-region`.
- **Article list**: the group filter is a FIELD in the TOOLBAR (`groupPicker`, hc2 — owner 2026-10-18, the prototype «Warengruppen-Auswahl»: a list filter is not an area choice, so it left the top of the rail; ARTICLE-GROUP-TREE-UI-001 b). It is the shared tree picker — since 2026-10-19 through the module's `partials/productGroupPicker`, which the article picker's window renders too — (`Z77\Shared` `partials/treePicker` + core.js «tree picker», css-backend.md «buttons, tabs, switch»): the muted label «WARENGRUPPE», the chosen group's path shortened («Handelswaren › … › SD-Cards», the full path as title), a caret; «×» clears the filter («Alle Warengruppen» when none). The popover searches ALL levels (every word in the group's path, at least one in its own name; each hit with its parent path) and otherwise shows ONE level: «Alle in «X»», then the children, each with its article count INCLUDING sub-groups (`ProductGroupTree::totals()` over `ProductGroupRepository::productCounts()`, computed only for the page, not for a region fetch) and «›» to its children; it opens at the level of the chosen group. Without the script the field is a `<details>` with every group as an indented link. Every choice is a LINK built by the server (`?group=<id>`, the rest of the list's state kept, page 1) — the tree travels to the script as a JSON data island, never as HTML. A group shows its articles INCLUDING sub-groups. The filter is the list's extra `group` (allowed values = the tree's ids), so the choice is the address; the list title names the group. Column «Warengruppe» (the path, drop stage 3). The article window has the group select (`partials/productGroupOptions` — shared with the group window's parent picker).

## stock seam (contract, plan §8.1)

- `StockLedger::bringTo(StockRequest): StockResult` — bring ONE source document to the given target state; books only the DIFFERENCE against the journal (ADR-043 decision 12), so a repeated call books nothing.
- `StockRequest` (checked on construction): `sourceType` / `sourceRef` (opaque origin, `order` / the order id), `trigger` (`status:{from}>{to}` · `lines` · `delivery-note` · `booking-print`, opaque), `actor`, optional `StockConfirmation`, `lines` = the COMPLETE current state — every article line as a `StockLineTarget` (`lineRef`, `variantId`, `reserved`, `consumed` — decimal strings with three places, the sign kept: negative = inflow, a return). A line appears at most once; an empty list means «the document holds nothing». Free and text lines are not sent.
- The SOURCE computes the targets from its own status flags (reserved = quantity if the status reserves, consumed = quantity if it consumes, else `0.000`) and calls on EVERY status change AND position change (ADR-043 addendum 2026-10-14). Quantities, never a status, cross the boundary; the ledger decides per variant whether stock is kept (`stock_relevant`) — a non-stock variant counts as target zero, so switching the flag off releases what the journal holds.
- Whatever the journal holds for the source and the request no longer names (a deleted line, a line whose variant changed — keyed by line AND variant) is brought to zero.
- Bringing CONSUMED stock back throws `StockReasonRequiredException` (the lines) unless the request carries a `StockConfirmation` (reason + confirmed) — ADR-043 decision 14; the source re-asks with a mandatory reason.
- The ledger JOINS the caller's unit of work and never commits (decision 13); it never posts (decision 18).
- Selection: `StockLedgers::default(UnifiedEntityManager, actor)` → **`PendingStockLedger`** in P7 slim (accepts every valid request, books nothing, never refuses); P7b returns the journal ledger here. A source takes an optional `StockLedger` in its constructor (a spy / refusing double in the harness) and asks `StockLedgers::default()` otherwise.

## rules

- When a source changes what a document holds in stock terms (status change, line add / edit / delete) → MUST call `StockLedger::bringTo()` ONCE per write with the COMPLETE target state of the document, inside its own unit of work; MUST NOT send a delta, a status, or call it from a controller, repository or template
- When a module needs the stock ledger → MUST take `StockLedgers::default($em, $actor)` unless a ledger was injected; MUST NOT instantiate `PendingStockLedger` (or later the journal ledger) directly in production code
- When writing an article → MUST go through `ArticleService` with an `ArticleDraft` and a named actor; MUST NOT persist `Product`, `Variant` or `Price` anywhere else, and MUST NOT call `describe()` / `identify()` / `replace()` / `placeIn()` on a managed entity outside the service
- When writing a product group → MUST go through `ProductGroupService` with a `ProductGroupDraft`; MUST NOT call `describe()` / `moveUnder()` / `setSortKey()` on a managed `ProductGroup` elsewhere, and MUST NOT rewrite articles when a group moves or its defaults change (placement by id; defaults reach NEW articles only)
- When code needs the tree (path, subtree, depth, inherited defaults, a picker) → MUST ask `ProductGroupRepository::tree()` / `ProductGroupTree` (one query for the whole tree); MUST NOT walk parents with one query per level, and MUST NOT build a second tree widget — the screens reuse the hub-tree rows and `partials/productGroupOptions`
- When a module or project extends the article → MUST override only the EMPTY `Product` / `Variant` under `override/z77/module/article/src/Entities/` (extends the abstract class, repeats the class attributes) and migrate its columns in `override/z77/project/res/migrations/`; MUST NOT copy `AbstractProduct` / `AbstractVariant`
- When code in module-article needs to know whether an article is referenced → MUST rely on the foreign key of the referencing table (the delete is refused, `ArticleInUseException`); MUST NOT name `Z77\Module\Order` or `OrderStatus` (harness K13)
- When a source lets a line CHOOSE an article in a window → MUST mount `Ui\ArticlePickerTrait` and answer its four hooks (base, context keys, prices, commands); MUST NOT build a second article search window, and the picker MUST NOT name the source (prices and the answer are the source's)
- When a screen filters by product group → MUST render the module's `partials/productGroupPicker` (over the kernel's `partials/treePicker`) with its own `href`; MUST NOT build the tree picker's nodes a second time
- When a document line takes an article's text → MUST use `Variant::lineText()` (name, then the description) as the snapshot; MUST NOT concatenate name, label or description itself
- When an order line takes an article's price → MUST read `PriceRepository::validAt($variant, $orderDate)` and keep the value as the line's snapshot; MUST NOT reference the price row from the line
- When the revenue account of an article is checked → MUST use `ArticleService::accountProblem()` / `LedgerAccountCheck` (soft, three-valued); MUST NOT keep a list of accounts or filter by number range (mandator.md)

## known issues

- **ARTICLE-DB-001** — added 2026-10-14. Don't assume section B of `tests/module-article.php` has run: it needs the `z77test` credentials, which are missing on PC2 (review 2026-10-13 §6). Steps 1 and 2 were verified there with sections A and K, `z77-db diff` / `setup --dry-run` clean on the z77.ch dev database and a live click-through by curl (create, refused account / price / number, update with price history, stale version, toggle, delete). Run B on PC1. The same holds for the product-group tree (B1–B3a, B5, B17–B26, written 2026-10-14, not run): verified with A17–A21, G1–G14 offline, the three migrations applied on z77.ch with `z77-db diff` clean, and a live click-through by curl (groups created with defaults, a non-revenue default account refused, a duplicate sibling name and a cycle refused, the article moved into «Dienstleistungen › Beratung», the list filter incl. sub-groups, the prefill and the default fill of a new article, an inactive group refused for a new article and kept by an existing one, an occupied group not deleted, an empty one deleted, a move and back, a stale version refused). 2026-10-18 (the description): the WHOLE harness ran green on the machine with the `z77test` credentials — A, G, K and B, 83 checks incl. B6a / B6b.
- **ARTICLE-GROUP-ORDER-001** — added 2026-10-14. Don't look for a way to reorder sibling groups: there is none yet. A new or moved group goes to the END of its siblings (`sort_key`); the order is changed today only by moving a group out and back. A drag & drop (the navigation tree's `TreeService::reorderInto()`) or an alphabetical order is the owner's call — `## pending`.
- **ARTICLE-GROUP-TREE-UI-001** — added 2026-10-14. Two deliberate deviations, flagged for the owner's look: (a) the Warengruppen screen is built on the hub-tree rows (`.be-tree--hub`, as the chart of accounts) although css-backend.md says new list screens use `.be-list` v2 — v2 has no depth, and the instruction was to reuse the one tree UI rather than invent a second; (b) ~~the article list's group filter uses the rail-top selection (`railSelect`)~~ — **resolved 2026-10-18**: the owner approved the prototype «Warengruppen-Auswahl»; the filter is the shared tree picker in the TOOLBAR (`groupPicker`, hc2), the rail-top select of the list and its template `groupSelect` are gone. (a) stays open.

## pending

- Run `tests/module-article.php` section B on PC1 (ARTICLE-DB-001).
- P7b: the journal ledger behind `StockLedger` (tables keyed by variant id, the difference booking, corrections with reason, stocktake through `openWorkChecks` scope `stocktake`, the stock column on the list) — plan §8.2; `StockLedgers::default()` switches then.
- The article number of a NEW article could later come from a number range (A2 «free or from a number range») — not in slim; the field is typed.
- **wdv import (P5b / P8)**: wdv's four fixed levels `group1..group4` map to a PATH in the product-group tree — the import creates the missing nodes level by level (a `ProductGroupDraft` per level, parent = the previous one, empty levels skipped) and places each product on the deepest one. The value lists of plan §4b «Migration effort» (country / region that slipped onto a group level) are decided BEFORE that mapping.
- **Variants with OPTIONS** (vintage, size — plan §4b «Variant options are DATA») — later, with the shop; today a product has its hidden default variant only.
- **Shop categories (MULTI-placement, Magento-style)** — later, with the shop (the owner found the idea interesting, 2026-10-14): an article in SEVERAL shop categories, a separate structure from the ONE accounting group of A1. Not built; the product-group tree stays the accounting classification.
- **Discount group and turnover statistics per product group** (plan §4b A1 names them; A6 derives a group-wide discount from the tree) — nothing reads them yet; built when a consumer needs them.
- Reorder sibling groups (ARTICLE-GROUP-ORDER-001): drag & drop via `TreeService::reorderInto()` or alphabetical — owner's call.
- Owner look at the remaining layout decision of ARTICLE-GROUP-TREE-UI-001 (a: the hub tree; b — the rail picker — was replaced by the toolbar tree picker 2026-10-18), and at the coordinator defaults (group mandatory, any node, «Allgemein»).
- Owner look at the article picker in the browser (built 2026-10-19; verified by the harnesses — module-article Q / B27, module-order F13 / M28, core-js K — and an offline render, not yet clicked live): the row's magnifier, «+ Artikel» → a new row, typing / ↑↓ / Enter / Esc, the group popover inside the window, the page without the script.
- Owner look at the tree picker in the browser (built 2026-10-18 from the approved prototype; verified by the harnesses and a jsdom run, not yet clicked live). Known limit, as before with the rail picker: the picker sits in the toolbar OUTSIDE the list's fetch region, so after a search / sort inside the region its links still carry the state of the page load (the search typed since is dropped when a group is chosen); the counts likewise stay those of the page load until the next page load.
- The actor: `ArticleService` takes the actor from its caller (the screen passes the logged-in user). The shared actor resolution (debtor.md pending: «when a third module needs it, it moves to the kernel's shared») is not done here — module-debtor was out of scope for this step.

## see also

- [`debtor.md`](debtor.md) — the parent navigation group `stammdaten-auftraege`; the invoice line's account rule this module mirrors
- [`mandator.md`](mandator.md) — `LedgerAccountCheck::documentAccounts()` / `isDocumentAccount()`, the shared `accountDatalist`
- [`vat.md`](vat.md) — tax codes by code, `TaxCodeRepository::selectable()`, `PriceMode`, the shared `taxCodeSelect`
- [`persistence-doctrine.md`](persistence-doctrine.md) — mapped superclass + empty entity, migrations, the transaction port the stock ledger joins
- [`listing.md`](listing.md) — the standard list the article screen is
- [`css-backend.md`](css-backend.md) — the action cell row «Stammdaten › Artikel»
- [`navigation.md`](navigation.md) — the seed `module-article.json`
- [`financial.md`](financial.md) — the chart of accounts, whose hub-tree rows the Warengruppen screen reuses
