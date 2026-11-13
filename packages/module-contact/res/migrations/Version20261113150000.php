<?php

declare(strict_types=1);

namespace Z77\Module\Contact\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-contact — the «Briefanrede» of an ADDRESS (owner decision 2026-11-13, board
 * «E-Mail-Standard»): how a mail to this address greets the person («Sehr geehrter Herr Meier»,
 * «Liebe Lulu»); '' = kernel `LetterSalutation`'s default («Guten Tag Herr Meier»).
 *
 * `address.letter_salutation` VARCHAR(120) NOT NULL DEFAULT '' — the DEFAULT lets a running older
 * release keep inserting addresses without the column.
 *
 * SCHEMA only, no data step. Expand only (ADR-039 decision 14).
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-contact: address.letter_salutation (2026-11-13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE address ADD letter_salutation VARCHAR(120) DEFAULT '' NOT NULL");
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP letter_salutation');
    }
}
