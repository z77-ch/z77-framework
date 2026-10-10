<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Debtor\Entities\PaymentTarget,
    Z77\Module\Debtor\Repositories\PaymentTargetRepository,
    Z77\Module\Debtor\Services\Creditor,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DebtorMasterData,
    Z77\Module\Debtor\Services\InvalidMasterDataException,
    Z77\Module\Debtor\Services\MasterDataCodeChangedException,
    Z77\Module\Debtor\Validators\PaymentTargetValidator,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The payment-target surface (plan §6.1) — the company's bank accounts a
 * customer pays into. Mounted by a thin host controller in the backend
 * (ADR-018 pattern). Host side: `use PaymentTargetControllerTrait` + a
 * one-line layout config delegating to {@see PaymentTargetLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list every target with its QR-IBAN and / or IBAN grouped in fours,
 *     the ledger account it is booked on — flagged when the bookkeeping
 *     will not take a posting on it — and the EFFECTIVE creditor block
 *     ({@see Creditor}: the holder fields, the mandator's where empty);
 *   - add a target; edit label, the two IBAN fields, the holder and the
 *     account — the CODE is immutable once created (payments carry it,
 *     ADR-043 decision 19);
 *   - activate / deactivate (inline switch). There is NO delete.
 *
 * Nothing is seeded here: an IBAN cannot be guessed, and a placeholder
 * would end up printed on a QR-bill. The first row the screen writes
 * creates the collection file.
 *
 * No JavaScript of its own. The using class MUST provide (via its host
 * base): `html()`, `fetch()`, `fetchError()`, `em()`, `$layoutManager`,
 * `$messageService`.
 */
trait PaymentTargetControllerTrait
{
    private const PAYMENT_TARGET_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function paymentTargetListBase(): string
    {
        return '/backend/finance/payment-target';
    }

    private function paymentTargets(): PaymentTargetRepository
    {
        return $this->em()->getRepository(PaymentTarget::class);
    }

    private function targetMasterData(): DebtorMasterData
    {
        return new DebtorMasterData($this->em());
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $targets = $this->paymentTargets()->allInOrder();
        $check   = new LedgerAccountCheck($this->em());

        $mandator     = Creditor::mandator($this->em());
        $accountState = [];
        $creditors    = [];
        foreach ($targets as $target) {
            $accountState[$target->getCode()] = $check->isPostable($target->getAccountNumber());
            $creditors[$target->getCode()]    = Creditor::of($target, $mandator);
        }

        $response = $this->html([
            'targets'      => $targets,
            'accountState' => $accountState,
            'creditors'    => $creditors,
            'ledgerKnown'  => $check->available(),
            'actionBase'   => $this->paymentTargetListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»): the add action is the
        // entry's most frequent action → the action cell (ADR-033 rev. 2026-10-08).
        $this->layoutManager->addPartials('act', 'Backend/PaymentTargetController', self::PAYMENT_TARGET_NS, 'hc1');

        return $response;
    }

    // ── add / edit ───────────────────────────────────────────────────────

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->editTarget(new PaymentTarget());
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $target = $id ? $this->paymentTargets()->find($id) : null;
        if ($target === null) {
            return $this->fetchError('Zahlungsziel nicht gefunden');
        }

        return $this->editTarget($target);
    }

    private function editTarget(PaymentTarget $target): HtmlResponse|FetchResponse
    {
        $isNew     = $target->getId() === null;
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'paymentTarget', $target->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $originalActive = $target->isActive();
            $target->mapFromArray(BodyCleaner::cleanFor(PaymentTarget::class, $body));
            // `active` has its own switch on the list; the form never touches it.
            $target->setActive($isNew ? true : $originalActive);

            try {
                $this->targetMasterData()->saveTarget($target);

                $this->messageService->pushFlash('success', 'Zahlungsziel «' . $target->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert'));

                return $this->paymentTargetRowAnswer($target, $isNew)->addCommand('close-modal');
            } catch (MasterDataCodeChangedException) {
                return $this->fetchError('Der Code ist nach dem Anlegen fix — für ein anderes Konto ein neues Zahlungsziel anlegen.');
            } catch (InvalidMasterDataException $e) {
                /** @var PaymentTargetValidator $validator */
                $validator = $e->validator;
            }
            // fall through — re-render the form with the errors
        }

        $response = $this->html([
            'entry'       => $target,
            'mandator'    => Creditor::mandator($this->em()),
            'ledgerKnown' => (new LedgerAccountCheck($this->em()))->available(),
            'entityCsrf'  => $isNew ? '' : DI::getCsrfService()->generateEntityToken('paymentTarget', $target->getId()),
            'validator'   => $validator ?? new PaymentTargetValidator($target),
            'actionBase'  => $this->paymentTargetListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/PaymentTargetController', self::PAYMENT_TARGET_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id     = (int) DI::getRequest()->getGetParameter('id');
        $target = $id ? $this->paymentTargets()->find($id) : null;
        if ($target === null) {
            return $this->fetchError('Zahlungsziel nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->targetMasterData()->setTargetActive($target, (bool) ($body['value'] ?? !$target->isActive()));
        } catch (DebtorException $e) {
            // A hand-edited file can still refuse here; the switch says so instead of answering 500.
            return $this->fetchError($e->getMessage());
        }

        return $this->paymentTargetRowAnswer($target);
    }

    // ── in-place answer ──────────────────────────────────────────────────

    /**
     * A save or a switch that changed one target answers with that row, not with `reload`
     * (ADR-047 addendum 2026-10-10): the row re-rendered through `_row` — the partial the list
     * renders, with the account check and the effective creditor block — replaces its node
     * (`replaceRow`); a NEW target is appended (`insertRow`, the empty-list sentence goes).
     * core.js wires the switch and the ⋮ of the inserted HTML. Push the flash BEFORE calling.
     */
    private function paymentTargetRowAnswer(PaymentTarget $target, bool $isNew = false): FetchResponse
    {
        $html = (new TemplateRenderer(self::PAYMENT_TARGET_NS))->partial('Backend/PaymentTargetController/_row', [
            'target'     => $target,
            'postable'   => (new LedgerAccountCheck($this->em()))->isPostable($target->getAccountNumber()),
            'creditor'   => Creditor::of($target, Creditor::mandator($this->em())),
            'actionBase' => $this->paymentTargetListBase(),
        ]);
        $response = $this->fetch()->setStatus('success')->setData(['id' => $target->getId()]);
        if (!$isNew) {
            return $response->replaceRow('paymentTarget', (int) $target->getId(), $html);
        }

        return $response
            ->addCommand('remove-element', ['target' => FetchResponse::listTarget('paymentTarget') . ' > .be-list__empty'])
            ->insertRow('paymentTarget', $html);
    }
}
