<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial, ninth migration — bank-driven bookkeeping, stage C4 (ADR-061 decisions 1–3,
 * 22–26, owner 2026-11-17): the date and the anchor.
 *
 *   - `bank_account.reconcile_from` — «Abgleich ab»: with «Kontoauszug einlesen» on, the bank
 *     account is bank-driven from this day (states, «Bezahlt am» only from the bank, the check,
 *     the close blocks). NULL = not at all — so EVERY existing bank account stays what it was;
 *   - `bank_anchor` — the confirmed difference bank ↔ ledger account at the opening of one day
 *     («Bank X · Fibu Y · Differenz Z»), one row per bank account and day.
 *
 * Expand only (ADR-039 decision 14): one nullable column and one new table; no existing value is
 * changed, nothing is derived from stored data. What the check reports is computed, not stored.
 */
final class Version20261117200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: bank_account.reconcile_from («Abgleich ab»), bank_anchor — the switch date, the anchor, the check (ADR-061 stage C4, 2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bank_anchor (id INT AUTO_INCREMENT NOT NULL, as_of DATE NOT NULL, bank_balance NUMERIC(15, 2) NOT NULL, ledger_balance NUMERIC(15, 2) NOT NULL, difference NUMERIC(15, 2) NOT NULL, confirmed_by VARCHAR(80) NOT NULL, confirmed_at DATETIME NOT NULL, bank_account_id INT NOT NULL, INDEX IDX_47343E0012CB990C (bank_account_id), UNIQUE INDEX uniq_bank_anchor_day (bank_account_id, as_of), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE bank_anchor ADD CONSTRAINT FK_47343E0012CB990C FOREIGN KEY (bank_account_id) REFERENCES bank_account (id)');
        $this->addSql('ALTER TABLE bank_account ADD reconcile_from DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank_anchor DROP FOREIGN KEY FK_47343E0012CB990C');
        $this->addSql('DROP TABLE bank_anchor');
        $this->addSql('ALTER TABLE bank_account DROP reconcile_from');
    }
}
