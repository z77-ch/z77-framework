<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Receipt\Entities\PaymentMethod,
    Z77\Module\Receipt\Entities\Receipt,
    Z77\Module\Receipt\Services\InvalidMasterDataException,
    Z77\Module\Receipt\Services\MasterDataCodeChangedException,
    Z77\Module\Receipt\Services\PaymentMethodService,
    Z77\Module\Receipt\Services\ReceiptException,
    Z77\Module\Receipt\Validators\PaymentMethodValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Finanzen › «Zahlungsarten» — on the phone and in the receipt «Bezahlt mit» (owner
 * decision E10, 2026-11-12): Kasse, Firmenkarte, privat ausgelegt, each with the CREDIT account a
 * receipt paid that way is booked against. Built like «Belegvorlagen» (the same look and behaviour,
 * {@see ReceiptTemplateControllerTrait}); mounted by a thin host controller in the backend (ADR-018
 * pattern): URL `/backend/finance/payment-method/…`.
 *
 *   - `list` — a STANDARD LIST ({@see MasterDataListing::paymentMethods()}): state icon, name, code,
 *     Haben, Reihenfolge, how many receipts carry it, the «Aktiv» switch. Action cell: «+ Zahlungsart»;
 *   - `add` / `edit` — the WINDOW: name, code (fixed after creation), Haben, Reihenfolge;
 *   - `toggle-active` — the inline switch. There is NO delete (receipts keep the code).
 *
 * Every write goes through {@see PaymentMethodService}. No JavaScript of its own (Rule 7). The using
 * class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`, `redirect()`, `em()`,
 * `$layoutManager`, `$messageService`, `$help`.
 */
trait PaymentMethodControllerTrait
{
    use MasterDataAnswers;

    private const PAYMENT_METHOD_NS = 'Z77\\Module\\Receipt';

    /** URL root of THIS mount. */
    protected function paymentMethodBase(): string
    {
        return ReceiptLayout::PAYMENT_METHOD_BASE;
    }

    private function paymentMethods(): PaymentMethodService
    {
        return new PaymentMethodService($this->em());
    }

    /** code → receipts carrying it. */
    private function paymentMethodUsage(): array
    {
        return $this->em()->getRepository(Receipt::class)->countByPaymentMethod();
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = MasterDataListing::paymentMethods();
        $state      = $definition->readRequest($request);
        $rows       = $this->paymentMethods()->all();
        if ($state->descending) {
            $rows = array_reverse($rows);
        }
        $fetch = ListDefinition::isFetch($request);
        if (!$fetch) {
            $this->help->attach('Backend/PaymentMethodController/list.help', self::PAYMENT_METHOD_NS, [], 'Hilfe: Zahlungsarten', toolbar: true);
        }
        $response = $this->html([
            'rows'       => $rows,
            'usage'      => $this->paymentMethodUsage(),
            'choices'    => new ReceiptChoices($this->em()),
            'definition' => $definition,
            'state'      => $state,
            'actionBase' => $this->paymentMethodBase(),
        ]);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/PaymentMethodController', self::PAYMENT_METHOD_NS, 'hc1');
        }

        return $response;
    }

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $position = 10;
        foreach ($this->paymentMethods()->all() as $method) {
            $position = max($position, $method->getPosition() + 10);
        }

        return $this->paymentMethodWindow(new PaymentMethod(['position' => $position]));
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $method = $id > 0 ? $this->em()->getRepository(PaymentMethod::class)->find($id) : null;
        if (!$method instanceof PaymentMethod) {
            return $this->masterDataRefuse('Zahlungsart nicht gefunden', $this->paymentMethodBase() . '/list');
        }

        return $this->paymentMethodWindow($method);
    }

    /** The payment-method window (ADR-047): GET renders it, POST saves through the service. */
    private function paymentMethodWindow(PaymentMethod $method): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request   = DI::getRequest();
        $isNew     = $method->getId() === null;
        $validator = new PaymentMethodValidator($method);
        $listUrl   = $this->paymentMethodBase() . '/list';

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$isNew && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'paymentMethod', (int) $method->getId())) {
                return $this->masterDataRefuse('Die Zahlungsart wurde inzwischen geändert — bitte neu laden.', $listUrl);
            }
            $active = $method->isActive();
            $values = BodyCleaner::cleanFor(PaymentMethod::class, $post);
            unset($values['active'], $values['id']);
            if (!$isNew) {
                $values['code'] = $method->getCode();   // fixed after creation — the service refuses a change anyway
            }
            $method->mapFromArray($values);
            $method->setActive($isNew ? true : $active);   // the list's switch owns `active`

            try {
                $this->paymentMethods()->save($method);

                return $this->masterDataSaved('Zahlungsart «' . $method->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'), $listUrl);
            } catch (MasterDataCodeChangedException) {
                return $this->masterDataRefuse('Der Code ist nach dem Anlegen fix — für eine andere Bedeutung eine neue Zahlungsart anlegen.', $listUrl);
            } catch (InvalidMasterDataException $e) {
                $validator = $e->validator;
            }
        }

        $choices = new ReceiptChoices($this->em());
        $this->help->attach('Backend/PaymentMethodController/edit.help', self::PAYMENT_METHOD_NS, [], 'Hilfe: Zahlungsart');
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'entry'      => $method,
            'validator'  => $validator,
            'choices'    => $choices,
            'accounts'   => $choices->accounts(),
            'usage'      => $isNew ? 0 : ($this->paymentMethodUsage()[$method->getCode()] ?? 0),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('paymentMethod', (int) $method->getId()),
            'actionBase' => $this->paymentMethodBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/PaymentMethodController', self::PAYMENT_METHOD_NS);

        return $response;
    }

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $method = $id > 0 ? $this->em()->getRepository(PaymentMethod::class)->find($id) : null;
        if (!$method instanceof PaymentMethod) {
            return $this->fetchError('Zahlungsart nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        try {
            $this->paymentMethods()->setActive($method, (bool) ($body['value'] ?? !$method->isActive()));
        } catch (ReceiptException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->masterDataRow('Backend/PaymentMethodController/_row', 'paymentMethod', $id, [
            'row'        => $method,
            'usage'      => $this->paymentMethodUsage()[$method->getCode()] ?? 0,
            'choices'    => new ReceiptChoices($this->em()),
            'actionBase' => $this->paymentMethodBase(),
        ]);
    }
}
