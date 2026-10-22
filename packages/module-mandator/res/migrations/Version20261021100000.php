<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the installation's default SUBJECT LABEL «Betreffname» (owner decision
 * 2026-10-21): `mandator.default_subject_label`, what a new order or invoice is pre-filled with
 * when its debtor carries no label of its own. VARCHAR(80) NOT NULL DEFAULT '' — the mandator's
 * string convention, '' = none (today's behaviour); the default keeps a row an older release
 * inserts valid. Schema only, expand only.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.default_subject_label — the default «Betreffname» of new documents (2026-10-21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD default_subject_label VARCHAR(80) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP default_subject_label');
    }
}
