<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\JournalControllerTrait;

/**
 * Backend mount of the journal fragment (plan §5.2–§5.3, ADR-018 pattern):
 * all logic and templates live in `module-financial`
 * ({@see JournalControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/journalControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/journal/list (detail, add, edit, confirm-delete, delete).
 */
class JournalController extends BackendAbstractController
{
    use JournalControllerTrait;
}
