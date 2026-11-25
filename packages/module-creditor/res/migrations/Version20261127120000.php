<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor — the VAT return's stage S5 on the creditor invoice (ADR-041 addendum 2026-11-25,
 * points 6 and 9; ADR-059 addendum 2026-11-25, point 1):
 *
 *   - `creditor_invoice_line.line_kind` — `standard` or `tax-only` («nur Steuer»: the amount IS the
 *     input tax, the account derived from the code); every existing line is `standard`;
 *   - `creditor_profile.default_tax_only` — the creditor learns «nur Steuer» together with its code
 *     (S56-4); every existing creditor: false;
 *   - `creditor_invoice.tax_entry_ref` — the invoice's second entry, the input tax caught up through
 *     the transit account on the first day of the running period (key `creditor:{id}:vat`); NULL =
 *     none.
 *
 * Expand only (ADR-039 decision 14): three columns with defaults, no data moved.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_invoice_line.line_kind, creditor_profile.default_tax_only, creditor_invoice.tax_entry_ref — VAT return stage S5 (2026-11-27)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_invoice ADD tax_entry_ref VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE creditor_invoice_line ADD line_kind VARCHAR(12) DEFAULT \'standard\' NOT NULL');
        $this->addSql('ALTER TABLE creditor_profile ADD default_tax_only TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_profile DROP default_tax_only');
        $this->addSql('ALTER TABLE creditor_invoice_line DROP line_kind');
        $this->addSql('ALTER TABLE creditor_invoice DROP tax_entry_ref');
    }
}
