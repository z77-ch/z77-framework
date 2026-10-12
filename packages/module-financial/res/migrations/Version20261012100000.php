<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, fourth migration (owner decision 2026-10-12): the flag
 * «auf Belegen wählbar» on every account — `account.selectable_on_documents`
 * (`Account::$selectableOnDocuments`). Only revenue accounts WITH the flag
 * are offered for a document line (module-mandator's `LedgerAccountCheck`,
 * module-debtor's invoice editor and `InvoicingService`); the set grows with
 * the chart instead of living in a second list (Rule 2).
 *
 * Expand only (ADR-039 decision 14): one column. It is added `NOT NULL`
 * without a default — the mapping has none, so `z77-db diff` stays clean
 * (the `Version20261006100000` model of module-debtor) — MariaDB fills the
 * existing rows with 0, and the backfill then sets the start values the
 * owner decided:
 *
 *   - ON for every account — the entity's default; a revenue account is
 *     offered on documents unless someone switches it off, and on any other
 *     type the flag is ignored;
 *   - OFF for the revenue accounts under the group numbered `38`
 *     «Erlösminderungen» — in the KMU chart 3800 Erlösminderungen, 3805
 *     Verluste aus Forderungen, 3809 Rundungsdifferenzen: they reduce
 *     revenue through the debtor's settings (discount, loss, rounding),
 *     they are never the account a document line earns on. The chart file
 *     ships the same three switched off (`res/charts/kmu.json`), so a chart
 *     adopted before this column and one adopted after it end up alike.
 *
 * This is a backfill of a NEW column, not a chart seed (financial.md: the
 * chart is never seeded by a migration) — it adds no account and changes
 * nothing else; a chart without a group `38` keeps every account ON. Proven
 * by `tests/module-financial.php` A10 (diff after migrate reports no change).
 */
final class Version20261012100000 extends AbstractMigration
{
    /** The Sterchi group of the revenue deductions — its revenue accounts start switched off. */
    public const REVENUE_DEDUCTIONS_GROUP = '38';

    public function getDescription(): string
    {
        return 'module-financial: account.selectable_on_documents — «auf Belegen wählbar» (owner 2026-10-12)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account ADD selectable_on_documents TINYINT NOT NULL');
        $this->addSql('UPDATE account SET selectable_on_documents = 1');
        $this->addSql(
            "UPDATE account a JOIN account g ON g.id = a.parent_id SET a.selectable_on_documents = 0 WHERE a.type = 'revenue' AND g.number = ?",
            [self::REVENUE_DEDUCTIONS_GROUP]
        );
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP selectable_on_documents');
    }
}
