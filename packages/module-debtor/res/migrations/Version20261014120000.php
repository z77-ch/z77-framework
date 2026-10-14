<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, the optional SUBJECT of a document (P7 slim, plan D10, owner
 * go 2026-10-14): `invoice.subject` — the «Betreff» printed under the title.
 * An order passes «Auftrag {number} · {Betreff}», the manual invoice editor a
 * free line. Part of the document's snapshot, written by `Invoice::issue()` /
 * `reissue()` only.
 *
 * The SCHEMA step alone (debtor.md rule since 2026-10-13: schema and data in
 * separate files) — and there is no data step: existing documents have no
 * subject, NULL is exactly that. Expand only (ADR-039 decision 14): a
 * nullable column a running previous release neither reads nor writes.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice.subject — the optional «Betreff» under the title (P7 slim, 2026-10-14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD subject VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP subject');
    }
}
