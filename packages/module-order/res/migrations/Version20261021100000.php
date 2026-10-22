<?php

declare(strict_types=1);

namespace Z77\Module\Order\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-order — the SUBJECT LABEL «Betreffname» of an order (owner decision 2026-10-21; the wdv
 * `subject_title`): `sales_order.subject_label`, printed in front of the subject on the order
 * PDFs («Projekt: …») and carried into the invoice at the booking print. VARCHAR(80) NULL —
 * debtor's `SubjectLabel::LENGTH`, the one rule.
 *
 * SCHEMA only: NULL = no label — every existing order prints exactly as before. Expand only.
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-order: sales_order.subject_label — the «Betreffname» (2026-10-21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order ADD subject_label VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_order DROP subject_label');
    }
}
