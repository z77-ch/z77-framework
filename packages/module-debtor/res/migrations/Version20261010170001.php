<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, tenth migration — the DATA step of `Version20261010170000`
 * (owner 2026-10-10: the document number is drawn at the booking print), in
 * a file of its own since 2026-10-13 (review A6: DDL commits implicitly in
 * MariaDB, so this UPDATE runs atomically only apart from the ALTER).
 *
 * Every document still in `invoicing` loses its number, and with it the two
 * payment-part columns derived from that number (`pay_reference` — the QR
 * reference carries the number at positions 17–26 — and `pay_message`,
 * «Rechnung 26100001»); both are written again when the document is booked.
 * Nothing of a FINAL document is touched — it is printed and booked, its
 * number stays. Idempotent: a second run finds no numbered draft.
 *
 * The number RANGES are NOT lowered here (persistence-doctrine: a range is
 * never set back by a migration — a number handed out may be printed
 * somewhere): the numbers the drafts carried are gaps in their month from
 * now on (DEBTOR-NR-003). On a development database they may be reset by
 * hand (the z77.ch dev database was, 2026-10-10 — `debtor.md`).
 *
 * The z77.ch development database ran this UPDATE as part of
 * `Version20261010170000` on 2026-10-10; its `schema_migration` carries
 * this file's row since 2026-10-13 (inserted by hand, not executed again).
 */
final class Version20261010170001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: drafts unnumbered — number, QR reference and message of every document in invoicing cleared (owner 2026-10-10)';
    }

    public function up(Schema $schema): void
    {
        // A draft carries no number, and nothing derived from one (the QR reference, the message naming the document).
        $this->addSql("UPDATE invoice SET number = NULL, pay_reference = '', pay_message = '' WHERE state = 'invoicing'");
    }

    /** Development only — the cleared numbers cannot come back (the ranges moved on); nothing to undo. */
    public function down(Schema $schema): void
    {
    }
}
