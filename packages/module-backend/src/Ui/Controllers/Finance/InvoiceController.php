<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\InvoiceControllerTrait;

/**
 * Backend mount of the document screens (P3 part 3, ADR-018 pattern): all
 * logic and templates live in `module-debtor` ({@see InvoiceControllerTrait});
 * this host only mounts it under the backend route + auth + shell (module
 * default role: ADMIN). The layout is pinned via
 * `Ui/Config/Finance/invoiceControllerConfig.inc.php`. In the navigation it
 * sits in the area «Aufträge» (module-debtor's seed, ADR-050); the URL group
 * stays `finance`, like the other debtor screens.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/invoice/list (detail, pdf, add, edit, credit-note, confirm-finalize, finalize).
 */
class InvoiceController extends BackendAbstractController
{
    use InvoiceControllerTrait;
}
