<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the two accounts of the year opening (P5, plan §5.7, owner decisions
 * 2026-10-22): `mandator.account_opening_balance` (the contra account every balance-sheet
 * account is opened against, KMU 9100 «Eröffnungsbilanz») and `mandator.account_annual_result`
 * (where the previous year's profit or loss is carried, KMU 2979 «Jahresgewinn/-verlust»). The
 * reader is module-financial's `YearOpeningService`. VARCHAR(10) NOT NULL like the other account
 * columns; the DEFAULTS give the one existing record the KMU numbers at once, so an installation
 * opens its next year without visiting the mandator screen first. Schema only, expand only.
 */
final class Version20261022140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.account_opening_balance / account_annual_result — the accounts of the year opening (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD account_opening_balance VARCHAR(10) DEFAULT '9100' NOT NULL, ADD account_annual_result VARCHAR(10) DEFAULT '2979' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP account_opening_balance, DROP account_annual_result');
    }
}
