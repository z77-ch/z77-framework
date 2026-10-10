<?php
namespace Z77\Module\Contact\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Contact\Entities\AddressType,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Repositories\AddressTypeRepository,
    Z77\Module\Contact\Repositories\ContactAddressRepository,
    Z77\Module\Contact\Services\AddressTypeCodeChangedException,
    Z77\Module\Contact\Services\AddressTypeMasterData,
    Z77\Module\Contact\Validators\AddressTypeValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The address-type surface (plan §4a, «managed data») — mounted by a thin
 * host controller in the backend (ADR-018 pattern), like the contact screen.
 * Host side: `use AddressTypeControllerTrait` + a one-line layout config
 * delegating to {@see AddressTypeLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list every type with how many addresses carry it;
 *   - add a type; edit its label — the CODE is immutable once created
 *     (`contact_address` rows reference it, ADR-043 decision 19);
 *   - activate / deactivate (inline switch). There is NO delete.
 *
 * No JavaScript of its own. The using class MUST provide (via its host
 * base): `html()`, `fetch()`, `fetchError()`, `em()`, `$layoutManager`,
 * `$messageService`.
 */
trait AddressTypeControllerTrait
{
    private const ADDRESS_TYPE_NS = 'Z77\\Module\\Contact';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function addressTypeListBase(): string
    {
        return '/backend/contact/address-type';
    }

    private function addressTypeRepo(): AddressTypeRepository
    {
        return $this->em()->getRepository(AddressType::class);
    }

    private function addressTypeLinks(): ContactAddressRepository
    {
        return $this->em()->getRepository(ContactAddress::class);
    }

    private function addressTypeMasterData(): AddressTypeMasterData
    {
        return new AddressTypeMasterData($this->em());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $types = $this->addressTypeRepo()->allInOrder();
        $usage = [];
        foreach ($types as $type) {
            $usage[$type->getCode()] = $this->addressTypeLinks()->countByTypeCode($type->getCode());
        }

        return $this->html([
            'types'      => $types,
            'usage'      => $usage,
            'actionBase' => $this->addressTypeListBase(),
        ]);
    }

    // ── add / edit ───────────────────────────────────────────────────────

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->editType(new AddressType());
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $type = $id ? $this->addressTypeRepo()->find($id) : null;
        if ($type === null) {
            return $this->fetchError('Adresstyp nicht gefunden');
        }

        return $this->editType($type);
    }

    private function editType(AddressType $type): HtmlResponse|FetchResponse
    {
        $isNew     = $type->getId() === null;
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'addressType', $type->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $originalActive = $type->isActive();
            $type->mapFromArray(BodyCleaner::cleanFor(AddressType::class, $body));
            // `active` has its own switch on the list; the form never touches it.
            $type->setActive($isNew ? true : $originalActive);

            $validator = new AddressTypeValidator($type, $this->addressTypeRepo());
            if ($validator->isValid()) {
                try {
                    $this->addressTypeMasterData()->save($type);
                } catch (AddressTypeCodeChangedException) {
                    return $this->fetchError('Der Code ist nach dem Anlegen fix — für eine andere Adressart einen neuen Typ anlegen.');
                }

                if (!$isNew) {
                    $this->messageService->pushFlash('success', 'Adresstyp «' . $type->getLabel() . '» gespeichert');

                    return $this->addressTypeRowAnswer($type)->addCommand('close-modal');
                }

                $this->messageService->pushFlash('success', 'Adresstyp «' . $type->getLabel() . '» angelegt');

                // The new row goes where the list puts it: before the type that follows it.
                $next = null;
                $all  = $this->addressTypeRepo()->allInOrder();
                foreach ($all as $i => $candidate) {
                    if ($candidate->getId() === $type->getId()) {
                        $next = $all[$i + 1] ?? null;
                    }
                }

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $type->getId()])
                    ->insertRow('addressType', $this->addressTypeRowHtml($type), $next === null ? 'append' : 'before', '',
                        $next === null ? null : FetchResponse::rowTarget('addressType', (int) $next->getId()))
                    ->addCommand('remove-element', ['target' => FetchResponse::listTarget('addressType') . ' > .be-list__empty'])
                    ->addCommand('close-modal');
            }
            // validation failed — fall through to re-render the form with errors
        }

        $response = $this->html([
            'entry'      => $type,
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('addressType', $type->getId()),
            'validator'  => $validator ?? new AddressTypeValidator($type),
            'actionBase' => $this->addressTypeListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/AddressTypeController', self::ADDRESS_TYPE_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $type = $id ? $this->addressTypeRepo()->find($id) : null;
        if ($type === null) {
            return $this->fetchError('Adresstyp nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        $this->addressTypeMasterData()->setActive($type, (bool) ($body['value'] ?? !$type->isActive()));

        return $this->addressTypeRowAnswer($type);
    }

    // ── in-place answer ──────────────────────────────────────────────────

    /**
     * A save that changed one type row answers with that row, not with `reload` (ADR-047
     * addendum 2026-10-10): `_row` — the partial the list renders — replaces the row in place.
     * Push the flash BEFORE calling: `fetch()` takes the in-place flash buffer when it is built.
     */
    private function addressTypeRowAnswer(AddressType $type): FetchResponse
    {
        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $type->getId()])
            ->replaceRow('addressType', (int) $type->getId(), $this->addressTypeRowHtml($type));
    }

    /** The type's list row, rendered by the list's own partial. */
    private function addressTypeRowHtml(AddressType $type): string
    {
        return (new TemplateRenderer(self::ADDRESS_TYPE_NS))->partial('Backend/AddressTypeController/_row', [
            'type'       => $type,
            'count'      => $this->addressTypeLinks()->countByTypeCode($type->getCode()),
            'actionBase' => $this->addressTypeListBase(),
        ]);
    }
}
