<?php

declare(strict_types=1);

namespace Z77\Module\Contact\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-contact, first migration (plan §4a, ADR-039 decisions 12–14):
 * `contact`, `address` and the typed link `contact_address`.
 *
 * Generated with `z77-db diff --namespace="Z77\Module\Contact\Migrations"`
 * against an empty database and reviewed: `utf8mb4_unicode_ci` on every
 * table (decision 18), `ENGINE = InnoDB` spelled out (decision 17) rather
 * than trusting the server default, foreign keys from the link to both
 * sides. Expand only — three new tables, nothing an older release reads
 * (decision 14). `z77-db diff` after `migrate` reports no change
 * (`tests/module-contact.php`).
 *
 * `contact_address.type_code` references the file-based `AddressType` BY
 * CODE — deliberately no foreign key: the row lives in `data/`, not in this
 * database (ADR-043 decision 19).
 */
final class Version20260921153209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-contact: contact, address, contact_address (plan §4a)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE address (id INT AUTO_INCREMENT NOT NULL, salutation VARCHAR(20) NOT NULL, title VARCHAR(40) NOT NULL, first_name VARCHAR(70) NOT NULL, name VARCHAR(70) NOT NULL, address_row VARCHAR(120) NOT NULL, street VARCHAR(120) NOT NULL, house_no VARCHAR(16) NOT NULL, zip VARCHAR(16) NOT NULL, city VARCHAR(70) NOT NULL, country VARCHAR(2) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contact (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, company VARCHAR(120) NOT NULL, first_name VARCHAR(70) NOT NULL, last_name VARCHAR(70) NOT NULL, language VARCHAR(5) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(40) NOT NULL, active TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE contact_address (id INT AUTO_INCREMENT NOT NULL, type_code VARCHAR(16) NOT NULL, title VARCHAR(80) NOT NULL, contact_id INT NOT NULL, address_id INT NOT NULL, INDEX idx_contact_address_type (type_code), UNIQUE INDEX uniq_contact_address_type (contact_id, address_id, type_code), INDEX IDX_97614E00E7A1254A (contact_id), INDEX IDX_97614E00F5B7AF75 (address_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contact_address ADD CONSTRAINT FK_97614E00E7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id)');
        $this->addSql('ALTER TABLE contact_address ADD CONSTRAINT FK_97614E00F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_address DROP FOREIGN KEY FK_97614E00E7A1254A');
        $this->addSql('ALTER TABLE contact_address DROP FOREIGN KEY FK_97614E00F5B7AF75');
        $this->addSql('DROP TABLE contact_address');
        $this->addSql('DROP TABLE contact');
        $this->addSql('DROP TABLE address');
    }
}
