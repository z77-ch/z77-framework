<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor, fifth migration — bank-driven bookkeeping, stage C1 (ADR-061, owner
 * 2026-11-17): `creditor_profile.bank_text`, the field «Banktext» — how the bank names the
 * creditor on the statement when an entry carries no counterparty (the merchant text of a card or
 * TWINT payment). The bank match looks for it in a movement's text; typed in the creditor window
 * or learned from the first movement assigned by hand. NULL = none.
 *
 * The link between an invoice and its movement is financial's table `bank_link`
 * (`Z77\Module\Financial\Migrations\Version20261117100000`) — creditor owns it by a key.
 *
 * Expand only (ADR-039 decision 14): one nullable column, no data moved.
 */
final class Version20261117110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_profile.bank_text — «Banktext», the merchant text of the bank (ADR-061 stage C1, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_profile ADD bank_text VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_profile DROP bank_text');
    }
}
