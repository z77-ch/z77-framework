<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — the order's PAYMENT TERMS (`payment_terms_code`, debtor's `PaymentTerms` BY CODE,
 * like `payment_target_code`; owner decision 2026-10-18: block (3) of the order window offers
 * «Zahlungskonditionen» — the days —, the bank account «Zahlungsziel» stays apart). NULL = «wie beim
 * Kunden»: the booking print takes the customer's debtor terms, exactly as before — existing orders
 * need no data step.
 *
 * Same definition as `payment_target_code` (`SalesOrder::TERMS_LENGTH` = 16), so `z77-db diff`
 * stays quiet.
 */
final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: the payment terms payment_terms_code on sales_order (2026-10-18)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD payment_terms_code VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP payment_terms_code');
    }
}
