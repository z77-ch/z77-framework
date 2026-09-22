<?php
namespace Z77\Module\Financial\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\Period;

/**
 * Financial module (ADR-040, ADR-042, plan §5) — double-entry bookkeeping.
 * P2 part 1: the chart of accounts and the fiscal years with their monthly
 * periods. Part 2: the journal (`JournalEntry` / `JournalLine`), the change
 * log of manual entries (`EntryChange`) and `LedgerService` — the one door
 * every posting source uses. Part 3: the reports (`LedgerReports`, SQL
 * aggregates over `journal_line` — no entity of their own).
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Financial\Ui\AccountControllerTrait},
 * {@see \Z77\Module\Financial\Ui\FiscalYearControllerTrait},
 * {@see \Z77\Module\Financial\Ui\JournalControllerTrait},
 * {@see \Z77\Module\Financial\Ui\ReportControllerTrait}) mounted by host
 * controllers in module-backend under `/backend/finance/…` next to the tax
 * codes (ADR-018 pattern). `defaultGroup` and `groupDefaults` are therefore
 * deliberately absent — `/financial/…` resolves nothing.
 *
 * Storage: every entity is Doctrine (ADR-039 decision 5 — announced here,
 * nothing scans a directory; the migrations live in `res/migrations`). The
 * KMU chart of accounts ships as `res/charts/kmu.json` and is adopted by a
 * button on an EMPTY chart, never seeded (owner, 2026-09-22).
 *
 * Optional key `journalListLimit` (positive int, default 200 — see
 * `LedgerService::listLimit()`): how many entries the journal screen shows
 * per fiscal year. Not set here on purpose: a project records only its
 * deviation (Rule 2).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        Account::class,
        FiscalYear::class,
        Period::class,
        JournalEntry::class,
        JournalLine::class,
        EntryChange::class,
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    'cache' => [
        'enabled' => false,
    ],
];
