<?php
namespace Z77\Module\Contact\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Contact\Entities\Address,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Repositories\ContactAddressRepository,
    Z77\Module\Contact\Repositories\ContactRepository,
    Z77\Module\Contact\Roles\ContactRoleRefusedException,
    Z77\Module\Contact\Roles\ContactRoles,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Services\InvalidAddressException,
    Z77\Module\Contact\Services\InvalidContactException,
    Z77\Module\Contact\Validators\ContactValidator,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The contact surface (plan §4a) — mounted by a thin host controller in the
 * backend (dms Drive / member accounts / tax-code pattern, ADR-018): the
 * host provides route + auth + shell, all logic and templates live here in
 * module-contact. Host side: `use ContactControllerTrait` + a one-line
 * layout config delegating to {@see ContactLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list contacts with their typed addresses; search by name, company or
 *     e-mail (`?q=`, a plain GET form — no JavaScript);
 *   - add a contact, optionally with its first address in the same step;
 *     edit the contact's own fields;
 *   - activate / deactivate a contact (inline switch). There is NO delete: a
 *     contact is deactivated, never deleted — documents reference it by id;
 *   - add / edit / remove a typed address in the ⋮ hub. A new address may
 *     only use an ACTIVE type; an existing one keeps a deactivated type but
 *     is never switched to one;
 *   - switch the ROLES other modules contribute («Debitor» …, owner decision
 *     2026-10-12) in the contact dialog: on «edit» each switch acts at once
 *     (`toggle-role`, the roles section answers in place); on «add» the
 *     chosen roles are applied AFTER the contact is saved, in registration
 *     order, each in its own unit of work — a refused role leaves the saved
 *     contact and says why. This trait knows no role: it renders what
 *     {@see ContactRoles} answers and calls the role's switch.
 *
 * Every write goes through {@see ContactService}, which runs the validators
 * and holds the rules; the trait only maps the request and renders. A
 * MANAGED entity is never mutated here before validation (ADR-039
 * decision 9): a change to an existing contact or address is handed to the
 * service as cleaned VALUES, and a refused change comes back as the
 * exception's draft for re-rendering the form.
 *
 * No JavaScript of its own: every action runs through the shared `core.js`
 * wiring (`data-fetch-get` modals, `data-fetch-post` forms, `data-fetch-toggle`
 * switch). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `em()`, `$layoutManager`, `$messageService`.
 */
trait ContactControllerTrait
{
    private const CONTACT_NS = 'Z77\\Module\\Contact';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function contactListBase(): string
    {
        return '/backend/contact/contact';
    }

    private function contacts(): ContactRepository
    {
        return $this->em()->getRepository(Contact::class);
    }

    private function contactAddresses(): ContactAddressRepository
    {
        return $this->em()->getRepository(ContactAddress::class);
    }

    private function contactService(): ContactService
    {
        return new ContactService($this->em());
    }

    private function addressTypes(): AddressTypes
    {
        return AddressTypes::from($this->em());
    }

    /** The roles other modules contribute — empty when no installed module registers one. */
    private function contactRoles(): ContactRoles
    {
        return ContactRoles::fromModules(DI::getModuleManager());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $query    = trim((string) DI::getRequest()->getGetParameter('q'));
        $limit    = ContactService::listLimit();
        $contacts = $this->contacts()->search($query, $limit);

        return $this->html([
            'contacts'       => $contacts,
            'linksByContact' => $this->contactAddresses()->findForContacts($contacts),
            'total'          => $this->contacts()->countMatching($query),
            'limit'          => $limit,
            'query'          => $query,
            'kindLabels'     => ContactForm::kindLabels(),
            'addressTypes'   => $this->addressTypes(),
            'actionBase'     => $this->contactListBase(),
        ]);
    }

    // ── contact: add ─────────────────────────────────────────────────────

    /**
     * «Kontakt anlegen»: the contact's fields plus an OPTIONAL first address
     * (type + address block). Nothing here is managed before the service
     * persists it, so the new entity is built straight from the body; a
     * refusal renders it back with the errors.
     */
    protected function addAction(): HtmlResponse|FetchResponse
    {
        $form   = new ContactForm(addressRequired: false);
        $chosen = [];

        if (DI::getRequest()->isPost()) {
            $body   = DI::getRequest()->getJsonBody();
            $chosen = self::chosenRoles($body);
            $form->fromPost($body);
            if ($form->save($this->contactService())) {
                $contact = $form->contact;
                $this->messageService->pushFlash('success', 'Kontakt «' . $contact->displayName() . '» angelegt');
                // The contact first, then the roles — each its own unit of work of the role's module.
                $this->applyRolesToNew($contact, $chosen);

                // The new row goes on top: the list may be filtered by a search the POST does
                // not carry, so its sorted place is not known here — the next load sorts it.
                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $contact->getId()])
                    ->insertRow('contact', $this->contactRowHtml($contact), 'prepend')
                    ->addCommand('remove-element', ['target' => FetchResponse::listTarget('contact') . ' > .be-list__empty'])
                    ->addCommand('close-modal');
            }
            // validation failed — fall through to re-render the form with errors
        }

        return $this->renderContactForm($form->contact, $form->vars($this->addressTypes()), $chosen);
    }

    /**
     * The roles ticked on «add» (`roles[<key>]` switches → `['roles' => ['debtor' => true]]`),
     * as a list of keys. Unknown keys are dropped later — the registry decides what exists.
     *
     * @return list<string>
     */
    private static function chosenRoles(array $body): array
    {
        $posted = $body['roles'] ?? [];
        if (!is_array($posted)) {
            return [];
        }

        return array_values(array_map('strval', array_keys(array_filter($posted, fn($on) => $on === true || $on === '1' || $on === 1))));
    }

    /**
     * Applies the roles chosen on «add» to the contact just saved — in registration order,
     * each through the role's own write path. A refusal does not undo the contact: it is
     * saved, and the flash names the role and the reason, so the user switches it on later
     * in «Bearbeiten» once the missing piece is set up.
     *
     * @param list<string> $chosen
     */
    private function applyRolesToNew(Contact $contact, array $chosen): void
    {
        foreach ($this->contactRoles()->all() as $key => $role) {
            if (!in_array($key, $chosen, true)) {
                continue;
            }
            try {
                $role->switchOn($contact);
                $this->messageService->pushFlash('success', '«' . $contact->displayName() . '» ist jetzt ' . $role->label());
            } catch (ContactRoleRefusedException $e) {
                $this->messageService->pushFlash('error', $role->label() . ' nicht festgelegt: ' . $e->getMessage());
            }
        }
    }

    // ── contact: edit ────────────────────────────────────────────────────

    /**
     * «Kontakt bearbeiten»: the managed contact is NOT mutated here — the
     * cleaned values go to `ContactService::update()`, which validates a
     * detached draft first (ADR-039 decision 9). On refusal the draft (the
     * submitted values) is what the form shows again.
     */
    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $contact = $id ? $this->contacts()->find($id) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }
        $shown     = $contact;
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            $csrf = trim((string) ($body['entity_csrf'] ?? ''));
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'contact', $contact->getId())) {
                return $this->fetchError('Invalid token');
            }

            try {
                $this->contactService()->update($contact, ContactForm::contactValues($body));
                $this->messageService->pushFlash('success', 'Kontakt «' . $contact->displayName() . '» gespeichert');

                return $this->contactRowAnswer($contact)->addCommand('close-modal');
            } catch (InvalidContactException $e) {
                $validator = $e->validator;
                $shown     = $e->draft;
            }
        }

        $fields = (new ContactForm(addressRequired: false))->vars($this->addressTypes());

        return $this->renderContactForm($shown, ['entry' => $shown, 'validator' => $validator ?? new ContactValidator($shown), 'languages' => ContactForm::languages($shown->getLanguage())] + $fields);
    }

    /**
     * The contact dialog: `_contactFields` (+ on «add» the optional `_addressFields`) — the
     * field variables come from {@see ContactForm::vars()} — and `_roles`, the roles other
     * modules contribute (nothing when none is registered).
     *
     * @param array<string, mixed> $fields
     * @param list<string> $chosenRoles «add» only: the roles ticked before a refused save
     */
    private function renderContactForm(Contact $shown, array $fields, array $chosenRoles = []): HtmlResponse
    {
        $isNew    = $shown->getId() === null;
        $response = $this->html($fields + [
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('contact', $shown->getId()),
            'actionBase' => $this->contactListBase(),
            'roleVars'   => $this->roleVars($isNew ? null : $shown, $chosenRoles),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/ContactController', self::CONTACT_NS);

        return $response;
    }

    // ── contact: active switch ───────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $contact = $id ? $this->contacts()->find($id) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->contactService()->setActive($contact, (bool) ($body['value'] ?? !$contact->isActive()));
        } catch (InvalidContactException) {
            return $this->fetchError('Kontakt ist unvollständig — zuerst bearbeiten.');
        }

        return $this->contactRowAnswer($contact);
    }

    // ── contact: roles of other modules ──────────────────────────────────

    /**
     * A role switch in the contact dialog (`data-fetch-toggle`, session CSRF via header):
     * `?id=<contact>&role=<key>`, body `value` = the wanted state. The role's module decides
     * and writes ({@see \Z77\Module\Contact\Roles\ContactRoleInterface}); a refusal comes back
     * as its German sentence and core.js reverts the switch. Success answers with the roles
     * section in place (`contactRoles:<id>`) — the state line and the link change with it.
     */
    #[Fetch, HttpMethod('POST')]
    protected function toggleRoleAction(): FetchResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $contact = $id ? $this->contacts()->find($id) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }
        $role = $this->contactRoles()->role((string) $request->getGetParameter('role'));
        if ($role === null) {
            return $this->fetchError('Diese Rolle gibt es nicht.');
        }

        $wanted = (bool) ($request->getJsonBody()['value'] ?? false);
        if ($role->state($contact)->on !== $wanted) {
            try {
                if ($wanted) {
                    $role->switchOn($contact);
                } else {
                    $role->switchOff($contact);
                }
            } catch (ContactRoleRefusedException $e) {
                return $this->fetchError($e->getMessage());
            }
            $this->messageService->pushFlash('success', '«' . $contact->displayName() . '» ' . ($wanted ? 'ist jetzt ' : 'ist nicht mehr ') . $role->label());
        }

        $html = (new TemplateRenderer(self::CONTACT_NS))->partial('Backend/ContactController/_roles', $this->roleVars($contact) + [
            'actionBase' => $this->contactListBase(),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $contact->getId()])
            ->replaceRow('contactRoles', (int) $contact->getId(), $html);
    }

    /**
     * The variables of `_roles`: one entry per registered role with its label and the state
     * the role answers — for an existing contact {@see \Z77\Module\Contact\Roles\ContactRoleInterface::state()}, on
     * «add» `stateForNew()` plus whether it was ticked.
     *
     * @param list<string> $chosen
     * @return array{contactId: int|null, roles: list<array{key: string, label: string, state: \Z77\Module\Contact\Roles\ContactRoleState, chosen: bool}>}
     */
    private function roleVars(?Contact $contact, array $chosen = []): array
    {
        $roles = [];
        foreach ($this->contactRoles()->all() as $key => $role) {
            $roles[] = [
                'key'    => $key,
                'label'  => $role->label(),
                'state'  => $contact === null ? $role->stateForNew() : $role->state($contact),
                'chosen' => in_array($key, $chosen, true),
            ];
        }

        return ['contactId' => $contact?->getId(), 'roles' => $roles];
    }

    // ── address: add ─────────────────────────────────────────────────────

    /**
     * «Adresse hinzufügen» for the contact in `?id=` — GET renders the
     * modal, POST builds the link (NOT attached to the managed contact) and
     * hands it to the service, which attaches it only when valid.
     */
    protected function addAddressAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $contact = $id ? $this->contacts()->find($id) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }

        $address          = new Address();
        $typeCode         = 'main';
        $linkTitle        = '';
        $linkValidator    = null;
        $addressValidator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim((string) ($body['entity_csrf'] ?? ''));
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'contactAddressAdd', $contact->getId())) {
                return $this->fetchError('Invalid token');
            }

            $linkValues = ContactForm::linkValues($body);
            $typeCode   = (string) ($linkValues['type_code'] ?? '');
            $linkTitle  = (string) ($linkValues['title'] ?? '');
            $address->mapFromArray(ContactForm::addressValues($body));
            $link = new ContactAddress($contact, $typeCode, $address, $linkTitle);

            try {
                $this->contactService()->addAddress($link);
                $this->messageService->pushFlash('success', 'Adresse «' . $address->oneLine() . '» zu «' . $contact->displayName() . '» hinzugefügt');

                return $this->contactRowAnswer($contact)->addCommand('close-modal');
            } catch (InvalidAddressException $e) {
                $linkValidator    = $e->linkValidator;
                $addressValidator = $e->addressValidator;
            }
            // fall through: re-render with errors
        }

        return $this->renderAddressForm($contact, null, $address, $typeCode, $linkTitle, $this->addressTypes()->active(),
            DI::getCsrfService()->generateEntityToken('contactAddressAdd', $contact->getId()), $linkValidator, $addressValidator);
    }

    // ── address: edit ────────────────────────────────────────────────────

    /**
     * Edit an existing typed address (`?id=` is the link id). The managed
     * link and address are NOT mutated here — the cleaned values go to
     * `ContactService::saveAddress()`, which validates a draft first; on
     * refusal the draft is what the form shows. The select offers active
     * types plus the link's current one (a deactivated type may be kept,
     * never chosen).
     */
    protected function editAddressAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $link = $id ? $this->contactAddresses()->find($id) : null;
        if ($link === null) {
            return $this->fetchError('Adresse nicht gefunden');
        }

        $shown            = $link;
        $linkValidator    = null;
        $addressValidator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim((string) ($body['entity_csrf'] ?? ''));
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'contactAddress', $link->getId())) {
                return $this->fetchError('Invalid token');
            }

            try {
                $this->contactService()->saveAddress($link, ContactForm::linkValues($body), ContactForm::addressValues($body));
                $this->messageService->pushFlash('success', 'Adresse «' . $link->getAddress()->oneLine() . '» gespeichert');

                return $this->contactRowAnswer($link->getContact())->addCommand('close-modal');
            } catch (InvalidAddressException $e) {
                $linkValidator    = $e->linkValidator;
                $addressValidator = $e->addressValidator;
                $shown            = $e->link;   // the draft: the submitted values
            }
        }

        // Active types, plus the link's own when it is deactivated (history keeps it).
        $types   = $this->addressTypes()->active();
        $current = $this->addressTypes()->all()[$link->getTypeCode()] ?? null;
        if ($current !== null && !$current->isActive()) {
            $types[] = $current;
        }

        return $this->renderAddressForm($link->getContact(), $link, $shown->getAddress(), $shown->getTypeCode(), $shown->getTitle(), $types,
            DI::getCsrfService()->generateEntityToken('contactAddress', $link->getId()), $linkValidator, $addressValidator);
    }

    /** @param list<\Z77\Module\Contact\Entities\AddressType> $types */
    private function renderAddressForm(Contact $contact, ?ContactAddress $link, Address $address, string $typeCode, string $linkTitle, array $types, string $entityCsrf, $linkValidator, $addressValidator): HtmlResponse
    {
        $response = $this->html([
            'contact'          => $contact,
            'link'             => $link,
            'address'          => $address,
            'typeCode'         => $typeCode,
            'linkTitle'        => $linkTitle,
            'types'            => $types,
            'entityCsrf'       => $entityCsrf,
            'linkValidator'    => $linkValidator,
            'addressValidator' => $addressValidator,
            'actionBase'       => $this->contactListBase(),
        ]);
        $this->layoutManager->addPartials('editAddress', 'Backend/ContactController', self::CONTACT_NS);

        return $response;
    }

    // ── address: remove ──────────────────────────────────────────────────

    protected function confirmRemoveAddressAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $link = $id ? $this->contactAddresses()->find($id) : null;
        if ($link === null) {
            return $this->fetchError('Adresse nicht gefunden');
        }

        $response = $this->html([
            'entry'        => $link,
            'entityCsrf'   => DI::getCsrfService()->generateEntityToken('contactAddress', $id),
            'addressTypes' => $this->addressTypes(),
            'actionBase'   => $this->contactListBase(),
        ]);
        $this->layoutManager->addPartials('confirmRemoveAddress', 'Backend/ContactController', self::CONTACT_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAddressAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'contactAddress', $id)) {
            return $this->fetchError('Invalid token');
        }

        $link = $this->contactAddresses()->find($id);
        if ($link === null) {
            return $this->fetchError('Adresse nicht gefunden');
        }

        $line    = $link->getAddress()->oneLine();
        $contact = $link->getContact();
        $this->contactService()->removeAddress($link);

        $this->messageService->pushFlash('success', 'Adresse «' . $line . '» entfernt');

        return $this->contactRowAnswer($contact)->addCommand('close-modal');
    }

    // ── in-place answer ──────────────────────────────────────────────────

    /**
     * A save that changed one contact row answers with that row, not with `reload` (ADR-047
     * addendum 2026-10-10): `_row` — the partial the list renders — replaces the row in place.
     * Push the flash BEFORE calling: `fetch()` takes the in-place flash buffer when it is built.
     */
    private function contactRowAnswer(Contact $contact): FetchResponse
    {
        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $contact->getId()])
            ->replaceRow('contact', (int) $contact->getId(), $this->contactRowHtml($contact));
    }

    /** The contact's list row, rendered by the list's own partial. */
    private function contactRowHtml(Contact $contact): string
    {
        return (new TemplateRenderer(self::CONTACT_NS))->partial('Backend/ContactController/_row', [
            'contact'      => $contact,
            'links'        => $this->contactAddresses()->findByContact($contact),
            'kindLabels'   => ContactForm::kindLabels(),
            'addressTypes' => $this->addressTypes(),
            'actionBase'   => $this->contactListBase(),
        ]);
    }

    // ── row action hub (⋮) ───────────────────────────────────────────────

    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $contact = $id ? $this->contacts()->find($id) : null;
        if ($contact === null) {
            return $this->fetchError('Kontakt nicht gefunden');
        }

        $response = $this->html([
            'entry'        => $contact,
            'links'        => $this->contactAddresses()->findByContact($contact),
            'addressTypes' => $this->addressTypes(),
            'actionBase'   => $this->contactListBase(),
        ]);
        $this->layoutManager->addPartials('actions', 'Backend/ContactController', self::CONTACT_NS);

        return $response;
    }
}
