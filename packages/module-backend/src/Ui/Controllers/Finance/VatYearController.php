<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\VatYearControllerTrait;

/**
 * Backend mount of «Finanzen › MWST-Abrechnungen › Jahresübersicht» — the yearly overview of the VAT
 * return (stage S7, ADR-018 pattern): all logic and templates live in `module-financial`
 * ({@see VatYearControllerTrait}); this host only mounts it under the backend route + auth + shell
 * (module default role: ADMIN, like every finance screen). The layout is pinned via
 * `Ui/Config/Finance/vatYearControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/vat-year (list, corrections, turnover, input, pdf, voucher, confirm-store,
 * store).
 */
class VatYearController extends BackendAbstractController
{
    use VatYearControllerTrait;
}
