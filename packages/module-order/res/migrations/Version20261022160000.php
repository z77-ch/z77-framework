<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — e-mail and phone in the order's two ADDRESS SNAPSHOTS (owner decision 2026-10-22,
 * step 1 of the mail plan): debtor's `AddressSnapshot` carries the address's e-mail and phone, so
 * offer, order confirmation and delivery note are later e-mailed to the billing address's e-mail
 * stored on the order.
 *
 * `sales_order.bill_email` / `ship_email` VARCHAR(190) and `bill_phone` / `ship_phone`
 * VARCHAR(40), NOT NULL DEFAULT '' — the embeddable's definitions (debtor's `addr_*`), '' = none.
 *
 * SCHEMA only, no data step: every existing order reads «no e-mail, no phone». Expand only
 * (ADR-039 decision 14).
 */
final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: bill_/ship_ email and phone on sales_order (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales_order ADD ship_email VARCHAR(190) DEFAULT '' NOT NULL, ADD ship_phone VARCHAR(40) DEFAULT '' NOT NULL, ADD bill_email VARCHAR(190) DEFAULT '' NOT NULL, ADD bill_phone VARCHAR(40) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP ship_email, DROP ship_phone, DROP bill_email, DROP bill_phone');
    }
}
