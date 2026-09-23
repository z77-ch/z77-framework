<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, first migration (plan §6.1, ADR-039 decisions 12–14): the
 * one Doctrine table of P3 part 1, `debtor_profile` — the debtor-specific
 * part of a contact. Payment terms, payment targets and dunning levels are
 * file-based and have no table.
 *
 * Generated with `z77-db diff --namespace="Z77\Module\Debtor\Migrations"`
 * against a database at module-contact's migration, and reviewed:
 * `utf8mb4_unicode_ci` on the table (decision 18), `ENGINE = InnoDB` spelled
 * out (decision 17) rather than trusting the server default, the
 * Doctrine-named foreign key kept so `diff` stays clean. Expand only — one
 * new table, nothing an older release reads (decision 14). `z77-db diff`
 * after `migrate` reports no change (`tests/module-debtor.php`).
 *
 * `uniq_debtor_profile_contact` is the rule «at most one profile per
 * contact» in the schema: the validator asks first so the screen gets a
 * field error, the index decides under a race.
 *
 * `payment_terms_code` references the file-based `PaymentTerms` BY CODE —
 * deliberately no foreign key: the row lives in `data/`, not in this
 * database (ADR-043 decision 19). `idx_debtor_profile_terms` serves the
 * count the payment-terms list shows before a deactivation.
 */
final class Version20260922173918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: debtor_profile (plan §6.1)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE debtor_profile (id INT AUTO_INCREMENT NOT NULL, payment_terms_code VARCHAR(16) NOT NULL, dunning_block TINYINT NOT NULL, active TINYINT NOT NULL, contact_id INT NOT NULL, INDEX idx_debtor_profile_terms (payment_terms_code), UNIQUE INDEX uniq_debtor_profile_contact (contact_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE debtor_profile ADD CONSTRAINT FK_B300E3EBE7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor_profile DROP FOREIGN KEY FK_B300E3EBE7A1254A');
        $this->addSql('DROP TABLE debtor_profile');
    }
}
