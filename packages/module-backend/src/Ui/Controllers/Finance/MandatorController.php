<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Mandator\Ui\MandatorControllerTrait;

/**
 * Backend mount of the Mandant fragment (owner decisions E1 / E2, ADR-018
 * pattern): all logic and templates live in `module-mandator`
 * ({@see MandatorControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/mandatorControllerConfig.inc.php`; the
 * controller's `defaultAction` is `edit` (`backendConfig` — one record, no
 * list).
 *
 * Reachable only in projects that install z77/module-mandator.
 *
 * URL: /backend/finance/mandator/edit.
 */
class MandatorController extends BackendAbstractController
{
    use MandatorControllerTrait;
}
