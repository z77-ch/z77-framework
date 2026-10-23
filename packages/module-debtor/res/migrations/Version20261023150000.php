<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the Faktura-Journal's SCHEMA (owner decision 2026-10-23, ADR-054; step 1 of
 * `docs/03-development/faktura-journal-bauplan.md` §4):
 *
 *   - `invoice`: `revision` INT NOT NULL DEFAULT 1 (the revision of an issued document, not the
 *     ORM `version`); `issued_by` / `issued_at` (the booking print), `posted_by` / `posted_at` and
 *     `journal_posting_id` (the journal posting and its protocol); `replaced_by_id` (the successor,
 *     self FK); `voided_by` / `voided_at` / `void_reason` (who / when / why replaced or deleted);
 *     `reissue_needed` (a source's blocker text); `document_stamp` (the current version's stamp). `invoice.state` stays VARCHAR(12) — the new
 *     values `issued`, `replaced`, `deleted` fit;
 *   - `invoice_journal_posting`: the protocol of one «Journal verbuchen» (who, when, count, gross,
 *     number range) — not a booking record;
 *   - `invoice_revision`: one row per ISSUED VERSION — its stamp (unique) and the reference to
 *     its archived PDF file (`file_path` relative to the installation, `file_sha256`), unique per
 *     (invoice, revision) — owner decisions 2026-10-23 «stamp» and «archive»;
 *   - `bank_transaction.suggested_invoice_id`: the pre-selected successor of a replaced reference.
 *
 * SCHEMA only, no data step: every row keeps its state (ADR-054 decision 22 — `final` stays
 * `final`, drafts stay drafts, no row becomes `issued`), `revision` reads 1 by the column default,
 * every other new column is NULL. Nothing writes the new columns and tables before step 3 —
 * expand only (ADR-039 decision 14), safe under the running release. The Doctrine-named foreign
 * keys and indexes are kept so `z77-db diff` stays clean.
 */
final class Version20261023150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: the Faktura-Journal schema — invoice revision / stamp / issue / post / void columns, invoice_journal_posting, invoice_revision, bank_transaction.suggested_invoice_id (2026-10-23)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_journal_posting (id INT AUTO_INCREMENT NOT NULL, posted_by VARCHAR(80) NOT NULL, posted_at DATETIME NOT NULL, document_count INT NOT NULL, gross_total NUMERIC(15, 2) NOT NULL, first_number INT NOT NULL, last_number INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_revision (id INT AUTO_INCREMENT NOT NULL, revision INT NOT NULL, document_stamp VARCHAR(10) NOT NULL, file_path VARCHAR(255) NOT NULL, file_sha256 CHAR(64) NOT NULL, file_name VARCHAR(120) NOT NULL, reason VARCHAR(255) DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, invoice_id INT NOT NULL, UNIQUE INDEX uniq_invoice_revision (invoice_id, revision), UNIQUE INDEX uniq_invoice_revision_stamp (document_stamp), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice_revision ADD CONSTRAINT FK_724B4F942989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE invoice ADD revision INT DEFAULT 1 NOT NULL, ADD issued_by VARCHAR(80) DEFAULT NULL, ADD issued_at DATETIME DEFAULT NULL, ADD posted_by VARCHAR(80) DEFAULT NULL, ADD posted_at DATETIME DEFAULT NULL, ADD voided_by VARCHAR(80) DEFAULT NULL, ADD voided_at DATETIME DEFAULT NULL, ADD void_reason VARCHAR(255) DEFAULT NULL, ADD reissue_needed VARCHAR(255) DEFAULT NULL, ADD document_stamp VARCHAR(10) DEFAULT NULL, ADD journal_posting_id INT DEFAULT NULL, ADD replaced_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517447899FE2 FOREIGN KEY (journal_posting_id) REFERENCES invoice_journal_posting (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517449AC69B54 FOREIGN KEY (replaced_by_id) REFERENCES invoice (id)');
        $this->addSql('CREATE INDEX IDX_906517447899FE2 ON invoice (journal_posting_id)');
        $this->addSql('CREATE INDEX IDX_906517449AC69B54 ON invoice (replaced_by_id)');
        $this->addSql('ALTER TABLE bank_transaction ADD suggested_invoice_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bank_transaction ADD CONSTRAINT FK_50BCB3AEC25F1ACB FOREIGN KEY (suggested_invoice_id) REFERENCES invoice (id)');
        $this->addSql('CREATE INDEX IDX_50BCB3AEC25F1ACB ON bank_transaction (suggested_invoice_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_transaction DROP FOREIGN KEY FK_50BCB3AEC25F1ACB');
        $this->addSql('DROP INDEX IDX_50BCB3AEC25F1ACB ON bank_transaction');
        $this->addSql('ALTER TABLE bank_transaction DROP suggested_invoice_id');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517447899FE2');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517449AC69B54');
        $this->addSql('DROP INDEX IDX_906517447899FE2 ON invoice');
        $this->addSql('DROP INDEX IDX_906517449AC69B54 ON invoice');
        $this->addSql('ALTER TABLE invoice DROP revision, DROP issued_by, DROP issued_at, DROP posted_by, DROP posted_at, DROP voided_by, DROP voided_at, DROP void_reason, DROP reissue_needed, DROP document_stamp, DROP journal_posting_id, DROP replaced_by_id');
        $this->addSql('ALTER TABLE invoice_revision DROP FOREIGN KEY FK_724B4F942989F1FD');
        $this->addSql('DROP TABLE invoice_revision');
        $this->addSql('DROP TABLE invoice_journal_posting');
    }
}
