<?php

namespace Z77\Module\Order\Entities;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\AddressSnapshot;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Vat\Calculation\PriceMode;
use Z77\Persistence\Doctrine\Type\MoneyType;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Money\Money;

/**
 * A sales order — the HEAD of a quote or an order (plan §4.3, owner O1 / D1): quote and order are
 * ONE record, the PHASE of its status says which (`new` = a quote). The office edits the order; the invoice is
 * born `final` at the booking print from it (a separate record, linked here — D5).
 *
 *   - `number` — the simple running number of the range `order` (owner O2, `NumberRange`), drawn at
 *     the FIRST save and kept when the quote becomes an order; unique;
 *   - `statusCode` — BY CODE into the file-based `OrderStatus` (ADR-043 decision 19, no foreign key);
 *     what the status MEANS is read from its flags, never from the code;
 *   - the customer is a CONTACT (FK) — a debtor profile is not required (a quote to a prospect,
 *     D9); the profile is opened at the booking print;
 *   - `ship` — the delivery address as a SNAPSHOT (debtor's `AddressSnapshot` embeddable, prefix
 *     `ship_`, Rule 9 reuse); all fields empty = no separate delivery address (the invoice address
 *     applies) — {@see deliveryAddress()};
 *   - `totalNet` / `totalTax` / `totalGross` — written by the one write path at each save from
 *     debtor's `InvoicingService::preview()` (D11: the totals are computed ONCE, by debtor), for
 *     the list only; null = the preview refuses the order as it stands (a line without tax code, …);
 *   - `invoice` — the booked invoice (D5: per ORDER, nullable, NOT unique — a later collective
 *     invoice points N orders at one invoice);
 *   - `sourceType` / `sourceRef` — the opaque origin (`backend` from the screen; an API or a
 *     subscription run later, plan §12);
 *   - `notes` — an internal remark, never printed.
 *
 * The timestamps of quote, confirmation, delivery and invoicing are DERIVED from the status log
 * ({@see SalesOrderStatusChange}, ADR-043 decision 4), never parallel columns. No line amount is
 * stored — what screen and PDF print comes from the preview (D11).
 *
 * Written ONLY by `Services\OrderService` (one write path, plan §5). Never deleted (D8).
 * Table `sales_order`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'sales_order')]
#[ORM\UniqueConstraint(name: SalesOrder::UNIQUE_NUMBER, columns: ['number'])]
#[ORM\Index(name: 'idx_sales_order_status', columns: ['status_code'])]
#[ORM\Index(name: 'idx_sales_order_date', columns: ['order_date'])]
class SalesOrder
{
    /** The gapless range the number is drawn from (owner O2, plan §4.3). */
    public const NUMBER_RANGE = 'order';

    public const UNIQUE_NUMBER = 'uniq_sales_order_number';

    public const STATUS_LENGTH      = 16;
    public const SUBJECT_LENGTH     = 200;
    public const CURRENCY_LENGTH    = 3;
    public const TARGET_LENGTH      = 16;
    public const NOTES_LENGTH       = 2000;
    public const SOURCE_TYPE_LENGTH = 64;
    public const SOURCE_REF_LENGTH  = 128;
    public const ACTOR_LENGTH       = 80;

    /** The origin of an order captured on the backend screen. */
    public const SOURCE_BACKEND = 'backend';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column]
    private int $number;

    /** `OrderStatus::$code` — by code, no foreign key (ADR-043 decision 19). */
    #[ORM\Column(name: 'status_code', length: self::STATUS_LENGTH)]
    private string $statusCode;

    #[ORM\Column(length: self::SUBJECT_LENGTH)]
    private string $subject = '';

    #[ORM\Column(name: 'order_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $orderDate;

    /** The service period; empty = the invoice date at the booking print. */
    #[ORM\Column(name: 'service_from', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $serviceFrom = null;

    #[ORM\Column(name: 'service_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $serviceTo = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false)]
    private Contact $contact;

    /** `net` | `gross` — module-vat's `PriceMode`. */
    #[ORM\Column(name: 'price_mode', length: 5)]
    private string $priceMode = 'net';

    /** The base currency, carried like the invoice's (ADR-042 decision 4). */
    #[ORM\Column(length: self::CURRENCY_LENGTH)]
    private string $currency = '';

    /** The payment target the booked invoice prints its payment part for (by code); null = none. */
    #[ORM\Column(name: 'payment_target_code', length: self::TARGET_LENGTH, nullable: true)]
    private ?string $paymentTargetCode = null;

    /** The delivery address snapshot; all empty = none ({@see deliveryAddress()}). */
    #[ORM\Embedded(class: AddressSnapshot::class, columnPrefix: 'ship_')]
    private AddressSnapshot $ship;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'total_net', type: MoneyType::NAME, nullable: true)]
    private ?Money $totalNet = null;

    #[ORM\Column(name: 'total_tax', type: MoneyType::NAME, nullable: true)]
    private ?Money $totalTax = null;

    #[ORM\Column(name: 'total_gross', type: MoneyType::NAME, nullable: true)]
    private ?Money $totalGross = null;

    /** The booked invoice (D5) — set by the booking print; NOT unique (a later collective invoice). */
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', nullable: true)]
    private ?Invoice $invoice = null;

    #[ORM\Column(name: 'source_type', length: self::SOURCE_TYPE_LENGTH)]
    private string $sourceType;

    #[ORM\Column(name: 'source_ref', length: self::SOURCE_REF_LENGTH, nullable: true)]
    private ?string $sourceRef;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'changed_by', length: self::ACTOR_LENGTH, nullable: true)]
    private ?string $changedBy = null;

    #[ORM\Column(name: 'changed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $changedAt = null;

    /** Optimistic lock: every write names the version its caller saw (`OrderService`). */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    /** @var Collection<int, SalesOrderLine> */
    #[ORM\OneToMany(targetEntity: SalesOrderLine::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    public function __construct(
        int $number,
        Contact $contact,
        string $statusCode,
        string $sourceType,
        ?string $sourceRef,
        string $createdBy,
        \DateTimeImmutable $createdAt,
    ) {
        if ($number < 1) {
            throw new \LogicException('An order number starts at 1');
        }
        $this->number     = $number;
        $this->contact    = $contact;
        $this->statusCode = $statusCode;
        $this->sourceType = $sourceType;
        $this->sourceRef  = $sourceRef;
        $this->createdBy  = $createdBy;
        $this->createdAt  = $createdAt;
        $this->orderDate  = $createdAt->setTime(0, 0);
        $this->ship       = self::noAddress();
        $this->lines      = new ArrayCollection();
    }

    /** The empty snapshot — «no separate delivery address». */
    public static function noAddress(): AddressSnapshot
    {
        return new AddressSnapshot('', '', '', '', '', '', '', '', '', '');
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): int { return $this->number; }
    public function getStatusCode(): string { return $this->statusCode; }
    public function getSubject(): string { return $this->subject; }
    public function getOrderDate(): \DateTimeImmutable { return $this->orderDate; }
    public function getServiceFrom(): ?\DateTimeImmutable { return $this->serviceFrom; }
    public function getServiceTo(): ?\DateTimeImmutable { return $this->serviceTo; }
    public function getContact(): Contact { return $this->contact; }
    public function getCurrency(): string { return $this->currency; }
    public function getPaymentTargetCode(): ?string { return $this->paymentTargetCode; }
    public function getNotes(): ?string { return $this->notes; }
    public function getTotalNet(): ?Money { return $this->totalNet; }
    public function getTotalTax(): ?Money { return $this->totalTax; }
    public function getTotalGross(): ?Money { return $this->totalGross; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getSourceType(): string { return $this->sourceType; }
    public function getSourceRef(): ?string { return $this->sourceRef; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getChangedBy(): ?string { return $this->changedBy; }
    public function getChangedAt(): ?\DateTimeImmutable { return $this->changedAt; }
    public function getVersion(): int { return $this->version; }

    public function priceMode(): PriceMode
    {
        return PriceMode::from($this->priceMode);
    }

    /** The delivery address snapshot, or null when none was chosen (the invoice address applies). */
    public function deliveryAddress(): ?AddressSnapshot
    {
        return $this->ship->lines() === [] && $this->ship->getCountry() === '' ? null : $this->ship;
    }

    /** The «Nr.» the screens and PDFs print — the bare number (owner O2). */
    public function documentNumber(): string
    {
        return (string) $this->number;
    }

    /** @return list<SalesOrderLine> in document order (children directly after their parent) */
    public function getLines(): array
    {
        $lines = $this->lines->toArray();
        usort($lines, static fn(SalesOrderLine $a, SalesOrderLine $b) => $a->getPosition() <=> $b->getPosition());

        return array_values($lines);
    }

    /**
     * The head as the one write path sets it — called AFTER the draft passed its rules (ADR-039
     * decision 9: a managed entity is never mutated before validation).
     */
    public function describe(
        Contact $contact,
        string $subject,
        \DateTimeImmutable $orderDate,
        ?\DateTimeImmutable $serviceFrom,
        ?\DateTimeImmutable $serviceTo,
        PriceMode $priceMode,
        string $currency,
        ?string $paymentTargetCode,
        ?AddressSnapshot $deliveryAddress,
        ?string $notes,
    ): void {
        $this->contact           = $contact;
        $this->subject           = $subject;
        $this->orderDate         = $orderDate->setTime(0, 0);
        $this->serviceFrom       = $serviceFrom?->setTime(0, 0);
        $this->serviceTo         = $serviceTo?->setTime(0, 0);
        $this->priceMode         = $priceMode->value;
        $this->currency          = $currency;
        $this->paymentTargetCode = $paymentTargetCode;
        $this->ship              = $deliveryAddress ?? self::noAddress();
        $this->notes             = $notes;
    }

    /** The status the order is in now — set by `OrderService` only, after the transition rule. */
    public function moveTo(string $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /** Totals from debtor's preview (D11); null = the preview refuses the order as it stands. */
    public function setTotals(?Money $net, ?Money $tax, ?Money $gross): void
    {
        $this->totalNet   = $net;
        $this->totalTax   = $tax;
        $this->totalGross = $gross;
    }

    /** The booked invoice — the booking print (step 9) sets it in the same unit of work. */
    public function linkInvoice(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function addLine(SalesOrderLine $line): void
    {
        if ($line->getOrder() !== $this) {
            throw new \LogicException('A line belongs to the order it was built for');
        }
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    /** Removes a line (orphan removal deletes its row at the flush). */
    public function removeLine(SalesOrderLine $line): void
    {
        $this->lines->removeElement($line);
    }

    /** Stamps who changed the order last — every write of an existing order, so its version moves. */
    public function touch(string $changedBy, \DateTimeImmutable $changedAt): void
    {
        $this->changedBy = $changedBy;
        $this->changedAt = $changedAt;
    }
}
