<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the rhythm change and the transit account of catching up input tax (ADR-041
 * addendum 2026-11-25, points 1 and 9):
 *
 *   - `vat_rhythm_from` DATE NULL — «Rhythmus gilt ab», the 1 January the current rhythm applies
 *     from; NULL = since «Abrechnung ab» (every existing record);
 *   - `vat_rhythm_history` VARCHAR(255) NOT NULL DEFAULT '' — the rhythms of the years before it
 *     (JSON year → rhythm), written by the server only; '' = none;
 *   - `account_vat_input_pending` VARCHAR(10) NOT NULL DEFAULT '' — «Vorsteuer, noch nicht
 *     abgerechnet» (KMU 1179); an existing record names it on the screen (an existing chart lacks
 *     1179 until it is created — no number is assumed).
 *
 * Schema only, expand only: an existing installation keeps working unchanged (its one rhythm
 * applies since «Abrechnung ab», as before).
 */
final class Version20261126110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: «Rhythmus gilt ab» with the earlier rhythms, the account «Vorsteuer, noch nicht abgerechnet» (ADR-041 addendum 2026-11-25)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD vat_rhythm_from DATE DEFAULT NULL, ADD vat_rhythm_history VARCHAR(255) DEFAULT '' NOT NULL, ADD account_vat_input_pending VARCHAR(10) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP vat_rhythm_from, DROP vat_rhythm_history, DROP account_vat_input_pending');
    }
}
