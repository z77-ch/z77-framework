<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Mailing\Ui\OutboxControllerTrait;

/**
 * Backend mount of the mail outbox (Service › Postausgang, owner 2026-10-22,
 * ADR-018 pattern): all logic and templates live in `module-mailing`
 * ({@see OutboxControllerTrait}); this host only mounts it under the backend
 * route + auth + shell (module default role: ADMIN, no deviation).
 * The layout is pinned via `Ui/Config/Service/outboxControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-mailing.
 *
 * URL: /backend/service/outbox/list (actions, detail, retry, cancel, start).
 */
class OutboxController extends BackendAbstractController
{
    use OutboxControllerTrait;
}
