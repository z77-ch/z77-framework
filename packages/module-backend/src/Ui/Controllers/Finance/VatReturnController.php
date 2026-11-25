<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Financial\Ui\VatReturnControllerTrait;

/**
 * Backend mount of «Finanzen › MWST-Abrechnungen» — the VAT return (P5 part 2, ADR-018 pattern):
 * all logic and templates live in `module-financial` ({@see VatReturnControllerTrait}); this host
 * only mounts it under the backend route + auth + shell (module default role: ADMIN, like every
 * finance screen; «Aufheben» — `confirmLiftAction` / `liftAction` — pinned to ADMIN in the access
 * config). The layout is pinned via `Ui/Config/Finance/vatReturnControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-financial.
 *
 * URL: /backend/finance/vat-return (list, actions, form, origin, reconciliation, revision, entries,
 * pdf, voucher, confirm-post, post, confirm-lift, lift).
 */
class VatReturnController extends BackendAbstractController
{
    use VatReturnControllerTrait;
}
