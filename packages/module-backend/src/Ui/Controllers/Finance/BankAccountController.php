<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\BankAccountControllerTrait;

/**
 * Backend mount of the «Bankkonten» (ADR-060, ADR-018 pattern): all logic and templates live in
 * `module-financial` ({@see BankAccountControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN — BANK-ACL-001). The layout is pinned
 * via `Ui/Config/Finance/bankAccountControllerConfig.inc.php`.
 *
 * URL: /backend/finance/bank-account/list (Stammdaten › Finanzen › Bankkonten).
 */
class BankAccountController extends BackendAbstractController
{
    use BankAccountControllerTrait;
}
