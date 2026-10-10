<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, ninth migration (owner 2026-10-10): «Fakturiert» — the
 * dated fact that a BOOKED document reached the customer, independent of
 * its posting state (`Entities\DeliveryChannel`, `Services\DeliveryService`):
 * `delivered_at` (when), `delivered_by` (who, the actor's name like
 * `created_by`), `delivered_via` (`pdf` | `manual`; e-mail later). All three
 * nullable — NULL = not yet delivered.
 *
 * Expand only (ADR-039 decision 14): three nullable columns, no default
 * written into existing rows. Documents booked before this migration read
 * as «nicht fakturiert» — the office marks the ones it has sent (or they
 * are marked on the next PDF). No backfill on purpose: the migration cannot
 * know which documents were sent.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261010140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice delivered_at / delivered_by / delivered_via — «Fakturiert» (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD delivered_at DATETIME DEFAULT NULL, ADD delivered_by VARCHAR(80) DEFAULT NULL, ADD delivered_via VARCHAR(12) DEFAULT NULL');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP delivered_at, DROP delivered_by, DROP delivered_via');
    }
}
