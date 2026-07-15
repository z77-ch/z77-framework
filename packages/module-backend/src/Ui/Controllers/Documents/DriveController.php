<?php
namespace Z77\Module\Backend\Ui\Controllers\Documents;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Dms\Ui\DriveControllerTrait;

/**
 * Backend mount of the DMS Drive fragment (ADR-018 / ADR-019 / ADR-020). All logic,
 * templates and JS live in `module-dms` ({@see DriveControllerTrait}); this host
 * controller only mounts it under the backend route + auth + shell. Scope is the tree +
 * ACL (ADR-020 decision (b)): the Drive shows every partition root the session principal
 * may read — there is no host-fixed area. Registered in `backendConfig` (group
 * `documents`, ADMIN); the layout is pinned to `module-dms` via
 * `Ui/Config/Documents/driveControllerConfig.inc.php`.
 *
 * URL: /backend/documents/drive/list.
 */
class DriveController extends BackendAbstractController
{
    use DriveControllerTrait;
}
