<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the VAT return, stage S2 slice 1 «model + read» (ADR-042 addendum 2026-11-24,
 * points 1, 2, 4 and 5; ADR-041 addendum points 1, 2, 5, 7 and 14; owner decisions of 2026-11-24 on
 * the S2 plan: nil return, figures fingerprint, own protocol table, resolved mapping + rule-file
 * hash, the write-once file archive; O11 of the S3 plan: `kind` leads the unique indexes).
 *
 *   - `vat_return` — one row per POSTED return: the period snapshot, the settings, the form edition
 *     and the resolved mapping, the declared payable amount, the settlement entry (NULL = a nil
 *     return), the fingerprints, the archived voucher, the lift. «Settled» is read from these rows
 *     (`VatReturn\VatSettlement`), never stored a second time. `UNIQUE (kind, period_from,
 *     revision)` and `UNIQUE (kind, period_from, live)` — `live` is 1 while posted, NULL once
 *     lifted, so at most ONE posted return per kind and period;
 *   - `vat_return_figure` — the declared figure of every form field of a return (exact and rounded);
 *   - `vat_return_log` — the protocol: post, lift, filed, unfiled.
 *
 * Expand only (ADR-039 decision 14): three new tables, nothing existing altered, no data step. No
 * production code writes a row yet — the period state `vat-settled` stays until slice 2.
 */
final class Version20261124182954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: vat_return, vat_return_figure, vat_return_log — the posted VAT returns, their declared figures, the protocol (VAT return stage S2, 2026-11-24)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vat_return (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, revision INT NOT NULL, state VARCHAR(12) NOT NULL, live TINYINT DEFAULT NULL, rhythm VARCHAR(16) NOT NULL, method VARCHAR(16) NOT NULL, consideration VARCHAR(16) NOT NULL, country CHAR(2) NOT NULL, form_edition VARCHAR(64) NOT NULL, mapping_hash CHAR(64) NOT NULL, mapping_snapshot LONGTEXT NOT NULL, currency CHAR(3) NOT NULL, payable NUMERIC(15, 2) NOT NULL, posted_tax NUMERIC(15, 2) NOT NULL, rounding_difference NUMERIC(15, 2) NOT NULL, filed_on DATE DEFAULT NULL, posted_by VARCHAR(80) NOT NULL, posted_at DATETIME NOT NULL, lifted_by VARCHAR(80) DEFAULT NULL, lifted_at DATETIME DEFAULT NULL, lift_reason VARCHAR(500) DEFAULT NULL, figures_fingerprint CHAR(64) NOT NULL, warnings_fingerprint CHAR(64) DEFAULT NULL, confirmed_warnings LONGTEXT DEFAULT NULL, voucher_path VARCHAR(255) NOT NULL, voucher_sha256 CHAR(64) NOT NULL, voucher_name VARCHAR(120) NOT NULL, version INT DEFAULT 1 NOT NULL, write_count INT DEFAULT 0 NOT NULL, settlement_entry_id INT DEFAULT NULL, reversal_entry_id INT DEFAULT NULL, INDEX idx_vat_return_live_to (kind, live, period_to), UNIQUE INDEX uniq_vat_return_revision (kind, period_from, revision), UNIQUE INDEX uniq_vat_return_live (kind, period_from, live), INDEX IDX_B81E90D6672E7D4E (settlement_entry_id), INDEX IDX_B81E90D6B7A53FC3 (reversal_entry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE vat_return_figure (id INT AUTO_INCREMENT NOT NULL, field VARCHAR(8) NOT NULL, kind VARCHAR(16) NOT NULL, value_exact NUMERIC(21, 6) NOT NULL, value_rounded NUMERIC(15, 2) NOT NULL, base NUMERIC(15, 2) DEFAULT NULL, line_count INT NOT NULL, vat_return_id INT NOT NULL, UNIQUE INDEX uniq_vat_return_figure_field (vat_return_id, field), INDEX IDX_4073B53640B3B73 (vat_return_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE vat_return_log (id INT AUTO_INCREMENT NOT NULL, vat_return_id INT NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, revision INT NOT NULL, action VARCHAR(8) NOT NULL, actor VARCHAR(80) NOT NULL, acted_at DATETIME NOT NULL, reason VARCHAR(500) DEFAULT NULL, detail LONGTEXT DEFAULT NULL, INDEX idx_vat_return_log_return (vat_return_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE vat_return ADD CONSTRAINT FK_B81E90D6672E7D4E FOREIGN KEY (settlement_entry_id) REFERENCES journal_entry (id)');
        $this->addSql('ALTER TABLE vat_return ADD CONSTRAINT FK_B81E90D6B7A53FC3 FOREIGN KEY (reversal_entry_id) REFERENCES journal_entry (id)');
        $this->addSql('ALTER TABLE vat_return_figure ADD CONSTRAINT FK_4073B53640B3B73 FOREIGN KEY (vat_return_id) REFERENCES vat_return (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return DROP FOREIGN KEY FK_B81E90D6672E7D4E');
        $this->addSql('ALTER TABLE vat_return DROP FOREIGN KEY FK_B81E90D6B7A53FC3');
        $this->addSql('ALTER TABLE vat_return_figure DROP FOREIGN KEY FK_4073B53640B3B73');
        $this->addSql('DROP TABLE vat_return_figure');
        $this->addSql('DROP TABLE vat_return');
        $this->addSql('DROP TABLE vat_return_log');
    }
}
