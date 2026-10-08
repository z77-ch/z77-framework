<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\DebtorProfileControllerTrait;

/**
 * Backend mount of the debtor master-data fragment (plan §6.1, ADR-018 pattern):
 * all logic and templates live in `module-debtor` ({@see DebtorProfileControllerTrait});
 * this host only mounts it under the backend route + auth + shell (module
 * default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/debtorProfileControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/debtor-profile/list (Stammdaten › Aufträge › Debitoren).
 */
class DebtorProfileController extends BackendAbstractController
{
    use DebtorProfileControllerTrait;
}
