# z77/module-article

The article master of the z77 business modules and the **one stock seam**: what an order line
names (number, text, unit, price, tax code, revenue account), organised in a product-group tree,
with a price history. An order line takes its values from the article as a snapshot; the article
knows no order.

Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework) monorepo
(`packages/module-article`); published on Packagist since 2.0.0:
`composer require z77/module-article`. MIT licence.

## What it does

- **Article** (`Entities\Product` / `Entities\Variant`, extendable: the fields live in
  `AbstractProduct` / `AbstractVariant`, the entities are empty — a project overrides the empty
  class under `override/`, ADR-039 addendum 2026-10-08): a product (name, description, product
  group, tax code by code, revenue account by number, «führt Bestand») with one hidden default
  variant (the article number — typed, unique — and the unit). One flat mask edits both.
- **Price history** (`Entities\Price`): one price field with «gültig ab»; a new day writes a new
  row, the same day replaces it. An order line proposes the price valid at its order date
  (`PriceRepository::validAt()`).
- **Product-group tree** (`Entities\ProductGroup`, `Services\ProductGroupTree`): one tree of free
  depth, exactly one group per article (any level), optional default tax code and revenue account
  inherited by NEW articles.
- **Write paths**: `Services\ArticleService` (create / update with version / deactivate / delete
  an unreferenced article), `Services\ProductGroupService` (create, rename, move, deactivate,
  delete an empty group). The revenue account is checked softly against the chart when
  `z77/module-financial` is installed.
- **Article picker** (`Ui\ArticlePickerTrait`): a searching window a source mounts to let a line
  choose its article (module-order's order window is the first).
- **The stock seam** (`Stock\StockLedger::bringTo(StockRequest)`): a source sends the COMPLETE
  target state of a document (reserved / consumed per line) on every write; the ledger books only
  the difference. Selected by `StockLedgers::default()`.

## Requirements

PHP 8.4 and `z77/kernel`, `z77/persistence-doctrine`, `z77/module-vat`, `z77/module-mandator`.
Suggested: `z77/module-financial` (the revenue-account check; without it the number is stored
unverified). The navigation entries hang under module-debtor's group «Auftragswesen»; without
debtor they wait (ADR-050).

## Enabling it in a project

1. Require the package (`composer require z77/module-article`); `composer install` / `update` adds the navigation entries of
   `data/framework/routing/navigation.d/module-article.json`.
2. Register the namespace in the project's `composer.json` `autoload.psr-4` — this module plus those of its requires (vat, mandator):
   `"Z77\\Module\\Article\\": ["override/z77/module/article/src/"]`
   — an unregistered package is installed but not a module, and `z77-db` does not see its
   migrations ([Switch the modules on](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md#2-switch-the-modules-on)).
3. Apply the migrations (`res/migrations`, namespace `Z77\Module\Article\Migrations`):
   `php vendor/bin/z77-db migrate`. A data step creates the root group «Allgemein» in an empty
   tree and places every existing article there.
4. To add fields to the article: override the empty `Product` / `Variant` under
   `override/z77/module/article/src/Entities/` and migrate the columns in the project.

## Backend screens

Mounted by `z77/module-backend` (`src/Ui/Controllers/Order/ArticleController.php`,
`ProductGroupController.php`):

| Menu | URL | What |
|---|---|---|
| Stammdaten › Auftragswesen › Warengruppen | `/backend/order/product-group/list` | the tree; each group opens as a window (name, parent = move, defaults for new articles) |
| Stammdaten › Auftragswesen › Artikel | `/backend/order/article/list` | the standard list with the product-group filter in the toolbar; each article opens as a window with its price history |

Both screens and both windows carry German help (`res/view/templates/Backend/*/*.help.tpl.php`).

## Not built yet

- **Stock** — the stock journal (plan step P7b): stock levels, reservations, corrections with a
  reason, stocktake. Today `StockLedgers::default()` returns `PendingStockLedger`, which accepts
  every request and books nothing; «führt Bestand» is stored but has no effect yet, and the list's
  «Bestand» column only says ja / nein. The help says «Lager: noch nicht verfügbar — folgt».
- Variants with options (vintage, size), shop categories (an article in several categories),
  per-customer prices, article numbers from a number range, reordering sibling groups.

## Documentation

- Topic: [`docs/topics/article.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/article.md) — model, the stock-seam contract,
  rules, known issues, pending.
- Decisions: ADR-039 (Doctrine driver, extendable entities), ADR-040, ADR-043 (stock movements);
  plan `docs/03-development/p7-order-plan.md`.
- Harness: `tests/module-article.php` (offline sections; section B needs the `z77test` database).

## License

MIT — see [`LICENSE`](LICENSE). Author: Peter Ruepp, [z77.ch](https://z77.ch).
