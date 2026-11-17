<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\BankMovementControllerTrait;

/**
 * Backend mount of «Finanzen › Bankkonto» — the camt.053 upload and the list «Bewegungen»
 * (ADR-060, ADR-018 pattern): all logic and templates live in `module-financial`
 * ({@see BankMovementControllerTrait}); this host only mounts it under the backend route + auth +
 * shell (module default role: ADMIN — BANK-ACL-001). The layout is pinned via
 * `Ui/Config/Finance/bankMovementControllerConfig.inc.php`.
 *
 * URL: /backend/finance/bank-movement/list.
 */
class BankMovementController extends BackendAbstractController
{
    use BankMovementControllerTrait;
}
