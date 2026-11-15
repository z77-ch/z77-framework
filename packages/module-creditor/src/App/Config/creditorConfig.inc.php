<?php
namespace Z77\Module\Creditor\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Roles\CreditorContactRole;

/**
 * Creditor module (creditor plan `docs/03-development/creditor-module-bauplan.md`, owner answers
 * §11 of 2026-11-15) — payables. Stage 1a: the CREDITOR — a contact with a creditor profile (UID,
 * several IBANs, the posting defaults), the role «Kreditor» in the contact editor and the master
 * data «Kreditoren». The creditor invoice, the Kreditorenjournal, the upload and the posting
 * («Journal verbuchen …», `postingVariant`) are stages 1b / 1c.
 *
 * This module has NO routes and no view area of its own: its backend screen is a fragment
 * ({@see \Z77\Module\Creditor\Ui\CreditorProfileControllerTrait} «Kreditoren») mounted by a host
 * controller in module-backend under `/backend/finance/creditor-profile` (ADR-018 pattern); its menu
 * entry comes from this package's seed `data/framework/routing/navigation.d/module-creditor.json`
 * (ADR-050).
 *
 * Storage (ADR-039 decision 5 — announced here, nothing scans a directory): `CreditorProfile` and
 * `CreditorIban` are Doctrine (migrations in `res/migrations`). The defaults reference module-
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
