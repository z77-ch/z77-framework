<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;

/**
 * The TEXT LAYER of a creditor invoice's PDF as the job read it (owner 2026-11-16, learning per
 * creditor). pdftotext runs only in the CLI job (CREDITOR-RECOG-WEB-001 — the web PHP may not start
 * processes), but the creditor learns when the OWNER settles an invoice in a web request (save,
 * «Kreditor übernehmen …»); so the job keeps the text it read here, and the learning reads it back
 * — no second extraction, no deferred learning step. Its own table, not a column of
 * `creditor_invoice`, so a list of invoices never loads the texts.
 *
 * One row per invoice (`invoice_id` unique — no foreign key, as `document_id`), replaced by every
 * text step (`CreditorInvoiceService::applyRecognition()`), deleted with the invoice
 * (`CreditorInvoiceService::delete()`). The text of a scan is never stored (it has none). At most
 * {@see MAX_BYTES} — a longer one is cut at a character boundary. Table `creditor_invoice_text`
 * (`Version20261116100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_invoice_text')]
#[ORM\UniqueConstraint(name: 'uniq_creditor_invoice_text_invoice', columns: ['invoice_id'])]
class CreditorInvoiceText
{
    /** The most bytes kept — the header and the totals are on the first pages. */
    public const MAX_BYTES = 262_144;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'invoice_id')]
    private int $invoiceId;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $readAt;

    public function __construct(int $invoiceId, string $text, \DateTimeImmutable $readAt)
    {
        $this->invoiceId = $invoiceId;
        $this->replace($text, $readAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoiceId(): int { return $this->invoiceId; }
    public function getText(): string { return $this->text; }
    public function getReadAt(): \DateTimeImmutable { return $this->readAt; }

    /** A new reading of the same invoice («Jetzt erkennen»). */
    public function replace(string $text, \DateTimeImmutable $readAt): void
    {
        $this->text   = strlen($text) > self::MAX_BYTES ? mb_strcut($text, 0, self::MAX_BYTES, 'UTF-8') : $text;
        $this->readAt = $readAt;
    }
}
