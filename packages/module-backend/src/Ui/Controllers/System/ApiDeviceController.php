<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Module\Api\Ui\ApiDeviceControllerTrait,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController;

/**
 * Backend mount of the device screen (Stammdaten › System › Geräte, ADR-057, board «Geräte»
 * 2026-11-12; ADR-018 pattern): all logic and templates live in `module-api`
 * ({@see ApiDeviceControllerTrait}); this host only mounts it under the backend route + auth +
 * shell. Role EDITOR (backendConfig): every backend user pairs and blocks his own devices, the
 * trait lets the user governor (ADMIN and up) see and act for others (ApiDeviceAccess). The
 * layout is pinned via `Ui/Config/System/apiDeviceControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-api.
 *
 * URL: /backend/system/api-device/list (actions, pair, pair-status, edit, confirm-revoke, revoke).
 */
class ApiDeviceController extends BackendAbstractController
{
    use ApiDeviceControllerTrait;
}
