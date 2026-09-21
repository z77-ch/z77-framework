<?php

namespace Z77\Module\Contact\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Core\DI;
use Z77\Module\Contact\Entities\AddressType;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Entities\ContactAddress;
use Z77\Module\Contact\Repositories\ContactAddressRepository;
use Z77\Module\Contact\Validators\AddressValidator;
use Z77\Module\Contact\Validators\ContactAddressValidator;
use Z77\Module\Contact\Validators\ContactValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of contacts and their typed addresses — the rules that
 * hold no matter which screen or script writes (plan §4a, ADR-043
 * decision 19):
 *
 *   - a contact is never deleted, only deactivated: there is no method for
 *     it — debtor and order reference it by id;
 *   - every write runs the validators, so a caller that is not the backend
 *     (an import, a shop checkout) is bound to the same rules;
 *   - a NEW link needs an ACTIVE address type; an existing link keeps its
 *     type after the type was deactivated, but is never SWITCHED to a
 *     deactivated one (owner, 2026-09-21);
 *   - an address row goes when its LAST link goes — never while another
 *     link still points at it.
 *
 * Validate BEFORE mutating a managed entity (ADR-039 decision 9, flush
 * scope): Doctrine writes every managed entity that changed, whether or not
 * it was passed to `persist()` — so a rejected change applied to a loaded
 * entity would still be written by the NEXT flush of anything else in the
 * same request (and, inside `getTransaction()->run()`, by the port's own
 * flush). Therefore a change to an existing contact or address arrives here
 * as VALUES ({@see update()}, {@see saveAddress()}): they are applied to a
 * detached draft, the draft is validated, and only then are they applied to
 * the managed entity. A new link is attached to its contact only after it
 * passed ({@see addAddress()}). A NEW contact is not managed until
 * `persist()`, so {@see save()} validates it as it is.
 *
 * One `flush()` per operation is one Doctrine transaction (ADR-039
 * decision 10): a new contact with n addresses is persisted through the
 * cascade and written atomically without the transaction port.
 */
final class ContactService
{
    /** Rows the backend list shows when contactConfig carries no `contactListLimit`. */
    public const DEFAULT_LIST_LIMIT = 200;

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * How many rows the backend contact list shows at most — contactConfig
     * `contactListLimit`, default {@see DEFAULT_LIST_LIMIT} (owner,
     * 2026-09-21). The search narrows, the list says «n von m angezeigt» for
     * what is beyond. A project changes it in its override copy of the
     * module config — today a FULL copy, since an override replaces the
     * package config (BOOT-CONFIG-001, docs/topics/bootstrap.md).
     *
     * Fails loudly instead of falling back: 0, a negative number or a string
     * is a typo in a config file, and a silently substituted default would
     * hide it.
     *
     * @throws \UnexpectedValueException the configured value is not a positive int
     */
    public static function listLimit(): int
    {
        // has(), not `?? default`: an explicit null is a typo to report, not "unset".
        $config     = DI::getModuleManager()->getModuleConfig('contact');
        $configured = $config?->has('contactListLimit')
            ? $config->get('contactListLimit')
            : self::DEFAULT_LIST_LIMIT;

        if (!is_int($configured) || $configured < 1) {
            throw new \UnexpectedValueException(
                'contactConfig: contactListLimit must be a positive int, got ' . var_export($configured, true) . '.'
            );
        }

        return $configured;
    }

    /**
     * Persist a NEW contact with every link attached through
     * {@see Contact::addAddress()} — validated as new links and written in
     * the same flush: a contact with n typed addresses in one unit of work.
     * Nothing here is managed before `persist()`, so a refusal leaves no
     * trace in the EntityManager.
     *
     * @throws InvalidContactException the contact failed validation (carries the validator)
     * @throws InvalidAddressException an attached address failed (carries both validators)
     * @throws \LogicException the contact already exists — use {@see update()}
     */
    public function save(Contact $contact): void
    {
        if ($contact->getId() !== null) {
            throw new \LogicException('save() takes a NEW contact — an existing one is changed through update($contact, $values) so nothing is applied before validation');
        }
        $this->assertContactValid($contact);
        foreach ($contact->getAddresses() as $link) {
            $this->assertLinkValid($link, requireActiveType: true);
        }

        $this->em->persist($contact);
        $this->flushOrRefuse($contact);
    }

    /**
     * Change an EXISTING contact: $values (snake_case keys as the body
     * cleaner produces them, e.g. `['company' => 'Muster AG']`) go onto a
     * detached draft first; only a valid draft is applied to the managed
     * entity and flushed. `active` is a value like any other.
     *
     * @param array<string, mixed> $values
     * @throws InvalidContactException carries the validator and the draft (for re-rendering the form)
     */
    public function update(Contact $contact, array $values): void
    {
        if ($contact->getId() === null) {
            throw new \LogicException('update() takes an existing contact — a new one goes through save()');
        }
        $draft = clone $contact;
        $draft->mapFromArray($values);
        $this->assertContactValid($draft);

        $contact->mapFromArray($values);
        $this->em->persist($contact);
        $this->flushOrRefuse($contact);
    }

    public function setActive(Contact $contact, bool $active): void
    {
        $this->update($contact, ['active' => $active]);
    }

    /**
     * Add a typed address to an existing contact. The link is built by the
     * caller but NOT yet attached (the constructor never attaches); it is
     * validated as a new link — active type required — and only then
     * attached, persisted and flushed together with its address.
     *
     * @throws InvalidAddressException
     */
    public function addAddress(ContactAddress $link): void
    {
        if ($link->getId() !== null) {
            throw new \LogicException('addAddress() takes a new link — an existing one is changed through saveAddress()');
        }
        if ($link->getContact()->getId() === null) {
            throw new \LogicException('addAddress() needs a persisted contact — a new contact takes its links through save()');
        }
        $this->assertLinkValid($link, requireActiveType: true);

        $link->getContact()->addAddress($link);
        $this->em->persist($link);
        $this->flushOrRefuse($link);
    }

    /**
     * Change an EXISTING link and its address: $linkValues (`type_code`,
     * `title`) and $addressValues (the address fields) go onto a detached
     * draft first. The type must exist; a type CHANGE needs an active target
     * (keeping the current, possibly deactivated, type is allowed).
     *
     * @param array<string, mixed> $linkValues
     * @param array<string, mixed> $addressValues
     * @throws InvalidAddressException carries both validators and the draft link
     */
    public function saveAddress(ContactAddress $link, array $linkValues, array $addressValues): void
    {
        if ($link->getId() === null) {
            throw new \LogicException('saveAddress() takes an existing link — a new one goes through addAddress()');
        }
        $draftAddress = clone $link->getAddress();
        $draftAddress->mapFromArray($addressValues);
        $draft = new ContactAddress($link->getContact(), $link->getTypeCode(), $draftAddress, $link->getTitle());
        $draft->mapFromArray($linkValues);
        $this->assertLinkValid($draft, requireActiveType: $draft->getTypeCode() !== $link->getTypeCode());

        $link->mapFromArray($linkValues);
        $link->getAddress()->mapFromArray($addressValues);
        $this->em->persist($link);
        $this->em->persist($link->getAddress());
        $this->flushOrRefuse($link);
    }

    /**
     * Remove a link; the address row goes with it when no other link points
     * at it. Both deletes are one flush. A contact stays — it is never
     * deleted. (Two requests removing the two last links of one address at
     * the same moment may both count «still referenced» and leave the row
     * behind — harmless, CONTACT-ORPHAN-001.)
     */
    public function removeAddress(ContactAddress $link): void
    {
        /** @var ContactAddressRepository $links */
        $links   = $this->em->getRepository(ContactAddress::class);
        $address = $link->getAddress();
        $orphan  = $links->countByAddress($address) <= 1;

        $link->getContact()->removeAddress($link);
        $this->em->remove($link);
        if ($orphan) {
            $this->em->remove($address);
        }
        $this->em->flush();
    }

    /** @throws InvalidContactException */
    private function assertContactValid(Contact $contact): void
    {
        $validator = new ContactValidator($contact);
        if (!$validator->isValid()) {
            throw new InvalidContactException($validator, $contact);
        }
    }

    /** @throws InvalidAddressException */
    private function assertLinkValid(ContactAddress $link, bool $requireActiveType): void
    {
        $linkValidator    = new ContactAddressValidator($link, $this->em->getRepository(AddressType::class), $requireActiveType);
        $addressValidator = new AddressValidator($link->getAddress());
        $linkOk    = $linkValidator->isValid();
        $addressOk = $addressValidator->isValid();
        if (!$linkOk || !$addressOk) {
            throw new InvalidAddressException($link, $linkValidator, $addressValidator);
        }
    }

    /**
     * The flush, with the unique constraint turned into the field error the
     * validator would have given: two requests can pass the validators and
     * link the same address twice under one type, and only the database sees
     * the race. After a failed flush the
     * EntityManager has been replaced (DOCTRINE-TX-004); the entity handed
     * in is detached and serves the form only.
     *
     * @throws InvalidContactException|InvalidAddressException
     */
    private function flushOrRefuse(Contact|ContactAddress $subject): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            $message = $e->getMessage();
            $link = $subject instanceof ContactAddress ? $subject : $this->linkIn($subject, $message);
            if ($link !== null && str_contains($message, ContactAddress::UNIQUE_CONTACT_ADDRESS_TYPE)) {
                $linkValidator = new ContactAddressValidator($link);
                $linkValidator->flagFieldError('type_code', 'Diese Adresse ist unter diesem Typ bereits erfasst.');
                throw new InvalidAddressException($link, $linkValidator, new AddressValidator($link->getAddress()));
            }
            throw $e;
        }
    }

    /** For a new contact whose links collided: the first attached link is what the form shows. */
    private function linkIn(Contact $contact, string $message): ?ContactAddress
    {
        $links = $contact->getAddresses();

        return str_contains($message, ContactAddress::UNIQUE_CONTACT_ADDRESS_TYPE) && $links !== [] ? $links[0] : null;
    }
}
