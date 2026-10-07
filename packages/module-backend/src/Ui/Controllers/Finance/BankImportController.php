<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\BankImportControllerTrait;

/**
 * Backend mount of the CAMT.054 import — «Zahlungseingänge» (P4 part 2,
 * ADR-018 pattern): all logic and templates live in `module-debtor`
 * ({@see BankImportControllerTrait}); this host only mounts it under the
 * backend route + auth + shell (module default role: ADMIN). The layout
 * is pinned via `Ui/Config/Finance/bankImportControllerConfig.inc.php`. In
 * the navigation it sits in the area «Aufträge» (module-debtor's seed,
 * ADR-050); the URL group stays `finance`, like the other debtor screens.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/bank-import/list (upload, detail, book, assign, ignore).
 */
class BankImportController extends BackendAbstractController
{
    use BankImportControllerTrait;
}
