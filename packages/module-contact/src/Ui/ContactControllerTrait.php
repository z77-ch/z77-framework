<?php
namespace Z77\Module\Contact\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Contact\Entities\Address,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Entities\ContactKind,
    Z77\Module\Contact\Repositories\ContactAddressRepository,
    Z77\Module\Contact\Repositories\ContactRepository,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Services\InvalidAddressException,
    Z77\Module\Contact\Services\InvalidContactException,
    Z77\Module\Contact\Validators\ContactValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
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
 *     is never switched to one.
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

    /** Address fields arrive in the contact form under this prefix (they collide with the contact's own names otherwise). */
    private const ADDRESS_PREFIX = 'address_';

    /** German display labels — PRESENTATION ONLY (the `ROLE_LABELS` pattern). The kind SET is {@see ContactKind}. */
    private const KIND_LABELS = [
        'person'       => 'Person',
        'organisation' => 'Organisation',
    ];

    /** Offered when the site's own languages do not cover a contact — the Swiss set. */
    private const CONTACT_LANGUAGES = ['de', 'fr', 'it', 'en'];

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

    /** @return array<string,string> kind value → German label, for selects */
    private function contactKindLabels(): array
    {
        $labels = [];
        foreach (ContactKind::cases() as $kind) {
            $labels[$kind->value] = self::KIND_LABELS[$kind->value] ?? $kind->value;
        }

        return $labels;
    }

    /** @return list<string> the site's languages first, then the common set, the contact's own always included */
    private function contactLanguages(string $current = ''): array
    {
        $languages = [...DI::getI18n()->getLanguages(), ...self::CONTACT_LANGUAGES];
        if ($current !== '') {
            $languages[] = $current;
        }

        return array_values(array_unique($languages));
    }

    /** The contact's own fields of a posted form, cleaned; `active` has its own switch and never comes from the form. */
    private function contactValues(array $body): array
    {
        $values = BodyCleaner::cleanFor(Contact::class, $body);
        unset($values['active']);

        return $values;
    }

    /** The address block of a posted form: `address_first_name` → `first_name`, …, cleaned for {@see Address}. */
    private function addressValues(array $body): array
    {
        $raw = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::ADDRESS_PREFIX)) {
                $raw[substr($key, strlen(self::ADDRESS_PREFIX))] = $value;
            }
        }

        return BodyCleaner::cleanFor(Address::class, $raw);
    }

    /** The link fields of a posted form (`type_code`, `title`), cleaned for {@see ContactAddress}. */
    private function linkValues(array $body): array
    {
        return BodyCleaner::cleanFor(ContactAddress::class, ['type_code' => $body['type_code'] ?? '', 'title' => $body['title'] ?? '']);
    }

    /** True when nothing of the address block was filled in (the block is optional on «add»). */
    private function addressValuesAreEmpty(array $addressValues): bool
    {
        foreach ($addressValues as $key => $value) {
            if ($key !== 'country' && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
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
            'kindLabels'     => $this->contactKindLabels(),
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
        $contact = new Contact();
        $contact->setLanguage(DI::getI18n()->getDefaultLanguage());
        $address          = new Address();
        $typeCode         = 'main';
        $linkTitle        = '';
        $validator        = null;
        $linkValidator    = null;
        $addressValidator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            $contact->mapFromArray($this->contactValues($body));

            $addressValues = $this->addressValues($body);
            $linkValues    = $this->linkValues($body);
            $typeCode      = (string) ($linkValues['type_code'] ?? $typeCode);
            $linkTitle     = (string) ($linkValues['title'] ?? '');
            if (!$this->addressValuesAreEmpty($addressValues)) {
                $address->mapFromArray($addressValues);
                $contact->addAddress(new ContactAddress($contact, $typeCode, $address, $linkTitle));
            }

            try {
                $this->contactService()->save($contact);
                $this->messageService->pushFlashAfterRedirect('success', 'Kontakt «' . $contact->displayName() . '» angelegt');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $contact->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            } catch (InvalidContactException $e) {
                $validator = $e->validator;
            } catch (InvalidAddressException $e) {
                $linkValidator    = $e->linkValidator;
                $addressValidator = $e->addressValidator;
            }
            // validation failed — fall through to re-render the form with errors
        }

        return $this->renderContactForm($contact, $validator, $address, $typeCode, $linkTitle, $linkValidator, $addressValidator);
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
                $this->contactService()->update($contact, $this->contactValues($body));
                $this->messageService->pushFlashAfterRedirect('success', 'Kontakt «' . $contact->displayName() . '» gespeichert');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $contact->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            } catch (InvalidContactException $e) {
                $validator = $e->validator;
                $shown     = $e->draft;
            }
        }

        return $this->renderContactForm($shown, $validator, new Address(), 'main', '', null, null);
    }

    private function renderContactForm(Contact $shown, ?ContactValidator $validator, Address $address, string $typeCode, string $linkTitle, $linkValidator, $addressValidator): HtmlResponse
    {
        $isNew    = $shown->getId() === null;
        $response = $this->html([
            'entry'            => $shown,
            'entityCsrf'       => $isNew ? '' : DI::getCsrfService()->generateEntityToken('contact', $shown->getId()),
            'validator'        => $validator ?? new ContactValidator($shown),
            'kindLabels'       => $this->contactKindLabels(),
            'languages'        => $this->contactLanguages($shown->getLanguage()),
            // The optional first address (add only).
            'address'          => $address,
            'typeCode'         => $typeCode,
            'linkTitle'        => $linkTitle,
            'types'            => $this->addressTypes()->active(),
            'linkValidator'    => $linkValidator,
            'addressValidator' => $addressValidator,
            'actionBase'       => $this->contactListBase(),
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

        return $this->fetch()->setStatus('success')->addCommand('reload');
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

            $linkValues = $this->linkValues($body);
            $typeCode   = (string) ($linkValues['type_code'] ?? '');
            $linkTitle  = (string) ($linkValues['title'] ?? '');
            $address->mapFromArray($this->addressValues($body));
            $link = new ContactAddress($contact, $typeCode, $address, $linkTitle);

            try {
                $this->contactService()->addAddress($link);
                $this->messageService->pushFlashAfterRedirect('success', 'Adresse «' . $address->oneLine() . '» zu «' . $contact->displayName() . '» hinzugefügt');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $link->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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
                $this->contactService()->saveAddress($link, $this->linkValues($body), $this->addressValues($body));
                $this->messageService->pushFlashAfterRedirect('success', 'Adresse «' . $link->getAddress()->oneLine() . '» gespeichert');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $link->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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

        $line = $link->getAddress()->oneLine();
        $this->contactService()->removeAddress($link);

        $this->messageService->pushFlashAfterRedirect('success', 'Adresse «' . $line . '» entfernt');

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
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
