<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\PeriodState,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Services\FiscalYearService,
    Z77\Module\Financial\Services\InvalidFiscalYearException,
    Z77\Module\Financial\Validators\FiscalYearValidator
;

/**
 * The fiscal-year surface (plan §5.1, owner decisions 2026-09-22) — mounted
 * by a thin host controller in the backend (ADR-018 pattern). Host side:
 * `use FiscalYearControllerTrait` + a one-line layout config delegating to
 * {@see FiscalYearLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list the fiscal years, newest first, with their periods and states;
 *   - «Geschäftsjahr eröffnen»: dates proposed (the day after the latest
 *     year, twelve months), free to change; the code is proposed from the
 *     dates when left empty. The opening derives the monthly periods and
 *     creates the journal-entry number range in one unit of work;
 *   - there is NO edit and NO delete of a year, and no state change of a
 *     period (P5).
 *
 * No JavaScript of its own: the code proposal for changed dates is made on
 * the server when the field is left empty. The using class MUST provide
 * (via its host base): `html()`, `fetch()`, `fetchError()`, `em()`,
 * `$layoutManager`, `$messageService`.
 */
trait FiscalYearControllerTrait
{
    private const FISCAL_YEAR_NS = 'Z77\\Module\\Financial';

    /** German display labels — PRESENTATION ONLY. The state SET is {@see PeriodState}. */
    private const PERIOD_STATE_LABELS = [
        'open'        => 'offen',
        'vat-settled' => 'MWST abgerechnet',
        'closed'      => 'abgeschlossen',
    ];

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function fiscalYearListBase(): string
    {
        return '/backend/finance/fiscal-year';
    }

    private function fiscalYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    private function fiscalYearService(): FiscalYearService
    {
        return new FiscalYearService($this->em());
    }

    /** @return array<string,string> state value → German label */
    private function periodStateLabels(): array
    {
        $labels = [];
        foreach (PeriodState::cases() as $state) {
            $labels[$state->value] = self::PERIOD_STATE_LABELS[$state->value] ?? $state->value;
        }

        return $labels;
    }

    /** A `YYYY-MM-DD` form value as a date, or null when it is not a real calendar date. */
    private static function fiscalYearDate(mixed $value): ?\DateTimeImmutable
    {
        $value = is_string($value) ? trim($value) : '';
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        return $this->html([
            'years'       => $this->fiscalYears()->allWithPeriods(),
            'stateLabels' => $this->periodStateLabels(),
            'actionBase'  => $this->fiscalYearListBase(),
        ]);
    }

    // ── open ─────────────────────────────────────────────────────────────

    /** «Geschäftsjahr eröffnen»: GET shows the proposal, POST opens through the service. */
    protected function openAction(): HtmlResponse|FetchResponse
    {
        $year      = $this->fiscalYearService()->proposeNext();
        $proposed  = $year->getCode();
        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body  = DI::getRequest()->getJsonBody();
            $start = self::fiscalYearDate($body['start_date'] ?? '');
            $end   = self::fiscalYearDate($body['end_date'] ?? '');
            $code  = trim((string) ($body['code'] ?? ''));
            if ($code === '' && $start !== null && $end !== null) {
                $code = FiscalYearService::proposeCode($start, $end);
            }
            $year = new FiscalYear($code, $start, $end);

            try {
                $this->fiscalYearService()->open($year);
                $this->messageService->pushFlashAfterRedirect('success', 'Geschäftsjahr «' . $year->getCode() . '» eröffnet: '
                    . count($year->getPeriods()) . ' Perioden');

                return $this->fetch()
                    ->setStatus('success')
                    ->setData(['id' => $year->getId()])
                    ->addCommand('close-modal')
                    ->addCommand('reload');
            } catch (InvalidFiscalYearException $e) {
                $validator = $e->validator;
            }
        }

        $response = $this->html([
            'entry'      => $year,
            'proposed'   => $proposed,
            'validator'  => $validator ?? new FiscalYearValidator($year),
            'actionBase' => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('open', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }
}
