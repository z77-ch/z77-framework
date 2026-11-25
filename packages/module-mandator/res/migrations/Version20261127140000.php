<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the transit account of the year-end VAT correction (ADR-042 addendum 3, point 4):
 *
 *   - `account_vat_correction_pending` VARCHAR(10) NOT NULL DEFAULT '' — «MWST-Korrektur, noch nicht
 *     abgerechnet» (KMU 2209); an existing record names it on the screen (an existing chart lacks
 *     2209 until it is created — no number is assumed).
 *
 * Schema only, expand only: an existing installation keeps working unchanged.
 */
final class Version20261127140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: the account «MWST-Korrektur, noch nicht abgerechnet» (ADR-042 addendum 3, point 4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD account_vat_correction_pending VARCHAR(10) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP account_vat_correction_pending');
    }
}
