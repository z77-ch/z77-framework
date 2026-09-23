<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\DunningLevelControllerTrait;

/**
 * Backend mount of the Mahnstufen fragment (plan §6.1, ADR-018 pattern):
 * all logic and templates live in `module-debtor` ({@see DunningLevelControllerTrait});
 * this host only mounts it under the backend route + auth + shell (module
 * default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/dunningLevelControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/dunning-level/list.
 */
class DunningLevelController extends BackendAbstractController
{
    use DunningLevelControllerTrait;
}
