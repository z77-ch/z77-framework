<?php

namespace Z77\Module\Financial\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * One account of the chart (plan §5.1): a number, a name, a
 * {@see AccountType}, an optional parent for grouping and two flags.
 *
 * Grouping is a plain parent reference — no nested set, no path column. A
 * group header (KMU class `1`, main group `10`, group `100`) is an account
 * that is NOT `postable`: it carries no journal lines, it only collects
 * children for the reports. Only postable accounts will be accepted on a
 * journal line (P2 part 2, `LedgerService`). The parent of an account is
 * always a group (`AccountValidator`).
 *
 * An account is DEACTIVATED, never deleted: journal lines reference it
 * (part 2), and a deleted account would take the history with it. The
 * NUMBER is fixed once the account exists — settings and other modules'
 * configuration name accounts by number (plan §5.1, §5.4
 * `accountExists(number)`), and a renumbered account would silently repoint
 * every one of them (`AccountService::update()` refuses it).
 *
 * Table `account` — the mapping IS the table definition; the module's
 * migration creates it identically, so `z77-db diff` sees no change.
 * `ArrayMappable` serves the form path; the parent travels as the entity
 * under the key `parent` (the controller resolves the posted id). A MANAGED
 * account is changed only through `AccountService::update()`, which
 * validates a detached clone first (ADR-039 decision 9).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: Account::UNIQUE_NUMBER, columns: ['number'])]
class Account
{
    use ArrayMappable;

    /** Longest account number the column holds — the validator refuses longer ones. */
    public const NUMBER_LENGTH = 10;

    /** The unique index on `number` — `AccountService` recognises its violation by this name. */
    public const UNIQUE_NUMBER = 'uniq_account_number';

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /**
     * Digits only (KMU: one digit for a class up to four and more for an
     * account). Stored as a string: `1020` and `01020` are different numbers,
     * and string order is exactly the chart's order (`1` < `10` < `100` <
     * `1000` < `1020` < `11`).
     */
    #[ORM\Column(length: self::NUMBER_LENGTH)]
    #[Clean('text')]
    private string $number = '';

    #[ORM\Column(length: 120)]
    #[Clean('text')]
    private string $name = '';

    /** An {@see AccountType} value. Stored as its string so an unknown value is visible, not lost. */
    #[ORM\Column(length: 12)]
    #[Clean('ident')]
    private string $type = '';

    /** The group this account belongs to — always a non-postable account; null at the top of the chart. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true)]
    private ?Account $parent = null;

    /** False = a group header: collects children, carries no journal line. */
    #[ORM\Column]
    #[Clean('bool')]
    private bool $postable = true;

    /** False = no longer offered for new postings; existing journal lines keep resolving. */
    #[ORM\Column]
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getNumber(): string { return $this->number; }
    public function getName(): string { return $this->name; }
    public function getType(): string { return $this->type; }
    public function getParent(): ?Account { return $this->parent; }
    public function isPostable(): bool { return $this->postable; }
    public function isActive(): bool { return $this->active; }

    /** «1020 Bankguthaben» — how an account is named in every list and select (Rule 8). */
    public function label(): string
    {
        return $this->number . ' ' . $this->name;
    }

    public function setNumber(string $number): void { $this->number = trim($number); }
    public function setName(string $name): void { $this->name = $name; }
    public function setType(string $type): void { $this->type = mb_strtolower(trim($type)); }
    public function setParent(?Account $parent): void { $this->parent = $parent; }
    public function setPostable(bool $postable): void { $this->postable = $postable; }
    public function setActive(bool $active): void { $this->active = $active; }
}
