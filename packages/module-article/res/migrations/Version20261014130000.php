<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article, first migration (P7 slim step 1, `p7-order-plan.md` §4.1, owner go
 * 2026-10-14) — the article master, P6 SLIM:
 *
 *   - `article_product` — the product stage (A3): name, tax code (BY CODE into module-vat, no
 *     foreign key — ADR-043 decision 19), revenue account (by number into the chart, no foreign
 *     key — financial is only suggested), «führt Bestand», active, the actor stamps and the
 *     optimistic-lock `version`;
 *   - `article_variant` — the variant stage: the article NUMBER (unique — A2), the label (''
 *     = the default variant), the unit, active; FK to the product;
 *   - `article_price` — the price history (A4): one row per variant and day
 *     (`uniq_article_price_day`), the amount in the base currency (`MoneyType`), net / gross.
 *
 * Schema only — there is no data step (no number range in slim: the article number is typed).
 * Expand only (ADR-039 decision 14): three new tables nothing reads yet. Generated with
 * `z77-db diff --namespace=…` against the z77.ch development database (the setup guard refuses
 * to write into a framework package from a project, DOCTRINE-CLI-001), `ENGINE = InnoDB` added
 * like the other modules' first migrations; `z77-db diff` after `migrate` reports no change.
 */
final class Version20261014130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: article_product, article_variant, article_price — the article master, P6 slim (P7 slim step 1, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_product (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, tax_code VARCHAR(8) NOT NULL, revenue_account VARCHAR(10) NOT NULL, stock_relevant TINYINT NOT NULL, active TINYINT NOT NULL, created_by VARCHAR(80) NOT NULL, created_at DATETIME NOT NULL, changed_by VARCHAR(80) DEFAULT NULL, changed_at DATETIME DEFAULT NULL, version INT DEFAULT 1 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE article_variant (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(32) NOT NULL, label VARCHAR(80) NOT NULL, unit VARCHAR(16) NOT NULL, active TINYINT NOT NULL, product_id INT NOT NULL, UNIQUE INDEX uniq_article_variant_number (number), INDEX IDX_1C91FB1A4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE article_price (id INT AUTO_INCREMENT NOT NULL, valid_from DATE NOT NULL, amount NUMERIC(15, 2) NOT NULL, price_mode VARCHAR(5) NOT NULL, variant_id INT NOT NULL, UNIQUE INDEX uniq_article_price_day (variant_id, valid_from), INDEX IDX_BD7F50C83B69A9AF (variant_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_variant ADD CONSTRAINT FK_1C91FB1A4584665A FOREIGN KEY (product_id) REFERENCES article_product (id)');
        $this->addSql('ALTER TABLE article_price ADD CONSTRAINT FK_BD7F50C83B69A9AF FOREIGN KEY (variant_id) REFERENCES article_variant (id)');
    }

    /** Development only — a release never rolls the schema back. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_price DROP FOREIGN KEY FK_BD7F50C83B69A9AF');
        $this->addSql('ALTER TABLE article_variant DROP FOREIGN KEY FK_1C91FB1A4584665A');
        $this->addSql('DROP TABLE article_price');
        $this->addSql('DROP TABLE article_variant');
        $this->addSql('DROP TABLE article_product');
    }
}
