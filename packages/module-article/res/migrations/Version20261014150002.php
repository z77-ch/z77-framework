<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article, the product-group tree — schema, step 3 of 3: `article_product.product_group_id`
 * becomes NOT NULL (A1: every product hangs in exactly one group), the state of the mapping.
 * After the data step every product has its group.
 *
 * Why in the SAME release and not in a later one (expand/contract, ADR-039 decision 14): no
 * release without the column exists — module-article is unreleased (built 2026-10-14, only the
 * z77.ch development database carries it), so no running `current` writes a product without a
 * group. Once module-article is released, a tightening like this goes into the release AFTER the
 * one that fills the column.
 */
final class Version20261014150002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: article_product.product_group_id NOT NULL — every product in exactly one group (A1, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product CHANGE product_group_id product_group_id INT NOT NULL');
    }

    /** Development only. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product CHANGE product_group_id product_group_id INT DEFAULT NULL');
    }
}
