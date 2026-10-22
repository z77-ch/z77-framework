<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — e-mail and phone in the document's ADDRESS SNAPSHOT (owner decision 2026-10-22,
 * step 1 of the mail plan): `AddressSnapshot` carries the address's e-mail and phone, so a document
 * is later e-mailed to the e-mail it was issued with (ADR-041 decision 6 — a booked document keeps
 * what it was issued with).
 *
 * `invoice.addr_email` VARCHAR(190) and `invoice.addr_phone` VARCHAR(40), NOT NULL DEFAULT '' —
 * the lengths of module-contact's `address.email` / `address.phone`, '' = none.
 *
 * SCHEMA only, no data step: every existing document reads «no e-mail, no phone» — nothing is
 * resolved again from the contact. Expand only (ADR-039 decision 14).
 */
final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice.addr_email and addr_phone — the snapshot\'s e-mail and phone (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE invoice ADD addr_email VARCHAR(190) DEFAULT '' NOT NULL, ADD addr_phone VARCHAR(40) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP addr_email, DROP addr_phone');
    }
}
