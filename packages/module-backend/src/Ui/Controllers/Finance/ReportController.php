<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\ReportControllerTrait;

/**
 * Backend mount of the ledger reports (plan §5.5, P2 part 3, ADR-018
 * pattern): all logic and templates live in `module-financial`
 * ({@see ReportControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/reportControllerConfig.inc.php`; the default
 * action is `trial-balance` (`backendConfig` → controllers → finance).
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/report (trial-balance, balance-sheet,
 * income-statement, account-statement, journal).
 */
class ReportController extends BackendAbstractController
{
    use ReportControllerTrait;
}
