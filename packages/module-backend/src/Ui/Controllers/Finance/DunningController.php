<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\DunningControllerTrait;

/**
 * Backend mount of the dunning screen — «Mahnungen» (P4 part 3, ADR-018
 * pattern): all logic and templates live in `module-debtor`
 * ({@see DunningControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout
 * is pinned via `Ui/Config/Finance/dunningControllerConfig.inc.php`. In
 * the navigation it sits in the area «Aufträge» (module-debtor's seed,
 * ADR-050); the URL group stays `finance`.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/dunning/list (run, notice-pdf).
 */
class DunningController extends BackendAbstractController
{
    use DunningControllerTrait;
}
