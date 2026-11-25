<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — `vat_return.kind` dropped (ADR-042 addendum 2 of 2026-11-25, point 8: «`kind`
 * goes before the first deploy»). z77 posts no correction return (point 1 there) and no
 * Jahresabstimmung (point 8): every row is the return of ONE settlement period, nothing ever wrote
 * a kind other than `ordinary`, and the enum of the kinds is deleted with the column.
 *
 * `up()` — in ONE `ALTER TABLE`, so the table is never without its unique backstop: the three
 * indexes that `kind` led (created by `Version20261124182954`) are dropped, the column is dropped,
 * and the three are rebuilt under their existing names without it:
 *
 *   - `uniq_vat_return_revision` — UNIQUE (period_from, revision);
 *   - `uniq_vat_return_live`     — UNIQUE (period_from, live): at most ONE posted return per period;
 *   - `idx_vat_return_live_to`   — (live, period_to): «settled» and «the latest live return».
 *
 * A contract step in one release, not expand/contract (ADR-039 decision 14): no release that
 * writes `vat_return` has been deployed — the VAT return goes onto a server only as a whole
 * (ADR-042 addendum 2, point 11) —, so no running release reads or writes the column.
 *
 * `down()` is honest: the column comes back at its place after `id`, every existing row as
 * `ordinary` (the only kind ever written), then without a default as it was created; the three
 * indexes lead with `kind` again.
 */
final class Version20261126100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: vat_return.kind dropped, its three indexes rebuilt without it — z77 posts no correction return and no Jahresabstimmung (VAT return stage S7 slice 7.0, owner 2026-11-25)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return'
            . ' DROP INDEX uniq_vat_return_revision, DROP INDEX uniq_vat_return_live, DROP INDEX idx_vat_return_live_to,'
            . ' DROP kind,'
            . ' ADD UNIQUE INDEX uniq_vat_return_revision (period_from, revision),'
            . ' ADD UNIQUE INDEX uniq_vat_return_live (period_from, live),'
            . ' ADD INDEX idx_vat_return_live_to (live, period_to)');
    }

    /** Development only — every row comes back as `ordinary`, the only kind ever written. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return'
            . ' DROP INDEX uniq_vat_return_revision, DROP INDEX uniq_vat_return_live, DROP INDEX idx_vat_return_live_to,'
            . " ADD kind VARCHAR(12) NOT NULL DEFAULT 'ordinary' AFTER id,"
            . ' ADD UNIQUE INDEX uniq_vat_return_revision (kind, period_from, revision),'
            . ' ADD UNIQUE INDEX uniq_vat_return_live (kind, period_from, live),'
            . ' ADD INDEX idx_vat_return_live_to (kind, live, period_to)');
        $this->addSql('ALTER TABLE vat_return ALTER kind DROP DEFAULT');
    }
}
