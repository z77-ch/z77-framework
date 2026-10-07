<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, seventh migration (plan §6.5, P4 part 3; ADR-039 decisions
 * 12–14): the dunning — `dunning_run` (one run: the notice date, who, when)
 * and `dunning_notice` (one notice: the invoice, the level by code and by
 * number as it was, the open amount then, the fee document issued with it).
 * No new column on `invoice`: a fee is a document of kind `fee` in the
 * existing table (owner 2026-10-06), sharing the invoice number range.
 *
 * Expand only (decision 14): two new tables. `utf8mb4_unicode_ci`
 * (decision 18), `ENGINE = InnoDB` (decision 17), the Doctrine-named
 * foreign keys and implicit indexes kept so `z77-db diff` stays clean;
 * `idx_dunning_notice_invoice` is the module's own name for the reading
 * that matters (the history and the current level per invoice). Written
 * against the mapping; `z77-db diff` after `migrate` reports no change
 * (`tests/module-debtor.php` A11).
 */
final class Version20261007150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: dunning_run and dunning_notice (plan §6.5, P4 part 3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dunning_run (id INT AUTO_INCREMENT NOT NULL, run_date DATE NOT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_dunning_run_date (run_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE dunning_notice (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, level_code VARCHAR(16) NOT NULL, level_number INT NOT NULL, open_amount NUMERIC(15, 2) NOT NULL, run_id INT NOT NULL, invoice_id INT NOT NULL, fee_invoice_id INT DEFAULT NULL, INDEX idx_dunning_notice_invoice (invoice_id), INDEX IDX_6FFF155B84E3FEC4 (run_id), INDEX IDX_6FFF155B27CC7F64 (fee_invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dunning_notice ADD CONSTRAINT FK_6FFF155B84E3FEC4 FOREIGN KEY (run_id) REFERENCES dunning_run (id)');
        $this->addSql('ALTER TABLE dunning_notice ADD CONSTRAINT FK_6FFF155B2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE dunning_notice ADD CONSTRAINT FK_6FFF155B27CC7F64 FOREIGN KEY (fee_invoice_id) REFERENCES invoice (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dunning_notice DROP FOREIGN KEY FK_6FFF155B84E3FEC4');
        $this->addSql('ALTER TABLE dunning_notice DROP FOREIGN KEY FK_6FFF155B2989F1FD');
        $this->addSql('ALTER TABLE dunning_notice DROP FOREIGN KEY FK_6FFF155B27CC7F64');
        $this->addSql('DROP TABLE dunning_notice');
        $this->addSql('DROP TABLE dunning_run');
    }
}
