<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order, first migration (P7 slim step 5, `p7-order-plan.md` §4.3, owner go 2026-10-14) —
 * the SCHEMA of the sales order. The range `order` is the data step of its own file
 * (`Version20261014140001`; debtor.md: schema and data in separate files since 2026-10-13):
 *
 *   - `sales_order` — the head: the number (`uniq_sales_order_number`, range `order`), the status
 *     BY CODE (`idx_sales_order_status`, no foreign key — ADR-043 decision 19), subject, order date
 *     (`idx_sales_order_date`), service period, the customer CONTACT (FK), price mode, currency,
 *     payment target code, the delivery address snapshot (`ship_*`, debtor's `AddressSnapshot`
 *     embeddable), the internal note, the totals of the preview (D11, nullable), the booked
 *     invoice (FK `invoice`, nullable, NOT unique — D5), the opaque origin, the actor stamps and
 *     the optimistic-lock `version`;
 *   - `sales_order_line` — the positions: type, the article VARIANT (FK `article_variant`,
 *     nullable — D2; a referenced article cannot be deleted, `ArticleInUseException`), the article
 *     number snapshot, text, quantity `DECIMAL(12,3)` (negative allowed), unit, unit price,
 *     discount, tax code and revenue account by code / number; one level of parent lines (self FK);
 *   - `sales_order_status_change` — the status log (from → to, `change_trigger` — `TRIGGER` is a
 *     reserved word in MariaDB —, the reversal reason, who, when).
 *
 * Expand only (ADR-039 decision 14): three new tables nothing reads yet. Generated with
 * `z77-db diff --namespace=…` against the z77.ch development database (the setup guard refuses to
 * write into a framework package from a project, DOCTRINE-CLI-001), `ENGINE = InnoDB` added like
 * the other modules' first migrations. Named AFTER module-article's `Version20261014130000`:
 * `sales_order_line` references `article_variant`, and migrations run in timestamp order across
 * the modules. `z77-db diff` after `migrate` reports no change.
 */
final class Version20261014140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: sales_order, sales_order_line, sales_order_status_change — the sales order schema (P7 slim step 5, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sales_order (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, status_code VARCHAR(16) NOT NULL, subject VARCHAR(200) NOT NULL, order_date DATE NOT NULL, service_from DATE DEFAULT NULL, service_to DATE DEFAULT NULL, price_mode VARCHAR(5) NOT NULL, currency VARCHAR(3) NOT NULL, payment_target_code VARCHAR(16) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, total_net NUMERIC(15, 2) DEFAULT NULL, total_tax NUMERIC(15, 2) DEFAULT NULL, total_gross NUMERIC(15, 2) DEFAULT NULL, source_type VARCHAR(64) NOT NULL, source_ref VARCHAR(128) DEFAULT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, ship_salutation VARCHAR(20) NOT NULL, ship_title VARCHAR(40) NOT NULL, ship_first_name VARCHAR(70) NOT NULL, ship_name VARCHAR(70) NOT NULL, ship_address_row VARCHAR(120) NOT NULL, ship_street VARCHAR(120) NOT NULL, ship_house_no VARCHAR(16) NOT NULL, ship_zip VARCHAR(16) NOT NULL, ship_city VARCHAR(70) NOT NULL, ship_country VARCHAR(2) NOT NULL, contact_id INT NOT NULL, invoice_id INT DEFAULT NULL, INDEX idx_sales_order_status (status_code), INDEX idx_sales_order_date (order_date), UNIQUE INDEX uniq_sales_order_number (number), INDEX IDX_36D222EE7A1254A (contact_id), INDEX IDX_36D222E2989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sales_order_line (id INT AUTO_INCREMENT NOT NULL, position INT NOT NULL, type VARCHAR(12) NOT NULL, article_number VARCHAR(32) DEFAULT NULL, text LONGTEXT NOT NULL, quantity NUMERIC(12, 3) DEFAULT NULL, unit VARCHAR(16) DEFAULT NULL, unit_price NUMERIC(15, 2) DEFAULT NULL, discount_percent INT NOT NULL, tax_code VARCHAR(8) DEFAULT NULL, revenue_account VARCHAR(10) DEFAULT NULL, order_id INT NOT NULL, parent_line_id INT DEFAULT NULL, variant_id INT DEFAULT NULL, INDEX IDX_93D9398D8D9F6D38 (order_id), INDEX IDX_93D9398D3F330104 (parent_line_id), INDEX IDX_93D9398D3B69A9AF (variant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sales_order_status_change (id INT AUTO_INCREMENT NOT NULL, from_code VARCHAR(16) DEFAULT NULL, to_code VARCHAR(16) NOT NULL, change_trigger VARCHAR(16) NOT NULL, reason VARCHAR(255) DEFAULT NULL, changed_by VARCHAR(80) NOT NULL, changed_at DATETIME NOT NULL, order_id INT NOT NULL, INDEX IDX_A88FCE428D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE sales_order ADD CONSTRAINT FK_36D222EE7A1254A FOREIGN KEY (contact_id) REFERENCES contact (id)');
        $this->addSql('ALTER TABLE sales_order ADD CONSTRAINT FK_36D222E2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
        $this->addSql('ALTER TABLE sales_order_line ADD CONSTRAINT FK_93D9398D8D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id)');
        $this->addSql('ALTER TABLE sales_order_line ADD CONSTRAINT FK_93D9398D3F330104 FOREIGN KEY (parent_line_id) REFERENCES sales_order_line (id)');
        $this->addSql('ALTER TABLE sales_order_line ADD CONSTRAINT FK_93D9398D3B69A9AF FOREIGN KEY (variant_id) REFERENCES article_variant (id)');
        $this->addSql('ALTER TABLE sales_order_status_change ADD CONSTRAINT FK_A88FCE428D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id)');
    }

    /** Development only — a release never rolls the schema back. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY FK_36D222EE7A1254A');
        $this->addSql('ALTER TABLE sales_order DROP FOREIGN KEY FK_36D222E2989F1FD');
        $this->addSql('ALTER TABLE sales_order_line DROP FOREIGN KEY FK_93D9398D8D9F6D38');
        $this->addSql('ALTER TABLE sales_order_line DROP FOREIGN KEY FK_93D9398D3F330104');
        $this->addSql('ALTER TABLE sales_order_line DROP FOREIGN KEY FK_93D9398D3B69A9AF');
        $this->addSql('ALTER TABLE sales_order_status_change DROP FOREIGN KEY FK_A88FCE428D9F6D38');
        $this->addSql('DROP TABLE sales_order_status_change');
        $this->addSql('DROP TABLE sales_order_line');
        $this->addSql('DROP TABLE sales_order');
    }
}
