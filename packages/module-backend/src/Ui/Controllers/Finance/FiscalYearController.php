<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\FiscalYearControllerTrait;

/**
 * Backend mount of the fiscal-year fragment (plan §5.1, ADR-018 pattern):
 * all logic and templates live in `module-financial`
 * ({@see FiscalYearControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/fiscalYearControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/fiscal-year/list.
 */
class FiscalYearController extends BackendAbstractController
{
    use FiscalYearControllerTrait;
}
