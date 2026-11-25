<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor — the VAT return's stage S6 on the creditor invoice, reverse charge (ADR-041
 * addendum 2026-11-25 point 10, ADR-059 addendum 2026-11-25; owner S56-13):
 *
 *   - `creditor_invoice.estv_rate` — the «ESTV-Kurs» typed in the invoice head: CHF per ONE unit of
 *     the invoice's foreign currency, the rate of the Swiss tax administration (Art. 45 MWSTV). The
 *     base of the reverse charge of a foreign-currency invoice is the printed amount × this rate;
 *     NULL = none typed (a CHF invoice never carries one).
 *
 * Expand only (ADR-039 decision 14): one nullable column, no data moved.
 */
final class Version20261128110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_invoice.estv_rate — VAT return stage S6, reverse charge (2026-11-28)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_invoice ADD estv_rate NUMERIC(12, 6) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_invoice DROP estv_rate');
    }
}
