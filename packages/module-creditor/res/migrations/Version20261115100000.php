<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor, stage 1a — the creditor's schema (creditor plan §2.2, owner answers §11 of
 * 2026-11-15):
 *
 *   - `creditor_profile`: the payables side of a contact — `contact_id` (unique: one profile per
 *     contact), `uid` (unique, NULL = none), the defaults `default_account`, `default_tax_code` /
 *     `default_without_vat`, `default_payment_method_code`, the stamp `defaults_set_at` /
 *     `defaults_set_by` (NULL = no defaults decided yet), `active`, `version` (the optimistic lock
 *     the service counts);
 *   - `creditor_iban`: the IBANs of a creditor — several per creditor, normalised, `is_qr_iban`,
 *     the optional `hint` that tells creditors apart when they SHARE an IBAN (the index on `iban` is
 *     deliberately NOT unique), `active`; one creditor lists an IBAN once.
 *
 * Expand only (ADR-039 decision 14): two new tables, nothing else touched. The creditor invoice,
 * its lines, the intake and the posting protocol come with their own migrations in stages 1b / 1c.
 * The Doctrine-named foreign keys and index are kept so `z77-db diff` stays clean.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_profile and creditor_iban — the creditor as a role of a contact (2026-11-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE creditor_iban (id INT AUTO_INCREMENT NOT NULL, iban VARCHAR(34) NOT NULL, is_qr_iban TINYINT NOT NULL, hint VARCHAR(80) DEFAULT \'\' NOT NULL, active TINYINT NOT NULL, creditor_profile_id INT NOT NULL, INDEX idx_creditor_iban_iban (iban), UNIQUE INDEX uniq_creditor_iban_profile (creditor_profile_id, iban), INDEX IDX_FAFA03E561E7D4D (creditor_profile_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('CREATE TABLE creditor_profile (id INT AUTO_INCREMENT NOT NULL, uid VARCHAR(15) DEFAULT NULL, default_account VARCHAR(10) DEFAULT NULL, default_tax_code VARCHAR(8) DEFAULT NULL, default_without_vat TINYINT DEFAULT 0 NOT NULL, default_payment_method_code VARCHAR(16) DEFAULT NULL, defaults_set_at DATETIME DEFAULT NULL, defaults_set_by VARCHAR(80) DEFAULT NULL, active TINYINT NOT NULL, version INT DEFAULT 1 NOT NULL, contact_id INT NOT NULL, UNIQUE INDEX uniq_creditor_profile_contact (contact_id), UNIQUE INDEX uniq_creditor_profile_uid (uid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE creditor_iban ADD CONSTRAINT FK_FAFA03E561E7D4D FOREIGN KEY (creditor_profile_id) REFERENCES creditor_profile (id)');
        $this->addSql('ALTER TABLE creditor_profile ADD CONSTRAINT FK_1581FCCE7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_iban DROP FOREIGN KEY FK_FAFA03E561E7D4D');
        $this->addSql('ALTER TABLE creditor_profile DROP FOREIGN KEY FK_1581FCCE7A1254A');
        $this->addSql('DROP TABLE creditor_iban');
        $this->addSql('DROP TABLE creditor_profile');
    }
}
