<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Vat\Ui\TaxCodeControllerTrait;

/**
 * Backend mount of the tax-code fragment (ADR-041 — dms Drive / member
 * accounts pattern, ADR-018): all logic and templates live in `module-vat`
 * ({@see TaxCodeControllerTrait}); this host only mounts it under the backend
 * route + auth + shell (module default role: ADMIN). The layout is pinned to
 * `module-vat` via `Ui/Config/Finance/taxCodeControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-vat (like the Drive
 * without module-dms — the route then has no classes to load).
 *
 * URL: /backend/finance/tax-code/list.
 */
class TaxCodeController extends BackendAbstractController
{
    use TaxCodeControllerTrait;
}
