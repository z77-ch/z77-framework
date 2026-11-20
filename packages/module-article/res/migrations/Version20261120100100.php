<?php

declare(strict_types=1);

namespace Z77\Module\Article\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-article — `write_count` on `article_product` (fixed 2026-11-20, DOCTRINE-VERSION-001).
 *
 * Why: since doctrine/orm 3.8 a `changed_at` re-stamped within the same second is no change, so a
 * write whose only header change was that stamp (only the lines / child rows changed) got no
 * UPDATE and the optimistic-lock version did not move. The entity now counts every write
 * (`Z77\Persistence\Doctrine\Mapping\WriteCounter`), a scalar that always changes — the row is
 * updated and the version bumped deterministically. Technical column, nothing reads it; existing
 * rows start at 0 (ADD with a default, no data step).
 */
final class Version20261120100100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-article: write_count on article_product — every write bumps the version again under doctrine/orm 3.8 (2026-11-20)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product ADD write_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_product DROP write_count');
    }
}
