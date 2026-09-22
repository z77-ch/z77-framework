<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\AccountControllerTrait;

/**
 * Backend mount of the chart-of-accounts fragment (plan §5.1, ADR-018
 * pattern): all logic and templates live in `module-financial`
 * ({@see AccountControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/accountControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/account/list.
 */
class AccountController extends BackendAbstractController
{
    use AccountControllerTrait;
}
