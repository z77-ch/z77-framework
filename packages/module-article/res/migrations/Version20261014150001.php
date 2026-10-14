<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article, the product-group tree — the DATA step, step 2 of 3, in its own file (DDL
 * commits implicitly in MariaDB, so a data statement next to a CREATE would not run in the
 * migration's transaction; debtor.md, 2026-10-13):
 *
 *   - the root group **«Allgemein»** (coordinator default 2026-10-14, article.md) — created while
 *     the tree is EMPTY, on every installation: the group is mandatory, so a fresh installation
 *     can create its first article right away, and an existing one has a place for its articles;
 *   - every product without a group is placed there (z77.ch: article 1001 «Beratung»).
 *
 * Re-runnable: the group is only created into an empty tree, only unplaced products are moved.
 * `down()` takes the products off «Allgemein» and removes it while it has no sub-group.
 */
final class Version20261014150001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: the root product group «Allgemein», existing products placed in it (A1, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO article_product_group (parent_id, name, sort_key, active, default_tax_code, default_revenue_account, version) SELECT NULL, 'Allgemein', 0, 1, '', '', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM article_product_group)");
        $this->addSql("UPDATE article_product SET product_group_id = (SELECT MIN(g.id) FROM article_product_group g WHERE g.parent_id IS NULL AND g.name = 'Allgemein') WHERE product_group_id IS NULL");
    }

    /** Development only. */
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE article_product SET product_group_id = NULL WHERE product_group_id IN (SELECT id FROM (SELECT g.id FROM article_product_group g WHERE g.parent_id IS NULL AND g.name = 'Allgemein') AS allgemein)");
        $this->addSql("DELETE FROM article_product_group WHERE parent_id IS NULL AND name = 'Allgemein' AND id NOT IN (SELECT parent_id FROM (SELECT DISTINCT parent_id FROM article_product_group WHERE parent_id IS NOT NULL) AS parents)");
    }
}
