<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — ADR-061 stage C3 part a (owner 2026-11-17): the camt.054 ENTRY reference and
 * the camt.054 archive.
 *
 *   - `bank_transaction.entry_ref` VARCHAR(35) NULL + index `idx_bank_transaction_entry_ref` —
 *     the entry's `Ntry/AcctSvcrRef`, the same on every transaction of a collective credit and
 *     the same the camt.053 carries for that entry (the later link to the bank movement);
 *   - `bank_message.file_sha256` VARCHAR(64) NULL and `archive_path` VARCHAR(255) NULL — the
 *     archived file (`data/archive/debtor/bank/<YYYY>/…`, `BankMessageArchive`).
 *
 * SCHEMA only, no data step: a backfill is impossible — no camt.054 file was kept before this
 * stage, so every existing row stays NULL. Expand only (ADR-039 decision 14), safe under the
 * running release (it writes none of the three columns, NULL is valid).
 */
final class Version20261117153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: bank_transaction.entry_ref, bank_message.file_sha256 / archive_path (ADR-061 C3a, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_transaction ADD entry_ref VARCHAR(35) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_bank_transaction_entry_ref ON bank_transaction (entry_ref)');
        $this->addSql('ALTER TABLE bank_message ADD file_sha256 VARCHAR(64) DEFAULT NULL, ADD archive_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bank_transaction_entry_ref ON bank_transaction');
        $this->addSql('ALTER TABLE bank_transaction DROP entry_ref');
        $this->addSql('ALTER TABLE bank_message DROP file_sha256, DROP archive_path');
    }
}
