<?php

namespace Z77\Module\Contact\Entities;

use Doctrine\Common\Collections\ArrayCollection,
    Doctrine\Common\Collections\Collection,
    Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * The one party every business module shares (plan §4a, ADR-040): there is
 * no «order customer» and no «debtor customer» — there is one contact, and
 * each module takes the address it needs through a typed
 * {@see ContactAddress}. Module-specific data (payment terms, dunning block,
 * order defaults) stays in its module, keyed by this contact's id; this
 * entity knows none of it.
 *
 * Person or organisation ({@see ContactKind}): a person carries first and
 * last name, an organisation a company name — the validator decides which
 * is required, the other stays optional (a contact person at a company).
 *
 * A contact is DEACTIVATED, never deleted: documents in debtor and order
 * reference it by id and snapshot the address they used (§4a). Nothing in
 * this module removes a contact row.
 *
 * Table `contact` — the mapping IS the table definition; the module's first
 * migration (`res/migrations`) creates it identically, so `z77-db diff` sees
 * no change afterwards. `ArrayMappable` is used for the form path (BodyCleaner
 * → `mapFromArray()`, validator → `mapToArray()`), as on the File driver.
 * A MANAGED contact is changed only through `ContactService::update()`,
 * which validates a detached clone first (ADR-039 decision 9 — see the
 * service); `clone` therefore has to yield an object that shares nothing
 * writable with the original.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'contact')]
class Contact
{
    use ArrayMappable;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** A {@see ContactKind} value. Stored as its string so an unknown value is visible, not lost. */
    #[ORM\Column(length: 12)]
    #[Clean('ident')]
    private string $kind = ContactKind::Person->value;

    /** Required for an organisation; optional for a person (the company they work at). */
    #[ORM\Column(length: 120)]
    #[Clean('text')]
    private string $company = '';

    #[ORM\Column(name: 'first_name', length: 70)]
    #[Clean('text')]
    private string $firstName = '';

    /** Required for a person. */
    #[ORM\Column(name: 'last_name', length: 70)]
    #[Clean('text')]
    private string $lastName = '';

    /** ISO 639-1, lower-case — the language documents to this contact are written in. */
    #[ORM\Column(length: 5)]
    #[Clean('ident')]
    private string $language = '';

    #[ORM\Column(length: 190)]
    #[Clean('email')]
    private string $email = '';

    #[ORM\Column(length: 40)]
    #[Clean('text')]
    private string $phone = '';

    /** False = no longer offered for new documents; existing references keep resolving. */
    #[ORM\Column]
    #[Clean('bool')]
    private bool $active = true;

    /**
     * The typed addresses (inverse side). `cascade: persist` so a new contact
     * built with its addresses is written in ONE `persist()` + `flush()`
     * (the P1 exit criterion: a contact with n typed addresses).
     *
     * @var Collection<int, ContactAddress>
     */
    #[ORM\OneToMany(targetEntity: ContactAddress::class, mappedBy: 'contact', cascade: ['persist'])]
    private Collection $addresses;

    public function __construct(array $data = [])
    {
        $this->addresses = new ArrayCollection();
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /**
     * A clone is the service's detached DRAFT: it keeps the id but gets its
     * own, empty collection — the draft never touches the addresses, and sharing
     * Doctrine's PersistentCollection would let it.
     */
    public function __clone()
    {
        $this->addresses = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getCompany(): string { return $this->company; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getLanguage(): string { return $this->language; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): string { return $this->phone; }
    public function isActive(): bool { return $this->active; }

    public function isOrganisation(): bool
    {
        return $this->kind === ContactKind::Organisation->value;
    }

    /**
     * How the contact is named on a list: the company for an organisation
     * (with the contact person in brackets when there is one), otherwise
     * «Last First». One place for the phrase (Rule 8).
     */
    public function displayName(): string
    {
        $person = trim($this->lastName . ' ' . $this->firstName);
        if ($this->isOrganisation()) {
            return $this->company . ($person !== '' ? ' (' . $person . ')' : '');
        }

        return $person . ($this->company !== '' ? ' (' . $this->company . ')' : '');
    }

    /** @return list<ContactAddress> in insertion order */
    public function getAddresses(): array
    {
        return $this->addresses->getValues();
    }

    /**
     * Attaches a link built for this contact (the constructor of
     * `ContactAddress` only points back here, it never attaches). For a NEW
     * contact the caller attaches before `ContactService::save()`; for an
     * existing one `ContactService::addAddress()` attaches after validation
     * — a rejected link must not sit in a managed collection.
     */
    public function addAddress(ContactAddress $link): void
    {
        if ($link->getContact() !== $this) {
            throw new \LogicException('ContactAddress belongs to another contact');
        }
        if (!$this->addresses->contains($link)) {
            $this->addresses->add($link);
        }
    }

    /** Detaches a link from the collection; the row itself is removed by the service. */
    public function removeAddress(ContactAddress $link): void
    {
        $this->addresses->removeElement($link);
    }

    public function setKind(string $kind): void { $this->kind = trim($kind); }
    public function setCompany(string $company): void { $this->company = $company; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function setLastName(string $lastName): void { $this->lastName = $lastName; }
    public function setLanguage(string $language): void { $this->language = mb_strtolower(trim($language)); }
    public function setEmail(string $email): void { $this->email = $email; }
    public function setPhone(string $phone): void { $this->phone = $phone; }
    public function setActive(bool $active): void { $this->active = $active; }
}
