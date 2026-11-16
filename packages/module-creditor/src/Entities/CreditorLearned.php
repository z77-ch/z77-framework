<?php

namespace Z77\Module\Creditor\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Creditor\Recognition\LearnedLabels;
use Z77\Module\Creditor\Recognition\SenderFeatures;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Shared\Attributes\Entity;

/**
 * One thing a creditor LEARNED from its invoices (owner 2026-11-16, creditor.md «Learning per
 * creditor») — so the next invoice is recognised without IBAN or Swiss UID and the AI runs only
 * the first times. Two kinds of rows:
 *
 *   - a FEATURE of the sender ({@see SenderFeatures}): `kind` = `vat` | `domain` | `sender`,
 *     `value` the normalised feature (`NL854443364B01`, `tinify.com`, `tinify b.v.`) — learned
 *     when the owner settles the creditor of an invoice; matching takes a feature only when exactly
 *     one creditor holds it;
 *   - a LABEL ({@see LearnedLabels}): `kind` = `label`, `field` = invoice_number | invoice_date |
 *     due_date | amount, `value` the label as printed («Amount due»), `currency` (an amount: the
 *     currency it was printed in — A0: a foreign invoice's original, never the CHF the owner typed),
 *     `date_order` (a numeric date month-first: `mdy`), `agreed` — how often PHP's reading at the
 *     label and the AI's answer agreed since the label was (re)learned. At most one label per field
 *     (the service replaces it).
 *
 * `invoice_id` names the invoice it was learned from (no foreign key — the invoice may be deleted);
 * `learned_by` / `learned_at` who and when. Nothing references a row; «Gelerntes vergessen» in the
 * creditor window deletes them all. Table `creditor_learned` (`Version20261116100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'creditor_learned')]
#[ORM\UniqueConstraint(name: CreditorLearned::UNIQUE, columns: ['creditor_profile_id', 'kind', 'field', 'value'])]
#[ORM\Index(name: 'idx_creditor_learned_value', columns: ['kind', 'value'])]
class CreditorLearned
{
    public const UNIQUE = 'uniq_creditor_learned';

    public const KIND_LABEL = 'label';

    /** The longest feature or label ({@see SenderFeatures::MAX_LENGTH}). */
    public const VALUE_LENGTH = SenderFeatures::MAX_LENGTH;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CreditorProfile::class)]
    #[ORM\JoinColumn(name: 'creditor_profile_id', nullable: false)]
    private CreditorProfile $profile;

    #[ORM\Column(length: 8)]
    private string $kind;

    /** A label's field; '' for a feature. */
    #[ORM\Column(length: 16, options: ['default' => ''])]
    private string $field = '';

    /** Binary collation: compared exactly as stored (normalised by the service) — `ü` is never `u` here, so the unique index cannot refuse a value the service judged new. */
    #[ORM\Column(length: self::VALUE_LENGTH, options: ['collation' => 'utf8mb4_bin'])]
    private string $value;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(name: 'date_order', length: 3, nullable: true)]
    private ?string $dateOrder = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $agreed = 0;

    #[ORM\Column(name: 'invoice_id', nullable: true)]
    private ?int $invoiceId = null;

    #[ORM\Column(name: 'learned_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $learnedBy;

    #[ORM\Column(name: 'learned_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $learnedAt;

    public function __construct(CreditorProfile $profile, string $kind, string $field, string $value, ?int $invoiceId, string $learnedBy, \DateTimeImmutable $learnedAt, ?string $currency = null, ?string $dateOrder = null)
    {
        if (!in_array($kind, [...SenderFeatures::KINDS, self::KIND_LABEL], true) || ($kind === self::KIND_LABEL) !== in_array($field, LearnedLabels::FIELDS, true)) {
            throw new \LogicException("A learned row is a feature (vat / domain / sender) or a label of a field — not {$kind} / {$field}");
        }
        if (trim($value) === '' || mb_strlen($value) > self::VALUE_LENGTH) {
            throw new \LogicException('A learned value is not empty and at most ' . self::VALUE_LENGTH . ' characters');
        }
        $this->profile   = $profile;
        $this->kind      = $kind;
        $this->field     = $kind === self::KIND_LABEL ? $field : '';
        $this->value     = $value;
        $this->invoiceId = $invoiceId;
        $this->learnedBy = mb_substr($learnedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->learnedAt = $learnedAt;
        $this->currency  = $field === LearnedLabels::AMOUNT ? $currency : null;
        $this->dateOrder = $field === LearnedLabels::DATE || $field === LearnedLabels::DUE ? $dateOrder : null;
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): CreditorProfile { return $this->profile; }
    public function getKind(): string { return $this->kind; }
    public function getField(): string { return $this->field; }
    public function getValue(): string { return $this->value; }
    public function getCurrency(): ?string { return $this->currency; }
    public function getDateOrder(): ?string { return $this->dateOrder; }
    public function getAgreed(): int { return $this->agreed; }
    public function getInvoiceId(): ?int { return $this->invoiceId; }
    public function getLearnedBy(): string { return $this->learnedBy; }
    public function getLearnedAt(): \DateTimeImmutable { return $this->learnedAt; }
    public function isLabel(): bool { return $this->kind === self::KIND_LABEL; }

    /** PHP's reading at the label and the AI's answer agreed once more. */
    public function agree(): void
    {
        $this->agreed++;
    }

    /**
     * A label LEARNED AGAIN (the AI or the owner gave another value than PHP read at it): the new
     * label, its currency / date order, and the trust starts over. Updated in place — one label per
     * field, never a second row.
     */
    public function relabel(string $label, ?string $currency, ?string $dateOrder, ?int $invoiceId, string $learnedBy, \DateTimeImmutable $learnedAt): void
    {
        if (!$this->isLabel() || trim($label) === '' || mb_strlen($label) > self::VALUE_LENGTH) {
            throw new \LogicException('Only a label row is relabelled, with a label of at most ' . self::VALUE_LENGTH . ' characters');
        }
        $this->value     = $label;
        $this->currency  = $this->field === LearnedLabels::AMOUNT ? $currency : null;
        $this->dateOrder = $this->field === LearnedLabels::DATE || $this->field === LearnedLabels::DUE ? $dateOrder : null;
        $this->agreed    = 0;
        $this->invoiceId = $invoiceId;
        $this->learnedBy = mb_substr($learnedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->learnedAt = $learnedAt;
    }
}
