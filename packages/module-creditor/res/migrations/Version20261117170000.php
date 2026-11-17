<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor, sixth migration — the line text («Kontierungstext») per creditor (owner
 * 2026-11-17): `creditor_profile.default_line_text` — the creditor's STANDARD line text, set by
 * «als Standard übernehmen» in the invoice window or typed in the creditor window; the recognition
 * always puts it on an empty line — and `creditor_profile.last_line_text` — the line text of the
 * creditor's invoice the owner saved last, the fallback when neither the standard nor the learned
 * column header gives a text. NULL = none.
 *
 * The column header itself is a row of `creditor_learned` (kind `label`, field `line_text`) — no
 * schema change there.
 *
 * Expand only (ADR-039 decision 14): two nullable columns, no data moved.
 */
final class Version20261117170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_profile.default_line_text and last_line_text — the line text per creditor (2026-11-17)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_profile ADD default_line_text VARCHAR(255) DEFAULT NULL, ADD last_line_text VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_profile DROP default_line_text, DROP last_line_text');
    }
}
