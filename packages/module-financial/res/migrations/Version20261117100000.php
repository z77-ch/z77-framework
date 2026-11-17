<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, seventh migration — bank-driven bookkeeping, stage C1 (ADR-061 decision 9,
 * owner 2026-11-17): `bank_link`, the link between a bank movement and what it pays.
 *
 *   - `movement_id` → `bank_movement`; `owner_type` + `owner_key` (`creditor:17` — opaque for
 *     financial, the owning module builds it) + `owner_label` (what the list «Bewegungen» shows);
 *   - `amount` — a link stores an AMOUNT, not a yes / no;
 *   - `basis` (`reference` = identity, set without asking · `proposal` = confirmed · `manual`),
 *     who / when.
 *
 * The rule of stage C1 — one link per movement over its full amount, one movement per owner — is
 * the two UNIQUE indexes; n:1 and 1:n later drop an index, the table stays.
 *
 * Expand only (ADR-039 decision 14): one new table, nothing else touched, no data moved.
 */
final class Version20261117100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_link — what a bank movement pays, with an amount (ADR-061 stage C1, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_link (id INT AUTO_INCREMENT NOT NULL, owner_type VARCHAR(20) NOT NULL, owner_key VARCHAR(60) NOT NULL, owner_label VARCHAR(120) NOT NULL, amount NUMERIC(15, 2) NOT NULL, basis VARCHAR(10) NOT NULL, linked_by VARCHAR(80) NOT NULL, linked_at DATETIME NOT NULL, movement_id INT NOT NULL, UNIQUE INDEX uniq_bank_link_movement (movement_id), UNIQUE INDEX uniq_bank_link_owner (owner_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE bank_link ADD CONSTRAINT FK_9CCCCB7F229E70A7 FOREIGN KEY (movement_id) REFERENCES bank_movement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_link DROP FOREIGN KEY FK_9CCCCB7F229E70A7');
        $this->addSql('DROP TABLE bank_link');
    }
}
