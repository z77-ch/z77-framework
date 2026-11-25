<?php
namespace Z77\Module\Vat\App;

use Z77\Core\Config\AuthRole;

/**
 * VAT module (ADR-041) — tax codes with dated rates as installation master
 * data, a rate lookup by service date and the VatCalculator.
 *
 * This module has NO routes and no view area of its own: its backend screen
 * is a fragment ({@see \Z77\Module\Vat\Ui\TaxCodeControllerTrait}) mounted by
 * a host controller in module-backend under `/backend/finance/tax-code/…`
 * (the dms Drive / member accounts pattern, ADR-018). `defaultGroup` and
 * `groupDefaults` are therefore deliberately absent — `/vat/…` resolves nothing.
 *
 * Entities are file-based (`data/framework/vat/`, seeded once on first
 * install from this package's `data/**\/*.default.json`, ADR-024 seed-once).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    // First-setup steps 40 «MWST-Codes: Konto fehlt im Kontenplan» and 41 «MWST-Codes: Code mit
    // fremder Kategorie» (the latter runs the upgrade of the seed codes, VatSeedUpgrade) — project
    // checks: module-backend's ProjectHealthDashboard shows the next open ones in setup order; slot
    // table: docs/03-development/project-health-bauplan.md.
    'projectChecks' => [\Z77\Module\Vat\ProjectHealth\VatAccountsCheck::class, \Z77\Module\Vat\ProjectHealth\VatSeedCodesCheck::class],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
