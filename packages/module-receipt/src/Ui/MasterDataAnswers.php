<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Shared\Listing\ListDefinition
;

/**
 * The answers the two master-data windows of the receipt module share («Belegvorlagen»,
 * «Zahlungsarten» — one look, one behaviour): a save closes the window and reloads the list region
 * it was opened from, a refusal flashes, the «Aktiv» switch answers with its row (FETCH-ROW-001).
 * Used by {@see ReceiptTemplateControllerTrait} and {@see PaymentMethodControllerTrait}; the using
 * class provides `fetch()`, `fetchError()`, `redirect()`, `$messageService`.
 */
trait MasterDataAnswers
{
    /** Close the window and reload the list region it came from; without the script: flash + the list. */
    private function masterDataSaved(string $flash, string $listUrl): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($listUrl, 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    private function masterDataRefuse(string $message, string $listUrl): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($listUrl, 303);
    }

    /** A switch answers with its row: `$partial` — the partial the list renders — in place of `data-entity="$type:$id"`. */
    private function masterDataRow(string $partial, string $type, int $id, array $context): FetchResponse
    {
        $html = (new TemplateRenderer(ReceiptLayout::NS))->partial($partial, $context);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $id])
            ->replaceRow($type, $id, $html);
    }
}
