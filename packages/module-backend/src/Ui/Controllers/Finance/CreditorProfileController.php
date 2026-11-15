<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Creditor\Ui\CreditorProfileControllerTrait;

/**
 * Backend mount of the creditor master data «Kreditoren» (creditor plan §8, ADR-018 pattern): all
 * logic and templates live in `module-creditor` ({@see CreditorProfileControllerTrait}); this host
 * only mounts it under the backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/creditorProfileControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-creditor.
 *
 * URL: /backend/finance/creditor-profile/list (Stammdaten › Finanzen › Kreditoren).
 */
class CreditorProfileController extends BackendAbstractController
{
    use CreditorProfileControllerTrait;
}
