<?php

namespace Z77\Module\Debtor\Validators;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see DebtorProfile}.
 *
 * Contact: required, persisted, and — for a NEW profile — ACTIVE (the
 * reference rule of ADR-043 decision 19 applied to the party; an EXISTING
 * profile keeps working and stays editable after its contact was
 * deactivated). **At most one profile per contact**: asked here so the form
 * shows a field error, decided by `uniq_debtor_profile_contact` under a race.
 *
 * Payment terms — the reference rule of ADR-043 decision 19, in the shape
 * `ContactAddressValidator` established for the address type:
 *
 *   - required and it must EXIST (no foreign key can say so: the row lives
 *     in `data/`, the profile in the database);
 *   - a NEW reference — a new profile, or an update that CHANGES the code —
 *     needs an ACTIVE row ($requireActive);
 *   - an existing profile KEEPS a code that was deactivated since. That is
 *     the whole point of «deactivate, never delete»: history resolves, only
 *     new references are steered.
 *
 * Without the repositories those checks are skipped (format-only).
 */
class DebtorProfileValidator extends EntityValidator
{
    public function __construct(
        DebtorProfile $profile,
        private ?PaymentTermsRepository $terms = null,
        private ?DebtorProfileRepository $profiles = null,
        private bool $requireActiveTerms = true,
    ) {
        parent::__construct($profile);
    }

    /** The unique-index race mapped onto the field the form shows (the `AccountValidator` model). */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateContact(?Contact $contact): void
    {
        if ($contact === null || $contact->getId() === null) {
            $this->addFieldError('contact_id', 'Ohne Kontakt gibt es keinen Debitor — bitte einen gespeicherten Kontakt wählen.');
            return;
        }

        // The reference rule applied to the party (ADR-043 decision 19, review
        // 2026-09-22): a NEW profile needs an ACTIVE contact; an existing one
        // keeps working and stays editable after its contact was deactivated,
        // because open items and documents are already written against it.
        if ($this->entity->getId() === null && !$contact->isActive()) {
            $this->addFieldError('contact_id', 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Debitor angelegt.');
            return;
        }

        if ($this->profiles === null) {
            return;
        }

        $existing = $this->profiles->findByContact($contact);
        if ($existing !== null && $existing->getId() !== $this->entity->getId()) {
            $this->addFieldError('contact_id', 'Für «' . $contact->displayName() . '» gibt es bereits einen Debitor.');
        }
    }

    public function validatePaymentTermsCode(string $code): void
    {
        $this->validate('payment_terms_code', 'Zahlungskonditionen', $code)
            ->notEmpty()
            ->maxLength(DebtorProfile::CODE_LENGTH);
        if ($this->hasFieldError('payment_terms_code') || $this->terms === null) {
            return;
        }

        $terms = $this->terms->findByCode($code);
        if ($terms === null) {
            $this->addFieldError('payment_terms_code', 'Zahlungskonditionen «' . $code . '» gibt es nicht.');
            return;
        }
        if ($this->requireActiveTerms && !$terms->isActive()) {
            $this->addFieldError(
                'payment_terms_code',
                'Zahlungskonditionen «' . $terms->getLabel() . '» sind inaktiv — nur ein unveränderter Debitor behält sie.'
            );
        }
    }
}
