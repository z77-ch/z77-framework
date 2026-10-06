<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, fourth migration (owner 2026-10-06; ADR-039 decisions
 * 12–14): the CUSTOMER NUMBER of a debtor — `debtor_profile.customer_number`,
 * unique (`uniq_debtor_profile_number`), drawn from the new gapless range
 * `customer` by `DebtorProfileService::save()`. It is the second field of the
 * QR reference (positions 11–16, `Invoicing\QrReference`), the wdv-630
 * layout.
 *
 * Expand only (decision 14): one column, one index, one range row. The
 * column is added `NOT NULL` without a default — MariaDB fills existing rows
 * with 0 — and the EXISTING profiles are then numbered in id order (1000,
 * 1001, 1002 …) in one statement, before the unique index goes on; the
 * range starts where the backfill ended, so the next profile continues the
 * sequence. **Customer numbers start at 1000** (owner 2026-10-06: a
 * four-digit number from the first customer; the numbers of an existing
 * business come with the wdv import, which assigns them explicitly and
 * raises the range above them — `debtor.md` pending).
 * The range row is created here, at 0, with the statement
 * `NumberRangeRepository::create()` runs (DOCTRINE-NR-003: a range exists
 * and is committed before anyone draws from it; the `Version20260923043935`
 * model) and then raised to the highest number handed out — `GREATEST`, so
 * a re-run after a development rollback never lowers a range that kept
 * drawing. `down()` removes the range only while nothing was drawn (the
 * `dropUnused()` rule): once a number was printed on a QR-bill it is
 * referenced by the bank's records, and the sequence must not start at 1
 * again.
 *
 * Written against the mapping; `z77-db diff` after `migrate` reports no
 * change (`tests/module-debtor.php` A11).
 */
final class Version20261006100000 extends AbstractMigration
{
    /** The first customer number handed out (owner 2026-10-06). */
    public const FIRST_NUMBER = 1000;

    public function getDescription(): string
    {
        return 'module-debtor: the customer number of a debtor profile and the range customer (owner 2026-10-06)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor_profile ADD customer_number INT NOT NULL');
        // Existing profiles get their numbers in id order — the oldest debtor is number 1000 (FIRST_NUMBER).
        $this->addSql('UPDATE debtor_profile p JOIN (SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS n FROM debtor_profile) r ON r.id = p.id SET p.customer_number = r.n + ' . (self::FIRST_NUMBER - 1));
        $this->addSql('CREATE UNIQUE INDEX uniq_debtor_profile_number ON debtor_profile (customer_number)');

        // The range, created ahead of the first draw (DOCTRINE-NR-003), then set to where the backfill ended — at least FIRST_NUMBER - 1.
        $this->addSql("INSERT INTO number_range (name, last_number) VALUES ('customer', 0) ON DUPLICATE KEY UPDATE last_number = last_number");
        $this->addSql("UPDATE number_range SET last_number = GREATEST(last_number, (SELECT COALESCE(MAX(customer_number), " . (self::FIRST_NUMBER - 1) . ") FROM debtor_profile)) WHERE name = 'customer'");
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_debtor_profile_number ON debtor_profile');
        $this->addSql('ALTER TABLE debtor_profile DROP customer_number');
        // Only while nothing was drawn — a number handed out is printed on a QR-bill (the `dropUnused()` rule).
        $this->addSql("DELETE FROM number_range WHERE name = 'customer' AND last_number = 0");
    }
}
