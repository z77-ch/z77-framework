<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor, third migration (P3 part 3; ADR-039 decisions 12–14): the
 * PAYMENT PART a document prints becomes columns of `invoice` — the pending
 * of `debtor.md` «the payment target and the reference become columns of
 * `invoice` when the PDF is built, so an issued document keeps what it
 * printed». Eleven `pay_*` columns, the embeddable `PaymentSnapshot`:
 * the payment target BY CODE (no foreign key, ADR-043 decision 19), the
 * account printed, the reference type (QRR | NON) and the reference, the
 * unstructured message, and the creditor block as it resolved.
 *
 * Expand only (decision 14): eleven new columns, nothing an older release
 * reads. `NOT NULL` without a default like the address snapshot's columns —
 * MariaDB fills existing rows with the empty string, which is exactly «no
 * payment part» (a document issued before this migration prints none until
 * it is re-issued; a final one never had a QR-bill in z77). Written against
 * the mapping and proven by `z77-db diff` reporting no change after
 * `migrate` (`tests/module-debtor.php` A11).
 */
final class Version20260930150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: the payment part of a document as columns of invoice (pay_*, P3 part 3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD pay_target_code VARCHAR(16) NOT NULL, ADD pay_account VARCHAR(21) NOT NULL, ADD pay_reference_type VARCHAR(3) NOT NULL, ADD pay_reference VARCHAR(27) NOT NULL, ADD pay_message VARCHAR(140) NOT NULL, ADD pay_creditor_name VARCHAR(120) NOT NULL, ADD pay_creditor_street VARCHAR(120) NOT NULL, ADD pay_creditor_house_no VARCHAR(16) NOT NULL, ADD pay_creditor_zip VARCHAR(16) NOT NULL, ADD pay_creditor_city VARCHAR(70) NOT NULL, ADD pay_creditor_country VARCHAR(2) NOT NULL');
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP pay_target_code, DROP pay_account, DROP pay_reference_type, DROP pay_reference, DROP pay_message, DROP pay_creditor_name, DROP pay_creditor_street, DROP pay_creditor_house_no, DROP pay_creditor_zip, DROP pay_creditor_city, DROP pay_creditor_country');
    }
}
