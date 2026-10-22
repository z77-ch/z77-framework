<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the VAT NOTE of a document composed WITHOUT VAT (owner decision 2026-10-22):
 * `invoice.vat_note` VARCHAR(255) NULL — the mandator's «Hinweis ohne MWST» as it read when the
 * document was composed, part of the SNAPSHOT (ADR-041 decision 6: a reprint reads as issued).
 * NULL = nothing printed: a document computed with VAT, or a mandator without a text.
 *
 * SCHEMA only, no data step: every existing document keeps printing as before. Expand only
 * (ADR-039 decision 14).
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice.vat_note — the note of a document without VAT, snapshotted (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD vat_note VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP vat_note');
    }
}
