<?php
namespace Z77\Module\Order\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Order\Close\InvoiceableOrdersCheck;
use Z77\Module\Order\Entities\SalesOrder;
use Z77\Module\Order\Entities\SalesOrderLine;
use Z77\Module\Order\Entities\SalesOrderStatusChange;

/**
 * Order module (ADR-040, ADR-043, P7 slim — `p7-order-plan.md`): the sales order (quote and order
 * as ONE record, D1) with its lines and its status log, the order status as file-based master
 * data with behaviour flags (`Entities\OrderStatus`, seeded once from
 * `data/framework/order/order_statuses.default.json`), the one write path `Services\OrderService`
 * (create / update / status change / delivery-note status / the booking print),
 * the stock call per write through module-article's `StockLedger`, and the mapping of an order to
 * debtor's `InvoiceDraft` (`Invoicing\OrderInvoiceDraft`).
 *
 * This module has NO routes and no view area of its own: its backend screens are fragments
 * (`Ui\OrderStatusControllerTrait`, `Ui\OrderControllerTrait`) mounted by host
 * controllers in module-backend under `/backend/order/…` (ADR-018 pattern).
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): `SalesOrder`,
 * `SalesOrderLine` and `SalesOrderStatusChange` are Doctrine; the migrations live in
 * `res/migrations` (schema and the `order` range in SEPARATE files). `OrderStatus` is file-based
 * and needs no announcement.
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        SalesOrder::class,
        SalesOrderLine::class,
        SalesOrderStatusChange::class,
    ],

    // financial's year close asks every module «anything open?» (open-work registry of
    // persistence-doctrine, scope `period-close`): an order dated in the year that sits in a
    // status flagged `invoiceable` is a WARNING (plan §13 step 10) — not invoiced, not booked.
    'openWorkChecks' => [
        'period-close' => [InvoiceableOrdersCheck::class],
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
