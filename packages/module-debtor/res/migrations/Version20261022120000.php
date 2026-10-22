<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the per-line CODE of a document line (owner decision 2026-10-22; the wdv
 * `shop_positions.code`): `invoice_line.code` VARCHAR(16) NULL — a short code the importer fills
 * and the PDF rows offer to an override template. Part of the SNAPSHOT (ADR-041 decision 6: a
 * reprint reads as issued). The framework's own templates do not print it.
 *
 * SCHEMA only, no data step: NULL = no code — every existing document prints exactly as before.
 * Expand only (ADR-039 decision 14).
 */
final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice_line.code — the short per-line code, snapshotted (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line DROP code');
    }
}
