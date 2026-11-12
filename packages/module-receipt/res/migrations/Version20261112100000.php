<?php

declare(strict_types=1);

namespace Z77\Module\Receipt\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-receipt — the receipt journal's schema (gateway plan T4, owner decisions E1–E12 of
 * 2026-11-12):
 *
 *   - `receipt`: one captured receipt — date, text, gross amount, template code, payment method
 *     code (E10), debit / credit account numbers, tax code or `without_vat`, the photo's DMS
 *     `document_id` (indexed: split parts share it, E12), the device and who captured it
 *     (snapshot), the client's `capture_key` (unique — the offline queue may send twice), who
 *     changed it, the state `captured` / `posted`, the posted entry `ledger_entry_ref` with who /
 *     when and the protocol, `version` (optimistic lock);
 *   - `receipt_journal_posting`: the protocol of one «Journal verbuchen» (who, when, count, gross).
 *
 * The receipt templates and payment methods are file-based master data and need no table. Expand only (ADR-039
 * decision 14): two new tables, nothing else touched. The Doctrine-named foreign key and index are
 * kept so `z77-db diff` stays clean.
 */
final class Version20261112100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-receipt: receipt and receipt_journal_posting — the receipt journal (2026-11-12)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE receipt_journal_posting (id INT AUTO_INCREMENT NOT NULL, posted_by VARCHAR(80) NOT NULL, posted_at DATETIME NOT NULL, receipt_count INT NOT NULL, gross_total NUMERIC(15, 2) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE receipt (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(12) NOT NULL, receipt_date DATE NOT NULL, text VARCHAR(255) NOT NULL, amount NUMERIC(15, 2) NOT NULL, template_code VARCHAR(16) DEFAULT NULL, payment_method_code VARCHAR(16) DEFAULT NULL, debit_account VARCHAR(10) DEFAULT NULL, credit_account VARCHAR(10) DEFAULT NULL, tax_code VARCHAR(8) DEFAULT NULL, without_vat TINYINT NOT NULL, document_id INT NOT NULL, device_id VARCHAR(64) DEFAULT NULL, device_name VARCHAR(80) DEFAULT NULL, captured_by VARCHAR(80) NOT NULL, captured_at DATETIME NOT NULL, capture_key VARCHAR(64) DEFAULT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, ledger_entry_ref VARCHAR(40) DEFAULT NULL, posted_by VARCHAR(80) DEFAULT NULL, posted_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, journal_posting_id INT DEFAULT NULL, INDEX IDX_5399B6457899FE2 (journal_posting_id), INDEX idx_receipt_state_date (state, receipt_date), INDEX idx_receipt_document (document_id), UNIQUE INDEX uniq_receipt_capture_key (capture_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE receipt ADD CONSTRAINT FK_5399B6457899FE2 FOREIGN KEY (journal_posting_id) REFERENCES receipt_journal_posting (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP FOREIGN KEY FK_5399B6457899FE2');
        $this->addSql('DROP TABLE receipt');
        $this->addSql('DROP TABLE receipt_journal_posting');
    }
}
