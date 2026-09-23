<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\ORM\Mapping as ORM;
use Z77\Module\Contact\Entities\Address;

/**
 * The address a document SHOWS, frozen at the moment of issue (plan §4a:
 * «every document stores the address it used as a snapshot; later address
 * changes never alter an issued document» — the `contact.md` rule).
 *
 * The shape (closes `contact.md`'s pending «snapshot shape (P3)»): the ten
 * fields of module-contact's `Address`, one column each, as an EMBEDDABLE
 * with the column prefix the owning entity chooses (`addr_` on `invoice`).
 * Flat columns rather than a JSON blob for the same reason ADR-042 keeps
 * amounts in `DECIMAL`: a person with a query tool can read and search them
 * («every invoice to zip 8001»), and no reader has to parse anything. An
 * embeddable rather than ten properties on `Invoice`, because the dunning
 * notice (P4) and the quote (P7) snapshot the same ten fields — the shape
 * exists once (Rule 8). NO reference to `address` or `contact_address`: the
 * contact is referenced by id on the document, the address is copied.
 *
 * Value object: built from an `Address` or from its fields, read only. The
 * printed address block (line order, country prefix) arrives with the PDF
 * in part 3 — nothing prints before that.
 */
#[ORM\Embeddable]
final class AddressSnapshot
{
    #[ORM\Column(length: 20)]
    private string $salutation = '';

    #[ORM\Column(length: 40)]
    private string $title = '';

    #[ORM\Column(name: 'first_name', length: 70)]
    private string $firstName = '';

    #[ORM\Column(length: 70)]
    private string $name = '';

    #[ORM\Column(name: 'address_row', length: 120)]
    private string $addressRow = '';

    #[ORM\Column(length: 120)]
    private string $street = '';

    #[ORM\Column(name: 'house_no', length: 16)]
    private string $houseNo = '';

    #[ORM\Column(length: 16)]
    private string $zip = '';

    #[ORM\Column(length: 70)]
    private string $city = '';

    #[ORM\Column(length: 2)]
    private string $country = 'CH';

    public function __construct(
        string $salutation,
        string $title,
        string $firstName,
        string $name,
        string $addressRow,
        string $street,
        string $houseNo,
        string $zip,
        string $city,
        string $country,
    ) {
        $this->salutation = trim($salutation);
        $this->title      = trim($title);
        $this->firstName  = trim($firstName);
        $this->name       = trim($name);
        $this->addressRow = trim($addressRow);
        $this->street     = trim($street);
        $this->houseNo    = trim($houseNo);
        $this->zip        = trim($zip);
        $this->city       = trim($city);
        $this->country    = mb_strtoupper(trim($country));
    }

    /** The snapshot of a contact's address row as it reads NOW. */
    public static function of(Address $address): self
    {
        return new self(
            $address->getSalutation(),
            $address->getTitle(),
            $address->getFirstName(),
            $address->getName(),
            $address->getAddressRow(),
            $address->getStreet(),
            $address->getHouseNo(),
            $address->getZip(),
            $address->getCity(),
            $address->getCountry(),
        );
    }

    public function getSalutation(): string { return $this->salutation; }
    public function getTitle(): string { return $this->title; }
    public function getFirstName(): string { return $this->firstName; }
    public function getName(): string { return $this->name; }
    public function getAddressRow(): string { return $this->addressRow; }
    public function getStreet(): string { return $this->street; }
    public function getHouseNo(): string { return $this->houseNo; }
    public function getZip(): string { return $this->zip; }
    public function getCity(): string { return $this->city; }
    public function getCountry(): string { return $this->country; }

    /** A document needs at least a name and a place; the rest is optional. */
    public function isComplete(): bool
    {
        return $this->name !== '' && $this->zip !== '' && $this->city !== '' && $this->country !== '';
    }
}
