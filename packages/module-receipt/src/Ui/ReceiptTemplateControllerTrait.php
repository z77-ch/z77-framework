<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Receipt\Entities\Receipt,
    Z77\Module\Receipt\Entities\ReceiptTemplate,
    Z77\Module\Receipt\Services\InvalidMasterDataException,
    Z77\Module\Receipt\Services\MasterDataCodeChangedException,
    Z77\Module\Receipt\Services\ReceiptException,
    Z77\Module\Receipt\Services\ReceiptTemplateService,
    Z77\Module\Receipt\Validators\ReceiptTemplateValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Finanzen › «Belegvorlagen» (board «Belegvorlagen», owner-approved 2026-11-12; E2,
 * E3, E10) — the phone's tiles: what was bought (Soll, VAT code), never how it was paid (that is the
 * payment method, {@see PaymentMethodControllerTrait}). Mounted by a thin host controller in the
 * backend (ADR-018 pattern): URL `/backend/finance/receipt-template/…`.
 *
 *   - `list` — a STANDARD LIST ({@see MasterDataListing::templates()}) in tile order: the state icon
 *     opens the template WINDOW, then symbol, name, code, Soll, MWST, Reihenfolge, how many receipts
 *     carry it, the «Aktiv» switch. Action cell: «+ Vorlage»;
 *   - `add` / `edit` — the template WINDOW (ADR-047, help per field ADR-048): name, code (fixed after
 *     creation), symbol (the fixed set `ReceiptTemplate::ICONS`), Soll, MWST-Code («ohne MWST» a
 *     deliberate choice), Reihenfolge;
 *   - `toggle-active` — the inline switch. There is NO delete: receipts keep the code; a template no
 *     longer wanted is switched off (`ReceiptTemplateService` has no delete, receipt.md).
 *
 * Every write goes through {@see ReceiptTemplateService} (validator with the references). No
 * JavaScript of its own (Rule 7). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait ReceiptTemplateControllerTrait
{
    use MasterDataAnswers;

    private const RECEIPT_TEMPLATE_NS = 'Z77\\Module\\Receipt';

    /** URL root of THIS mount. */
    protected function receiptTemplateBase(): string
    {
        return ReceiptLayout::TEMPLATE_BASE;
    }

    private function receiptTemplates(): ReceiptTemplateService
    {
        return new ReceiptTemplateService($this->em());
    }

    /** code → receipts carrying it. */
    private function receiptTemplateUsage(): array
    {
        return $this->em()->getRepository(Receipt::class)->countByTemplate();
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = MasterDataListing::templates();
        $state      = $definition->readRequest($request);
        $rows       = $this->receiptTemplates()->all();
        if ($state->descending) {
            $rows = array_reverse($rows);
        }
        $fetch = ListDefinition::isFetch($request);
        if (!$fetch) {
            $this->help->attach('Backend/ReceiptTemplateController/list.help', self::RECEIPT_TEMPLATE_NS, [], 'Hilfe: Belegvorlagen', toolbar: true);
        }
        $response = $this->html([
            'rows'       => $rows,
            'usage'      => $this->receiptTemplateUsage(),
            'choices'    => new ReceiptChoices($this->em()),
            'definition' => $definition,
            'state'      => $state,
            'actionBase' => $this->receiptTemplateBase(),
        ]);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/ReceiptTemplateController', self::RECEIPT_TEMPLATE_NS, 'hc1');
        }

        return $response;
    }

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $position = 10;
        foreach ($this->receiptTemplates()->all() as $template) {
            $position = max($position, $template->getPosition() + 10);
        }

        return $this->receiptTemplateWindow(new ReceiptTemplate(['position' => $position]));
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $template = $id > 0 ? $this->em()->getRepository(ReceiptTemplate::class)->find($id) : null;
        if (!$template instanceof ReceiptTemplate) {
            return $this->masterDataRefuse('Belegvorlage nicht gefunden', $this->receiptTemplateBase() . '/list');
        }

        return $this->receiptTemplateWindow($template);
    }

    /**
     * The template window (ADR-047): GET renders it, POST saves through the service. Fetched, the
     * save closes the window and reloads the list region; a refusal re-renders the window with the
     * field errors. Without the script it is a page.
     */
    private function receiptTemplateWindow(ReceiptTemplate $template): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request   = DI::getRequest();
        $isNew     = $template->getId() === null;
        $validator = new ReceiptTemplateValidator($template);
        $listUrl   = $this->receiptTemplateBase() . '/list';

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$isNew && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'receiptTemplate', (int) $template->getId())) {
                return $this->masterDataRefuse('Die Belegvorlage wurde inzwischen geändert — bitte neu laden.', $listUrl);
            }
            $active = $template->isActive();
            $values = BodyCleaner::cleanFor(ReceiptTemplate::class, $post);
            unset($values['active'], $values['id']);
            if (!$isNew) {
                $values['code'] = $template->getCode();   // fixed after creation — the service refuses a change anyway
            }
            $template->mapFromArray($values);
            $template->setActive($isNew ? true : $active);   // the list's switch owns `active`

            try {
                $this->receiptTemplates()->save($template);

                return $this->masterDataSaved('Belegvorlage «' . $template->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'), $listUrl);
            } catch (MasterDataCodeChangedException) {
                return $this->masterDataRefuse('Der Code ist nach dem Anlegen fix — für eine andere Bedeutung eine neue Vorlage anlegen.', $listUrl);
            } catch (InvalidMasterDataException $e) {
                $validator = $e->validator;
            }
        }

        $choices = new ReceiptChoices($this->em());
        $this->help->attach('Backend/ReceiptTemplateController/edit.help', self::RECEIPT_TEMPLATE_NS, [], 'Hilfe: Belegvorlage');
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'entry'      => $template,
            'validator'  => $validator,
            'choices'    => $choices,
            'accounts'   => $choices->accounts(),
            'taxCodes'   => $choices->taxCodes([$template->getTaxCode()]),
            'usage'      => $isNew ? 0 : ($this->receiptTemplateUsage()[$template->getCode()] ?? 0),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('receiptTemplate', (int) $template->getId()),
            'actionBase' => $this->receiptTemplateBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/ReceiptTemplateController', self::RECEIPT_TEMPLATE_NS);

        return $response;
    }

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id       = (int) DI::getRequest()->getGetParameter('id');
        $template = $id > 0 ? $this->em()->getRepository(ReceiptTemplate::class)->find($id) : null;
        if (!$template instanceof ReceiptTemplate) {
            return $this->fetchError('Belegvorlage nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        try {
            $this->receiptTemplates()->setActive($template, (bool) ($body['value'] ?? !$template->isActive()));
        } catch (ReceiptException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->masterDataRow('Backend/ReceiptTemplateController/_row', 'receiptTemplate', $id, [
            'row'        => $template,
            'usage'      => $this->receiptTemplateUsage()[$template->getCode()] ?? 0,
            'choices'    => new ReceiptChoices($this->em()),
            'actionBase' => $this->receiptTemplateBase(),
        ]);
    }
}
