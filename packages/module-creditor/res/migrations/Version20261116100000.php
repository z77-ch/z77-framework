<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor — learning per creditor (owner 2026-11-16, creditor.md «Learning per creditor»):
 *
 *   - `creditor_learned`: what a creditor learned from its invoices — a FEATURE of the sender
 *     (`kind` vat / domain / sender, `value` normalised) or the LABEL of a value (`kind` label,
 *     `field` invoice_number / invoice_date / due_date / amount, `value` the label as printed,
 *     `currency`, `date_order`, `agreed` = PHP and the AI agreed so often); `invoice_id` the invoice
 *     it came from (no foreign key), who / when. `value` compares binary (`utf8mb4_bin`) — the
 *     service normalises it, the unique index must not see `ü` and `u` as one;
 *   - `creditor_invoice_text`: the text layer the job read per invoice (`invoice_id` unique, no
 *     foreign key) — the web request learns from it, since pdftotext runs only in the job
 *     (CREDITOR-RECOG-WEB-001).
 *
 * Expand only (ADR-039 decision 14): two new tables, nothing else touched — the code before this
 * migration never reads them. The Doctrine-named foreign key and index are kept so `z77-db diff`
 * stays clean.
 */
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_learned and creditor_invoice_text — learning per creditor (2026-11-16)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE creditor_invoice_text (id INT AUTO_INCREMENT NOT NULL, invoice_id INT NOT NULL, text LONGTEXT NOT NULL, read_at DATETIME NOT NULL, UNIQUE INDEX uniq_creditor_invoice_text_invoice (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE creditor_learned (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(8) NOT NULL, field VARCHAR(16) DEFAULT \'\' NOT NULL, value VARCHAR(160) NOT NULL COLLATE `utf8mb4_bin`, currency VARCHAR(3) DEFAULT NULL, date_order VARCHAR(3) DEFAULT NULL, agreed INT DEFAULT 0 NOT NULL, invoice_id INT DEFAULT NULL, learned_by VARCHAR(80) NOT NULL, learned_at DATETIME NOT NULL, creditor_profile_id INT NOT NULL, INDEX idx_creditor_learned_value (kind, value), UNIQUE INDEX uniq_creditor_learned (creditor_profile_id, kind, field, value), INDEX IDX_7C3438A6561E7D4D (creditor_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE creditor_learned ADD CONSTRAINT FK_7C3438A6561E7D4D FOREIGN KEY (creditor_profile_id) REFERENCES creditor_profile (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_learned DROP FOREIGN KEY FK_7C3438A6561E7D4D');
        $this->addSql('DROP TABLE creditor_invoice_text');
        $this->addSql('DROP TABLE creditor_learned');
    }
}
