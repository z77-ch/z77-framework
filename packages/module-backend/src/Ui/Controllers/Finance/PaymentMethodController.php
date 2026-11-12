<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Receipt\Ui\PaymentMethodControllerTrait;

/**
 * Backend mount of the «Zahlungsarten» — «Bezahlt mit» of a receipt (gateway plan E10, ADR-018
 * pattern): all logic and templates live in `module-receipt` ({@see PaymentMethodControllerTrait});
 * this host only mounts it under the backend route + auth + shell (module default role: ADMIN). The
 * layout is pinned via `Ui/Config/Finance/paymentMethodControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-receipt.
 *
 * URL: /backend/finance/payment-method/list (Stammdaten › Finanzen › Zahlungsarten).
 */
class PaymentMethodController extends BackendAbstractController
{
    use PaymentMethodControllerTrait;
}
