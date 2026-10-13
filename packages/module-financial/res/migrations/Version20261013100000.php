<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, fifth migration (owner decision 2026-10-13, review 2026-10-13 §2): the flag
 * «nur durch Module bebuchbar» on every account — `account.module_posting_only`
 * (`Account::$modulePostingOnly`). A MANUAL journal entry (`EntryKind::Manual` — the journal's
 * capture forms, `ManualEntryService`, any script posting `PostingRequest::manual()`) may not
 * write a line onto such an account; a module's GENERATED posting through `LedgerService::post()`
 * passes. It protects a collective account — the receivables 1100 that debtor keeps in step with
 * its open items — from the stray manual entry the review found in the dev data.
 *
 * Expand only (ADR-039 decision 14): one column, added `NOT NULL` without a default — the
 * mapping has none, so `z77-db diff` stays clean (the `Version20261012100000` model) — MariaDB
 * fills the existing rows with 0 (OFF, the entity default).
 *
 * The backfill sets it ON for the account the MANDATOR names as the receivable collective
 * account (`mandator.account_receivable`, module-mandator's key `receivable`) — not for a fixed
 * number. Why (Rule 2): the installation has ONE declaration of which account is its debtor
 * collective account, the mandator record; a chart whose receivables sit on 1105, or a migrated
 * wdv chart, gets THAT account flagged and no guessed 1100. After this migration the flag on the
 * account is the one source the ledger reads; the mandator's key only seeded it (an installation
 * that later moves its receivable account switches the flag on the new account in the chart —
 * the year-close reconciliation warns while it is off). No mandator table, no record or an empty
 * field → nothing is flagged; the KMU chart file ships 1100 flagged for a chart adopted after
 * this migration (`res/charts/kmu.json`, its own collective account by the Sterchi chart).
 *
 * This is a backfill of a NEW column, not a chart seed (financial.md: the chart is never seeded
 * by a migration) — it adds no account and changes nothing else.
 */
final class Version20261013100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: account.module_posting_only — «nur durch Module bebuchbar» (owner 2026-10-13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD module_posting_only TINYINT NOT NULL');

        // module-mandator is a dependency of financial, but its table may be missing in a
        // half-installed database — the backfill is then skipped, never failed.
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['mandator'])) {
            return;
        }
        $columns = array_map(static fn($c) => strtolower($c->getName()), $schemaManager->listTableColumns('mandator'));
        if (!in_array('account_receivable', $columns, true)) {
            return;
        }
        $this->addSql(
            "UPDATE account a JOIN mandator m ON m.account_receivable = a.number SET a.module_posting_only = 1 WHERE a.postable = 1 AND m.account_receivable <> ''"
        );
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP module_posting_only');
    }
}
