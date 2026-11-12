<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Module\Api\Ui\ApiAppUserControllerTrait,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController;

/**
 * Backend mount of the screen «App-Benutzer» (Stammdaten › System › App-Benutzer, ADR-058 A9,
 * board «App-Benutzer» approved 2026-11-12; ADR-018 pattern): all logic and templates live in
 * `module-api` ({@see ApiAppUserControllerTrait}); this host only mounts it under the backend route
 * + auth + shell and hands in the German role labels (the user list's one source). No own role in
 * `backendConfig`: the module role ADMIN is the rule (only an admin manages app users). The layout
 * is pinned via `Ui/Config/System/apiAppUserControllerConfig.inc.php`. Replaces «Geräte».
 *
 * Reachable only in projects that install z77/module-api.
 *
 * URL: /backend/system/api-app-user/list (actions, add, edit, confirm-deactivate, deactivate,
 * confirm-activate, activate, confirm-revoke, revoke).
 */
class ApiAppUserController extends BackendAbstractController
{
    use ApiAppUserControllerTrait;

    /** @return array<string, string> role => German label */
    protected function appUserRoleLabels(): array
    {
        return BackendUserController::roleLabels();
    }
}
