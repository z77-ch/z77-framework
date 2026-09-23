<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
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
 * The debtor surface (plan §6.1) — the receivables side of a contact.
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use DebtorControllerTrait` + a one-line layout config delegating
 * to {@see DebtorLayout::config()}.
 *
 * **Why a screen of its own and not a fragment on the contact screen**
 * (decided 2026-09-22): mounting into `/backend/contact/contact` would mean
 * the contact screen's template renders a debtor partial — a change in
 * module-contact for a module contact must not know (plan §2, «contact knows
 * no module»). So the list here IS contact-oriented — the same search, the
 * same rows — and only its columns and actions are debtor's. Nothing in
 * module-contact is touched.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list contacts with their debtor data, searched the way the contact
 *     list searches (`?q=`, a plain GET form, bounded by contactConfig
 *     `contactListLimit` — contact's setting, read once through
 *     `ContactService::listLimit()`);
 *   - «Debitor anlegen» on an ACTIVE contact that has none, edit the one it
 *     has: payment terms and dunning block. The CONTACT of a profile never
 *     changes — a different party is a different profile. An existing
 *     profile on a contact deactivated SINCE keeps working and stays
 *     editable (the reference rule, ADR-043 decision 19);
 *   - activate / deactivate a profile (inline switch). There is NO delete;
 *   - show the account settings (`debtorConfig → debtorAccounts`) with the
 *     refusal each one would raise — so a wrong account is found here and
 *     not when part 2 posts an invoice ({@see DebtorAccounts::status()}).
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
trait DebtorControllerTrait
{
    private const DEBTOR_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function debtorListBase(): string
    {
        return '/backend/finance/debtor';
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
            'accountLabels'     => DebtorAccounts::LABELS,
            'actionBase'        => $this->debtorListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»).
        $this->layoutManager->addPartials('search', 'Backend/DebtorController', self::DEBTOR_NS, 'hc2');

        return $response;
    }

    // ── add / edit ───────────────────────────────────────────────────────

    /** «Debitor anlegen» for the contact in `?contact=` — a profile is always opened FOR a contact. */
    protected function addAction(): HtmlResponse|FetchResponse
    {
        $contactId = (int) DI::getRequest()->getGetParameter('contact');
        $contact   = $contactId ? $this->debtorContacts()->find($contactId) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }
        if ($this->profiles()->findByContact($contact) !== null) {
            return $this->fetchError('Für diesen Kontakt gibt es bereits einen Debitor.');
        }
        if (!$contact->isActive()) {
            // The list does not offer the button for an inactive contact; this
            // is the server's own refusal for a hand-built URL.
            return $this->fetchError('Kontakt «' . $contact->displayName() . '» ist inaktiv — für einen inaktiven Kontakt wird kein Debitor angelegt.');
        }

        $profile = new DebtorProfile();
        $profile->setContact($contact);

        return $this->editProfile($profile, $contact);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $profile = $id ? $this->profiles()->find($id) : null;
        if ($profile === null || $profile->getContact() === null) {
            return $this->fetchError('Debitor nicht gefunden');
        }

        return $this->editProfile($profile, $profile->getContact());
    }

    private function editProfile(DebtorProfile $profile, Contact $contact): HtmlResponse|FetchResponse
    {
        $isNew     = $profile->getId() === null;
        $validator = null;
        $draft     = $profile;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'debtorProfile', $profile->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            // The contact travels in the URL, never in the body; `active` has its own switch.
            $values = BodyCleaner::cleanFor(DebtorProfile::class, $body);
            unset($values['id'], $values['contact'], $values['active']);

            try {
                if ($isNew) {
                    // Not managed until persist() — build it and let the service validate it.
                    $profile->mapFromArray($values);
                    $this->profileService()->save($profile);
                } else {
                    $this->profileService()->update($profile, $values);
                }

                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Debitor «' . $contact->displayName() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                );

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $profile->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('debtorProfile', $profile->getId()),
            'validator'  => $validator ?? new DebtorProfileValidator($draft),
            'actionBase' => $this->debtorListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/DebtorController', self::DEBTOR_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $profile = $id ? $this->profiles()->find($id) : null;
        if ($profile === null) {
            return $this->fetchError('Debitor nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->profileService()->setActive($profile, (bool) ($body['value'] ?? !$profile->isActive()));
        } catch (InvalidDebtorProfileException $e) {
            return $this->fetchError(implode(' ', [...$e->validator->getErrors(), ...array_values($e->validator->getFieldErrors())]));
        }

        return $this->fetch()->setStatus('success')->addCommand('reload');
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
