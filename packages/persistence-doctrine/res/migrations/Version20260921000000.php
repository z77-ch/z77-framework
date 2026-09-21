<?php

declare(strict_types=1);

namespace Z77\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `number_range` — the package's own table (ADR-039 decision 15, ADR-042
 * decision 9): one row per range name, the last number handed out.
 *
 * Written by hand to match `Entities\NumberRange` exactly — the statement is
 * what `SchemaTool` generates for the mapping (the harness compares both
 * tables column by column), so `z77-db diff` sees no difference afterwards.
 * `utf8mb4_unicode_ci` throughout (decision 18); names compare
 * case-insensitively on purpose. `ENGINE = InnoDB` spelled out (decision 17)
 * rather than trusting the server default.
 */
final class Version20260921000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'number_range: gapless numbering under a row lock (ADR-039 decision 15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE number_range (name VARCHAR(64) NOT NULL, last_number INT UNSIGNED NOT NULL, PRIMARY KEY (name)) '
            . 'DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB'
        );
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE number_range');
    }
}
