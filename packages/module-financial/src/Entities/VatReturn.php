<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\DBAL\Types\Types,
    Doctrine\ORM\Mapping as ORM,
    Z77\Module\Financial\VatReturn\VatPeriod,
    Z77\Module\Mandator\Entities\VatConsideration,
    Z77\Module\Mandator\Entities\VatMethod,
    Z77\Module\Mandator\Entities\VatRhythm,
    Z77\Persistence\Doctrine\Mapping\WriteCounter,
    Z77\Persistence\Doctrine\Type\MoneyType,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Money\Money
;

/**
 * A POSTED VAT return (ADR-042 addendum 2026-11-24, points 1, 2 and 5; ADR-041 addendum points 1,
 * 2, 5, 7 and 14): what «MWST buchen» declared for one settlement period, snapshotted — the
 * period, the settings it was computed with, the form edition and the RESOLVED mapping, the
 * payable amount, the settlement entry, who posted it and the archived voucher. The declared
 * figures per field are {@see VatReturnFigure} rows, the protocol {@see VatReturnLog}.
 *
 * «Settled» is READ FROM these rows, never stored a second time: a period is settled while a
 * live return covers it — `VatReturn\VatSettlement` is the one reader.
 *
 * Every row is the return of ONE settlement period: z77 posts no correction return and no
 * Jahresabstimmung (ADR-042 addendum 2 of 2026-11-25, points 1 and 8), so the row names no kind —
 * the column `kind` was dropped by `Version20261126100000`.
 *
 *   - `revision` — 1 for the first return of a period, +1 for each one posted again after a lift
 *     (`UNIQUE (period_from, revision)`).
 *   - `live` — TRUE while `posted`, NULL once lifted: only there for `UNIQUE (period_from, live)`
 *     — NULLs do not collide, so at most ONE posted return per period, the database backstop
 *     under the locks.
 *   - `settlement_entry_id` — NULL for a NIL return (no coded line: the ledger needs two lines,
 *     each > 0, so there is no entry — the period is settled all the same, owner 2026-11-24).
 *   - no filed date — posted means filed (owner 2026-11-25: the return is filed in the ePortal
 *     first, the posting IS the filing; the column `filed_on` was dropped by
 *     `Version20261125200000`).
 *   - the fingerprints — what the poster confirmed: the figures (every field exact + the
 *     settlement lines) and the warnings (the close's shape).
 *   - the voucher — the PDF in financial's write-once archive: path, SHA-256, file name.
 *
 * Immutable except ONE named transition: {@see lift()} — it counts the write
 * ({@see WriteCounter}), so the optimistic lock moves even when no scalar changes
 * (DOCTRINE-VERSION-001). The one writer is `VatReturn\VatReturnPostingService` — post (slice
 * 5a), lift (slice 6a).
 *
 * Table `vat_return` (`Version20261124182954`; `Version20261125200000`, `Version20261126100000`).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'vat_return')]
#[ORM\UniqueConstraint(name: VatReturn::UNIQUE_REVISION, columns: ['period_from', 'revision'])]
#[ORM\UniqueConstraint(name: VatReturn::UNIQUE_LIVE, columns: ['period_from', 'live'])]
#[ORM\Index(name: 'idx_vat_return_live_to', columns: ['live', 'period_to'])]
class VatReturn
{
    use WriteCounter;

    /** Unique indexes — a writer recognises their violation by name. */
    public const UNIQUE_REVISION = 'uniq_vat_return_revision';
    public const UNIQUE_LIVE     = 'uniq_vat_return_live';

    public const EDITION_LENGTH      = 64;
    public const VOUCHER_PATH_LENGTH = 255;
    public const VOUCHER_NAME_LENGTH = 120;
    public const REASON_LENGTH       = 500;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'period_from', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(name: 'period_to', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    #[ORM\Column]
    private int $revision;

    /** A {@see VatReturnState} value. */
    #[ORM\Column(length: 12)]
    private string $state;

    /** TRUE while posted, NULL once lifted — for the unique index only. */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $live;

    /** A {@see VatRhythm} value at posting. */
    #[ORM\Column(length: 16)]
    private string $rhythm;

    /** A {@see VatMethod} value at posting. */
    #[ORM\Column(length: 16)]
    private string $method;

    /** A {@see VatConsideration} value at posting. */
    #[ORM\Column(length: 16)]
    private string $consideration;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    /** The form edition's name (`FormEdition::$name`). */
    #[ORM\Column(name: 'form_edition', length: self::EDITION_LENGTH)]
    private string $formEdition;

    /** SHA-256 of the rule file the edition was read from. */
    #[ORM\Column(name: 'mapping_hash', length: 64, options: ['fixed' => true])]
    private string $mappingHash;

    /** JSON: the RESOLVED assignments used — `[{code, category, rate, field, kind, input}]`. */
    #[ORM\Column(name: 'mapping_snapshot', type: Types::TEXT)]
    private string $mappingSnapshot;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    /** Field 500 − field 510, signed, rounded once — what the settlement entry posts. */
    #[ORM\Column(type: MoneyType::NAME)]
    private Money $payable;

    /** The tax the coded lines posted (`VatReturnForm::$postedTax`). */
    #[ORM\Column(name: 'posted_tax', type: MoneyType::NAME)]
    private Money $postedTax;

    /** The line on the VAT-return rounding account, signed. */
    #[ORM\Column(name: 'rounding_difference', type: MoneyType::NAME)]
    private Money $roundingDifference;

    /** The settlement entry — NULL for a nil return. */
    #[ORM\ManyToOne(targetEntity: JournalEntry::class)]
    #[ORM\JoinColumn(name: 'settlement_entry_id', nullable: true)]
    private ?JournalEntry $settlementEntry;

    /** The reversal the lift wrote — NULL while posted, and for a lifted nil return. */
    #[ORM\ManyToOne(targetEntity: JournalEntry::class)]
    #[ORM\JoinColumn(name: 'reversal_entry_id', nullable: true)]
    private ?JournalEntry $reversalEntry = null;

    #[ORM\Column(name: 'posted_by', length: JournalEntry::ACTOR_LENGTH)]
    private string $postedBy;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $postedAt;

    #[ORM\Column(name: 'lifted_by', length: JournalEntry::ACTOR_LENGTH, nullable: true)]
    private ?string $liftedBy = null;

    #[ORM\Column(name: 'lifted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    #[ORM\Column(name: 'lift_reason', length: self::REASON_LENGTH, nullable: true)]
    private ?string $liftReason = null;

    /** SHA-256 over every field's exact value and the settlement lines — what the poster saw. */
    #[ORM\Column(name: 'figures_fingerprint', length: 64, options: ['fixed' => true])]
    private string $figuresFingerprint;

    /** The confirmed warnings' fingerprint (`FiscalYearCloseService::warningsFingerprint()` shape); NULL = none. */
    #[ORM\Column(name: 'warnings_fingerprint', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $warningsFingerprint;

    /** JSON list of the confirmed warning sentences; NULL = none. */
    #[ORM\Column(name: 'confirmed_warnings', type: Types::TEXT, nullable: true)]
    private ?string $confirmedWarnings;

    #[ORM\Column(name: 'voucher_path', length: self::VOUCHER_PATH_LENGTH)]
    private string $voucherPath;

    #[ORM\Column(name: 'voucher_sha256', length: 64, options: ['fixed' => true])]
    private string $voucherSha256;

    #[ORM\Column(name: 'voucher_name', length: self::VOUCHER_NAME_LENGTH)]
    private string $voucherName;

    /** Optimistic lock: the lift from a stale screen. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /**
     * A return, posted.
     *
     * @param list<array<string, mixed>> $mappingSnapshot   the resolved assignments (code, category, rate, field, kind, input)
     * @param list<string>               $confirmedWarnings the messages of the warnings the poster confirmed
     */
    public function __construct(
        VatPeriod $period,
        int $revision,
        VatMethod $method,
        VatConsideration $consideration,
        string $country,
        string $formEdition,
        string $mappingHash,
        array $mappingSnapshot,
        Money $payable,
        Money $postedTax,
        Money $roundingDifference,
        ?JournalEntry $settlementEntry,
        string $postedBy,
        \DateTimeImmutable $postedAt,
        string $figuresFingerprint,
        ?string $warningsFingerprint,
        array $confirmedWarnings,
        string $voucherPath,
        string $voucherSha256,
        string $voucherName,
    ) {
        if ($revision < 1) {
            throw new \LogicException('A VAT return revision counts from 1');
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new \LogicException("A VAT return names its country pack by ISO code — '{$country}' given");
        }
        if ($formEdition === '' || mb_strlen($formEdition) > self::EDITION_LENGTH) {
            throw new \LogicException('A VAT return names its form edition (at most ' . self::EDITION_LENGTH . ' characters)');
        }
        foreach (['mapping hash' => $mappingHash, 'figures fingerprint' => $figuresFingerprint, 'voucher hash' => $voucherSha256] as $what => $hash) {
            self::assertHash($what, $hash);
        }
        if ($warningsFingerprint !== null) {
            self::assertHash('warnings fingerprint', $warningsFingerprint);
        }
        if ($payable->currency !== $postedTax->currency || $payable->currency !== $roundingDifference->currency) {
            throw new \LogicException('A VAT return is declared in one currency');
        }
        if ($voucherPath === '' || mb_strlen($voucherPath) > self::VOUCHER_PATH_LENGTH) {
            throw new \LogicException('A VAT return names its archived voucher (at most ' . self::VOUCHER_PATH_LENGTH . ' characters)');
        }
        $voucherName = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $voucherName)), 0, self::VOUCHER_NAME_LENGTH);
        if ($voucherName === '') {
            throw new \LogicException('A VAT return names its voucher file');
        }
        $confirmedWarnings = array_values(array_map(static fn(string $w): string => trim((string) preg_replace('/\s+/u', ' ', $w)), $confirmedWarnings));

        $this->periodFrom          = $period->from->setTime(0, 0);
        $this->periodTo            = $period->to->setTime(0, 0);
        $this->rhythm              = $period->rhythm->value;
        $this->revision            = $revision;
        $this->state               = VatReturnState::Posted->value;
        $this->live                = true;
        $this->method              = $method->value;
        $this->consideration       = $consideration->value;
        $this->country             = $country;
        $this->formEdition         = $formEdition;
        $this->mappingHash         = $mappingHash;
        $this->mappingSnapshot     = json_encode(array_values($mappingSnapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->currency            = $payable->currency;
        $this->payable             = $payable;
        $this->postedTax           = $postedTax;
        $this->roundingDifference  = $roundingDifference;
        $this->settlementEntry     = $settlementEntry;
        $this->postedBy            = mb_substr($postedBy, 0, JournalEntry::ACTOR_LENGTH);
        $this->postedAt            = $postedAt;
        $this->figuresFingerprint  = $figuresFingerprint;
        $this->warningsFingerprint = $warningsFingerprint;
        $this->confirmedWarnings   = $confirmedWarnings === [] ? null : json_encode($confirmedWarnings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->voucherPath         = $voucherPath;
        $this->voucherSha256       = $voucherSha256;
        $this->voucherName         = $voucherName;
    }

    /**
     * Lifts the return (ADR-042 addendum point 5): the row stays with its figures and voucher, the
     * period is open again. The service checks the rules first (admin, latest, year open) and
     * refuses with a reason; these guards are the domain's backstop.
     *
     * @param JournalEntry|null $reversal the reversal of the settlement entry — none for a nil return
     */
    public function lift(?JournalEntry $reversal, string $by, \DateTimeImmutable $at, string $reason): void
    {
        if ($this->state !== VatReturnState::Posted->value) {
            throw new \LogicException("VAT return {$this->id} is {$this->state} — only a posted return is lifted");
        }
        if (($reversal === null) !== ($this->settlementEntry === null)) {
            throw new \LogicException('A lift reverses the settlement entry exactly when the return has one');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LENGTH) {
            throw new \LogicException('A lift carries a reason of at most ' . self::REASON_LENGTH . ' characters');
        }
        $this->state         = VatReturnState::Lifted->value;
        $this->live          = null;
        $this->reversalEntry = $reversal;
        $this->liftedBy      = mb_substr($by, 0, JournalEntry::ACTOR_LENGTH);
        $this->liftedAt      = $at;
        $this->liftReason    = $reason;
        $this->countWrite();
    }

    public function getId(): ?int { return $this->id; }
    public function getPeriodFrom(): \DateTimeImmutable { return $this->periodFrom; }
    public function getPeriodTo(): \DateTimeImmutable { return $this->periodTo; }
    public function getRevision(): int { return $this->revision; }
    public function getState(): VatReturnState { return VatReturnState::from($this->state); }
    public function isLive(): bool { return $this->live === true; }
    public function getRhythm(): VatRhythm { return VatRhythm::from($this->rhythm); }
    public function getMethod(): VatMethod { return VatMethod::from($this->method); }
    public function getConsideration(): VatConsideration { return VatConsideration::from($this->consideration); }
    public function getCountry(): string { return $this->country; }
    public function getFormEdition(): string { return $this->formEdition; }
    public function getMappingHash(): string { return $this->mappingHash; }
    public function getCurrency(): string { return $this->currency; }
    public function getPayable(): Money { return $this->payable; }
    public function getPostedTax(): Money { return $this->postedTax; }
    public function getRoundingDifference(): Money { return $this->roundingDifference; }
    public function getSettlementEntry(): ?JournalEntry { return $this->settlementEntry; }
    public function getReversalEntry(): ?JournalEntry { return $this->reversalEntry; }
    public function getPostedBy(): string { return $this->postedBy; }
    public function getPostedAt(): \DateTimeImmutable { return $this->postedAt; }
    public function getLiftedBy(): ?string { return $this->liftedBy; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }
    public function getLiftReason(): ?string { return $this->liftReason; }
    public function getFiguresFingerprint(): string { return $this->figuresFingerprint; }
    public function getWarningsFingerprint(): ?string { return $this->warningsFingerprint; }
    public function getVoucherPath(): string { return $this->voucherPath; }
    public function getVoucherSha256(): string { return $this->voucherSha256; }
    public function getVoucherName(): string { return $this->voucherName; }
    public function getVersion(): int { return $this->version; }

    /** True for a nil return — posted without a settlement entry. */
    public function isNil(): bool
    {
        return $this->settlementEntry === null;
    }

    /**
     * The settlement period as snapshotted — `short` when it does not match the grid period of its
     * rhythm (cut by «Abrechnung ab» / «bis»).
     */
    public function period(): VatPeriod
    {
        return VatPeriod::stored($this->periodFrom, $this->periodTo, $this->getRhythm());
    }

    /** @return list<array<string, mixed>> the resolved assignments the return was computed with */
    public function getMappingSnapshot(): array
    {
        return json_decode($this->mappingSnapshot, true, 16, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function getConfirmedWarnings(): array
    {
        return $this->confirmedWarnings === null ? [] : json_decode($this->confirmedWarnings, true, 4, JSON_THROW_ON_ERROR);
    }

    private static function assertHash(string $what, string $hash): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new \LogicException("A VAT return's {$what} is a lower-case SHA-256 in hex");
        }
    }
}
