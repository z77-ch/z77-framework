<?php

declare(strict_types=1);

namespace Z77\Module\Mailing\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mailing — the mail outbox (step 2 of the mail plan, owner 2026-10-22): `mail_outbox`,
 * one row per recipient, written once by `OutboxService` and sent in throttled batches by the
 * `mail-outbox` job. `html_body` MEDIUMTEXT (the rendered mail), `text_body` / `attachments`
 * TEXT (`attachments` = JSON list of `type:id` references resolved at send time). The index
 * `(state, next_attempt_at)` is the job's «what is due» read; `batch_id` and `source` group the
 * rows of one send action / one origin. No foreign key: `source` is opaque on purpose. Schema
 * only, expand only.
 */
final class Version20261022170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mailing: mail_outbox — the queued e-mails the mail-outbox job sends (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mail_outbox (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, created_by VARCHAR(80) NOT NULL, recipient_email VARCHAR(254) NOT NULL, recipient_name VARCHAR(190) NOT NULL, reply_to VARCHAR(254) NOT NULL, subject VARCHAR(255) NOT NULL, html_body MEDIUMTEXT NOT NULL, text_body TEXT NOT NULL, template VARCHAR(190) NOT NULL, language VARCHAR(5) NOT NULL, attachments TEXT NOT NULL, source VARCHAR(128) NOT NULL, batch_id VARCHAR(32) NOT NULL, state VARCHAR(12) NOT NULL, attempts INT NOT NULL, last_error VARCHAR(500) NOT NULL, sent_at DATETIME DEFAULT NULL, next_attempt_at DATETIME DEFAULT NULL, INDEX idx_mail_outbox_state_due (state, next_attempt_at), INDEX idx_mail_outbox_batch (batch_id), INDEX idx_mail_outbox_source (source), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mail_outbox');
    }
}
