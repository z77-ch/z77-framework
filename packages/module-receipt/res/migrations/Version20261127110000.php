<?php

declare(strict_types=1);

namespace Z77\Module\Receipt\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-receipt — the tax-only receipt and catching up input tax (VAT return stage S5, ADR-041
 * addendum 2026-11-25, points 6 and 9):
 *
 *   - `receipt.line_kind`: what the receipt's amount IS — `standard` (a gross amount the posting
 *     splits into net and tax, or posts «ohne MWST») or `tax-only` («nur Steuer»: the amount IS the
 *     input tax, e.g. the import VAT of a customs assessment; Soll is derived from the code). The
 *     values of financial's `Ledger\GrossPartKind`. An existing receipt is `standard`;
 *   - `receipt.tax_entry_ref`: `{fiscal-year}/{number}` of the SECOND entry of a receipt whose input
 *     tax was caught up in the running VAT period (key `receipt:{id}:vat`); null otherwise.
 *
 * Expand only (ADR-039 decision 14): two columns, nothing else touched.
 */
final class Version20261127110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-receipt: receipt.line_kind and receipt.tax_entry_ref — the tax-only receipt and the caught-up input tax (2026-11-27)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE receipt ADD line_kind VARCHAR(12) DEFAULT 'standard' NOT NULL, ADD tax_entry_ref VARCHAR(40) DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE receipt DROP line_kind, DROP tax_entry_ref');
    }
}
