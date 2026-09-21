<?php

namespace Z77\Module\Contact\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * A postal address as it is printed (plan §4a): addressee (salutation,
 * title, first name, name), an additional address row (department, c/o),
 * street and house number, zip, city, country. The addressee is part of the
 * address on purpose — a delivery address often names another person than
 * the contact itself.
 *
 * An address is reached only through a {@see ContactAddress} link, which
 * carries the TYPE (main, invoice, delivery, …) and a title. Several links
 * of one contact may point at the same address row (the wdv `Addressing`
 * shape); the service removes the row when the last link goes.
 *
 * Every document (quote, order, invoice, dunning notice) stores the address
 * it used as a SNAPSHOT — a later change here never alters an issued
 * document (§4a). This entity therefore has no history of its own.
 *
 * `country` is ISO 3166-1 alpha-2, upper-cased at the setter; the zip
 * format is validated per country ({@see \Z77\Module\Contact\Validators\AddressValidator}).
 *
 * Table `address`.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'address')]
class Address
{
    use ArrayMappable;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** «Herr», «Frau», «Firma» — free text, the installation's convention. */
    #[ORM\Column(length: 20)]
    #[Clean('text')]
    private string $salutation = '';

    /** Academic or professional title («Dr.»). */
    #[ORM\Column(length: 40)]
    #[Clean('text')]
    private string $title = '';

    #[ORM\Column(name: 'first_name', length: 70)]
    #[Clean('text')]
    private string $firstName = '';

    /** The addressee line: last name or company name. Required. */
    #[ORM\Column(length: 70)]
    #[Clean('text')]
    private string $name = '';

    /** Additional line: department, c/o, building. */
    #[ORM\Column(name: 'address_row', length: 120)]
    #[Clean('text')]
    private string $addressRow = '';

    #[ORM\Column(length: 120)]
    #[Clean('text')]
    private string $street = '';

    #[ORM\Column(name: 'house_no', length: 16)]
    #[Clean('text')]
    private string $houseNo = '';

    #[ORM\Column(length: 16)]
    #[Clean('text')]
    private string $zip = '';

    #[ORM\Column(length: 70)]
    #[Clean('text')]
    private string $city = '';

    /** ISO 3166-1 alpha-2, upper-case. */
    #[ORM\Column(length: 2)]
    #[Clean('ident')]
    private string $country = 'CH';

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
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

    public function setSalutation(string $salutation): void { $this->salutation = $salutation; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setFirstName(string $firstName): void { $this->firstName = $firstName; }
    public function setName(string $name): void { $this->name = $name; }
    public function setAddressRow(string $addressRow): void { $this->addressRow = $addressRow; }
    public function setStreet(string $street): void { $this->street = $street; }
    public function setHouseNo(string $houseNo): void { $this->houseNo = $houseNo; }
    public function setZip(string $zip): void { $this->zip = trim($zip); }
    public function setCity(string $city): void { $this->city = $city; }
    public function setCountry(string $country): void { $this->country = mb_strtoupper(trim($country)); }

    /** «Musterstrasse 12, 8000 Zürich» — the one-line phrase for lists (Rule 8). */
    public function oneLine(): string
    {
        $street = trim($this->street . ' ' . $this->houseNo);
        $place  = trim($this->zip . ' ' . $this->city);
        $parts  = array_filter([$street, $place], static fn(string $p) => $p !== '');
        $line   = implode(', ', $parts);
        if ($this->country !== '' && $this->country !== 'CH') {
            $line .= ($line === '' ? '' : ' ') . '(' . $this->country . ')';
        }

        return $line;
    }
}
