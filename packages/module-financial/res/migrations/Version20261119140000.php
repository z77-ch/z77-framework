<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the period columns of `bank_statement` renamed: `from_date` → `period_from`,
 * `to_date` → `period_to` (fixed 2026-11-19).
 *
 * Why: MariaDB 12.3 made TO_DATE a parser-level keyword (MDEV-19683, the Oracle-compatible
 * TO_DATE()); an unquoted `to_date` is a syntax error there (1064). A fresh install on MariaDB 13
 * failed in `Version20261116140000`, which now quotes the name. The rename removes the keyword from
 * the schema for good, so no raw SQL, report or later migration has to remember the quote — and an
 * existing installation keeps working when its host upgrades MariaDB. `from_date` is no keyword; it
 * is renamed with it so the pair reads as one.
 *
 * A rename in ONE step, not expand/contract (ADR-039 decision 14, deviation stated here): the table
 * is internal to the bank account service (no other module, no API, no export reads the columns),
 * and its data is a few rows per account. Expand/contract would need a release that writes both
 * pairs, because the old columns are NOT NULL without a default. With the single-database deploy,
 * `current` cannot read or import a bank statement between this migration and the switch — do not
 * import one in that window; with the copied-database deploy (ADR-039 addendum 2026-10-08 (a))
 * there is no window. `CHANGE` keeps the data (ADR-039 addendum (a) 3: a rename, not drop + add).
 * `to_date` is quoted in both directions: the statements must run on MariaDB 12.3+ as well.
 */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_statement.from_date / to_date renamed to period_from / period_to — TO_DATE is a keyword since MariaDB 12.3 (2026-11-19)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_statement CHANGE from_date period_from DATE NOT NULL, CHANGE `to_date` period_to DATE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_statement CHANGE period_from from_date DATE NOT NULL, CHANGE period_to `to_date` DATE NOT NULL');
    }
}
