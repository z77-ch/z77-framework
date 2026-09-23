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
 * NO `debtorAccounts` any more (owner decision E2, 2026-09-23): the accounts
 * the receivables side posts to — receivable 1100, discount 3800, loss
 * 3805, rounding 3809 (its OWN account, owner 2026-09-22: rounding and
 * discount must stay separable in the reports), dunning fee 6950 (damages
 * for the delay, no VAT, not turnover — owner 2026-09-22) in the KMU chart
 * — live on the MANDATOR record (`z77/module-mandator`, backend
 * `/backend/finance/mandator`), edited there and checked against the chart
 * on save. The reader is unchanged: {@see \Z77\Module\Debtor\Services\DebtorAccounts}
 * stays the ONE access point (Rule 2) and refuses AT THE POINT OF USE, in
 * German, naming the key and the mandator, when a number is missing or the
 * bookkeeping will not take a posting on it — never at boot. A
 * `debtorAccounts` key still present here (a project override copied before
 * the move, BOOT-CONFIG-001) is REFUSED loudly by `DebtorAccounts`, never
 * read as a second source.
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

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
