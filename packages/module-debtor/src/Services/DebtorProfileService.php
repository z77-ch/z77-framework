<?php

namespace Z77\Module\Debtor\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Module\Debtor\Validators\DebtorProfileValidator;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of {@see DebtorProfile} — the rules that hold no matter
 * which screen or script writes (plan §6.1, ADR-039 decision 9, ADR-043
 * decision 19):
 *
 *   - a profile is never deleted, only deactivated: there is no method for
 *     it — invoices and open items are written against the party;
 *   - at most ONE profile per contact — the validator asks, the unique index
 *     `uniq_debtor_profile_contact` decides under a race and the violation
 *     comes back as the field error the validator would have given;
 *   - the payment-terms code must EXIST; a NEW reference — a new profile, or
 *     an update that CHANGES the code — needs an ACTIVE row, an unchanged
 *     one keeps a deactivated row (the `ContactService::saveAddress()`
 *     model);
 *   - every write runs the validator, so an import is bound to the same
 *     rules as the backend.
 *
 * **Validate BEFORE mutating a managed entity** (ADR-039 decision 9, flush
 * scope — the `ContactService` / `AccountService` model): a change to an
 * existing profile arrives as VALUES ({@see update()}); they go onto a
 * detached clone, the clone is validated, and only a valid one is applied to
 * the managed entity. A refused change never reaches the next flush. A NEW
 * profile is not managed until `persist()`, so {@see save()} validates it as
 * it is.
 *
 * One `flush()` per update is one Doctrine transaction (ADR-039
 * decision 10). Only {@see save()} runs through the transaction port: it
 * draws the CUSTOMER NUMBER from the range `customer` and inserts the row in
 * ONE unit of work, so the number is consumed exactly when the profile
 * exists (gapless, the document model).
 */
final class DebtorProfileService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * Persist a NEW profile — and give it its CUSTOMER NUMBER: the next of
     * the gapless range `customer`, drawn as the FIRST write of a unit of
     * work (lock order, `NumberRange` first — the `InvoicingService::invoice()`
     * model), the row inserted in the same unit of work, so a refused insert
     * (the unique-contact race) rolls the draw back and consumes nothing.
     * Validation runs BEFORE the unit of work: a refused profile never takes
     * the range lock. Nothing here is managed before `persist()`, so a
     * refusal leaves no trace in the EntityManager.
     *
     * @throws InvalidDebtorProfileException carries the validator and the profile
     * @throws \LogicException the profile already exists — use {@see update()}
     */
    public function save(DebtorProfile $profile): void
    {
        if ($profile->getId() !== null) {
            throw new \LogicException('save() takes a NEW debtor profile — an existing one is changed through update($profile, $values)');
        }
        $this->assertValid($profile, requireActiveTerms: true);

        $this->refuseOnRace($profile, function () use ($profile): void {
            $this->em->getTransaction(DebtorProfile::class)->run(function () use ($profile): void {
                /** @var NumberRangeRepository $ranges */
                $ranges = $this->em->getRepository(NumberRange::class);
                $profile->assignCustomerNumber($ranges->next(DebtorProfile::CUSTOMER_NUMBER_RANGE));   // the FIRST write
                $this->em->persist($profile);
            });
        });
    }

    /**
     * Change an EXISTING profile: $values (snake_case keys as the body
     * cleaner produces them) go onto a detached draft first; only a valid
     * draft is applied to the managed entity and flushed. The CONTACT is not
     * changeable — a profile belongs to the party it was opened for; a
     * different party is a different profile.
     *
     * @param array<string, mixed> $values
     * @throws InvalidDebtorProfileException carries the validator and the draft (for re-rendering the form)
     */
    public function update(DebtorProfile $profile, array $values): void
    {
        if ($profile->getId() === null) {
            throw new \LogicException('update() takes an existing debtor profile — a new one goes through save()');
        }
        unset($values['contact'], $values['contact_id'], $values['id']);

        $draft = clone $profile;
        $draft->mapFromArray($values);
        // An UNCHANGED code may be a deactivated one; a CHANGE needs an active row.
        $this->assertValid($draft, requireActiveTerms: $draft->getPaymentTermsCode() !== $profile->getPaymentTermsCode());

        $profile->mapFromArray($values);
        $this->em->persist($profile);
        $this->refuseOnRace($profile, fn() => $this->em->flush());
    }

    public function setActive(DebtorProfile $profile, bool $active): void
    {
        $this->update($profile, ['active' => $active]);
    }

    private function profiles(): DebtorProfileRepository
    {
        return $this->em->getRepository(DebtorProfile::class);
    }

    private function terms(): PaymentTermsRepository
    {
        return $this->em->getRepository(PaymentTerms::class);
    }

    /** @throws InvalidDebtorProfileException */
    private function assertValid(DebtorProfile $profile, bool $requireActiveTerms): void
    {
        $validator = new DebtorProfileValidator($profile, $this->terms(), $this->profiles(), $requireActiveTerms);
        if (!$validator->isValid()) {
            throw new InvalidDebtorProfileException($validator, $profile);
        }
    }

    /**
     * Runs the write, with the unique contact turned into the field error
     * the validator would have given: two requests can pass the validator
     * for the same contact, and only the database sees the race. After a
     * failed flush the EntityManager has been replaced (DOCTRINE-TX-004) —
     * by the driver outside a unit of work, by the port's rollback inside
     * one; the profile handed in is detached and serves the form only.
     *
     * @throws InvalidDebtorProfileException
     */
    private function refuseOnRace(DebtorProfile $profile, callable $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), DebtorProfile::UNIQUE_CONTACT)) {
                throw $e;
            }
            $validator = new DebtorProfileValidator($profile);
            $validator->flagFieldError('contact_id', 'Für diesen Kontakt wurde soeben ein Debitor angelegt — Liste neu laden.');
            throw new InvalidDebtorProfileException($validator, $profile);
        }
    }
}
