<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order, second migration (P7 slim step 5, `p7-order-plan.md` §4.3) — the DATA step of
 * `Version20261014140000`, in its own file (debtor.md, 2026-10-13: DDL commits implicitly in
 * MariaDB, so a data statement next to a CREATE would not run in the migration's transaction;
 * apart, this step is atomic).
 *
 * The gapless range **`order`** (owner O2: a simple running number from the number-range
 * management, not `YYMMnnnn`) is created at 0 — the order numbers start at 1 (owner Q5). Created
 * ahead of the first draw (DOCTRINE-NR-003) with the statement `NumberRangeRepository::create()`
 * runs; `ON DUPLICATE KEY` keeps a range that exists (a re-run after a development rollback, or a
 * wdv import that raised it — P8) untouched. `OrderService::create()` draws it as the first write
 * of its unit of work.
 *
 * `down()` removes the range only while nothing was drawn (the `dropUnused()` rule): a number
 * handed out is printed on a quote.
 */
final class Version20261014140001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: the number range order, created at 0 (P7 slim step 5, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO number_range (name, last_number) VALUES ('order', 0) ON DUPLICATE KEY UPDATE last_number = last_number");
    }

    /** Development only — only while nothing was drawn. */
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM number_range WHERE name = 'order' AND last_number = 0");
    }
}
