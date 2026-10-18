<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — the order's BILLING address snapshot (`bill_*`, debtor's `AddressSnapshot`
 * embeddable, the twin of `ship_*`; ADR-043 addendum 2026-10-16, built 2026-10-17): an address
 * change at the customer must not change an existing order — its PDFs and its booking print come
 * from the order. All columns empty = none chosen: the booking print takes the customer's invoice
 * address as before (debtor's `InvoicingService::invoiceAddressOf()`), so existing orders need no
 * data step — MariaDB fills the new NOT NULL columns of the existing rows with ''.
 *
 * Same definitions as the `ship_*` columns of `Version20261014140000`, so `z77-db diff` stays
 * quiet.
 */
final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: the billing address snapshot bill_* on sales_order (2026-10-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD bill_salutation VARCHAR(20) NOT NULL, ADD bill_title VARCHAR(40) NOT NULL, ADD bill_first_name VARCHAR(70) NOT NULL, ADD bill_name VARCHAR(70) NOT NULL, ADD bill_address_row VARCHAR(120) NOT NULL, ADD bill_street VARCHAR(120) NOT NULL, ADD bill_house_no VARCHAR(16) NOT NULL, ADD bill_zip VARCHAR(16) NOT NULL, ADD bill_city VARCHAR(70) NOT NULL, ADD bill_country VARCHAR(2) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP bill_salutation, DROP bill_title, DROP bill_first_name, DROP bill_name, DROP bill_address_row, DROP bill_street, DROP bill_house_no, DROP bill_zip, DROP bill_city, DROP bill_country');
    }
}
