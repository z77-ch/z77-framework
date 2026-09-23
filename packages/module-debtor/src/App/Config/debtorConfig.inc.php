<?php
namespace Z77\Module\Debtor\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Debtor\Accounting\LedgerAccountingGateway;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\InvoiceLine;
use Z77\Module\Debtor\Entities\InvoiceTax;

/**
 * Debtor module (ADR-040, plan §6) — receivables. P3 part 1: the MASTER
 * DATA — the debtor profile per contact, the payment terms, the payment
 * targets and the dunning levels. P3 part 2: `InvoicingService` — invoices
 * and credit notes (`Invoice`, `InvoiceLine`, `InvoiceTax`), the states
 * `invoicing` / `final`, and the accounting port (`accountingGateway`,
 * below). PDF / QR-bill and the document screens are part 3; payments,
 * CAMT and the dunning runs are P4.
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Debtor\Ui\PaymentTermsControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\PaymentTargetControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\DunningLevelControllerTrait},
 * {@see \Z77\Module\Debtor\Ui\DebtorControllerTrait}) mounted by host
 * controllers in module-backend under `/backend/finance/…` next to the tax
 * codes, the chart and the journal (ADR-018 pattern). `defaultGroup` and
 * `groupDefaults` are therefore deliberately absent — `/debtor/…` resolves
 * nothing.
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory):
 * `DebtorProfile`, `Invoice`, `InvoiceLine` and `InvoiceTax` are Doctrine
 * (the migrations live in `res/migrations`);
 * `PaymentTerms`, `PaymentTarget` and `DunningLevel` are file-based
 * installation master data under `data/framework/debtor/`, seeded once on
 * first install from this package's `data/**\/*.default.json` (ADR-024
 * seed-once) and referenced BY CODE (ADR-043 decision 19). The payment
 * targets carry NO seed on purpose: an IBAN cannot be guessed, and a seeded
 * placeholder would end up printed on a QR-bill.
 *
 * `debtorAccounts` (key → account NUMBER, read only through
 * {@see \Z77\Module\Debtor\Services\DebtorAccounts}): where the receivables
 * side posts. The ONE place these accounts are named (Rule 2) — the mirror
 * of financial's `vatAccounts`. Defaults per the KMU chart, verified
 * against `packages/module-financial/res/charts/kmu.json`:
 *
 *   - `receivable` → 1100 «Forderungen aus Lieferungen und Leistungen (Debitoren)»
 *   - `discount`   → 3800 «Erlösminderungen» (Skonto is one)
 *   - `loss`       → 3805 «Verluste aus Forderungen, Veränderung Delkredere»
 *   - `rounding`   → 3809 «Rundungsdifferenzen» — the invoice's 0.05 line.
 *     Its OWN account in group 38 (owner, 2026-09-22): rounding and discount
 *     must stay separable in the reports, so the KMU chart gained the row
 *     rather than the two keys sharing 3800. NOT financial's VAT-return
 *     rounding account, which is its own setting (plan §5.1, §6.1).
 *   - `dunningFee` → 6950 «Finanzertrag» (owner, 2026-09-22): a dunning fee
 *     is DAMAGES FOR THE DELAY, not a service — so it carries no VAT
 *     (plan §6.5) and it is not turnover, which is why it stays out of the
 *     revenue classes 3 the VAT return reads by code.
 *
 * A missing key, or an account the bookkeeping will not take a posting on,
 * is refused AT THE POINT OF USE with a German message naming the key —
 * never at boot (`DebtorAccounts::postableNumber()`).
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match)
 * — it must carry the FULL config, not just the key it changes. Known
 * framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        DebtorProfile::class,
        Invoice::class,
        InvoiceLine::class,
        InvoiceTax::class,
    ],

    // The accounting port (plan §6.6): the class `InvoicingService::finalize()` posts through.
    // `LedgerAccountingGateway` books into module-financial and refuses to run without it;
    // an installation that keeps its books elsewhere names `NullAccountingGateway` here.
    'accountingGateway' => LedgerAccountingGateway::class,

    'debtorAccounts' => [
        'receivable' => '1100',
        'discount'   => '3800',
        'loss'       => '3805',
        'rounding'   => '3809',
        'dunningFee' => '6950',
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
