<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the filed date of a posted VAT return is dropped (owner decision of
 * 2026-11-25, «posted means filed»): the user files the return in the ESTV ePortal first, then
 * posts it in z77 — the posting IS the filing. No field «Eingereicht am», no filed state, no log
 * actions `filed` / `unfiled`; lifting a return no longer depends on a filed date.
 *
 * `up()` drops `vat_return.filed_on` (created by `Version20261124182954`).
 *
 * `down()` is honest but not lossless: it re-adds the column empty (`DATE DEFAULT NULL`, at its
 * original place after `rounding_difference`) — the dates the column held before `up()` are gone.
 */
final class Version20261125200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: vat_return.filed_on dropped — posted means filed (VAT return stage S2, owner 2026-11-25)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return DROP filed_on');
    }

    /** Development only — the column comes back EMPTY; the filed dates it held are lost. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return ADD filed_on DATE DEFAULT NULL AFTER rounding_difference');
    }
}
