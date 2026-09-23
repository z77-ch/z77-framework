<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, second migration (plan §6.1, §6.2; ADR-039 decisions 12–14):
 * the documents of P3 part 2 — `invoice` (invoices AND credit notes, told
 * apart by `kind`), `invoice_line` and `invoice_tax` — and the two number
 * ranges the documents draw from.
 *
 * Generated with `z77-db diff --namespace="Z77\Module\Debtor\Migrations"`
 * against a database at the module's first migration, and reviewed:
 * `utf8mb4_unicode_ci` on every table (decision 18), `ENGINE = InnoDB`
 * spelled out (decision 17), the Doctrine-named indexes and foreign keys
 * kept so `diff` stays clean, expand only — three new tables and two rows,
 * nothing an older release reads (decision 14). `z77-db diff` after
 * `migrate` reports no change (`tests/module-debtor.php`).
 *
 * `uniq_invoice_kind_number`: one number per kind, the schema's word on the
 * gapless range. `addr_*` is the ADDRESS SNAPSHOT of the document
 * (`AddressSnapshot`, plan §4a) — copied, never referenced; the contact
 * itself is referenced by id. `payment_terms_code` and `tax_code` reference
 * the file-based master data BY CODE with no foreign key (ADR-043
 * decision 19). `credit_note_of_id` and `parent_line_id` are self
 * references. `position` is deliberately not unique (a re-issue inserts
 * before it deletes).
 *
 * The two `number_range` rows `invoice` and `credit-note` are created HERE,
 * at 0, so that the first draw never takes the new-row path under
 * contention (DOCTRINE-NR-003 — the ledger creates its range at the
 * fiscal-year opening for the same reason; the documents have no such
 * event, the install is theirs). The statement is the one
 * `NumberRangeRepository::create()` runs, and this module owns both ranges;
 * `down()` removes them only while nothing was drawn (the `dropUnused()`
 * rule). Once numbers were drawn, `down()` therefore LEAVES the range row
 * (correct — every number handed out was referenced), and a later `up()` is
 * idempotent on it (`ON DUPLICATE KEY UPDATE` changes nothing), so a
 * development rollback and re-run continues the sequence at the old
 * number, never at 1 again.
 */
final class Version20260923043935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice, invoice_line, invoice_tax and the ranges invoice / credit-note (plan §6.2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, number INT NOT NULL, state VARCHAR(12) NOT NULL, language VARCHAR(5) NOT NULL, invoice_date DATE NOT NULL, service_from DATE NOT NULL, service_to DATE DEFAULT NULL, currency VARCHAR(3) NOT NULL, exchange_rate NUMERIC(12, 6) DEFAULT NULL, price_mode VARCHAR(5) NOT NULL, payment_terms_code VARCHAR(16) NOT NULL, due_date DATE NOT NULL, discount_tiers LONGTEXT NOT NULL, terms_text LONGTEXT DEFAULT NULL, net_total NUMERIC(15, 2) NOT NULL, tax_total NUMERIC(15, 2) NOT NULL, rounding NUMERIC(15, 2) NOT NULL, gross_total NUMERIC(15, 2) NOT NULL, source_type VARCHAR(64) DEFAULT NULL, source_ref VARCHAR(128) DEFAULT NULL, ledger_entry_ref VARCHAR(32) DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, addr_salutation VARCHAR(20) NOT NULL, addr_title VARCHAR(40) NOT NULL, addr_first_name VARCHAR(70) NOT NULL, addr_name VARCHAR(70) NOT NULL, addr_address_row VARCHAR(120) NOT NULL, addr_street VARCHAR(120) NOT NULL, addr_house_no VARCHAR(16) NOT NULL, addr_zip VARCHAR(16) NOT NULL, addr_city VARCHAR(70) NOT NULL, addr_country VARCHAR(2) NOT NULL, contact_id INT NOT NULL, credit_note_of_id INT DEFAULT NULL, INDEX idx_invoice_state_date (state, invoice_date), UNIQUE INDEX uniq_invoice_kind_number (kind, number), INDEX IDX_90651744E7A1254A (contact_id), INDEX IDX_90651744CC65BB15 (credit_note_of_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, type VARCHAR(12) NOT NULL, text LONGTEXT NOT NULL, quantity NUMERIC(12, 3) DEFAULT NULL, unit VARCHAR(16) DEFAULT NULL, unit_price NUMERIC(15, 2) DEFAULT NULL, discount_percent INT NOT NULL, amount NUMERIC(15, 2) NOT NULL, tax_code VARCHAR(8) DEFAULT NULL, tax_rate INT DEFAULT NULL, tax_label VARCHAR(80) DEFAULT NULL, revenue_account VARCHAR(10) DEFAULT NULL, source_type VARCHAR(64) DEFAULT NULL, source_ref VARCHAR(128) DEFAULT NULL, invoice_id INT NOT NULL, parent_line_id INT DEFAULT NULL, INDEX idx_invoice_line_account (revenue_account), INDEX IDX_D3D1D6932989F1FD (invoice_id), INDEX IDX_D3D1D6933F330104 (parent_line_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invoice_tax (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, tax_code VARCHAR(8) NOT NULL, tax_category VARCHAR(16) NOT NULL, tax_label VARCHAR(80) NOT NULL, tax_rate INT NOT NULL, tax_base NUMERIC(15, 2) NOT NULL, tax_amount NUMERIC(15, 2) NOT NULL, invoice_id INT NOT NULL, INDEX idx_invoice_tax_code (tax_code), INDEX IDX_2670D5B52989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744E7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744CC65BB15 FOREIGN KEY (credit_note_of_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT FK_D3D1D6932989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT FK_D3D1D6933F330104 FOREIGN KEY (parent_line_id) REFERENCES invoice_line (id)');
        $this->addSql('ALTER TABLE invoice_tax ADD CONSTRAINT FK_2670D5B52989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');

        // The document ranges, created ahead of the first draw (DOCTRINE-NR-003); consumes no number.
        $this->addSql("INSERT INTO number_range (name, last_number) VALUES ('invoice', 0), ('credit-note', 0) ON DUPLICATE KEY UPDATE last_number = last_number");
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744E7A1254A');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744CC65BB15');
        $this->addSql('ALTER TABLE invoice_line DROP FOREIGN KEY FK_D3D1D6932989F1FD');
        $this->addSql('ALTER TABLE invoice_line DROP FOREIGN KEY FK_D3D1D6933F330104');
        $this->addSql('ALTER TABLE invoice_tax DROP FOREIGN KEY FK_2670D5B52989F1FD');
        $this->addSql('DROP TABLE invoice_tax');
        $this->addSql('DROP TABLE invoice_line');
        $this->addSql('DROP TABLE invoice');
        // Only while nothing was drawn — a number handed out is referenced somewhere (the `dropUnused()` rule).
        $this->addSql("DELETE FROM number_range WHERE name IN ('invoice', 'credit-note') AND last_number = 0");
    }
}
