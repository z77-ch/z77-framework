<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the WRITER ROW of the VAT return (stage S2, review of slice 5a): the
 * `number_range` row `vat-return` that every return writer locks first
 * (`VatReturn\VatReturnPostingService::WRITER_LOCK`, step 0 of its lock order — financial.md
 * «MWST buchen»). It never hands out a number; it only serialises the writers.
 *
 * Seeded HERE, ahead of the first lock, because `NumberRangeRepository::lock()` creates a missing
 * row inside the caller's unit of work: a first «MWST buchen» that is then REFUSED rolls the new
 * row back, and every writer queued behind it races to insert it — all but one get a deadlock
 * (1213, DOCTRINE-NR-003). A seeded row never takes that path. The statement of
 * `NumberRangeRepository::create()`; `ON DUPLICATE KEY` keeps a row an earlier lock created.
 *
 * Data only — no schema change. `down()` removes the row only while it is at 0 (the
 * `dropUnused()` rule; the writer row always is).
 */
final class Version20261125100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: the number_range row vat-return, the VAT return writer lock, created at 0 (VAT return stage S2, 2026-11-25)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO number_range (name, last_number) VALUES ('vat-return', 0) ON DUPLICATE KEY UPDATE last_number = last_number");
    }

    /** Development only — only while nothing was drawn. */
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM number_range WHERE name = 'vat-return' AND last_number = 0");
    }
}
