<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the «Briefanrede» in the document's ADDRESS SNAPSHOT (owner decision 2026-11-13,
 * board «E-Mail-Standard»): `AddressSnapshot` carries the address's Briefanrede, so the document's
 * mail greets as the address said when the document was made (`{briefanrede}`).
 *
 * `invoice.addr_letter_salutation` VARCHAR(120) NOT NULL DEFAULT '' — '' = kernel
 * `LetterSalutation`'s default («Guten Tag Herr Meier»).
 *
 * SCHEMA only, no data step: every existing document reads «no Briefanrede». Expand only (ADR-039
 * decision 14).
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice.addr_letter_salutation (2026-11-13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice ADD addr_letter_salutation VARCHAR(120) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP addr_letter_salutation');
    }
}
