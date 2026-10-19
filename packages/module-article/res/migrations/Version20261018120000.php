<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article — the product DESCRIPTION (owner 2026-10-18): a multi-line text beside the name,
 * shared by every variant; an order line takes it as its text from the second line on
 * (`AbstractVariant::lineText()`). Schema only: MariaDB fills the new NOT NULL column of the
 * existing products with '' (= no description), so no data step is needed.
 *
 * `LONGTEXT NOT NULL` is what Doctrine maps `Types::TEXT` to, so `z77-db diff` stays quiet.
 */
final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: article_product.description — the product description (2026-10-18)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product ADD description LONGTEXT NOT NULL');
    }

    /** Development only — drops every description typed since. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product DROP description');
    }
}
