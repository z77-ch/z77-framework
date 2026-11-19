<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the bank account switch «gemischt geführt» (owner 2026-11-19, ADR-061
 * addendum 2026-11-19): `bank_account.mixed_use`. Only a mixed account offers «Privat», the views
 * «Geschäftlich» / «Privat», the column «Stand» and private patterns.
 *
 * Start value BY DATA (owner's choice): an account that already holds a private decision — a
 * movement marked private by hand, or a pattern that is private or «gemischt» — starts ON, every
 * other one OFF. Nothing decided before is hidden.
 */
final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_account.mixed_use («gemischt geführt»), ON where a private mark or a private / mixed pattern exists (owner 2026-11-19)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account ADD mixed_use TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE bank_account a SET a.mixed_use = 1 WHERE EXISTS (SELECT 1 FROM bank_pattern p WHERE p.bank_account_id = a.id AND p.kind IN ('private', 'mixed'))"
            . " OR EXISTS (SELECT 1 FROM bank_movement_mark k JOIN bank_movement m ON m.id = k.movement_id WHERE m.bank_account_id = a.id AND k.state = 'private')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_account DROP mixed_use');
    }
}
