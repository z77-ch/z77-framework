<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, first migration (plan §5.1, P2 part 1; ADR-039
 * decisions 12–14): the chart of accounts `account` and the fiscal years
 * `fiscal_year` with their monthly periods `fiscal_period`.
 *
 * Generated with `z77-db diff --namespace="Z77\Module\Financial\Migrations"`
 * against an empty database (only the `number_range` package migration
 * applied) and reviewed: `utf8mb4_unicode_ci` on every table (decision 18),
 * `ENGINE = InnoDB` spelled out (decision 17) rather than trusting the
 * server default, foreign keys account → parent account and period →
 * fiscal year. Expand only — three new tables, nothing an older release
 * reads, no DROP of any other table (decision 14, DOCTRINE-MIG-001).
 * `z77-db diff` after `migrate` reports no change (`tests/module-financial.php`).
 *
 * The journal-entry number range of a fiscal year is a ROW in
 * `number_range`, created when the year is opened — not by a migration.
 */
final class Version20260922071232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: account, fiscal_year, fiscal_period (plan §5.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(10) NOT NULL, name VARCHAR(120) NOT NULL, type VARCHAR(12) NOT NULL, postable TINYINT NOT NULL, active TINYINT NOT NULL, parent_id INT DEFAULT NULL, UNIQUE INDEX uniq_account_number (number), INDEX IDX_7D3656A4727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fiscal_period (id INT AUTO_INCREMENT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, state VARCHAR(12) NOT NULL, fiscal_year_id INT NOT NULL, UNIQUE INDEX uniq_fiscal_period_start (fiscal_year_id, start_date), INDEX IDX_2432983963F9139E (fiscal_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fiscal_year (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, UNIQUE INDEX uniq_fiscal_year_code (code), UNIQUE INDEX uniq_fiscal_year_start (start_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE account ADD CONSTRAINT FK_7D3656A4727ACA70 FOREIGN KEY (parent_id) REFERENCES account (id)');
        $this->addSql('ALTER TABLE fiscal_period ADD CONSTRAINT FK_2432983963F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account DROP FOREIGN KEY FK_7D3656A4727ACA70');
        $this->addSql('ALTER TABLE fiscal_period DROP FOREIGN KEY FK_2432983963F9139E');
        $this->addSql('DROP TABLE account');
        $this->addSql('DROP TABLE fiscal_period');
        $this->addSql('DROP TABLE fiscal_year');
    }
}
