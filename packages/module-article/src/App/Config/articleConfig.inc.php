<?php
namespace Z77\Module\Article\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Article\Entities\Price;
use Z77\Module\Article\Entities\Product;
use Z77\Module\Article\Entities\Variant;

/**
 * Article module (ADR-040, plan §4b, P7 slim — `p7-order-plan.md` §4.1 / §8.1): the article
 * master an order line resolves to, and the stock SEAM (`Stock\StockLedger`) every order write
 * reaches stock through. P6 SLIM: product + hidden default variant (A3 in the schema, flat in the
 * UI), the price with «gültig ab» history (A4), the article number on the variant (A2), tax code
 * and revenue account on the article (owner 2026-10-14), the flag «führt Bestand». No product-group
 * tree, no variant options, no images, no stock journal (P7b).
 *
 * This module has NO routes and no view area of its own: its backend screen is a fragment
 * ({@see \Z77\Module\Article\Ui\ArticleControllerTrait}) mounted by a host controller in
 * module-backend under `/backend/order/article/…` (ADR-018 pattern).
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): `Product`, `Variant`
 * and `Price` are Doctrine; the migrations live in `res/migrations`. `Product` and `Variant` are
 * EXTENDABLE master data (ADR-039 addendum 2026-10-08 (b)): a project overrides only the empty
 * class under `override/z77/module/article/src/Entities/` and migrates its own columns in
 * `override/z77/project/res/migrations/` — the FQCN stays the one announced here.
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Product::class,
        Variant::class,
        Price::class,
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
