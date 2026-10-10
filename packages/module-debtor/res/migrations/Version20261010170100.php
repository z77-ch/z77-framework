<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, eleventh migration (owner 2026-10-10: «Fakturiert fällt
 * weg» — with the booking print every booked document was printed at that
 * moment): the three columns of the «Fakturiert» fact that
 * `Version20261010140000` added the same day are dropped —
 * `delivered_at`, `delivered_by`, `delivered_via`. A «sent» date returns
 * with the e-mail dispatch (`debtor.md` pending), designed then.
 *
 * The contract step of decision 14 (ADR-039) — in a file of its own, apart
 * from the data step of `Version20261010170000` (no data migration and drop
 * in one file). Allowed now because no running release reads the columns:
 * they were added and abandoned on 2026-10-10 before any release shipped
 * them (only the z77.ch development database ran `Version20261010140000`).
 * Dropping is data loss on purpose: a recorded «Fakturiert» date has no
 * successor.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261010170100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: drop invoice delivered_at / delivered_by / delivered_via — «Fakturiert» removed (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP delivered_at, DROP delivered_by, DROP delivered_via');
    }

    /** Development only — the columns come back empty; what they held is gone. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD delivered_at DATETIME DEFAULT NULL, ADD delivered_by VARCHAR(80) DEFAULT NULL, ADD delivered_via VARCHAR(12) DEFAULT NULL');
    }
}
