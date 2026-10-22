<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the NOTE a document without VAT prints (owner decision 2026-10-22): a company
 * not liable to VAT («mehrwertsteuerpflichtig» off) says so on every invoice, credit note, quote
 * and order confirmation — «Keine MWST — der Rechnungssteller ist nicht mehrwertsteuerpflichtig.»
 * The text is maintained on the mandator, next to the switch: `mandator.vat_exempt_note`
 * VARCHAR(255) NOT NULL DEFAULT '' — the mandator's string convention, '' = no note (today's
 * behaviour); the default keeps a row an older release inserts valid. Schema only, expand only.
 */
final class Version20261022100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.vat_exempt_note — the note a document without VAT prints (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD vat_exempt_note VARCHAR(255) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP vat_exempt_note');
    }
}
