<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article, the PRODUCT-GROUP TREE (plan §4b A1, owner 2026-10-14) — schema, step 1 of 3:
 *
 *   - `article_product_group` — one tree of free depth: the parent (self foreign key, NULL = a
 *     root; the database refuses to delete a group that still has children), name, `sort_key`
 *     among the siblings, active, the DEFAULT tax code (by code into module-vat) and revenue
 *     account (by number into the chart — no foreign keys, ADR-043 decision 19) for NEW articles,
 *     the optimistic-lock `version`;
 *   - `article_product.product_group_id` — the ONE placement of a product, a foreign key (a group
 *     that carries articles cannot be deleted). Added NULLABLE here: existing products get their
 *     group in the data step `Version20261014150001` («Allgemein»), and `Version20261014150002`
 *     makes the column NOT NULL — the mapping's state.
 *
 * Expand: a new table and a new nullable column — the running release ignores both. Generated
 * with `z77-db diff --namespace=…` against the z77.ch development database (DOCTRINE-CLI-001),
 * split into the three files by hand, `ENGINE = InnoDB` added like the module's first migration;
 * `z77-db diff` after `migrate` reports no change.
 */
final class Version20261014150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: article_product_group (the product-group tree) and article_product.product_group_id, nullable (A1, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE article_product_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(80) NOT NULL, sort_key INT NOT NULL, active TINYINT NOT NULL, default_tax_code VARCHAR(8) NOT NULL, default_revenue_account VARCHAR(10) NOT NULL, version INT DEFAULT 1 NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_4B47D03C727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE article_product_group ADD CONSTRAINT FK_4B47D03C727ACA70 FOREIGN KEY (parent_id) REFERENCES article_product_group (id)');
        $this->addSql('ALTER TABLE article_product ADD product_group_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_3E98401A35E4B3D0 ON article_product (product_group_id)');
        $this->addSql('ALTER TABLE article_product ADD CONSTRAINT FK_3E98401A35E4B3D0 FOREIGN KEY (product_group_id) REFERENCES article_product_group (id)');
    }

    /** Development only — a release never rolls the schema back. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product DROP FOREIGN KEY FK_3E98401A35E4B3D0');
        $this->addSql('DROP INDEX IDX_3E98401A35E4B3D0 ON article_product');
        $this->addSql('ALTER TABLE article_product DROP product_group_id');
        $this->addSql('ALTER TABLE article_product_group DROP FOREIGN KEY FK_4B47D03C727ACA70');
        $this->addSql('DROP TABLE article_product_group');
    }
}
