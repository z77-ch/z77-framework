<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, third migration (P5 part 1, owner decisions 2026-09-30):
 * the protocol of closing and reopening fiscal years,
 * `fiscal_year_close_log` — which year (id AND code), close or reopen, who,
 * when, the mandatory reason of a reopen, the warnings a close confirmed.
 *
 * Written by hand in the shape `z77-db diff` produces for the mapping of
 * `FiscalYearCloseLog` (the `journal_entry_change` table of the second
 * migration is the model) and proven by `tests/module-financial.php` A10:
 * `diff` after `migrate` reports no change. `utf8mb4_unicode_ci` and
 * `ENGINE = InnoDB` spelled out (ADR-039 decisions 17–18). Deliberately NO
 * foreign key to `fiscal_year`: the protocol of a reopened, still empty
 * year must survive its deletion (the `journal_entry_change` model).
 * Expand only — one new table, nothing an older release reads (decision 14).
 * The period states live in the existing `fiscal_period.state` column; no
 * column changes.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: fiscal_year_close_log (P5 part 1 — closing and reopening fiscal years)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiscal_year_close_log (id INT AUTO_INCREMENT NOT NULL, fiscal_year_id INT NOT NULL, fiscal_year_code VARCHAR(16) NOT NULL, action VARCHAR(8) NOT NULL, actor VARCHAR(80) NOT NULL, acted_at DATETIME NOT NULL, reason VARCHAR(500) DEFAULT NULL, confirmed_warnings LONGTEXT DEFAULT NULL, INDEX idx_fiscal_year_close_log_year (fiscal_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE fiscal_year_close_log');
    }
}
