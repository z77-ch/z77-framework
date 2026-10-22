<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — the per-line CODE of an order line (owner decision 2026-10-22; the wdv
 * `shop_positions.code`): `sales_order_line.code` VARCHAR(16) NULL — a short code the importer
 * fills, carried into the invoice line at the booking print and offered to the PDF rows of an
 * override template. VARCHAR(16) — debtor's `InvoiceLine::CODE_LENGTH`, the one rule.
 *
 * SCHEMA only: NULL = no code — every existing order prints exactly as before. Expand only
 * (ADR-039 decision 14).
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: sales_order_line.code — the short per-line code (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_line ADD code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order_line DROP code');
    }
}
