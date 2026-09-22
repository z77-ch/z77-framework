<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, second migration (plan §5.2–§5.3, P2 part 2; ADR-042
 * decisions 5–9): the journal `journal_entry` + `journal_line` and the change
 * log of manual entries `journal_entry_change`.
 *
 * Generated with `z77-db diff --namespace="Z77\Module\Financial\Migrations"`
 * against a database at the part-1 migration and reviewed: `utf8mb4_unicode_ci`
 * on every table (ADR-039 decision 18), `ENGINE = InnoDB` spelled out
 * (decision 17), the Doctrine-named indexes and keys kept so `diff` stays
 * clean. Expand only — three new tables, nothing an older release reads, no
 * DROP of any other table (decision 14). `z77-db diff` after `migrate`
 * reports no change (`tests/module-financial.php`).
 *
 * What the schema enforces on its own:
 *
 *   - `uniq_journal_entry_number` — one number per fiscal year;
 *   - `uniq_journal_entry_idempotency` — a repeated key cannot post twice,
 *     even under a race the service cannot see (NULL for manual entries);
 *   - `uniq_journal_entry_reversal_of` — an entry is reversed AT MOST ONCE;
 *   - `journal_entry.version` — the optimistic lock (`#[ORM\Version]`) a
 *     manual edit or delete is checked against (review 2026-09-22, H1/M1–M3;
 *     folded into this migration before it was ever committed, like
 *     module-contact's first one);
 *   - `journal_line.tax_code` references the file-based `TaxCode` of
 *     module-vat BY CODE — deliberately no foreign key (ADR-043 decision 19);
 *   - `journal_entry_change` references the entry by plain columns — no
 *     foreign key, so the row of a DELETED entry survives and documents the
 *     gap in the numbering (decision 9).
 *
 * `journal_line.position` is not unique on purpose: Doctrine inserts before
 * it deletes, and an edit that replaces the lines would collide with itself.
 */
final class Version20260922091711 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: journal_entry, journal_line, journal_entry_change (plan §5.2–§5.3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE journal_entry (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, entry_date DATE NOT NULL, text VARCHAR(255) NOT NULL, kind VARCHAR(12) NOT NULL, source_type VARCHAR(64) DEFAULT NULL, source_ref VARCHAR(128) DEFAULT NULL, idempotency_key VARCHAR(128) DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, fiscal_year_id INT NOT NULL, reversal_of_id INT DEFAULT NULL, INDEX idx_journal_entry_date (fiscal_year_id, entry_date), UNIQUE INDEX uniq_journal_entry_number (fiscal_year_id, number), UNIQUE INDEX uniq_journal_entry_idempotency (idempotency_key), UNIQUE INDEX uniq_journal_entry_reversal_of (reversal_of_id), INDEX IDX_C8FAAE5A63F9139E (fiscal_year_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE journal_entry_change (id INT AUTO_INCREMENT NOT NULL, entry_id INT NOT NULL, fiscal_year_id INT NOT NULL, entry_number INT NOT NULL, action VARCHAR(8) NOT NULL, changed_by VARCHAR(80) NOT NULL, changed_at DATETIME NOT NULL, before_snapshot LONGTEXT NOT NULL, after_snapshot LONGTEXT DEFAULT NULL, INDEX idx_journal_entry_change_entry (entry_id), INDEX idx_journal_entry_change_year (fiscal_year_id, entry_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE journal_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, debit NUMERIC(15, 2) NOT NULL, credit NUMERIC(15, 2) NOT NULL, tax_code VARCHAR(8) DEFAULT NULL, tax_rate INT DEFAULT NULL, tax_base NUMERIC(15, 2) DEFAULT NULL, tax_amount NUMERIC(15, 2) DEFAULT NULL, text VARCHAR(255) DEFAULT NULL, entry_id INT NOT NULL, account_id INT NOT NULL, INDEX idx_journal_line_tax_code (tax_code), INDEX IDX_692C8C7EBA364942 (entry_id), INDEX IDX_692C8C7E9B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE journal_entry ADD CONSTRAINT FK_C8FAAE5A63F9139E FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_year (id)');
        $this->addSql('ALTER TABLE journal_entry ADD CONSTRAINT FK_C8FAAE5A29A0BB4E FOREIGN KEY (reversal_of_id) REFERENCES journal_entry (id)');
        $this->addSql('ALTER TABLE journal_line ADD CONSTRAINT FK_692C8C7EBA364942 FOREIGN KEY (entry_id) REFERENCES journal_entry (id)');
        $this->addSql('ALTER TABLE journal_line ADD CONSTRAINT FK_692C8C7E9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE journal_entry DROP FOREIGN KEY FK_C8FAAE5A63F9139E');
        $this->addSql('ALTER TABLE journal_entry DROP FOREIGN KEY FK_C8FAAE5A29A0BB4E');
        $this->addSql('ALTER TABLE journal_line DROP FOREIGN KEY FK_692C8C7EBA364942');
        $this->addSql('ALTER TABLE journal_line DROP FOREIGN KEY FK_692C8C7E9B6B5FBA');
        $this->addSql('DROP TABLE journal_line');
        $this->addSql('DROP TABLE journal_entry_change');
        $this->addSql('DROP TABLE journal_entry');
    }
}
