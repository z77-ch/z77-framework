<?php
namespace Z77\Module\Mandator\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Mandator\Entities\Mandator,
    Z77\Module\Mandator\Services\CurrentMandator,
    Z77\Module\Mandator\Services\InvalidMandatorException,
    Z77\Module\Mandator\Services\LedgerAccountCheck,
    Z77\Module\Mandator\Services\MandatorAccounts,
    Z77\Module\Mandator\Services\MandatorAlreadyExistsException,
    Z77\Module\Mandator\Services\MandatorService,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf
;

/**
 * The mandator screen (owner decisions E1 / E2, 2026-09-23) — ONE record,
 * so ONE page: `edit` shows the existing mandator or, while there is none,
 * an empty form with the KMU account start values pre-filled
 * ({@see MandatorAccounts::prefilled()}); the first save creates the
 * record, every later save changes it. No list, no «Neu» button, no delete.
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use MandatorControllerTrait` + a one-line layout config delegating
 * to {@see MandatorLayout::config()}, and `defaultAction` = `edit` in
 * `backendConfig` (the `report` model — there is no list).
 *
 * A PAGE with a plain `<form method="post">` and `#[Csrf]` (`csrf_token`),
 * like the journal forms — the record has thirty fields, a modal is the
 * wrong size. An existing record additionally carries the entity token
 * `mandator`. The account fields are typed by number with a `<datalist>` of
 * the postable accounts when module-financial is registered
 * ({@see LedgerAccountCheck::postableAccounts()}); each shows its status
 * (ok / prüfen / ungeprüft) so a wrong account is found here and not when
 * a posting refuses it. No JavaScript of its own (Rule 7).
 *
 * When the record CANNOT be read — table missing, module not registered
 * ({@see CurrentMandator::unavailableReason()}) — the page shows the German
 * sentence as a band and no form (review 2026-09-23: a message, never a
 * 500). Every write goes through {@see MandatorService}; the managed record
 * is never mutated here before validation (ADR-039 decision 9) — the cleaned
 * values go to the service, a refused change comes back as the exception's
 * draft. The using class MUST provide (via its host base): `html()`,
 * `redirect()`, `em()`, `$layoutManager`, `$messageService`.
 */
trait MandatorControllerTrait
{
    private const MANDATOR_NS = 'Z77\\Module\\Mandator';

    /** URL root of THIS mount — the form posts to it. */
    protected function mandatorBase(): string
    {
        return '/backend/finance/mandator';
    }

    private function mandatorService(): MandatorService
    {
        return new MandatorService($this->em());
    }

    /**
     * GET: the record (or the pre-filled empty form). POST: create or
     * update through the service; success redirects back here with a flash,
     * a refusal re-renders the form with the draft and its field errors.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse
    {
        $request     = DI::getRequest();
        $reader      = new CurrentMandator($this->em());
        $unavailable = $reader->unavailableReason();
        if ($unavailable !== null) {
            return $this->html(['unavailable' => $unavailable, 'actionBase' => $this->mandatorBase()]);
        }
        $current   = $reader->find();
        $shown     = $current;
        $validator = null;

        if ($request->isPost()) {
            $post   = $request->getPostParameters();
            $values = BodyCleaner::cleanFor(Mandator::class, $post);
            unset($values['id']);
            // An unchecked checkbox is not submitted — the hidden `liable_to_vat=0` before it is.
            $values['liable_to_vat'] = (bool) ($post['liable_to_vat'] ?? false);

            try {
                if ($current === null) {
                    $shown = new Mandator($values);
                    $this->mandatorService()->save($shown);
                } else {
                    $csrf = trim((string) ($post['entity_csrf'] ?? ''));
                    if (!DI::getCsrfService()->validateEntityToken($csrf, 'mandator', $current->getId())) {
                        $this->messageService->pushFlashAfterRedirect('error', 'Ungültiges Formular-Token — bitte neu laden.');

                        return $this->redirect($this->mandatorBase() . '/edit', 303);
                    }
                    $this->mandatorService()->update($current, $values);
                }
                $this->messageService->pushFlashAfterRedirect('success', 'Mandant «' . $shown->getName() . '» gespeichert');

                return $this->redirect($this->mandatorBase() . '/edit', 303);
            } catch (InvalidMandatorException $e) {
                $validator = $e->validator;
                $shown     = $e->mandator;   // the refused draft — never the managed entity
            } catch (MandatorAlreadyExistsException $e) {
                // Two admins saved the first record at once (the primary key decided); the second sees the first's.
                $this->messageService->pushFlashAfterRedirect('error', $e->getMessage());

                return $this->redirect($this->mandatorBase() . '/edit', 303);
            }
        }

        $shown ??= MandatorAccounts::prefilled();
        $check   = new LedgerAccountCheck($this->em());

        return $this->html([
            'unavailable'   => null,
            'entry'         => $shown,
            'isNew'         => $current === null,
            // Field errors only after a refused POST; on GET an un-run validator (no errors). What BECAME
            // invalid since the save is flagged by `accountStatus` («prüfen»), not by a field error.
            'validator'     => $validator ?? $this->mandatorService()->validator($shown),
            'entityCsrf'    => $current === null ? '' : DI::getCsrfService()->generateEntityToken('mandator', $current->getId()),
            'accountKeys'   => array_keys(Mandator::ACCOUNT_KEYS),
            'accountLabels' => MandatorAccounts::LABELS,
            'accountStatus' => (new MandatorAccounts($this->em()))->status($shown),
            'accounts'      => $check->postableAccounts(),
            'ledgerKnown'   => $check->available(),
            'actionBase'    => $this->mandatorBase(),
        ]);
    }
}
