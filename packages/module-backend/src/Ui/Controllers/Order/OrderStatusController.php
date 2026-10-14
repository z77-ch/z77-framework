<?php
namespace Z77\Module\Backend\Ui\Controllers\Order;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Order\Ui\OrderStatusControllerTrait;

/**
 * Backend mount of the order-status fragment (P7 slim step 3, plan §3 / §9.5; ADR-018 pattern):
 * all logic and templates live in `module-order` ({@see OrderStatusControllerTrait}); this host
 * only mounts it under the backend route + auth + shell (module default role: ADMIN). The layout
 * is pinned via `Ui/Config/Order/orderStatusControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-order.
 *
 * URL: /backend/order/order-status/list (Stammdaten › Aufträge › Auftragsstatus).
 */
class OrderStatusController extends BackendAbstractController
{
    use OrderStatusControllerTrait;
}
