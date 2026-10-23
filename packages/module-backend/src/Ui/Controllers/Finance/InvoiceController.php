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
 * sits in the area «Auftragswesen» (module-debtor's seed, ADR-050); the URL group
 * stays `finance`, like the other debtor screens.
 *
 * Reachable only in projects that install z77/module-debtor.
 *
 * URL: /backend/finance/invoice/list (detail, pdf — the preview of a draft, the reprint of a booked document —,
 * add, edit, credit-note, new-contact — «+ Neuer Kontakt» of the customer picker, customer-pick / customer-debtor / customer-terms (CustomerPickerTrait), confirm-finalize, finalize — the booking print, owner 2026-10-10; booking-result, result-actions (the row ⋮, 2026-10-23), pdf-all, zip, confirm-mail, mail — the booking print's result list, step 3 of the mail plan, 2026-10-22).
 */
class InvoiceController extends BackendAbstractController
{
    use InvoiceControllerTrait;
}
