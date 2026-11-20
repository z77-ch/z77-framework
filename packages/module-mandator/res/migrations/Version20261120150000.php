<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the logo set-up window (owner decisions 2026-11-20): the DMS documents the
 * window stores, one per use, and the «Später / kein Logo» of the setup step.
 *
 *   - `print_logo_document_id`, `email_logo_document_id`, `login_logo_document_id`,
 *     `web_logo_document_id` INT DEFAULT NULL — the derived variants (PDF letterhead, mail, login,
 *     website); no foreign key (the DMS metadata is the File store, not this database);
 *   - `logo_step_dismissed` TINYINT DEFAULT 0 NOT NULL — the setup step `mandator.logo` silenced.
 *
 * NULL / 0 = today's behaviour: the readers fall back to `logo_path` / `email_logo_path`, which stay
 * and keep working (installations like z77.ch). The defaults keep a row an older release inserts
 * valid. Schema only, expand only.
 */
final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: the logo set-up — print / email / login / web logo document ids and logo_step_dismissed (2026-11-20)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator ADD print_logo_document_id INT DEFAULT NULL, ADD email_logo_document_id INT DEFAULT NULL, ADD login_logo_document_id INT DEFAULT NULL, ADD web_logo_document_id INT DEFAULT NULL, ADD logo_step_dismissed TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP print_logo_document_id, DROP email_logo_document_id, DROP login_logo_document_id, DROP web_logo_document_id, DROP logo_step_dismissed');
    }
}
