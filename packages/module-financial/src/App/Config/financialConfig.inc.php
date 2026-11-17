<?php
namespace Z77\Module\Financial\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Financial\Close\BankReconciliationCheck;
use Z77\Module\Financial\Close\SubledgerReconciliationCheck;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankAnchor;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankMovementMark;
use Z77\Module\Financial\Entities\BankPattern;
use Z77\Module\Financial\Entities\BankPosting;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Module\Financial\Entities\EntryChange;
use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Entities\JournalLine;
use Z77\Module\Financial\Entities\Period;

/**
 * Financial module (ADR-040, ADR-042, plan §5) — double-entry bookkeeping.
 * P2 part 1: the chart of accounts and the fiscal years with their monthly
 * periods. Part 2: the journal (`JournalEntry` / `JournalLine`), the change
 * log of manual entries (`EntryChange`) and `LedgerService` — the one door
 * every posting source uses. Part 3: the reports (`LedgerReports`, SQL
 * aggregates over `journal_line` — no entity of their own). P5 part 1:
 * closing and reopening a fiscal year (`FiscalYearCloseService`) with its
 * protocol (`FiscalYearCloseLog`); the close check asks the open-work
 * registry under the scope `period-close` — the modules that have open work
 * register there, and since 2026-10-13 financial registers ONE check of its
 * own: `SubledgerReconciliationCheck`, each collective account against the
 * subledger its module reports (`subledgerReconciliations`, a key the MODULES
 * declare in their config — financial names none of them). The BANK ACCOUNT
 * SERVICE (ADR-060, `Z77\Module\Financial\Bank\`, stage B1 of 2026-11-16): the
 * master data «Bankkonten» (`BankAccount`), the camt.053 import
 * (`BankStatementService` → `BankStatement`, `BankMovement`, the archive
 * `data/archive/financial/bank/<YYYY>/`) — an information source for the
 * creditor journal; a statement is only stored when it joins the account's
 * chain seamlessly. BANK-DRIVEN BOOKKEEPING (ADR-061): the link (`BankLink`, C1), and since
 * stage C2 (2026-11-17) the state of a movement — derived from the link, the office's mark
 * (`BankMovementMark`) and the learned patterns (`BankPattern`) — and the direct posting
 * «Geschäftlich buchen …» (`BankPosting`, `Bank\BankPostingService`; its setting under
 * `bankPosting` below). Stage C4 (2026-11-17): the date «Abgleich ab» on the bank account, the
 * anchor (`BankAnchor`), the check (`Bank\BankReconciliation`) and the close blocks
 * (`Close\BankReconciliationCheck` under `openWorkChecks`). Which journal entry belongs to which
 * linked owner is answered by the owning modules under the key `bankEntryOwners`
 * (`Bank\BankEntryOwners` — financial names none of them).
 *
 * This module has NO routes and no view area of its own: its backend screens
 * are fragments ({@see \Z77\Module\Financial\Ui\AccountControllerTrait},
 * {@see \Z77\Module\Financial\Ui\FiscalYearControllerTrait},
 * {@see \Z77\Module\Financial\Ui\JournalControllerTrait},
 * {@see \Z77\Module\Financial\Ui\ChangeLogControllerTrait},
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
 *
 * NO `vatAccounts` any more (owner decision E2, 2026-09-23): the accounts
 * the VAT of a tax-code category is posted to — input tax 1170 / 1171,
 * output VAT 2200 in the KMU chart — live on the MANDATOR record
 * (`z77/module-mandator`, backend `/backend/finance/mandator`), edited there
 * and checked against the chart on save. The reader is unchanged:
 * `LedgerService::vatAccountFor()` maps the category onto the record's
 * `vat-input-material` / `vat-input-other` / `vat-owed` and answers null
 * for a category without a tax line (zero, exempt, reverse-charge) or
 * without a number set; the caller refuses with a message naming the
 * mandator. A `vatAccounts` key that is still present here — a project
 * override copied before the move (BOOT-CONFIG-001) — is REFUSED loudly by
 * `vatAccountFor()`, never read as a second source (Rule 2).
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
        FiscalYearCloseLog::class,
        // The bank account service (ADR-060, stage B1): master data «Bankkonten», statements, movements.
        BankAccount::class,
        BankStatement::class,
        BankMovement::class,
        // Bank-driven bookkeeping (ADR-061, stage C1): what a movement pays, with an amount.
        BankLink::class,
        // Stage C2: what the office decided once (patterns), by hand (marks), and the direct postings.
        BankPattern::class,
        BankMovementMark::class,
        BankPosting::class,
        // Stage C4: the confirmed difference bank ↔ ledger account.
        BankAnchor::class,
    ],

    // «Geschäftlich buchen …» from a bank movement (ADR-061 decision 15) — read in ONE place,
    // `Bank\BankPostingService`. A project records its deviation under `override/` (Rule 2).
    // The ACCOUNTS of «Privat» and «Übertrag eigenes Konto» are NOT here (stage C4, 2026-11-17):
    // like every account of the company they stand on the mandator record (Stammdaten › Firma ›
    // Mandant, Reiter «Konten»: `private`, `bank-transfer`), read by `LedgerService::bankPostingAccount()`.
    'bankPosting' => [
        // From this GROSS amount (base currency) a posting WITH a tax code needs an attached file,
        // else it is not saved; below it the file is voluntary. A practical rule, not law.
        'voucherFromGross' => '100.00',
    ],

    // The year close compares every collective account with the subledger its module reports
    // (owner 2026-10-13) — a warning per difference; the modules declare `subledgerReconciliations`.
    // Bank-driven bookkeeping (ADR-061 decisions 25, 26, stage C4): a switched-on bank account
    // BLOCKS the year close and the VAT return of a period — statement not read to its end,
    // movements «zu prüfen», the check fails, the transfer account not at zero — and the VAT
    // return gets the control line «Vorsteuer ohne Beleg» (a warning).
    'openWorkChecks' => [
        'period-close' => [SubledgerReconciliationCheck::class, BankReconciliationCheck::class],
        'vat-return'   => [BankReconciliationCheck::class],
    ],

    // Nothing here renders a page; the host's cache policy applies to the mount.
    // The backend dashboard (ADR-052): this module's pending work and «+ Neu» buttons —
    // module-backend collects every module's `dashboard` list (`DashboardContributors`).
    'dashboard' => [\Z77\Module\Financial\Dashboard\FinancialDashboard::class],

    'cache' => [
        'enabled' => false,
    ],
];
