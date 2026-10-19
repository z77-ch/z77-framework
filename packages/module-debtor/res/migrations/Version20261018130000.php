<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the debtor's PRICE MODE (owner decision 2026-10-18: «der Debitor trägt den
 * Preismodus»): `debtor_profile.price_mode` `net` | `gross`, module-vat's `PriceMode`. A new
 * order or invoice for the debtor starts with it; the document may deviate (its own
 * `price_mode` stays the truth of what it was priced in).
 *
 * SCHEMA only, no data step: the column default `'net'` fills every existing profile with what
 * every document had started with until now (an order always began `net`), and keeps a row that
 * an older release inserts without the column valid — expand only (ADR-039 decision 14). The
 * mapping carries the same default (`DebtorProfile::$priceMode`), so `z77-db diff` stays quiet.
 */
final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: debtor_profile.price_mode — net | gross, default net (2026-10-18)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE debtor_profile ADD price_mode VARCHAR(5) DEFAULT 'net' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor_profile DROP price_mode');
    }
}
