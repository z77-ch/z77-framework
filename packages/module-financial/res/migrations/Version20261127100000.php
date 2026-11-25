<?php

declare(strict_types=1);

namespace Z77\Module\Financial\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-financial — the correction entry and the block «Korrekturen früherer Perioden» (VAT
 * return stage S3; ADR-042 addendum 2 of 2026-11-25, points 1 and 2; addendum 3, points 5 and 6).
 *
 *   - `journal_entry.vat_period_from` / `vat_period_to` / `vat_period_rhythm` — the MARKER of a
 *     «Korrekturbuchung zu Periode X»: the settled VAT period a manual entry of the running period
 *     corrects; NULL on every existing and every other entry. Index `idx_journal_entry_vat_period`
 *     (`vat_period_from`) — «which entries correct period X?»;
 *   - `vat_return_correction` — the block stored with the return that carries it: per corrected
 *     period and form field the exact figure as declared, the earlier corrections, this one, the
 *     bases of a rate field, the correction entries; FKs to the carrying and to the corrected return.
 *
 * Expand only (ADR-039 decision 14): three nullable columns, an index and a new table; no data step
 * — no entry is a correction entry before this release.
 */
final class Version20261127100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-financial: journal_entry.vat_period_from / _to / _rhythm (the correction entry), vat_return_correction (the block of a posted return) — VAT return stage S3, 2026-11-27';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE journal_entry ADD vat_period_from DATE DEFAULT NULL, ADD vat_period_to DATE DEFAULT NULL, ADD vat_period_rhythm VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_journal_entry_vat_period ON journal_entry (vat_period_from)');
        $this->addSql('CREATE TABLE vat_return_correction (id INT AUTO_INCREMENT NOT NULL, corrected_from DATE NOT NULL, corrected_to DATE NOT NULL, position INT NOT NULL, field VARCHAR(8) NOT NULL, kind VARCHAR(16) NOT NULL, original_exact NUMERIC(21, 6) NOT NULL, earlier_exact NUMERIC(21, 6) NOT NULL, correction_exact NUMERIC(21, 6) NOT NULL, original_base NUMERIC(15, 2) DEFAULT NULL, earlier_base NUMERIC(15, 2) DEFAULT NULL, correction_base NUMERIC(15, 2) DEFAULT NULL, entries LONGTEXT NOT NULL, vat_return_id INT NOT NULL, corrected_return_id INT NOT NULL, INDEX idx_vat_return_correction_period (corrected_from, corrected_to), UNIQUE INDEX uniq_vat_return_correction_field (vat_return_id, corrected_from, field), INDEX IDX_8686AF20640B3B73 (vat_return_id), INDEX IDX_8686AF20CCFE1798 (corrected_return_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE vat_return_correction ADD CONSTRAINT FK_8686AF20640B3B73 FOREIGN KEY (vat_return_id) REFERENCES vat_return (id)');
        $this->addSql('ALTER TABLE vat_return_correction ADD CONSTRAINT FK_8686AF20CCFE1798 FOREIGN KEY (corrected_return_id) REFERENCES vat_return (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vat_return_correction DROP FOREIGN KEY FK_8686AF20640B3B73');
        $this->addSql('ALTER TABLE vat_return_correction DROP FOREIGN KEY FK_8686AF20CCFE1798');
        $this->addSql('DROP TABLE vat_return_correction');
        $this->addSql('DROP INDEX idx_journal_entry_vat_period ON journal_entry');
        $this->addSql('ALTER TABLE journal_entry DROP vat_period_from, DROP vat_period_to, DROP vat_period_rhythm');
    }
}
