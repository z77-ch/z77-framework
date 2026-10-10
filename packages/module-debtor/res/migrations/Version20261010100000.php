<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, eighth migration (owner 2026-10-10; ADR-039 decisions
 * 12–14): ONE number series for invoices, credit notes and fees, in the
 * format `YYMMnnnn` by the month of the invoice date
 * (`Invoicing\DocumentNumber`). The number is now unique ACROSS kinds —
 * `uniq_invoice_number` on `invoice (number)` — so a number names exactly
 * one document (the QR reference, a payment message, the search).
 *
 * Expand only (decision 14): one index. The former per-kind index
 * `uniq_invoice_kind_number` stays — the new one implies it, and a release
 * never drops what the running release relies on; a later release drops it
 * (`debtor.md` pending). Existing numbers are NOT renumbered: a finalized
 * document is booked and printed. A `YYMMnnnn` number of an invoice date
 * from 2010 on has eight digits and a month 01–12 in digits 3–4; should a
 * former number ever look like one, the unique index refuses the insert
 * and the unit of work rolls back — nothing is overwritten. An invoice and
 * a credit note of the former per-kind ranges CAN carry the same number:
 * then the index cannot be built, and the migration stops BEFORE it with
 * the duplicates named instead of a bare duplicate-key error.
 *
 * NO range rows here: the per-month ranges `document.YYMM` come into being
 * with the first document of their month — `InvoicingService::invoice()`
 * creates the month's range with `NumberRangeRepository::create()` before
 * its unit of work (DOCTRINE-NR-003); a month cannot be created ahead at
 * install. The former ranges `invoice` and `credit-note` stay: the running
 * release still draws from them until the switch, and a range that has
 * handed out a number is never dropped (the `dropUnused()` rule) — `invoice`
 * keeps documenting the former numbers; an unused `credit-note` (0) goes
 * with the contract step.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: one document number series YYMMnnnn, unique across kinds (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        $duplicates = $this->connection->fetchFirstColumn('SELECT number FROM invoice GROUP BY number HAVING COUNT(*) > 1 ORDER BY number');
        $this->abortIf(
            $duplicates !== [],
            'Documents of different kinds share a number (' . implode(', ', $duplicates) . ') — the one series needs a unique number. '
            . 'Resolve the duplicates by hand before migrating (a finalized document is booked and printed: decide with the owner).'
        );

        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_number ON invoice (number)');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_invoice_number ON invoice');
        // Only months that never handed out a number (the `dropUnused()` rule) — a drawn number is printed on a document.
        $this->addSql("DELETE FROM number_range WHERE name LIKE 'document.%' AND last_number = 0");
    }
}
