<?php
namespace Z77\Module\Backend\Ui\Controllers\Order;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Order\Ui\OrderControllerTrait;

/**
 * Backend mount of the order fragment (P7 slim steps 7–9, plan §3 / §9.3 / §9.4; ADR-018
 * pattern): all logic and templates live in `module-order` ({@see OrderControllerTrait}); this
 * host only mounts it under the backend route + auth + shell (module default role: ADMIN). The
 * layout is pinned via `Ui/Config/Order/orderControllerConfig.inc.php`. The default controller
 * of the group `order` (`backendConfig` → `groupDefaults`).
 *
 * Reachable only in projects that install z77/module-order.
 *
 * URL: /backend/order/order/list (Aufträge › Aufträge) — add, edit (the order window), new-contact,
 * status, pdf, invoice-preview, confirm-delivery-note, delivery-note, confirm-book, book, confirm-reinvoice,
 * reinvoice («Neu fakturieren», ADR-054), confirm-delete («Auftrag löschen», ADR-054).
 */
class OrderController extends BackendAbstractController
{
    use OrderControllerTrait;
}
