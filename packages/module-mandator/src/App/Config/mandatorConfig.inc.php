<?php
namespace Z77\Module\Mandator\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Mandator\Entities\Mandator;

/**
 * Mandator module — the installation's OWN company («Mandant», owner
 * decisions E1 / E2 of 2026-09-23). ONE record: the letterhead identity
 * (name, address, contact data, logo), the UID and the VAT liability, and
 * the ledger accounts the business modules post with — the settings that
 * plan §5.1 and §6.1 listed as «file config» and that lived in
 * `financialConfig → vatAccounts` and `debtorConfig → debtorAccounts` until
 * this module existed. They are edited in the backend now and read through
 * the two access points that already existed (`LedgerService::vatAccountFor()`,
 * debtor's `DebtorAccounts`), never from the record directly.
 *
 * This module has NO routes and no view area of its own: its one backend
 * screen is a fragment ({@see \Z77\Module\Mandator\Ui\MandatorControllerTrait})
 * mounted by a host controller in module-backend under
 * `/backend/finance/mandator` next to the chart and the journal (ADR-018
 * pattern). `defaultGroup` and `groupDefaults` are therefore deliberately
 * absent — `/mandator/…` resolves nothing.
 *
 * Storage: `Mandator` is Doctrine (ADR-039 decision 5 — announced here,
 * nothing scans a directory; the migration lives in `res/migrations`). The
 * row's id is FIXED (`Mandator::ID`) — the primary key is the «one mandator»
 * guard; nothing in the framework filters by mandator and no other table
 * prepares a column for it: several mandators are a build of their own
 * (`docs/topics/mandator.md`).
 *
 * Registering the module on an EXISTING installation is the project's step
 * (the installer reads the modules from the PROJECT composer.json's
 * autoload.psr-4, not from the package): add
 * `"Z77\\Module\\Mandator\\": ["override/z77/module/mandator/src/"]` there,
 * `composer update`, `php vendor/bin/z77-db migrate`, then save the
 * mandator in the backend. Until then the readers refuse with a German
 * sentence naming exactly these steps (`CurrentMandator`).
 *
 * No account defaults here on purpose: the KMU start values a NEW record is
 * pre-filled with are constants in `Services\MandatorAccounts::DEFAULTS`,
 * verified against `module-financial/res/charts/kmu.json` by the harness.
 * The numbers themselves live in the record — the ONE place (Rule 2).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Mandator::class,
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
