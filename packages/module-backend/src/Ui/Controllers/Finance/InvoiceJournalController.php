<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Debtor\Ui\InvoiceJournalControllerTrait;

/**
 * Backend mount of the Faktura-Journal (ADR-054, step 3 of the build plan, ADR-018 pattern): all
 * logic and templates live in `module-debtor` ({@see InvoiceJournalControllerTrait}); this host
 * only mounts it under the backend route + auth + shell (module default role: ADMIN). The layout
 * is pinned via `Ui/Config/Finance/invoiceJournalControllerConfig.inc.php`. In the navigation it
 * sits in the area «Auftragswesen» right after «Rechnungen» (module-debtor's seed, ADR-050); the
 * URL group stays `finance`.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/invoice-journal/list (pdf, confirm-post, post).
 */
class InvoiceJournalController extends BackendAbstractController
{
    use InvoiceJournalControllerTrait;
}
