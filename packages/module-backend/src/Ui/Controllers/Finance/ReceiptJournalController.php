<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Receipt\Ui\ReceiptJournalControllerTrait;

/**
 * Backend mount of the «Belegjournal» (gateway plan E1 / E11 / E12, ADR-018 pattern): all logic and
 * templates live in `module-receipt` ({@see ReceiptJournalControllerTrait}); this host only mounts it
 * under the backend route + auth + shell (module default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/receiptJournalControllerConfig.inc.php`. In the navigation it sits in the area
 * «Finanzen» right after «Journal» (module-receipt's seed, ADR-050).
 *
 * Reachable only in projects that install z77/module-receipt.
 *
 * URL: /backend/finance/receipt-journal/list (edit, split, confirm-delete, template-fill,
 * payment-fill, confirm-post, post).
 */
class ReceiptJournalController extends BackendAbstractController
{
    use ReceiptJournalControllerTrait;
}
