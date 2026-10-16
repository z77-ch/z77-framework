<?php
namespace Z77\Module\Order\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Order\Entities\OrderPhase,
    Z77\Module\Order\Entities\OrderStatus,
    Z77\Module\Order\Repositories\OrderStatusRepository,
    Z77\Module\Order\Services\InvalidOrderStatusException,
    Z77\Module\Order\Services\OrderException,
    Z77\Module\Order\Services\OrderMasterData,
    Z77\Module\Order\Services\OrderStatusCodeChangedException,
    Z77\Module\Order\Validators\OrderStatusValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Aufträge › Auftragsstatus (plan §9.5, step 3) — the order statuses as DATA: labels
 * the installation orders its orders by, each in one fixed PHASE that carries the behaviour (ADR-043
 * addendum 2026-10-15); a new status is added here without a line of code. Mounted by a thin host controller in
 * the backend (ADR-018 pattern): `use OrderStatusControllerTrait` + a one-line layout config
 * delegating to {@see OrderStatusLayout::config()}; URL `/backend/order/order-status/…`.
 *
 *   - `list` — a STANDARD LIST ({@see OrderStatusListing}) along the ladder: the state icon opens
 *     the status WINDOW, then code, label, phase, «Sortierung», «Wird gesetzt durch» and the «Aktiv» switch.
 *     Action cell: «+ Status»;
 *   - `add` / `edit` — the status WINDOW (ADR-047; a window, not a modal popup, because it carries
 *     help — ADR-048, the article model; deviation from plan §9.5's «⋮ → edit dialog»): code
 *     (fixed after creation), label, phase (a select of the fixed phases), «Sortierung» and the default switch
 *     worded by the phase («Startstatus für neue Aufträge», «Wird gesetzt beim …» — the status an action sets); a second active default of a phase is a field error;
 *   - `toggle-active` — the inline switch; deactivate always, activate only when the cross-row
 *     rule holds (one active default per phase). There is NO delete.
 *
 * Every write goes through {@see OrderMasterData}. No JavaScript of its own (Rule 7). The using
 * class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`, `redirect()`,
 * `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait OrderStatusControllerTrait
{
    private const ORDER_STATUS_NS = 'Z77\\Module\\Order';

    /** URL root of THIS mount. */
    protected function orderStatusListBase(): string
    {
        return OrderStatusLayout::BASE;
    }

    private function orderStatuses(): OrderStatusRepository
    {
        return $this->em()->getRepository(OrderStatus::class);
    }

    private function orderMasterData(): OrderMasterData
    {
        return new OrderMasterData($this->em());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = OrderStatusListing::definition();
        $state      = $definition->readRequest($request);
        $rows       = $this->orderStatuses()->allInOrder();
        if ($state->descending) {
            $rows = array_reverse($rows);
        }

        $response = $this->html([
            'rows'       => $rows,
            'definition' => $definition,
            'state'      => $state,
            'actionBase' => $this->orderStatusListBase(),
        ]);
        // The fragment owns its header slot: «+ Status» is the entry's most frequent action → the
        // action cell (backend-screen.md §2). A FETCH of the region (sort) wants the list alone.
        if (!ListDefinition::isFetch($request)) {
            $this->layoutManager->addPartials('act', 'Backend/OrderStatusController', self::ORDER_STATUS_NS, 'hc1');
        }

        return $response;
    }

    // ── add / edit (window) ───────────────────────────────────────────────

    #[Csrf]
    protected function addAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        return $this->orderStatusWindow(new OrderStatus(['phase' => OrderPhase::New->value, 'position' => 10]));
    }

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $status = $id ? $this->orderStatuses()->find($id) : null;
        if ($status === null) {
            return $this->orderStatusRefuse('Auftragsstatus nicht gefunden');
        }

        return $this->orderStatusWindow($status);
    }

    /**
     * The status window (ADR-047): GET renders it, POST saves through {@see OrderMasterData}.
     * Fetched, the save answers with commands — close the window, reload the list region it was
     * opened from (a new row, or one moved to another phase, changes its place); a refusal re-renders the window
     * with the field errors. Without the script it is a page: a plain form, redirect to the list.
     */
    private function orderStatusWindow(OrderStatus $status): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request   = DI::getRequest();
        $isNew     = $status->getId() === null;
        $validator = new OrderStatusValidator($status);

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!$isNew && !DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'orderStatus', $status->getId())) {
                return $this->orderStatusRefuse('Der Status wurde inzwischen geändert — bitte neu laden.');
            }

            $active = $status->isActive();
            $values = BodyCleaner::cleanFor(OrderStatus::class, $post);
            $values['start']         = ($post['start'] ?? '') === '1';           // switches: present = on
            $values['phase_default'] = ($post['phase_default'] ?? '') === '1';
            unset($values['active'], $values['id']);
            if (!$isNew) {
                $values['code'] = $status->getCode();   // fixed after creation — the service refuses a change anyway
            }
            $status->mapFromArray($values);
            $status->setActive($isNew ? true : $active);   // the list's switch owns `active`

            try {
                $this->orderMasterData()->save($status);

                return $this->orderStatusSaved('Auftragsstatus «' . $status->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'));
            } catch (OrderStatusCodeChangedException) {
                return $this->orderStatusRefuse('Der Code ist nach dem Anlegen fix — für eine andere Bedeutung einen neuen Status anlegen.');
            } catch (InvalidOrderStatusException $e) {
                $validator = $e->validator;
            }
            // fall through — re-render the window with the errors and what was typed
        }

        $context = [
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'entry'      => $status,
            'validator'  => $validator,
            'phases'     => OrderPhase::choices(),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('orderStatus', $status->getId()),
            'actionBase' => $this->orderStatusListBase(),
        ];
        // Attached BEFORE html(): the base controller renders the attached help into the answer.
        $this->help->attach('Backend/OrderStatusController/edit.help', self::ORDER_STATUS_NS, [], 'Hilfe: Auftragsstatus');

        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/OrderStatusController', self::ORDER_STATUS_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $status = $id ? $this->orderStatuses()->find($id) : null;
        if ($status === null) {
            return $this->fetchError('Auftragsstatus nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        try {
            $this->orderMasterData()->setActive($status, (bool) ($body['value'] ?? !$status->isActive()));
        } catch (InvalidOrderStatusException $e) {
            return $this->fetchError(implode(' ', $e->validator->getFieldErrors()));
        } catch (OrderException $e) {
            return $this->fetchError($e->getMessage());
        }

        return $this->orderStatusRowAnswer($status);
    }

    // ── answers ──────────────────────────────────────────────────────────

    /** Close the window and reload the list region it came from; without the script: flash + list. */
    private function orderStatusSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->orderStatusListBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    private function orderStatusRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->orderStatusListBase() . '/list', 303);
    }

    /** A switch answers with its row (FETCH-ROW-001): `_row` — the partial the list renders. */
    private function orderStatusRowAnswer(OrderStatus $status): FetchResponse
    {
        $html = (new TemplateRenderer(self::ORDER_STATUS_NS))->partial('Backend/OrderStatusController/_row', [
            'row'        => $status,
            'actionBase' => $this->orderStatusListBase(),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $status->getId()])
            ->replaceRow('orderStatus', (int) $status->getId(), $html);
    }
}
