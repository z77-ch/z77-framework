<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\ChangeLogControllerTrait;

/**
 * Backend mount of the journal's change log (Finanzen › Änderungsprotokoll,
 * owner 2026-10-08, ADR-018 pattern): all logic and templates live in
 * `module-financial` ({@see ChangeLogControllerTrait}); this host only mounts
 * it under the backend route + auth + shell (module default role: ADMIN).
 * The layout is pinned via `Ui/Config/Finance/changeLogControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/change-log/list (detail).
 */
class ChangeLogController extends BackendAbstractController
{
    use ChangeLogControllerTrait;
}
