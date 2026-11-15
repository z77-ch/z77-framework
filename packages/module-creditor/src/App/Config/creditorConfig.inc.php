<?php
namespace Z77\Module\Creditor\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorIntake;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorJournalPosting;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Roles\CreditorContactRole;

/**
 * Creditor module (creditor plan `docs/03-development/creditor-module-bauplan.md`, owner answers
 * §11 of 2026-11-15) — payables. Stage 1a: the CREDITOR — a contact with a creditor profile (UID,
 * several IBANs, the posting defaults), the role «Kreditor» in the contact editor and the master
 * data «Kreditoren». Stage 1b: the CREDITOR INVOICE (lines, intake, the protocol table), the PDF
 * upload and the «Kreditorenjournal». The posting («Journal verbuchen …», `postingVariant`) is
 * stage 1c.
 *
 * This module has NO routes and no view area of its own: its backend screens are fragments
 * ({@see \Z77\Module\Creditor\Ui\CreditorProfileControllerTrait} «Kreditoren»,
 * {@see \Z77\Module\Creditor\Ui\CreditorJournalControllerTrait} «Kreditorenjournal») mounted by host
 * controllers in module-backend under `/backend/finance/creditor-profile` and
 * `/backend/finance/creditor-journal` (ADR-018 pattern); the menu entries come from this package's
 * seed `data/framework/routing/navigation.d/module-creditor.json` (ADR-050).
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): every entity below is
 * Doctrine (migrations in `res/migrations`). The PDFs lie in the DMS. The defaults reference module-
 * receipt's payment methods, the chart (module-financial) and the tax codes (module-vat) BY CODE.
 *
 * ⚠️ Today a project override of this file REPLACES it (first source match) — it must carry the
 * FULL config. Known framework-wide gap, BOOT-CONFIG-001 (`docs/topics/bootstrap.md`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        CreditorProfile::class,
        CreditorIban::class,
        CreditorIntake::class,
        CreditorInvoice::class,
        CreditorInvoiceLine::class,
        CreditorJournalPosting::class,
    ],

    // The intake of supplier PDFs (creditor plan §3): the largest PDF one file may be. Read in ONE
    // place, `CreditorIntakeService::maxBytes()`; the upload's client gate shows the same limit.
    // The folder of the folder job (stage 2) will live here too.
    'intake' => [
        'maxBytes' => 20 * 1024 * 1024,
    ],

    // The role «Kreditor» in the contact editor (creditor plan §2.2, the debtor model of
    // 2026-10-12): module-contact's registry `contactRoles` collects it — contact knows no module,
    // this one contributes. On = an empty creditor profile; off = delete it (with its IBANs).
    'contactRoles' => [
        'creditor' => CreditorContactRole::class,
    ],

    // Nothing here renders a page.
    'cache' => [
        'enabled' => false,
    ],
];
