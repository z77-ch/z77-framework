<?php

declare(strict_types=1);

namespace Z77\Module\Mandator\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-mandator — the account of PAYMENT CHARGES (ADR-041 addendum 2026-11-24, point 15;
 * DEBTOR-FEE-KIND-001): `mandator.account_payment_charges`, where debtor's allocation kind
 * «Gebühren» posts the commission of a card terminal or a payment provider — an expense without a
 * tax code, the invoice's turnover and VAT stay untouched (MWST-Info 07, 1.1). The reader is
 * debtor's `DebtorAccounts` (key `charges`).
 *
 * VARCHAR(10) NOT NULL like the other account columns, DEFAULT '6940' (KMU «Bankspesen») — the
 * year opening's model (`Version20261022140000`): the number has been in the shipped chart from
 * the start, so the one existing record names it at once; a chart without it shows «prüfen» on the
 * mandator screen and the setup step «Mandant: Konten zuweisen» asks. Schema only, expand only.
 */
final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-mandator: mandator.account_payment_charges — the account of payment charges, debtor\'s allocation kind «Gebühren» (2026-11-24)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE mandator ADD account_payment_charges VARCHAR(10) DEFAULT '6940' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mandator DROP account_payment_charges');
    }
}
