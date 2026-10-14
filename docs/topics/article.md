# article

2026-10-14

## entry

1. `packages/module-article/src/Services/ArticleService.php` — the one write path of the article master: one save writes product, default variant and price; the A4 price rule; deactivate vs delete
2. `packages/module-article/src/Stock/StockLedger.php` — THE stock seam: the contract every source (module-order) reaches stock through, `bringTo(StockRequest)`
3. `tests/module-article.php` — the harness: validator (A), stock seam (K) offline; migration, price history, conflict, deactivate / delete (B) against a throwaway schema

## file map

SOURCE=/packages/module-article/composer.json
SOURCE=/packages/module-article/README.md
SOURCE=/packages/module-article/src/App/Config/articleConfig.inc.php
SOURCE=/packages/module-article/src/Entities/AbstractProduct.php
SOURCE=/packages/module-article/src/Entities/Product.php
SOURCE=/packages/module-article/src/Entities/AbstractVariant.php
SOURCE=/packages/module-article/src/Entities/Variant.php
SOURCE=/packages/module-article/src/Entities/Price.php
SOURCE=/packages/module-article/src/Repositories/ProductRepository.php
SOURCE=/packages/module-article/src/Repositories/VariantRepository.php
SOURCE=/packages/module-article/src/Repositories/PriceRepository.php
SOURCE=/packages/module-article/src/Repositories/ArticleSearch.php
SOURCE=/packages/module-article/src/Services/ArticleService.php
SOURCE=/packages/module-article/src/Services/ArticleDraft.php
SOURCE=/packages/module-article/src/Services/ArticleException.php
SOURCE=/packages/module-article/src/Services/InvalidArticleException.php
SOURCE=/packages/module-article/src/Services/ArticleConflictException.php
SOURCE=/packages/module-article/src/Services/ArticleInUseException.php
SOURCE=/packages/module-article/src/Validators/ArticleValidator.php
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
SOURCE=/packages/module-article/res/migrations/Version20261014130000.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/listAction.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/_row.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/act.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/edit.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/edit.help.tpl.php
SOURCE=/packages/module-article/res/view/templates/Backend/ArticleController/confirmDelete.tpl.php
SOURCE=/packages/module-article/data/framework/routing/navigation.d/module-article.json
SOURCE=/packages/module-backend/src/Ui/Controllers/Order/ArticleController.php
SOURCE=/packages/module-backend/src/Ui/Config/Order/articleControllerConfig.inc.php
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

`z77/module-article` is the article master an order line resolves to and the home of the ONE stock write path (ADR-040 decision 8, ADR-043 decision 9). Built 2026-10-14 as **P6 slim** with P7 slim (plan `p7-order-plan.md` steps 1 and 2, owner go «Alles ok los»): an article is a product (name, tax code, revenue account, «führt Bestand») with a hidden DEFAULT variant (number, unit) and a price history — two levels in the schema, ONE flat mask (owner Q2). The stock seam exists, its journal does not (P7b): every order write already calls `StockLedger::bringTo()`, and P7b changes article only. Depends on kernel, persistence-doctrine, module-vat and module-mandator (the soft account check); suggests module-financial; knows no order.

- **Schema** (`Version20261014130000`, expand only, no data step): `article_product` (`name` 120, `tax_code` 8 BY CODE into module-vat, `revenue_account` 10 BY NUMBER into the chart — no foreign keys, ADR-043 decision 19 —, `stock_relevant`, `active`, actor stamps, `version`), `article_variant` (`product_id` FK, `number` VARCHAR(32) UNIQUE `uniq_article_variant_number`, `label` — '' = the default variant —, `unit` 16, `active`), `article_price` (`variant_id` FK, `valid_from` DATE, `amount` money, `price_mode` net / gross; UNIQUE `uniq_article_price_day` on variant + day). No number range: the article number is typed (A2 — free, never derived).
- **Extendable master data** (ADR-039 addendum 2026-10-08 (b), the reference example): `AbstractProduct` / `AbstractVariant` are `#[ORM\MappedSuperclass]` with every field and the logic; `Product` / `Variant` are EMPTY entities. A project overrides only the empty class under `override/z77/module/article/src/Entities/` (same FQCN, repeats the class attributes incl. the unique constraint) and migrates its columns in `override/z77/project/res/migrations/`. A mapped superclass owns no one-to-many: variants are read through `VariantRepository` (`defaultOf()`, `allOf()`, `defaultsFor()`, and — since P7 step 7, 2026-10-14 — `selectable()`: the active variants of active products by number, the article picker a SOURCE asks for its `<datalist>`; module-order's order window is the first).
- **One save** (`ArticleService::create()` / `update($id, $version, ArticleDraft)`): the `ArticleDraft` (typed strings, no request) is validated FIRST (`ArticleValidator` — number required, no blank, unique; name; unit ≤ 16, may be empty; price not negative, two decimals, «'» grouping; net / gross; «gültig ab» a date; tax code known and ACTIVE, or the one the article keeps; revenue account digits + the soft check), then product, default variant and price are written in ONE unit of work. Update needs the version the form saw (stale → `ArticleConflictException`); `touch()` stamps every save so the version moves even on a price-only change.
- **Price history (A4, owner Q3)**: one field in the mask with «gültig ab» (default today). A price row starting that day is REPLACED; else a NEW row is written when the price valid that day says something else (amount or mode); an unchanged save writes nothing. `PriceRepository::validAt(variant, day)` is the price an order line proposes at its order date; `shownAt()` is the list's (today's, else the next future one, «ab …»).
- **Revenue account**: `ArticleService::accountProblem()` asks `LedgerAccountCheck` — postable, type «Ertrag», «auf Belegen wählbar» (the invoice line's rule and wording, mandator.md); `null` = financial not registered → stored unverified (SOFT). The datalist is `documentAccounts()`. No keep-exception (like an invoice draft): an account the chart switched off must be replaced at the next save.
- **Deactivate vs delete**: `setActive()` switches product and every variant; `delete($id, $version)` removes prices, variants, product — and a row elsewhere that references a variant (module-order's line has a foreign key on `article_variant`) makes the DATABASE refuse; the refusal becomes `ArticleInUseException` («deaktivieren»). Article never asks who references it.
- **Screen** (Stammdaten › Aufträge › Artikel, `/backend/order/article/list`, host `Order/ArticleController`, group `order`): a standard list (`ArticleListing`: state icon, Nr., Bezeichnung, Einheit, Preis, MWST, Konto, Bestand, Aktiv; Nr. / Name / MWST / Konto search, Nr. / Name sort), action cell «+ Artikel». The article is a WINDOW (ADR-047; a window, not a modal popup, because it carries help — ADR-048) with the action row Anlegen / Speichern · Löschen … · Abbrechen, the price history read-only below the form; the save closes the window and reloads the list region (an edit also replaces its row). «Löschen …» loads the confirmation into the same window. Without the script: plain page forms (`#[Csrf]`), redirect to the list.
- **Navigation**: seed `module-article.json` — `artikel` «Artikel» under debtor's `stammdaten-auftraege` (sort 11; module-order's «Auftragsstatus» takes 10). Article does not require debtor: without it the entry waits (ADR-050).

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
- When writing an article → MUST go through `ArticleService` with an `ArticleDraft` and a named actor; MUST NOT persist `Product`, `Variant` or `Price` anywhere else, and MUST NOT call `describe()` / `identify()` / `replace()` on a managed entity outside the service
- When a module or project extends the article → MUST override only the EMPTY `Product` / `Variant` under `override/z77/module/article/src/Entities/` (extends the abstract class, repeats the class attributes) and migrate its columns in `override/z77/project/res/migrations/`; MUST NOT copy `AbstractProduct` / `AbstractVariant`
- When code in module-article needs to know whether an article is referenced → MUST rely on the foreign key of the referencing table (the delete is refused, `ArticleInUseException`); MUST NOT name `Z77\Module\Order` or `OrderStatus` (harness K13)
- When an order line takes an article's price → MUST read `PriceRepository::validAt($variant, $orderDate)` and keep the value as the line's snapshot; MUST NOT reference the price row from the line
- When the revenue account of an article is checked → MUST use `ArticleService::accountProblem()` / `LedgerAccountCheck` (soft, three-valued); MUST NOT keep a list of accounts or filter by number range (mandator.md)

## known issues

- **ARTICLE-DB-001** — added 2026-10-14. Don't assume section B of `tests/module-article.php` has run: it needs the `z77test` credentials, which are missing on PC2 (review 2026-10-13 §6). Steps 1 and 2 were verified there with sections A and K, `z77-db diff` / `setup --dry-run` clean on the z77.ch dev database and a live click-through by curl (create, refused account / price / number, update with price history, stale version, toggle, delete). Run B on PC1.

## pending

- Run `tests/module-article.php` section B on PC1 (ARTICLE-DB-001).
- P7b: the journal ledger behind `StockLedger` (tables keyed by variant id, the difference booking, corrections with reason, stocktake through `openWorkChecks` scope `stocktake`, the stock column on the list) — plan §8.2; `StockLedgers::default()` switches then.
- The article number of a NEW article could later come from a number range (A2 «free or from a number range») — not in slim; the field is typed.
- The actor: `ArticleService` takes the actor from its caller (the screen passes the logged-in user). The shared actor resolution (debtor.md pending: «when a third module needs it, it moves to the kernel's shared») is not done here — module-debtor was out of scope for this step.

## see also

- [`debtor.md`](debtor.md) — the parent navigation group `stammdaten-auftraege`; the invoice line's account rule this module mirrors
- [`mandator.md`](mandator.md) — `LedgerAccountCheck::documentAccounts()` / `isDocumentAccount()`, the shared `accountDatalist`
- [`vat.md`](vat.md) — tax codes by code, `TaxCodeRepository::selectable()`, `PriceMode`, the shared `taxCodeSelect`
- [`persistence-doctrine.md`](persistence-doctrine.md) — mapped superclass + empty entity, migrations, the transaction port the stock ledger joins
- [`listing.md`](listing.md) — the standard list the article screen is
- [`css-backend.md`](css-backend.md) — the action cell row «Stammdaten › Artikel»
- [`navigation.md`](navigation.md) — the seed `module-article.json`
