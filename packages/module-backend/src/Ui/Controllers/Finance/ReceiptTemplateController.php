<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Receipt\Ui\ReceiptTemplateControllerTrait;

/**
 * Backend mount of the «Belegvorlagen» (gateway plan E2 / E3 / E10, ADR-018 pattern): all logic and
 * templates live in `module-receipt` ({@see ReceiptTemplateControllerTrait}); this host only mounts
 * it under the backend route + auth + shell (module default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/receiptTemplateControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-receipt.
 *
 * URL: /backend/finance/receipt-template/list (Stammdaten › Finanzen › Belegvorlagen).
 */
class ReceiptTemplateController extends BackendAbstractController
{
    use ReceiptTemplateControllerTrait;
}
