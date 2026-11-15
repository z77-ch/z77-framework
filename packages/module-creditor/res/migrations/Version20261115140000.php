<?php

declare(strict_types=1);

namespace Z77\Module\Creditor\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * module-creditor, stage 2 fix (owner 2026-11-15, creditor.md CREDITOR-RECOG-WEB-001): the
 * recognition never runs in a web request — the intake marks an invoice «Erkennung ausstehend»
 * and the job `creditor-intake` recognises it. `creditor_invoice.recognition_pending_at` holds the
 * mark (NULL = nothing waits), indexed for the job's «oldest pending first».
 *
 * Expand only (ADR-039 decision 14): one nullable column and its index — the code before this
 * migration never reads it, existing rows wait for nothing. The Doctrine-named index is kept so
 * `z77-db diff` stays clean.
 */
final class Version20261115140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'module-creditor: creditor_invoice.recognition_pending_at — the recognition runs in the job only (2026-11-15)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE creditor_invoice ADD recognition_pending_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_creditor_invoice_recognition_pending ON creditor_invoice (recognition_pending_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_creditor_invoice_recognition_pending ON creditor_invoice');
        $this->addSql('ALTER TABLE creditor_invoice DROP recognition_pending_at');
    }
}
