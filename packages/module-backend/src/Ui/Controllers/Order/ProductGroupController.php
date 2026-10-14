<?php
namespace Z77\Module\Backend\Ui\Controllers\Order;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Article\Ui\ProductGroupControllerTrait;

/**
 * Backend mount of the product-group fragment (plan §4b A1, owner 2026-10-14; ADR-018 pattern):
 * all logic and templates live in `module-article` ({@see ProductGroupControllerTrait}); this
 * host only mounts it under the backend route + auth + shell (module default role: ADMIN). The
 * layout is pinned via `Ui/Config/Order/productGroupControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-article.
 *
 * URL: /backend/order/product-group/list (Stammdaten › Aufträge › Warengruppen).
 */
class ProductGroupController extends BackendAbstractController
{
    use ProductGroupControllerTrait;
}
