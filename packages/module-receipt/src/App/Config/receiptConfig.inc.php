<?php
namespace Z77\Module\Receipt\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Receipt\Close\UnpostedReceiptsCheck;
use Z77\Module\Receipt\Entities\Receipt;
use Z77\Module\Receipt\Entities\ReceiptJournalPosting;

/**
 * Receipt module (gateway plan `gateway-app-kanal-bauplan.md` T4, owner decisions E1–E12 of
 * 2026-11-12): receipt templates and payment methods, the receipt journal a phone captures into,
 * the owner's correction and split, «Journal verbuchen» up to a cut-off into module-financial.
 *
 * This module has NO routes and no view area of its own. Its backend screens (Stammdaten
 * «Belegvorlagen» and «Bezahlt mit», the mask «Belegjournal») come after the owner-approved board
 * (Rule 8); its API targets (`receipt-templates`, `receipts`) are registered with module-api in
 * wave 2.
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): `Receipt` and
 * `ReceiptJournalPosting` are Doctrine (migrations in `res/migrations`); `ReceiptTemplate` and
 * `PaymentMethod` are file-based installation master data (`data/framework/receipt/
 * receipt_templates.json`, `payment_methods.json`, not seeded) and need no announcement. The photos live in the DMS partition «Belege» (module key
 * `receipt`, remappable by `dmsConfig['moduleFolders']`).
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Receipt::class,
        ReceiptJournalPosting::class,
    ],

    // financial asks every module «anything open?» (open-work registry of persistence-doctrine): a
    // receipt still in the journal blocks the year close and the VAT return of its date, and the
    // reports of its range warn about it.
    'openWorkChecks' => [
        'period-close'  => [UnpostedReceiptsCheck::class],
        'ledger-report' => [UnpostedReceiptsCheck::class],
        'vat-return'    => [UnpostedReceiptsCheck::class],
    ],

    // Nothing here renders a page.
    'cache' => [
        'enabled' => false,
    ],
];
