<?php
namespace Z77\Module\Vat\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Vat\Entities\TaxCategory,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Entities\TaxRate,
    Z77\Module\Vat\Repositories\TaxCodeRepository,
    Z77\Module\Vat\Repositories\TaxRateRepository,
    Z77\Module\Vat\Services\InvalidRateException,
    Z77\Module\Vat\Services\RateInEffectException,
    Z77\Module\Vat\Services\TaxCodeChangedException,
    Z77\Module\Vat\Services\VatMasterData,
    Z77\Module\Vat\Validators\TaxCodeValidator,
    Z77\Module\Vat\Validators\TaxRateValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The tax-code surface (ADR-041, plan §4) — mounted by a thin host controller
 * in the backend (dms Drive / member accounts pattern, ADR-018): the host
 * provides route + auth + shell, all logic and templates live here in
 * module-vat. Host side: `use TaxCodeControllerTrait` + a one-line layout
 * config delegating to {@see TaxCodeLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list every code with its rates, newest first;
 *   - add a code; edit label, country and category of a code — the CODE
 *     itself is immutable once created (documents reference it by code);
 *   - activate / deactivate a code (inline switch). There is NO delete: a
 *     code is deactivated, never deleted (ADR-043 decision 19);
 *   - «Neuer Satz gültig ab»: a rate change is a NEW row. A row is never
 *     edited; it can be removed only while its validity has not started
 *     ({@see VatMasterData::removeRate()}).
 *
 * No JavaScript of its own: every action runs through the shared `core.js`
 * wiring (`data-fetch-get` modals, `data-fetch-post` forms, `data-fetch-toggle`
 * switch). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `em()`, `$layoutManager`, `$messageService`.
 */
trait TaxCodeControllerTrait
{
    private const VAT_NS = 'Z77\\Module\\Vat';

    /**
     * German display labels — PRESENTATION ONLY (the `ROLE_LABELS` pattern of
     * the backend user screen). The category SET is {@see TaxCategory}; a
     * category without a label here shows its value.
     */
    private const CATEGORY_LABELS = [
        'standard'       => 'Normalsatz',
        'reduced'        => 'Reduzierter Satz',
        'special'        => 'Sondersatz',
        'zero'           => 'Befreit (0 %)',
        'exempt'         => 'Ausgenommen',
        'reverse-charge' => 'Bezugsteuer',
        'input-material' => 'Vorsteuer Material / Dienstleistungen',
        'input-other'    => 'Vorsteuer Investitionen / übriger Aufwand',
    ];

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function vatListBase(): string
    {
        return '/backend/finance/tax-code';
    }

    private function vatCodes(): TaxCodeRepository
    {
        return $this->em()->getRepository(TaxCode::class);
    }

    private function vatRates(): TaxRateRepository
    {
        return $this->em()->getRepository(TaxRate::class);
    }

    private function vatMasterData(): VatMasterData
    {
        return new VatMasterData($this->em());
    }

    /** Today in the installation's time zone — what «not yet in effect» is measured against. */
    private function vatToday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }

    /** @return array<string,string> category value → German label, for selects */
    private function vatCategoryLabels(): array
    {
        $labels = [];
        foreach (TaxCategory::cases() as $category) {
            $labels[$category->value] = self::CATEGORY_LABELS[$category->value] ?? $category->value;
        }

        return $labels;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        return $this->html([
            'codes'          => $this->vatCodes()->allSorted(),
            'ratesByCode'    => $this->vatRates()->allGroupedByCode(),
            'categoryLabels' => $this->vatCategoryLabels(),
            'today'          => $this->vatToday()->format('Y-m-d'),
            'actionBase'     => $this->vatListBase(),
        ]);
    }

    // ── code: add / edit ─────────────────────────────────────────────────

    protected function addAction(): HtmlResponse|FetchResponse
    {
        $code = new TaxCode();
        $code->setCountry('CH');

        return $this->editCode($code);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $code = $id ? $this->vatCodes()->find($id) : null;
        if ($code === null) {
            return $this->fetchError('Steuercode nicht gefunden');
        }

        return $this->editCode($code);
    }

    private function editCode(TaxCode $code): HtmlResponse|FetchResponse
    {
        $isNew     = $code->getId() === null;
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'taxCode', $code->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $originalActive = $code->isActive();

            $code->mapFromArray(BodyCleaner::cleanFor(TaxCode::class, $body));

            // `active` has its own switch on the list; the form never touches it.
            $code->setActive($isNew ? true : $originalActive);

            $validator = new TaxCodeValidator($code, $this->vatCodes());
            if ($validator->isValid()) {
                try {
                    // The code is the key documents carry — VatMasterData
                    // refuses a changed one (the form field is read-only on edit).
                    $this->vatMasterData()->saveCode($code);
                } catch (TaxCodeChangedException) {
                    return $this->fetchError('Der Code ist nach dem Anlegen fix — für eine andere Steuerart einen neuen Code anlegen.');
                }

                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Steuercode «' . $code->getCode() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                );

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $code->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            }
            // validation failed — fall through to re-render the form with errors
        }

        $response = $this->html([
            'entry'          => $code,
            'entityCsrf'     => $isNew ? '' : DI::getCsrfService()->generateEntityToken('taxCode', $code->getId()),
            'validator'      => $validator ?? new TaxCodeValidator($code),
            'categoryLabels' => $this->vatCategoryLabels(),
            'actionBase'     => $this->vatListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/TaxCodeController', self::VAT_NS);

        return $response;
    }

    // ── code: active switch ──────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $code = $id ? $this->vatCodes()->find($id) : null;
        if ($code === null) {
            return $this->fetchError('Steuercode nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        $this->vatMasterData()->setActive($code, (bool) ($body['value'] ?? !$code->isActive()));

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // ── rate: new row ────────────────────────────────────────────────────

    /** «Neuer Satz gültig ab» for the code in `?code=` — GET renders the modal, POST saves the new row. */
    protected function addRateAction(): HtmlResponse|FetchResponse
    {
        $codeKey = TaxCode::normalizeCode((string) DI::getRequest()->getGetParameter('code'));
        $code    = $codeKey !== '' ? $this->vatCodes()->findByCode($codeKey) : null;
        if ($code === null) {
            return $this->fetchError('Steuercode nicht gefunden');
        }

        $rate = new TaxRate();
        $rate->setCode($code->getCode());
        $validator   = null;
        $ratePercent = '';

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim((string) ($body['entity_csrf'] ?? ''));
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'taxRateAdd', $code->getCode())) {
                return $this->fetchError('Invalid token');
            }

            // The form posts the rate as a percent string; the integer is set
            // here, never mapped from the body — BodyCleaner passes a property
            // without #[Clean] through RAW, so `rate` and `created_on` are
            // dropped from the body before mapping. `code` comes from the URL.
            $ratePercent = trim((string) ($body['rate_percent'] ?? ''));
            unset($body['rate'], $body['code'], $body['created_on']);
            $rate->mapFromArray(BodyCleaner::cleanFor(TaxRate::class, $body));

            $rateIsValid = true;
            try {
                $rate->setRate(TaxRate::percentToHundredths($ratePercent));
            } catch (\InvalidArgumentException) {
                $rateIsValid = false;
            }

            $saved = false;
            if ($rateIsValid) {
                // The write service holds the rules (backdating, createdOn) and
                // runs the validator; its exception hands the field errors back.
                try {
                    $this->vatMasterData()->addRate($rate, $this->vatToday());
                    $saved = true;
                } catch (InvalidRateException $e) {
                    $validator = $e->validator;
                }
            } else {
                // Percent unparsable: still show the other fields' errors.
                $validator = new TaxRateValidator($rate, $this->vatCodes(), $this->vatRates(), $this->vatToday());
                $validator->isValid();
            }

            if ($saved) {
                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Satz ' . TaxRate::formatPercent($rate->getRate()) . ' % für «' . $code->getCode() . '» gültig ab ' . $rate->getValidFrom() . ' angelegt'
                );

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $rate->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            }
            // fall through: the template reads the rate error from $rateError
        }

        $response = $this->html([
            'code'        => $code,
            'entry'       => $rate,
            'ratePercent' => $ratePercent,
            'rateError'   => isset($rateIsValid) && !$rateIsValid ? 'Satz in Prozent mit höchstens zwei Dezimalen, z.B. 8.1' : '',
            // Own context: keyed by the CODE (a new row has no id); the remove
            // token below is keyed by the rate id under `taxRate`.
            'entityCsrf'  => DI::getCsrfService()->generateEntityToken('taxRateAdd', $code->getCode()),
            'validator'   => $validator ?? new TaxRateValidator($rate),
            'today'       => $this->vatToday()->format('Y-m-d'),
            'actionBase'  => $this->vatListBase(),
        ]);
        $this->layoutManager->addPartials('addRate', 'Backend/TaxCodeController', self::VAT_NS);

        return $response;
    }

    // ── rate: remove a row that is not yet in effect ─────────────────────

    protected function confirmRemoveRateAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $rate = $id ? $this->vatRates()->find($id) : null;
        if ($rate === null) {
            return $this->fetchError('Satz nicht gefunden');
        }

        $response = $this->html([
            'entry'      => $rate,
            'removable'  => $this->vatMasterData()->canRemoveRate($rate, $this->vatToday()),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('taxRate', $id),
            'actionBase' => $this->vatListBase(),
        ]);
        $this->layoutManager->addPartials('confirmRemoveRate', 'Backend/TaxCodeController', self::VAT_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeRateAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'taxRate', $id)) {
            return $this->fetchError('Invalid token');
        }

        $rate = $this->vatRates()->find($id);
        if ($rate === null) {
            return $this->fetchError('Satz nicht gefunden');
        }

        try {
            $this->vatMasterData()->removeRate($rate, $this->vatToday());
        } catch (RateInEffectException) {
            return $this->fetchError('Der Satz ist bereits in Kraft und bleibt als Historie stehen — eine Korrektur ist ein neuer Satz.');
        }

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Satz ' . TaxRate::formatPercent($rate->getRate()) . ' % für «' . $rate->getCode() . '» gültig ab ' . $rate->getValidFrom() . ' entfernt'
        );

        return $this->fetch()->setStatus('success')->addCommand('close-modal')->addCommand('reload');
    }

    // ── row action hub (⋮) ───────────────────────────────────────────────

    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $code = $id ? $this->vatCodes()->find($id) : null;
        if ($code === null) {
            return $this->fetchError('Steuercode nicht gefunden');
        }

        $today = $this->vatToday();
        $rates = $this->vatRates()->findByCode($code->getCode());
        $removable = [];
        foreach ($rates as $rate) {
            $removable[$rate->getId()] = $this->vatMasterData()->canRemoveRate($rate, $today);
        }

        $response = $this->html([
            'entry'      => $code,
            'rates'      => $rates,
            'removable'  => $removable,
            'actionBase' => $this->vatListBase(),
        ]);
        $this->layoutManager->addPartials('actions', 'Backend/TaxCodeController', self::VAT_NS);

        return $response;
    }
}
