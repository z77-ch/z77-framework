<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the two accounts of bank-driven bookkeeping (ADR-061 decision 17, stage C4,
 * 2026-11-17): `mandator.account_private` («Privat» — Privatbezug / Privateinlage) and
 * `mandator.account_bank_transfer` (the transfer clearing account between two own business bank
 * accounts). They stood in `financialConfig → bankPosting` for one day (stage C2, never deployed)
 * and move here, where every other account of the company lives (Rule 2). The reader is
 * module-financial's `LedgerService::bankPostingAccount()`. VARCHAR(10) NOT NULL like the other
 * account columns, default '' — NO number is assumed: a chart may use the usual ones for
 * something else. Schema only, expand only.
 */
final class Version20261117190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.account_private / account_bank_transfer — the accounts of bank-driven bookkeeping (ADR-061 stage C4, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD account_private VARCHAR(10) DEFAULT '' NOT NULL, ADD account_bank_transfer VARCHAR(10) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP account_private, DROP account_bank_transfer');
    }
}
