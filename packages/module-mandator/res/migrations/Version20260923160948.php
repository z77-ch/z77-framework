<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator, first migration (owner decisions E1 / E2 of 2026-09-23,
 * ADR-039 decisions 12–14): the one table `mandator` — the installation's
 * own company: letterhead, UID, VAT liability and the eight ledger accounts
 * that moved here from `financialConfig → vatAccounts` and
 * `debtorConfig → debtorAccounts`.
 *
 * Written to the shape `z77-db diff` generates and verified by
 * `tests/module-mandator.php` (A): `diff` after `migrate` reports no change.
 * `utf8mb4_unicode_ci` (decision 18), `ENGINE = InnoDB` spelled out
 * (decision 17), expand only — one new table, nothing an older release
 * reads (decision 14). No foreign key anywhere: the accounts are referenced
 * BY NUMBER into financial's chart, which may be absent. No `mandator_id` is
 * added to any other table — one mandator (E1).
 *
 * `id INT NOT NULL` WITHOUT AUTO_INCREMENT on purpose (review 2026-09-23):
 * the id is assigned (`Mandator::ID` = 1), so the primary key IS the «one
 * mandator» guard — a second INSERT fails in the database. A multi-mandator
 * build turns the column into AUTO_INCREMENT in its own migration.
 */
final class Version20260923160948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator (the installation\'s own company — letterhead, UID, VAT liability, ledger accounts; owner E1 / E2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mandator (id INT NOT NULL, name VARCHAR(120) NOT NULL, address_suffix_one VARCHAR(120) NOT NULL, address_suffix_two VARCHAR(120) NOT NULL, street VARCHAR(120) NOT NULL, house_no VARCHAR(16) NOT NULL, zip VARCHAR(16) NOT NULL, city VARCHAR(70) NOT NULL, country VARCHAR(2) NOT NULL, email VARCHAR(190) NOT NULL, phone VARCHAR(40) NOT NULL, website VARCHAR(190) NOT NULL, logo_path VARCHAR(255) NOT NULL, uid VARCHAR(15) NOT NULL, liable_to_vat TINYINT NOT NULL, account_receivable VARCHAR(10) NOT NULL, account_discount VARCHAR(10) NOT NULL, account_loss VARCHAR(10) NOT NULL, account_rounding VARCHAR(10) NOT NULL, account_dunning_fee VARCHAR(10) NOT NULL, account_vat_input_material VARCHAR(10) NOT NULL, account_vat_input_other VARCHAR(10) NOT NULL, account_vat_owed VARCHAR(10) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE mandator');
    }
}
