<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — the «Briefanrede» in the order's two ADDRESS SNAPSHOTS (owner decision 2026-11-13,
 * board «E-Mail-Standard»): debtor's `AddressSnapshot` carries the address's Briefanrede, so offer,
 * order confirmation and delivery note greet as the billing address says (`{briefanrede}`).
 *
 * `sales_order.ship_letter_salutation` / `bill_letter_salutation` VARCHAR(120) NOT NULL DEFAULT '' —
 * the embeddable's definition (debtor's `addr_letter_salutation`), '' = the default greeting.
 *
 * SCHEMA only, no data step. Expand only (ADR-039 decision 14).
 */
final class Version20261113150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: bill_/ship_ letter_salutation on sales_order (2026-11-13)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_order ADD ship_letter_salutation VARCHAR(120) DEFAULT '' NOT NULL, ADD bill_letter_salutation VARCHAR(120) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP ship_letter_salutation, DROP bill_letter_salutation');
    }
}
