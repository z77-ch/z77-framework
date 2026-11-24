<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the settings of the VAT return (ADR-041 addendum 2026-11-24, points 1–4 and 13):
 *
 *   - `vat_method` (effective | net-tax-rate) and `vat_consideration` (agreed | received) — the two
 *     axes of the settlement method; `vat_settlement_rhythm` (quarter | month | year). All NULL =
 *     «not set»: there is NO silent default, the return refuses and the setup check asks while the
 *     mandator is liable;
 *   - `vat_settlement_from` / `vat_settlement_until` — «Abrechnung ab» / «Abrechnung bis», NULL;
 *   - `account_vat_settlement` (KMU 2201) and `account_vat_rounding` (KMU 6945) — two account keys
 *     like the others, VARCHAR(10) NOT NULL DEFAULT '': an existing record names them on the screen
 *     (no number is assumed — an existing chart may lack 6945).
 *
 * Schema only, expand only: an existing installation keeps working unchanged.
 */
final class Version20261124120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: the VAT return settings — method, consideration, rhythm, «Abrechnung ab» / «bis», settlement and rounding account (ADR-041 addendum 2026-11-24)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD vat_method VARCHAR(16) DEFAULT NULL, ADD vat_consideration VARCHAR(16) DEFAULT NULL, ADD vat_settlement_rhythm VARCHAR(16) DEFAULT NULL, ADD vat_settlement_from DATE DEFAULT NULL, ADD vat_settlement_until DATE DEFAULT NULL, ADD account_vat_settlement VARCHAR(10) DEFAULT '' NOT NULL, ADD account_vat_rounding VARCHAR(10) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP vat_method, DROP vat_consideration, DROP vat_settlement_rhythm, DROP vat_settlement_from, DROP vat_settlement_until, DROP account_vat_settlement, DROP account_vat_rounding');
    }
}
