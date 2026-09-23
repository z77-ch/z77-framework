<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Debtor\Entities\PaymentTarget,
    Z77\Module\Debtor\Repositories\PaymentTargetRepository,
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
 *   - list every target with its IBAN grouped in fours, a «QR-IBAN» badge
 *     where the IID says so, and the ledger account it is booked on —
 *     flagged when the bookkeeping will not take a posting on it;
 *   - add a target; edit label, IBAN and account — the CODE is immutable
 *     once created (payments carry it, ADR-043 decision 19);
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

        $accountState = [];
        foreach ($targets as $target) {
            $accountState[$target->getCode()] = $check->isPostable($target->getAccountNumber());
        }

        $response = $this->html([
            'targets'      => $targets,
            'accountState' => $accountState,
            'ledgerKnown'  => $check->available(),
            'actionBase'   => $this->paymentTargetListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»).
        $this->layoutManager->addPartials('addButton', 'Backend/PaymentTargetController', self::PAYMENT_TARGET_NS, 'hc1');

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

                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Zahlungsziel «' . $target->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                );

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $target->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }
}
