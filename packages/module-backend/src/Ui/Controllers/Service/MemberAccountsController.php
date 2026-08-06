<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Member\Ui\AccountsControllerTrait;

/**
 * Backend mount of the member accounts fragment (B7 — dms Drive pattern,
 * ADR-018): all logic and templates live in `module-member`
 * ({@see AccountsControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned to `module-member` via
 * `Ui/Config/Service/memberAccountsControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-member (like the Drive
 * without module-dms — the route then has no classes to load).
 *
 * URL: /backend/service/member-accounts/list.
 */
class MemberAccountsController extends BackendAbstractController
{
    use AccountsControllerTrait;
}
