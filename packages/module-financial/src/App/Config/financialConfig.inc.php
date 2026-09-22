<?php
namespace Z77\Module\Financial\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\Period;

/**
 * Financial module (ADR-040, ADR-042, plan §5) — double-entry bookkeeping.
 * P2 part 1: the chart of accounts and the fiscal years with their monthly
 * periods; journal, `LedgerService` and reports follow.
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Financial\Ui\AccountControllerTrait},
 * {@see \Z77\Module\Financial\Ui\FiscalYearControllerTrait}) mounted by host
 * controllers in module-backend under `/backend/finance/…` next to the tax
 * codes (ADR-018 pattern). `defaultGroup` and `groupDefaults` are therefore
 * deliberately absent — `/financial/…` resolves nothing.
 *
 * Storage: every entity is Doctrine (ADR-039 decision 5 — announced here,
 * nothing scans a directory; the migrations live in `res/migrations`). The
 * KMU chart of accounts ships as `res/charts/kmu.json` and is adopted by a
 * button on an EMPTY chart, never seeded (owner, 2026-09-22).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Account::class,
        FiscalYear::class,
        Period::class,
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
