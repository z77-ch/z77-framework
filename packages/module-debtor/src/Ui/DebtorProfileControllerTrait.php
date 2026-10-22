<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Repositories\ContactRepository,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\PaymentTerms,
    Z77\Module\Debtor\Repositories\DebtorProfileRepository,
    Z77\Module\Debtor\Repositories\PaymentTermsRepository,
    Z77\Module\Debtor\Services\DebtorAccounts,
    Z77\Module\Debtor\Services\DebtorProfileService,
    Z77\Module\Debtor\Services\InvalidDebtorProfileException,
    Z77\Module\Debtor\Validators\DebtorProfileValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The debtor MASTER DATA (plan §6.1) — the receivables side of a contact,
 * under Stammdaten › Aufträge › Debitoren (moved 2026-10-07, owner: «Stammdaten
 * mit Einträgen vermischt» — the open items are {@see DebtorControllerTrait}).
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use DebtorProfileControllerTrait` + a one-line layout config delegating
 * to {@see DebtorProfileLayout::config()}.
 *
 * **Why a screen of its own and not a fragment on the contact screen**
 * (decided 2026-09-22): mounting into `/backend/contact/contact` would mean
 * the contact screen's template renders a debtor partial — a change in
 * module-contact for a module contact must not know (plan §2, «contact knows
 * no module»). So the list here IS contact-oriented — the same search, the
 * same rows — and only its columns and actions are debtor's. **Making a
 * contact a debtor** is no longer done here (owner decision 2026-10-12, «der
 * Umweg über + Debitor ist falsch»): the contact editor's role switch
 * «Debitor» does it ({@see \Z77\Module\Debtor\Roles\DebtorContactRole},
 * module-contact's generic `contactRoles` registry), and takes it back while
 * no document references the contact.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list contacts with their debtor data, searched the way the contact
 *     list searches (`?q=`, a plain GET form, bounded by contactConfig
 *     `contactListLimit` — contact's setting, read once through
 *     `ContactService::listLimit()`);
 *   - edit the profile a contact has: payment terms and dunning block. The
 *     CONTACT of a profile never changes — a different party is a different
 *     profile. An existing profile on a contact deactivated SINCE keeps
 *     working and stays editable (the reference rule, ADR-043 decision 19).
 *     The action cell is EMPTY (2026-10-12: «+ Debitor» removed — a profile
 *     is opened in the contact editor, or by the first draft of an invoice);
 *   - activate / deactivate a profile (inline switch). No delete HERE — the
 *     contact editor's «Debitor» off deletes a profile without documents;
 *   - show the account settings (the mandator's debtor accounts, read
 *     through {@see DebtorAccounts::status()}) with the refusal each one
 *     would raise — so a wrong account is found here and not when an
 *     invoice is posted; they are EDITED on the mandator screen.
 *
 * Every write goes through {@see DebtorProfileService}; a MANAGED profile is
 * never mutated here before validation (ADR-039 decision 9) — the cleaned
 * values go to the service, a refused change comes back as the exception's
 * draft.
 *
 * No JavaScript of its own. The using class MUST provide (via its host
 * base): `html()`, `fetch()`, `fetchError()`, `em()`, `$layoutManager`,
 * `$messageService`.
 */
trait DebtorProfileControllerTrait
{
    private const DEBTOR_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function debtorListBase(): string
    {
        return DebtorProfileLayout::BASE;
    }

    private function debtorContacts(): ContactRepository
    {
        return $this->em()->getRepository(Contact::class);
    }

    private function profiles(): DebtorProfileRepository
    {
        return $this->em()->getRepository(DebtorProfile::class);
    }

    private function debtorTerms(): PaymentTermsRepository
    {
        return $this->em()->getRepository(PaymentTerms::class);
    }

    private function profileService(): DebtorProfileService
    {
        return new DebtorProfileService($this->em());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $query    = trim((string) DI::getRequest()->getGetParameter('q'));
        $limit    = ContactService::listLimit();
        $contacts = $this->debtorContacts()->search($query, $limit);

        $response = $this->html([
            'contacts'          => $contacts,
            'profilesByContact' => $this->profiles()->findForContacts($contacts),
            'termsByCode'       => self::termsByCode($this->debtorTerms()->allInOrder()),
            'total'             => $this->debtorContacts()->countMatching($query),
            'limit'             => $limit,
            'query'             => $query,
            'accounts'          => (new DebtorAccounts($this->em()))->status(),
            'accountsNotice'    => (new DebtorAccounts($this->em()))->notice(),
            'accountLabels'     => DebtorAccounts::LABELS,
            'actionBase'        => $this->debtorListBase(),
        ]);
        // The fragment owns its header slots (financial.md, «fragment slots»): the search → toolbar.
        // The action cell stays EMPTY (2026-10-12): a debtor is made in the contact editor (role
        // «Debitor»); what is done here — edit, switch — is bound to a row (backend-screen.md §2).
        $this->layoutManager->addPartials('search', 'Backend/DebtorProfileController', self::DEBTOR_NS, 'hc2');

        return $response;
    }

    // ── edit ─────────────────────────────────────────────────────────────

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $profile = $id ? $this->profiles()->find($id) : null;
        if ($profile === null || $profile->getContact() === null) {
            return $this->fetchError('Debitor nicht gefunden');
        }

        return $this->editProfile($profile, $profile->getContact());
    }

    /**
     * The profile dialog of an EXISTING profile — a new one is opened in the contact editor
     * (role «Debitor», 2026-10-12) or by the first draft of an invoice, not here.
     */
    private function editProfile(DebtorProfile $profile, Contact $contact): HtmlResponse|FetchResponse
    {
        $validator = null;
        $draft     = $profile;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim((string) ($body['entity_csrf'] ?? ''));
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'debtorProfile', $profile->getId())) {
                return $this->fetchError('Invalid token');
            }

            // The contact is never mapped from the body; `active` has its own switch.
            $values = BodyCleaner::cleanFor(DebtorProfile::class, $body);
            unset($values['id'], $values['contact'], $values['contact_id'], $values['active']);

            try {
                $this->profileService()->update($profile, $values);

                $this->messageService->pushFlash('success', 'Debitor «' . $contact->displayName() . '» gespeichert');

                return $this->debtorRowAnswer($profile, $contact)->addCommand('close-modal');
            } catch (InvalidDebtorProfileException $e) {
                $validator = $e->validator;
                $draft     = $e->profile;
            }
            // fall through — re-render the form with the errors and what was typed
        }

        $response = $this->html([
            'entry'      => $draft,
            'contact'    => $contact,
            'terms'      => $this->selectableTerms($draft->getPaymentTermsCode()),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('debtorProfile', $profile->getId()),
            'validator'  => $validator ?? new DebtorProfileValidator($draft),
            'actionBase' => $this->debtorListBase(),
            // The installation's «Betreffname» (the mandator's) — what an empty debtor label means (owner 2026-10-21).
            'globalSubjectLabel' => (new \Z77\Module\Debtor\Services\SubjectLabelDefaults($this->em()))->global(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/DebtorProfileController', self::DEBTOR_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $profile = $id ? $this->profiles()->find($id) : null;
        if ($profile === null || $profile->getContact() === null) {
            return $this->fetchError('Debitor nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->profileService()->setActive($profile, (bool) ($body['value'] ?? !$profile->isActive()));
        } catch (InvalidDebtorProfileException $e) {
            return $this->fetchError(implode(' ', [...$e->validator->getErrors(), ...array_values($e->validator->getFieldErrors())]));
        }

        return $this->debtorRowAnswer($profile, $profile->getContact());
    }

    // ── in-place answer ──────────────────────────────────────────────────

    /**
     * A save or a switch that changed one debtor answers with its CONTACT's row, not with
     * `reload` (ADR-047 addendum 2026-10-10): `_row` — the partial the list renders — replaces
     * the node `debtorContact:<id>` (`replaceRow`). A new profile replaces the same row (it
     * was «kein Debitor» before) and core.js wires its new switch and ⋮. A contact not shown
     * in the current list (search, limit) has no row — nothing to replace, the flash says it.
     * Push the flash BEFORE calling: `fetch()` takes the flashes pushed so far.
     */
    private function debtorRowAnswer(DebtorProfile $profile, Contact $contact): FetchResponse
    {
        $html = (new TemplateRenderer(self::DEBTOR_NS))->partial('Backend/DebtorProfileController/_row', [
            'contact'     => $contact,
            'profile'     => $profile,
            'termsByCode' => self::termsByCode($this->debtorTerms()->allInOrder()),
            'actionBase'  => $this->debtorListBase(),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $profile->getId()])
            ->replaceRow('debtorContact', (int) $contact->getId(), $html);
    }

    // ── selects ──────────────────────────────────────────────────────────

    /**
     * What the payment-terms select offers: the ACTIVE rows, plus the
     * profile's own code when it was deactivated since — a deactivated row
     * may be KEPT, never newly chosen (ADR-043 decision 19, the
     * `ManualEntryForm::selectableCodes()` / address-type rule).
     *
     * @return list<PaymentTerms>
     */
    private function selectableTerms(string $current): array
    {
        $offered = [];
        foreach ($this->debtorTerms()->allInOrder() as $terms) {
            if ($terms->isActive() || $terms->getCode() === $current) {
                $offered[] = $terms;
            }
        }

        return $offered;
    }

    /**
     * @param list<PaymentTerms> $terms
     * @return array<string, PaymentTerms> code → row, for the list's label lookup
     */
    private static function termsByCode(array $terms): array
    {
        $byCode = [];
        foreach ($terms as $row) {
            $byCode[$row->getCode()] = $row;
        }

        return $byCode;
    }
}
