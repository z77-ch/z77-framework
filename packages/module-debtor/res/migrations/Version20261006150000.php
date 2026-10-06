<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, fifth migration (plan §6.3, P4 part 1; ADR-039 decisions
 * 12–14): the PAYMENTS — `payment` (one settlement event: the money that
 * arrived on a payment target, or a write-off) and `payment_allocation`
 * (what it clears on which final invoice: payment / discount / loss, with
 * the journal reference of its posting).
 *
 * Expand only (decision 14): two new tables, nothing an older release
 * reads. `utf8mb4_unicode_ci` on both (decision 18), `ENGINE = InnoDB`
 * spelled out (decision 17), the Doctrine-named foreign keys and the
 * implicit index of `payment_id` kept so `z77-db diff` stays clean;
 * `idx_payment_allocation_invoice` is the module's own name for the
 * reading that matters (Σ allocations per invoice, the open amount). No
 * number range: a payment has no document number — it is identified by
 * its id, and the CAMT import of part 2 by the transaction's identifiers
 * in `source_ref`. `amount` is the `MoneyType` column (NUMERIC 15,2, base
 * currency). Written against the mapping; `z77-db diff` after `migrate`
 * reports no change (`tests/module-debtor.php` A11).
 */
final class Version20261006150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: payment and payment_allocation (plan §6.3, P4 part 1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, payment_date DATE NOT NULL, amount NUMERIC(15, 2) NOT NULL, payment_target_code VARCHAR(16) NOT NULL, account_number VARCHAR(10) NOT NULL, note VARCHAR(140) DEFAULT NULL, source_type VARCHAR(64) NOT NULL, source_ref VARCHAR(128) DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, INDEX idx_payment_date (payment_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE payment_allocation (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, kind VARCHAR(12) NOT NULL, amount NUMERIC(15, 2) NOT NULL, ledger_entry_ref VARCHAR(32) DEFAULT NULL, payment_id INT NOT NULL, invoice_id INT NOT NULL, INDEX idx_payment_allocation_invoice (invoice_id), INDEX IDX_69B2A2FF4C3A3BB (payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_69B2A2FF4C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id)');
        $this->addSql('ALTER TABLE payment_allocation ADD CONSTRAINT FK_69B2A2FF2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_69B2A2FF4C3A3BB');
        $this->addSql('ALTER TABLE payment_allocation DROP FOREIGN KEY FK_69B2A2FF2989F1FD');
        $this->addSql('DROP TABLE payment_allocation');
        $this->addSql('DROP TABLE payment');
    }
}
