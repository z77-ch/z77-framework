<?php

namespace Z77\Module\Debtor\Roles;

use Z77\Core\DI;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Roles\ContactRoleInterface;
use Z77\Module\Contact\Roles\ContactRoleRefusedException;
use Z77\Module\Contact\Roles\ContactRoleState;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Entities\PaymentTerms;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Module\Debtor\Repositories\PaymentTermsRepository;
use Z77\Module\Debtor\Services\DebtorProfileInUseException;
use Z77\Module\Debtor\Services\DebtorProfileService;
use Z77\Module\Debtor\Services\InvalidDebtorProfileException;
use Z77\Module\Debtor\Ui\DebtorProfileLayout;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «Debitor» as a role of a contact (owner decision 2026-10-12: «ein Kontakt — wo
 * auch immer erstellt — wird im Kontakt-Editor als Debitor festgelegt; solange keine
 * Belege daran hängen, kann der Debitor-Status wieder entfernt werden»). Registered
 * in `debtorConfig` → `contactRoles` → `debtor`; module-contact renders it without
 * knowing this module ({@see \Z77\Module\Contact\Roles\ContactRoles}).
 *
 *   - ON  = the debtor profile is opened through {@see DebtorProfileService::save()}:
 *           the customer number drawn from the range `customer`, the DEFAULT payment
 *           terms (`PaymentTermsRepository::defaultForNewDebtor()`, 2026-10-12). Refused —
 *           with what to set up — for an inactive contact, a contact that already is a
 *           debtor, or when no active terms are marked «Standard». Everything further
 *           (other terms, dunning block) is the debtor dialog the state links to.
 *   - OFF = the profile is DELETED through {@see DebtorProfileService::delete()} — only
 *           while no document (any kind, any state, drafts included) is written to the
 *           contact; otherwise refused: deactivate instead (a forgotten draft can be
 *           deleted since 2026-10-13, `InvoicingService::discard()`). The customer number
 *           is not given back. Because off destroys the profile, the state names
 *           `offConfirm` (owner 2026-10-13, review M5): the contact dialog asks first and
 *           switches off only on the confirmation with an entity token; ON stays immediate.
 *
 * The «+ Kontakt» window of the invoice editor shows no such switch: a contact made
 * there becomes a debtor with its first draft anyway (DEBTOR-CONTACT-001).
 */
final class DebtorContactRole implements ContactRoleInterface
{
    public function label(): string
    {
        return 'Debitor';
    }

    public function state(Contact $contact): ContactRoleState
    {
        $profile = $this->profiles()->findByContact($contact);
        if ($profile === null) {
            $refusal = $this->refusalToOpen($contact);

            return new ContactRoleState(
                on: false,
                summary: $refusal === null ? $this->newSummary() : '',
                switchable: $refusal === null,
                reason: $refusal ?? '',
            );
        }

        $documents = $this->invoices()->countForContact((int) $contact->getId());
        $summary   = 'Kundennummer ' . $profile->getCustomerNumber() . ' · ' . $this->termsLabel($profile->getPaymentTermsCode())
            . ' · Preise ' . ($profile->getPriceMode() === 'gross' ? 'brutto' : 'netto')
            . ($profile->isActive() ? '' : ' · inaktiv');

        return new ContactRoleState(
            on: true,
            summary: $summary,
            switchable: $documents === 0,
            reason: $documents === 0 ? '' : (new DebtorProfileInUseException($documents, $contact->displayName()))->getMessage(),
            linkUrl: DebtorProfileLayout::BASE . '/edit?id=' . $profile->getId(),
            linkLabel: 'Debitor bearbeiten …',
            // Off DELETES the profile (owner 2026-10-13, review M5: never with one click).
            offConfirm: 'Debitor entfernen? Das Debitorenprofil von «' . $contact->displayName() . '» mit Kundennummer ' . $profile->getCustomerNumber()
                . ' wird gelöscht; die Kundennummer wird nicht wieder vergeben. Der Kontakt bleibt.',
        );
    }

    public function stateForNew(): ContactRoleState
    {
        $refusal = $this->refusalForTerms();

        return new ContactRoleState(
            on: false,
            summary: $refusal === null ? $this->newSummary() : '',
            switchable: $refusal === null,
            reason: $refusal ?? '',
        );
    }

    public function switchOn(Contact $contact): void
    {
        if ($this->profiles()->findByContact($contact) !== null) {
            throw new ContactRoleRefusedException('«' . $contact->displayName() . '» ist bereits Debitor.');
        }
        $refusal = $this->refusalToOpen($contact);
        if ($refusal !== null) {
            throw new ContactRoleRefusedException($refusal);
        }

        try {
            // The one opening path (draws the customer number); the price mode net — changed in the debtor dialog.
            (new DebtorProfileService($this->em()))->open($contact, (string) $this->terms()->defaultForNewDebtor()?->getCode());
        } catch (InvalidDebtorProfileException $e) {
            throw new ContactRoleRefusedException(implode(' ', [...$e->validator->getErrors(), ...array_values($e->validator->getFieldErrors())]), 0, $e);
        }
    }

    public function switchOff(Contact $contact): void
    {
        $profile = $this->profiles()->findByContact($contact);
        if ($profile === null) {
            return;   // not a debtor (any more) — nothing to take away
        }
        try {
            (new DebtorProfileService($this->em()))->delete($profile);
        } catch (DebtorProfileInUseException $e) {
            throw new ContactRoleRefusedException($e->getMessage(), 0, $e);
        }
    }

    /** Why this contact cannot be made a debtor now — the validator says the same on save — or null. */
    private function refusalToOpen(Contact $contact): ?string
    {
        if (!$contact->isActive()) {
            return 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Debitor angelegt.';
        }

        return $this->refusalForTerms();
    }

    private function refusalForTerms(): ?string
    {
        return $this->terms()->defaultForNewDebtor() === null
            ? 'Keine aktiven Standard-Zahlungskonditionen — zuerst unter Stammdaten › Aufträge › Zahlungskonditionen eine aktive Kondition als «Standard» markieren.'
            : null;
    }

    private function newSummary(): string
    {
        return 'erhält die nächste Kundennummer · ' . (string) $this->terms()->defaultForNewDebtor()?->getLabel();
    }

    private function termsLabel(string $code): string
    {
        return $this->terms()->findByCode($code)?->getLabel() ?? $code;
    }

    private function em(): UnifiedEntityManager
    {
        return DI::getUnifiedEntityManager();
    }

    private function profiles(): DebtorProfileRepository
    {
        return $this->em()->getRepository(DebtorProfile::class);
    }

    private function invoices(): InvoiceRepository
    {
        return $this->em()->getRepository(Invoice::class);
    }

    private function terms(): PaymentTermsRepository
    {
        return $this->em()->getRepository(PaymentTerms::class);
    }
}
