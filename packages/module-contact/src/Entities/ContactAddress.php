<?php

namespace Z77\Module\Contact\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * Contact ↔ address with a TYPE and a title (plan §4a, the wdv `Addressing`
 * shape): «this is the invoice address of contact 17», «this is the
 * delivery address ‹Lager Ost›». n links per contact; two links may share
 * one {@see Address} row.
 *
 * The type is file-based master data ({@see AddressType}) referenced BY
 * `code` — no foreign key across the two drivers (ADR-043 decision 19).
 * A new link needs an ACTIVE type; an existing link keeps resolving after
 * its type was deactivated (history). The type's label is not snapshotted
 * here: this row is master data, not a document — documents snapshot the
 * whole address they used (§4a).
 *
 * Table `contact_address`, unique on (contact, address, type) so the same
 * address is not linked twice under one type. `contact` and `address` are
 * constructor arguments and never mapped from a form; `ArrayMappable` serves
 * `typeCode` and `title` only.
 *
 * The constructor does NOT attach the link to the contact's collection:
 * that is `Contact::addAddress()`, called by the builder of a new contact
 * or by `ContactService::addAddress()` AFTER validation — a link that was
 * refused must never sit in a managed collection (ADR-039 decision 9).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'contact_address')]
#[ORM\UniqueConstraint(name: ContactAddress::UNIQUE_CONTACT_ADDRESS_TYPE, columns: ['contact_id', 'address_id', 'type_code'])]
#[ORM\Index(name: 'idx_contact_address_type', columns: ['type_code'])]
class ContactAddress
{
    use ArrayMappable;

    /** Longest type code the column holds — the validator refuses longer ones. */
    public const TYPE_CODE_LENGTH = 16;

    /** The unique index on (contact, address, type) — `ContactService` recognises its violation by this name. */
    public const UNIQUE_CONTACT_ADDRESS_TYPE = 'uniq_contact_address_type';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Contact::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false)]
    private Contact $contact;

    /** Persisted with the link — a new address arrives attached to its first link. */
    #[ORM\ManyToOne(targetEntity: Address::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'address_id', nullable: false)]
    private Address $address;

    /** {@see AddressType::$code} — lower-case kebab, referenced by code. */
    #[ORM\Column(name: 'type_code', length: self::TYPE_CODE_LENGTH)]
    #[Clean('ident')]
    private string $typeCode = '';

    /** Free label that tells two addresses of one type apart («Lager Ost»). */
    #[ORM\Column(length: 80)]
    #[Clean('text')]
    private string $title = '';

    public function __construct(Contact $contact, string $typeCode, Address $address, string $title = '')
    {
        $this->contact = $contact;
        $this->address = $address;
        $this->setTypeCode($typeCode);
        $this->setTitle($title);
    }

    public function getId(): ?int { return $this->id; }
    public function getContact(): Contact { return $this->contact; }
    public function getAddress(): Address { return $this->address; }
    public function getTypeCode(): string { return $this->typeCode; }
    public function getTitle(): string { return $this->title; }

    public function setTypeCode(string $typeCode): void { $this->typeCode = AddressType::normalizeCode($typeCode); }
    public function setTitle(string $title): void { $this->title = $title; }
}
