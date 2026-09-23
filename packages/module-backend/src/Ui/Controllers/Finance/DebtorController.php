<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\DebtorControllerTrait;

/**
 * Backend mount of the Debitoren fragment (plan §6.1, ADR-018 pattern):
 * all logic and templates live in `module-debtor` ({@see DebtorControllerTrait});
 * this host only mounts it under the backend route + auth + shell (module
 * default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/debtorControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/debtor/list.
 */
class DebtorController extends BackendAbstractController
{
    use DebtorControllerTrait;
}
