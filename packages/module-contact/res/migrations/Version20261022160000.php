<?php

declare(strict_types=1);

namespace Z77\Module\Contact\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-contact — e-mail and phone of an ADDRESS (owner decision 2026-10-22, step 1 of the mail
 * plan): documents are e-mailed to the e-mail of the billing address, the newsletter to the
 * «Newsletter» address (else the main one). In wdv every address carried its own e-mail and phone;
 * the importer fills them.
 *
 * `address.email` VARCHAR(190) and `address.phone` VARCHAR(40), both NOT NULL DEFAULT '' — the
 * lengths of `contact.email` / `contact.phone`, '' = none. The DEFAULT lets a running older
 * release keep inserting addresses without the two columns.
 *
 * SCHEMA only, no data step. Expand only (ADR-039 decision 14).
 */
final class Version20261022160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-contact: address.email and address.phone (2026-10-22)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE address ADD email VARCHAR(190) DEFAULT '' NOT NULL, ADD phone VARCHAR(40) DEFAULT '' NOT NULL");
    }

    /** Development only — a release never drops what the running release reads (decision 14). */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP email, DROP phone');
    }
}
