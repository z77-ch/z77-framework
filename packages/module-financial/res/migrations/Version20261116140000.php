<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, sixth migration — the bank account service, stage B1 (ADR-060, owner
 * decisions of 2026-11-15 / 2026-11-16):
 *
 *   - `bank_account`: the master data «Bankkonten» — `code` UNIQUE (what a payment method
 *     names), label, `iban` UNIQUE (normalised), currency, `ledger_account`, the switch
 *     `statement_import` («Kontoauszug einlesen»), `active`;
 *   - `bank_statement`: one imported camt.053 statement — the bank's message and statement id
 *     (UNIQUE per bank account), the period, opening and closing booked balance with their
 *     dates, the file (name, SHA-256, path in `data/archive/financial/bank/<YYYY>/`), how many
 *     movements were new / already known, who / when. The statements of an account form ONE
 *     seamless chain; the newest row is «eingelesen bis … · Saldo …»;
 *   - `bank_movement`: one booked entry — `dedup_key` UNIQUE per bank account (the bank's
 *     `AcctSvcrRef`, else a fingerprint: nothing is stored twice), direction, booking and value
 *     date, amount, the account's balance after it, the instructed foreign amount with currency
 *     and rate, bank transaction code, counterparty and IBAN, structured reference, text.
 *
 * No table for «which invoice took a movement» yet: its shape (one invoice per movement, owner
 * 2026-11-16) is decided with stage B2, which brings its own small migration.
 *
 * Expand only (ADR-039 decision 14): three new tables, nothing else touched. No data is moved —
 * debtor's `bank_message` / `bank_transaction` (the camt.054 import) are a different store and
 * stay as they are. The Doctrine-named foreign keys and indexes are kept so `z77-db diff` stays
 * clean.
 */
final class Version20261116140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_account, bank_statement, bank_movement — the bank account service, stage B1 (2026-11-16)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_account (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(16) NOT NULL, label VARCHAR(80) NOT NULL, iban VARCHAR(34) NOT NULL, currency VARCHAR(3) NOT NULL, ledger_account VARCHAR(10) NOT NULL, statement_import TINYINT NOT NULL, active TINYINT NOT NULL, UNIQUE INDEX uniq_bank_account_code (code), UNIQUE INDEX uniq_bank_account_iban (iban), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE bank_movement (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, entry_ref VARCHAR(35) DEFAULT NULL, dedup_key VARCHAR(70) NOT NULL, direction VARCHAR(6) NOT NULL, reversal TINYINT NOT NULL, booking_date DATE NOT NULL, value_date DATE DEFAULT NULL, amount NUMERIC(15, 2) NOT NULL, currency VARCHAR(3) NOT NULL, balance NUMERIC(15, 2) NOT NULL, original_amount NUMERIC(15, 2) DEFAULT NULL, original_currency VARCHAR(3) DEFAULT NULL, exchange_rate NUMERIC(18, 8) DEFAULT NULL, rate_source VARCHAR(10) DEFAULT NULL, bank_tx_code VARCHAR(20) NOT NULL, counterparty_name VARCHAR(140) NOT NULL, counterparty_iban VARCHAR(34) NOT NULL, reference_type VARCHAR(4) NOT NULL, reference VARCHAR(35) NOT NULL, text VARCHAR(1000) NOT NULL, tx_count INT NOT NULL, bank_account_id INT NOT NULL, statement_id INT NOT NULL, INDEX idx_bank_movement_booking (bank_account_id, booking_date), INDEX idx_bank_movement_reference (reference), UNIQUE INDEX uniq_bank_movement_dedup (bank_account_id, dedup_key), INDEX IDX_C59D8F7012CB990C (bank_account_id), INDEX IDX_C59D8F70849CB65B (statement_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE bank_statement (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(35) NOT NULL, statement_id VARCHAR(35) NOT NULL, electronic_sequence INT DEFAULT NULL, legal_sequence INT DEFAULT NULL, from_date DATE NOT NULL, to_date DATE NOT NULL, opening_balance NUMERIC(15, 2) NOT NULL, opening_date DATE NOT NULL, closing_balance NUMERIC(15, 2) NOT NULL, closing_date DATE NOT NULL, created_on DATETIME NOT NULL, file_name VARCHAR(120) NOT NULL, file_sha256 VARCHAR(64) NOT NULL, archive_path VARCHAR(255) NOT NULL, movement_count INT NOT NULL, duplicate_count INT NOT NULL, imported_by VARCHAR(80) NOT NULL, imported_at DATETIME NOT NULL, bank_account_id INT NOT NULL, INDEX idx_bank_statement_sha256 (file_sha256), UNIQUE INDEX uniq_bank_statement_message (bank_account_id, message_id, statement_id), INDEX IDX_B33607EF12CB990C (bank_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE bank_movement ADD CONSTRAINT FK_C59D8F7012CB990C FOREIGN KEY (bank_account_id) REFERENCES bank_account (id)');
        $this->addSql('ALTER TABLE bank_movement ADD CONSTRAINT FK_C59D8F70849CB65B FOREIGN KEY (statement_id) REFERENCES bank_statement (id)');
        $this->addSql('ALTER TABLE bank_statement ADD CONSTRAINT FK_B33607EF12CB990C FOREIGN KEY (bank_account_id) REFERENCES bank_account (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_movement DROP FOREIGN KEY FK_C59D8F7012CB990C');
        $this->addSql('ALTER TABLE bank_movement DROP FOREIGN KEY FK_C59D8F70849CB65B');
        $this->addSql('ALTER TABLE bank_statement DROP FOREIGN KEY FK_B33607EF12CB990C');
        $this->addSql('DROP TABLE bank_account');
        $this->addSql('DROP TABLE bank_movement');
        $this->addSql('DROP TABLE bank_statement');
    }
}
