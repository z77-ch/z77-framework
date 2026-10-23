<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the logo of the HTML mails (owner decision 2026-10-23): `mandator.email_logo_path`
 * VARCHAR(255) NOT NULL DEFAULT '', a file of its own next to the print logo `logo_path` — Outlook
 * draws a mail image at its intrinsic pixel size, so the mail logo is uploaded at display size
 * (about 100 px wide). '' = no logo in the mails (today's behaviour); the default keeps a row an
 * older release inserts valid. Schema only, expand only.
 */
final class Version20261023120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.email_logo_path — the logo of the HTML mails (2026-10-23)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD email_logo_path VARCHAR(255) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP email_logo_path');
    }
}
