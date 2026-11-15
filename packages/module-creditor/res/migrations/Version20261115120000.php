<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor, stage 1b — the invoice side of the schema (creditor plan §2.3, §3, §9; owner
 * answers §11 of 2026-11-15). ONE migration for every table the creditor invoice needs, including
 * the columns stages 1c / 2 / 3 fill, so those stages need none (plan §9):
 *
 *   - `creditor_intake`: one file that arrived (channel upload / folder, the client's name, size,
 *     `sha256` UNIQUE — the identical file is never read twice —, state, reason, the DMS
 *     `document_id`, `failed_name` for the folder job's `failed/`, who / when);
 *   - `creditor_invoice`: the supplier invoice — creditor (or the recognised `creditor_proposal`),
 *     intake, PDF, invoice number / date / due date, IBAN snapshot, reference type + reference,
 *     message, `amount` CHF plus the FX columns of the receipt FX plan T1, payment state / `paid_on`
 *     / payment method / credit account, the posting variant snapshot, state (open / blocked /
 *     posted), the duplicate mark and its release, `recognition`, who / when, `version`;
 *   - `creditor_invoice_line`: the lines inside one invoice (owner answer 2) — position, text,
 *     gross amount, account, tax code or `without_vat`;
 *   - `creditor_journal_posting`: the protocol of a «Journal verbuchen» (written from stage 1c).
 *
 * Expand only (ADR-039 decision 14): four new tables, nothing else touched. The Doctrine-named
 * foreign keys and indexes are kept so `z77-db diff` stays clean.
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_intake, creditor_invoice, creditor_invoice_line, creditor_journal_posting — the Kreditorenjournal (2026-11-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE creditor_intake (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(8) NOT NULL, original_name VARCHAR(255) NOT NULL, size_bytes INT NOT NULL, sha256 VARCHAR(64) DEFAULT NULL, state VARCHAR(12) NOT NULL, reason VARCHAR(255) DEFAULT \'\' NOT NULL, document_id INT DEFAULT NULL, failed_name VARCHAR(255) DEFAULT NULL, received_by VARCHAR(80) NOT NULL, received_at DATETIME NOT NULL, UNIQUE INDEX uniq_creditor_intake_sha256 (sha256), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE creditor_invoice (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(12) NOT NULL, creditor_proposal LONGTEXT DEFAULT NULL, document_id INT NOT NULL, invoice_number VARCHAR(40) DEFAULT NULL, invoice_date DATE DEFAULT NULL, due_date DATE DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, reference_type VARCHAR(4) DEFAULT \'NON\' NOT NULL, reference VARCHAR(27) DEFAULT NULL, message VARCHAR(280) DEFAULT \'\' NOT NULL, amount NUMERIC(15, 2) DEFAULT NULL, original_amount NUMERIC(15, 2) DEFAULT NULL, original_currency VARCHAR(3) DEFAULT NULL, exchange_rate NUMERIC(12, 6) DEFAULT NULL, payment_state VARCHAR(12) DEFAULT \'unpaid\' NOT NULL, paid_on DATE DEFAULT NULL, payment_method_code VARCHAR(16) DEFAULT NULL, credit_account VARCHAR(10) DEFAULT NULL, posting_variant VARCHAR(12) DEFAULT \'direct\' NOT NULL, ledger_entry_ref VARCHAR(40) DEFAULT NULL, posted_by VARCHAR(80) DEFAULT NULL, posted_at DATETIME DEFAULT NULL, duplicate_of INT DEFAULT NULL, duplicate_reason VARCHAR(255) DEFAULT \'\' NOT NULL, duplicate_cleared_of INT DEFAULT NULL, duplicate_cleared_by VARCHAR(80) DEFAULT NULL, duplicate_cleared_at DATETIME DEFAULT NULL, recognition LONGTEXT DEFAULT NULL, received_by VARCHAR(80) NOT NULL, received_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, creditor_profile_id INT DEFAULT NULL, intake_id INT DEFAULT NULL, journal_posting_id INT DEFAULT NULL, INDEX idx_creditor_invoice_state_date (state, invoice_date), INDEX idx_creditor_invoice_document (document_id), INDEX idx_creditor_invoice_number (creditor_profile_id, invoice_number), INDEX idx_creditor_invoice_reference (iban, reference), INDEX IDX_106AA287561E7D4D (creditor_profile_id), INDEX IDX_106AA287733DE450 (intake_id), INDEX IDX_106AA2877899FE2 (journal_posting_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE creditor_invoice_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, text VARCHAR(255) DEFAULT \'\' NOT NULL, amount NUMERIC(15, 2) DEFAULT NULL, account VARCHAR(10) DEFAULT NULL, tax_code VARCHAR(8) DEFAULT NULL, without_vat TINYINT DEFAULT 0 NOT NULL, invoice_id INT NOT NULL, INDEX IDX_671725462989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE creditor_journal_posting (id INT AUTO_INCREMENT NOT NULL, posted_by VARCHAR(80) NOT NULL, posted_at DATETIME NOT NULL, invoice_count INT NOT NULL, gross_total NUMERIC(15, 2) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE creditor_invoice ADD CONSTRAINT FK_106AA287561E7D4D FOREIGN KEY (creditor_profile_id) REFERENCES creditor_profile (id)');
        $this->addSql('ALTER TABLE creditor_invoice ADD CONSTRAINT FK_106AA287733DE450 FOREIGN KEY (intake_id) REFERENCES creditor_intake (id)');
        $this->addSql('ALTER TABLE creditor_invoice ADD CONSTRAINT FK_106AA2877899FE2 FOREIGN KEY (journal_posting_id) REFERENCES creditor_journal_posting (id)');
        $this->addSql('ALTER TABLE creditor_invoice_line ADD CONSTRAINT FK_671725462989F1FD FOREIGN KEY (invoice_id) REFERENCES creditor_invoice (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_invoice DROP FOREIGN KEY FK_106AA287561E7D4D');
        $this->addSql('ALTER TABLE creditor_invoice DROP FOREIGN KEY FK_106AA287733DE450');
        $this->addSql('ALTER TABLE creditor_invoice DROP FOREIGN KEY FK_106AA2877899FE2');
        $this->addSql('ALTER TABLE creditor_invoice_line DROP FOREIGN KEY FK_671725462989F1FD');
        $this->addSql('DROP TABLE creditor_intake');
        $this->addSql('DROP TABLE creditor_invoice');
        $this->addSql('DROP TABLE creditor_invoice_line');
        $this->addSql('DROP TABLE creditor_journal_posting');
    }
}
