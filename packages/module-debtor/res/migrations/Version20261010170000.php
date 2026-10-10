<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, tenth migration (owner 2026-10-10: «a printed invoice with
 * a number IS a document»): the document number is drawn at the BOOKING
 * PRINT (`InvoicingService::finalize()`), no longer when a draft is issued.
 * A draft in `invoicing` has no number — `invoice.number` becomes nullable.
 *
 * Data step: every document still in `invoicing` loses its number, and with
 * it the two payment-part columns derived from that number (`pay_reference`
 * — the QR reference carries the number at positions 17–26 — and
 * `pay_message`, «Rechnung 26100001»); both are written again when the
 * document is booked. Nothing of a FINAL document is touched — it is printed
 * and booked, its number stays.
 *
 * The number RANGES are NOT lowered here (persistence-doctrine: a range is
 * never set back by a migration — a number handed out may be printed
 * somewhere): the numbers the drafts carried are gaps in their month from
 * now on. On a development database they may be reset by hand (the z77.ch
 * dev database was, 2026-10-10 — `debtor.md`); on an installation that
 * printed drafts with numbers they stay gaps on purpose.
 *
 * Expand/contract (ADR-039 decision 14, addendum 2026-10-08): making the
 * column nullable is an expand — but the release BEFORE this one reads a
 * draft's number as a non-null `int` and would fail on a NULL. This
 * migration therefore must not run under a running previous release on the
 * same database (single-database deploy): deploy it with a copied database
 * (addendum (a)) or with nothing running. As of 2026-10-10 no installation
 * runs a release with module-debtor's documents, so no deploy is affected.
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
        return 'module-debtor: the document number is drawn at the booking print — invoice.number nullable, drafts unnumbered (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice CHANGE number number INT DEFAULT NULL');
        // A draft carries no number, and nothing derived from one (the QR reference, the message naming the document).
        $this->addSql("UPDATE invoice SET number = NULL, pay_reference = '', pay_message = '' WHERE state = 'invoicing'");
    }

    /**
     * Development only. A draft cannot get its old number back (the range has moved on, the number may be
     * someone else's by now): refused while any document is unnumbered — book or delete the drafts first.
     */
    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoice WHERE number IS NULL') > 0,
            'Drafts without a number exist — the column cannot become NOT NULL again. Book or remove them first (development only).'
        );
        $this->addSql('ALTER TABLE invoice CHANGE number number INT NOT NULL');
    }
}
