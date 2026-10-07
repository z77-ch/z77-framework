<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, sixth migration (plan §6.4, P4 part 2; ADR-039 decisions
 * 12–14): the CAMT.054 import — `bank_message` (one imported notification:
 * the bank's message id, unique; the IBAN and the payment target it
 * resolved to; file and importer) and `bank_transaction` (one credit of a
 * message with its reference, dates, amount, remittance and debtor as the
 * bank sent them, plus the receivables side's state, the matched invoice,
 * the payment that booked it, the remainder and a note).
 *
 * Expand only (decision 14): two new tables. `utf8mb4_unicode_ci`
 * (decision 18), `ENGINE = InnoDB` (decision 17), the Doctrine-named
 * foreign keys and their implicit indexes kept so `z77-db diff` stays
 * clean; the module's own names: `uniq_bank_message_id` (the dedup
 * across imports), `uniq_bank_transaction_ref` (the dedup within a
 * message), `idx_bank_transaction_state_date` (the close check reads
 * unbooked transactions by date). Written against the mapping; `z77-db
 * diff` after `migrate` reports no change (`tests/module-debtor.php` A11).
 */
final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: bank_message and bank_transaction — the CAMT.054 import (plan §6.4, P4 part 2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_message (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(35) NOT NULL, created_on DATETIME NOT NULL, iban VARCHAR(34) NOT NULL, payment_target_code VARCHAR(16) NOT NULL, file_name VARCHAR(120) NOT NULL, imported_by VARCHAR(80) NOT NULL, imported_at DATETIME NOT NULL, UNIQUE INDEX uniq_bank_message_id (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE bank_transaction (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, tx_ref VARCHAR(35) NOT NULL, value_date DATE NOT NULL, booking_date DATE DEFAULT NULL, amount NUMERIC(15, 2) NOT NULL, currency VARCHAR(3) NOT NULL, reference_type VARCHAR(4) NOT NULL, reference VARCHAR(35) NOT NULL, remittance VARCHAR(140) NOT NULL, debtor_name VARCHAR(140) NOT NULL, debtor_city VARCHAR(70) NOT NULL, state VARCHAR(12) NOT NULL, remainder NUMERIC(15, 2) NOT NULL, note VARCHAR(255) DEFAULT NULL, message_id INT NOT NULL, invoice_id INT DEFAULT NULL, payment_id INT DEFAULT NULL, INDEX idx_bank_transaction_state_date (state, value_date), INDEX IDX_50BCB3AE537A1329 (message_id), INDEX IDX_50BCB3AE2989F1FD (invoice_id), INDEX IDX_50BCB3AE4C3A3BB (payment_id), UNIQUE INDEX uniq_bank_transaction_ref (message_id, tx_ref), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE537A1329 FOREIGN KEY (message_id) REFERENCES bank_message (id)');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AE4C3A3BB FOREIGN KEY (payment_id) REFERENCES payment (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_50BCB3AE537A1329');
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_50BCB3AE2989F1FD');
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_50BCB3AE4C3A3BB');
        $this->addSql('DROP TABLE bank_transaction');
        $this->addSql('DROP TABLE bank_message');
    }
}
