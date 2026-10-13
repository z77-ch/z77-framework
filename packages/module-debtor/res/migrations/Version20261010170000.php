<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, ninth migration (owner 2026-10-10: «a printed invoice with
 * a number IS a document»): the document number is drawn at the BOOKING
 * PRINT (`InvoicingService::finalize()`), no longer when a draft is issued.
 * A draft in `invoicing` has no number — `invoice.number` becomes nullable.
 *
 * The SCHEMA step only. The data step — every draft loses its number and the
 * payment-part columns derived from it — is `Version20261010170001`, a file
 * of its own (review 2026-10-13, A6; owner decision of the same day): DDL
 * commits implicitly in MariaDB, so a data step in the same file would not
 * run in the migration's transaction; on its own it is atomic. Until
 * 2026-10-13 both were this one file — it had run on the z77.ch development
 * database only, whose `schema_migration` was given the row of the new data
 * file (its UPDATE had run with this file).
 *
 * Expand/contract (ADR-039 decision 14, addendum 2026-10-08): making the
 * column nullable is an expand — but the release BEFORE this one reads a
 * draft's number as a non-null `int` and would fail on a NULL (which the
 * data step writes). This pair therefore must not run under a running
 * previous release on the same database (single-database deploy): deploy it
 * with a copied database (addendum (a)) or with nothing running. As of
 * 2026-10-10 no installation runs a release with module-debtor's documents,
 * so no deploy is affected.
 *
 * The unique index `uniq_invoice_number` (and the former
 * `uniq_invoice_kind_number`) admit any number of NULLs in MariaDB — the
 * drafts do not collide.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261010170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: the document number is drawn at the booking print — invoice.number nullable (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice CHANGE number number INT DEFAULT NULL');
    }

    /**
     * Development only. A draft cannot get its old number back (the range has moved on, the number may be
     * someone else's by now): refused while any document is unnumbered — book or delete the drafts first.
     */
    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE number IS NULL') > 0,
            'Drafts without a number exist — the column cannot become NOT NULL again. Book or delete them first (development only).'
        );
        $this->addSql('ALTER TABLE invoice CHANGE number number INT NOT NULL');
    }
}
