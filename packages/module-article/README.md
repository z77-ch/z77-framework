# z77/module-article

The article master for z77 business modules (ADR-040, plan §4b) and the ONE stock write path
(ADR-043). Depends on `z77/kernel`, `z77/persistence-doctrine` (ADR-039), `z77/module-vat` and
`z77/module-mandator` (the soft revenue-account check), and only *suggests*
`z77/module-financial`. Developed in the [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework)
monorepo (`packages/module-article`); not yet a split target or on Packagist — projects consume it
through a `path` repository until it is.

State: **P6 slim with P7 slim (2026-10-14)** — `docs/03-development/p7-order-plan.md` steps 1 and 2.

- `Product` / `Variant` (Doctrine, extendable: `AbstractProduct` / `AbstractVariant` carry every
  field, the entities are empty — ADR-039 addendum 2026-10-08 (b)) — a product with a hidden
  DEFAULT variant; the article number (unique, typed) and the unit sit on the variant, name, tax
  code (by code), revenue account (by number) and «führt Bestand» on the product.
- `Price` — the price history per variant («gültig ab», net / gross); one field in the mask.
- `ProductGroup` — the product-group tree (plan §4b A1, 2026-10-14): one tree of free depth, exactly
  one group per product (mandatory, any node), optional default tax code / revenue account for NEW
  articles; `Services\ProductGroupService` is its write path, `Services\ProductGroupTree` the read model.
- `Services\ArticleService` — the one write path (create / update with version / setActive /
  delete of an unreferenced article).
- `Stock\StockLedger` — the stock seam every order write calls with the COMPLETE target state of
  its document; `PendingStockLedger` (books nothing) until P7b adds the movement journal.
- Backend: Stammdaten › Aufträge › Warengruppen (`/backend/order/product-group/list`) and › Artikel
  (`/backend/order/article/list`, filtered by group from the rail), mounted by module-backend's
  `Order/ProductGroupController` and `Order/ArticleController`.

Topic doc: [`docs/topics/article.md`](../../docs/topics/article.md).
