<?php

namespace Z77\Module\Debtor\Entities;

use Doctrine\ORM\Mapping as ORM,
    Z77\Module\Contact\Entities\Contact,
    Z77\Shared\Attributes\Clean,
    Z77\Shared\Attributes\Entity,
    Z77\Shared\Traits\ArrayMappable
;

/**
 * The debtor-specific part of a contact (plan §4a, §6.1): there is no
 * «debtor customer» — there is one {@see Contact}, and this row records
 * what RECEIVABLES need to know about it. Keyed by the contact's id, in
 * debtor's own table: `module-contact` knows no module (plan §2), so not one
 * column of this lives on `contact`.
 *
 * **At most one profile per contact** — `uniq_debtor_profile_contact`. The
 * validator asks first so the screen gets a field error; the index is what
 * decides under a race.
 *
 * What is on it in P3 part 1, and what deliberately is not:
 *
 *   - `paymentTermsCode` — the default payment terms of this debtor,
 *     referenced BY CODE (ADR-043 decision 19) into the file-based
 *     {@see PaymentTerms}. No foreign key: the row lives in `data/`, not in
 *     this database. A NEW reference needs an ACTIVE row, an existing one
 *     keeps a deactivated one ({@see \Z77\Module\Debtor\Validators\DebtorProfileValidator}).
 *   - `dunningBlock` — no dunning run may pick this debtor up (P4). A
 *     decision of the office, not a state derived from the open items.
 *   - **No language.** `Contact::$language` already says which language a
 *     document to this party is written in (plan §4a); a second field would
 *     be a second truth about one fact (Rule 2).
 *   - **No currency.** Q6 decided against foreign-currency invoicing, and
 *     §6.2 puts currency and rate on the DOCUMENT, not on the party — so
 *     the field would be an unread column with no caller (guiding rule:
 *     nothing in stock). Part 2 adds it to the invoice, where it is read.
 *
 * A profile is DEACTIVATED, never deleted: invoices and open items are
 * written against the party and the history must stay explainable. A MANAGED
 * profile is changed only through `DebtorProfileService::update()`, which
 * validates a detached clone first (ADR-039 decision 9).
 *
 * Table `debtor_profile` — the mapping IS the table definition; the module's
 * first migration creates it identically, so `z77-db diff` sees no change.
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'debtor_profile')]
#[ORM\UniqueConstraint(name: DebtorProfile::UNIQUE_CONTACT, columns: ['contact_id'])]
#[ORM\Index(name: 'idx_debtor_profile_terms', columns: ['payment_terms_code'])]
class DebtorProfile
{
    use ArrayMappable;

    /** The unique index on `contact_id` — `DebtorProfileService` recognises its violation by this name. */
    public const UNIQUE_CONTACT = 'uniq_debtor_profile_contact';

    /** Longest payment-terms code the column holds — the validator refuses longer ones. */
    public const CODE_LENGTH = 16;

    /** Server-controlled — no setter; the database assigns it. */
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    /** The party this profile belongs to — one profile per contact. */
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', nullable: false)]
    private ?Contact $contact = null;

    /** {@see PaymentTerms::$code} — by code, no foreign key (ADR-043 decision 19). */
    #[ORM\Column(name: 'payment_terms_code', length: self::CODE_LENGTH)]
    #[Clean('ident')]
    private string $paymentTermsCode = '';

    /** True = no dunning run picks this debtor up (P4). */
    #[ORM\Column(name: 'dunning_block')]
    #[Clean('bool')]
    private bool $dunningBlock = false;

    /** False = no longer offered for new documents; existing ones keep resolving. */
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
    public function getContact(): ?Contact { return $this->contact; }
    public function getPaymentTermsCode(): string { return $this->paymentTermsCode; }
    public function hasDunningBlock(): bool { return $this->dunningBlock; }
    public function isActive(): bool { return $this->active; }

    public function setContact(?Contact $contact): void { $this->contact = $contact; }
    public function setPaymentTermsCode(string $code): void { $this->paymentTermsCode = PaymentTerms::normalizeCode($code); }
    public function setDunningBlock(bool $block): void { $this->dunningBlock = $block; }
    public function setActive(bool $active): void { $this->active = $active; }
}
