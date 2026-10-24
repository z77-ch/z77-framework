<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — a DELETED order is kept (ADR-054 decision 14, owner decision O5 of 2026-10-23 —
 * step 6 of the Faktura-Journal build plan): `sales_order.deleted_by` VARCHAR(80), `deleted_at`
 * DATETIME, `delete_reason` VARCHAR(255), all NULL = not deleted; index `idx_sales_order_deleted`
 * for the lists, which hide deleted orders.
 *
 * SCHEMA only, no data step: every existing order reads «not deleted». Expand only (ADR-039
 * decision 14) — safe under the running release.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: deleted_by / deleted_at / delete_reason on sales_order (ADR-054, 2026-10-24)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD deleted_by VARCHAR(80) DEFAULT NULL, ADD deleted_at DATETIME DEFAULT NULL, ADD delete_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_sales_order_deleted ON sales_order (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_sales_order_deleted ON sales_order');
        $this->addSql('ALTER TABLE sales_order DROP deleted_by, DROP deleted_at, DROP delete_reason');
    }
}
