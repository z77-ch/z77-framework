<?php

declare(strict_types=1);

namespace Z77\Module\Debtor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-debtor — the SUBJECT LABEL «Betreffname» (owner decision 2026-10-21; the wdv
 * `subject_title`): `invoice.subject_label` — printed in front of the subject, «Projekt: …»,
 * part of the document's snapshot like `subject` — and `debtor_profile.default_subject_label`,
 * the debtor's own default a new order or invoice starts with (NULL = the installation's default,
 * the mandator's `default_subject_label`). Both VARCHAR(80) NULL (`SubjectLabel::LENGTH`).
 *
 * SCHEMA only, no data step: NULL = no label — every existing document prints exactly as before,
 * every existing debtor follows the installation's default. Expand only (ADR-039 decision 14).
 */
final class Version20261021100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-debtor: invoice.subject_label and debtor_profile.default_subject_label — the «Betreffname» (2026-10-21)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD subject_label VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE debtor_profile ADD default_subject_label VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE debtor_profile DROP default_subject_label');
        $this->addSql('ALTER TABLE invoice DROP subject_label');
    }
}
