<?php

namespace Z77\Module\Creditor\Roles;

use Z77\Core\DI;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Roles\ContactRoleInterface;
use Z77\Module\Contact\Roles\ContactRoleRefusedException;
use Z77\Module\Contact\Roles\ContactRoleState;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Creditor\Services\CreditorProfileService;
use Z77\Module\Creditor\Services\CreditorRefusedException;
use Z77\Module\Creditor\Services\InvalidCreditorProfileException;
use Z77\Module\Creditor\Ui\CreditorLayout;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * «Kreditor» as a role of a contact (creditor plan §2.2, the debtor model of 2026-10-12): a contact —
 * wherever it was created — is declared a creditor in the CONTACT editor. Registered in
 * `creditorConfig` → `contactRoles` → `creditor`; module-contact renders it without knowing this
 * module ({@see \Z77\Module\Contact\Roles\ContactRoles}).
 *
 *   - ON  = an empty creditor profile through {@see CreditorProfileService::open()} (no number — a
 *           creditor number is not needed). Refused for an inactive contact or one that already is
 *           a creditor. UID, IBANs and defaults are the creditor window the state links to.
 *   - OFF = the profile and its IBANs are DELETED through {@see CreditorProfileService::delete()};
 *           because that destroys data, the state names `offConfirm` (the contact dialog asks
 *           first). While an invoice names the creditor, off is REFUSED («nur deaktivieren», the
 *           debtor rule of 2026-10-12): the state says so (not switchable, with the reason) and
 *           {@see switchOff()} refuses again on the server.
 */
final class CreditorContactRole implements ContactRoleInterface
{
    /** What the switch says before the role is on. */
    private const NEW_SUMMARY = 'Lieferant: IBAN, UID und Standardkonto danach unter «Kreditor bearbeiten …»';

    public function label(): string
    {
        return 'Kreditor';
    }

    public function state(Contact $contact): ContactRoleState
    {
        $profile = $this->profiles()->findByContact($contact);
        if ($profile === null) {
            $refusal = $this->refusalToOpen($contact);

            return new ContactRoleState(
                on: false,
                summary: $refusal === null ? self::NEW_SUMMARY : '',
                switchable: $refusal === null,
                reason: $refusal ?? '',
            );
        }

        $ibans    = count($this->ibans()->forProfile($profile));
        $invoices = (new CreditorProfileService($this->em()))->invoiceCount((int) $profile->getId());
        $summary = ($profile->getUid() !== null ? $profile->getUid() . ' · ' : '')
            . ($ibans === 0 ? 'keine IBAN' : ($ibans === 1 ? '1 IBAN' : $ibans . ' IBAN'))
            . ($profile->getDefaultAccount() !== null ? ' · Konto ' . $profile->getDefaultAccount() : ' · ohne Standardkonto')
            . ($invoices > 0 ? ' · ' . ($invoices === 1 ? '1 Rechnung' : $invoices . ' Rechnungen') : '')
            . ($profile->isActive() ? '' : ' · inaktiv');

        return new ContactRoleState(
            on: true,
            summary: $summary,
            switchable: $invoices === 0,
            reason: $invoices === 0 ? '' : CreditorProfileService::inUse($invoices, $contact->displayName())->getMessage(),
            linkUrl: CreditorLayout::BASE . '/edit?id=' . $profile->getId(),
            linkLabel: 'Kreditor bearbeiten …',
            offConfirm: 'Kreditor entfernen? Das Kreditorenprofil von «' . $contact->displayName() . '» mit UID, Standardwerten und '
                . ($ibans === 1 ? '1 IBAN' : $ibans . ' IBAN') . ' wird gelöscht. Der Kontakt bleibt.',
        );
    }

    public function stateForNew(): ContactRoleState
    {
        return new ContactRoleState(on: false, summary: self::NEW_SUMMARY);
    }

    public function switchOn(Contact $contact): void
    {
        try {
            (new CreditorProfileService($this->em()))->open($contact);
        } catch (InvalidCreditorProfileException $e) {
            throw new ContactRoleRefusedException($e->sentence(), 0, $e);
        }
    }

    public function switchOff(Contact $contact): void
    {
        $profile = $this->profiles()->findByContact($contact);
        if ($profile === null) {
            return;   // not a creditor (any more) — nothing to take away
        }
        try {
            (new CreditorProfileService($this->em()))->delete($profile);
        } catch (CreditorRefusedException $e) {
            throw new ContactRoleRefusedException($e->getMessage(), 0, $e);
        }
    }

    /** Why this contact cannot be made a creditor now — the validator says the same on save — or null. */
    private function refusalToOpen(Contact $contact): ?string
    {
        return $contact->isActive() ? null : 'Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Kreditor angelegt.';
    }

    private function em(): UnifiedEntityManager
    {
        return DI::getUnifiedEntityManager();
    }

    private function profiles(): CreditorProfileRepository
    {
        return $this->em()->getRepository(CreditorProfile::class);
    }

    private function ibans(): CreditorIbanRepository
    {
        return $this->em()->getRepository(CreditorIban::class);
    }
}
