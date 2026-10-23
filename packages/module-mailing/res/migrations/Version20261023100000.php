<?php

declare(strict_types=1);

namespace Z77\Module\Mailing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mailing — who triggered an outbox mail and in whose name (owner decision 2026-10-23):
 * a staff member sends in his own name. The SMTP account stays the sender (envelope and From
 * address from `config/client/mail.inc.php`); the chosen SENDER IDENTITY of the backend user
 * gives the From's display name and the Reply-To.
 *
 * `mail_outbox.sender_user_id` INT NULL (the backend user's id — no foreign key: users are file
 * records), `sender_name` VARCHAR(190) and `sender_email` VARCHAR(254), both NOT NULL DEFAULT ''
 * — a snapshot of the identity at queue time, '' = no identity (a mail of the account alone, as
 * every row before this migration). The DEFAULT lets a running older release keep queueing.
 *
 * SCHEMA only, no data step. Expand only (ADR-039 decision 14).
 */
final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mailing: mail_outbox.sender_user_id, sender_name, sender_email — the sender identity (2026-10-23)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mail_outbox ADD sender_user_id INT DEFAULT NULL, ADD sender_name VARCHAR(190) DEFAULT '' NOT NULL, ADD sender_email VARCHAR(254) DEFAULT '' NOT NULL");
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mail_outbox DROP sender_user_id, DROP sender_name, DROP sender_email');
    }
}
