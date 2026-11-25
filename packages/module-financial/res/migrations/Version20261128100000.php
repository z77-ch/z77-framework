<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the stored yearly overview of the VAT return («MWST-Jahresübersicht … ablegen»,
 * stage S7 slice 7.5; owner S7-1 option b, B-4, B-5, C-D of 2026-11-25).
 *
 *   - `vat_year_overview` — one row per storing: the tax year (the calendar year), «Stand», the
 *     overview's fingerprint at that moment, the archived PDF (path, SHA-256, file name), the
 *     protocol's detail, who and when. Deliberately NO unique key on the tax year: the overview is
 *     stored again whenever it has changed since the newest storing, and every stored PDF stays.
 *     Index `idx_vat_year_overview_year` (`tax_year`) — «the newest storing of year X».
 *
 * Expand only (ADR-039 decision 14): a new table, no data step.
 */
final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: vat_year_overview (the stored yearly overview of the VAT return) — VAT return stage S7, 2026-11-28';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE vat_year_overview (id INT AUTO_INCREMENT NOT NULL, tax_year INT NOT NULL, stand_at DATETIME NOT NULL, fingerprint CHAR(64) NOT NULL, pdf_path VARCHAR(255) NOT NULL, pdf_sha256 CHAR(64) NOT NULL, pdf_name VARCHAR(120) NOT NULL, detail LONGTEXT DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, INDEX idx_vat_year_overview_year (tax_year), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vat_year_overview');
    }
}
