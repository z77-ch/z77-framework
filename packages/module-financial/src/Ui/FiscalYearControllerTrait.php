<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\PeriodState,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Services\FiscalYearNotDeletableException,
    Z77\Module\Financial\Services\FiscalYearService,
    Z77\Module\Financial\Services\InvalidFiscalYearException,
    Z77\Module\Financial\Validators\FiscalYearValidator,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
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
 *   - «Löschen …» on the LATEST year while nothing was ever posted in it
 *     (owner decision 2026-09-22, FIN-FY-002): a confirmation modal
 *     (`data-fetch-get`) and a Fetch POST with the entity token
 *     `fiscalYear`; `FiscalYearService::delete()` decides again under lock;
 *   - there is NO edit of a year, and no state change of a period (P5).
 *
 * No JavaScript of its own: the code proposal for changed dates is made on
 * the server when the field is left empty. The using class MUST provide
 * (via its host base): `html()`, `fetch()`, `fetchError()`, `em()`,
 * `$layoutManager`, `$messageService`.
 */
trait FiscalYearControllerTrait
{
    private const FISCAL_YEAR_NS = 'Z77\\Module\\Financial';

    /** The loser of two simultaneous openings that deadlocked (FIN-FY-003). */
    private const FISCAL_YEAR_OPEN_RACE_MESSAGE = 'Ein anderes Geschäftsjahr wurde gleichzeitig eröffnet — Liste neu laden.';

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

    /** Why a year cannot be deleted, in German — the modal and the refused POST share it. */
    private function fiscalYearRefusalMessage(string $reason): string
    {
        return match ($reason) {
            FiscalYearNotDeletableException::NOT_FOUND   => 'Das Geschäftsjahr gibt es nicht mehr — bitte die Liste neu laden.',
            FiscalYearNotDeletableException::NOT_LATEST  => 'Nur das letzte Geschäftsjahr lässt sich löschen — sonst entstünde eine Lücke zwischen den Jahren.',
            FiscalYearNotDeletableException::HAS_ENTRIES => 'Im Geschäftsjahr gibt es Buchungen — es lässt sich nicht mehr löschen.',
            FiscalYearNotDeletableException::HAD_ENTRIES => 'Im Geschäftsjahr wurde schon gebucht (gelöschte Buchungen sind im Änderungsprotokoll dokumentiert) — es lässt sich nicht mehr löschen.',
            FiscalYearNotDeletableException::RANGE_USED  => 'Aus dem Nummernkreis des Geschäftsjahrs wurde schon eine Nummer bezogen — es lässt sich nicht mehr löschen.',
            default                                      => 'Das Geschäftsjahr lässt sich nicht löschen.',
        };
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        // Only the latest year can be deletable; the button shows for it alone.
        $latest    = $this->fiscalYears()->latest();
        $deletable = $latest !== null && $this->fiscalYearService()->deletionRefusal($latest) === null ? $latest->getId() : null;

        $response = $this->html([
            'years'       => $this->fiscalYears()->allWithPeriods(),
            'stateLabels' => $this->periodStateLabels(),
            'deletableId' => $deletable,
            'actionBase'  => $this->fiscalYearListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»).
        $this->layoutManager->addPartials('openButton', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS, 'hc1');

        return $response;
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
            } catch (\Throwable $e) {
                // Two openings of the FIRST year on an empty table deadlock under
                // lockAll() (FIN-FY-003): the loser rolled back — a sentence, not a 500.
                if (!RaceFailure::isDeadlock($e)) {
                    throw $e;
                }
                $validator = new FiscalYearValidator($year);
                $validator->flagFieldError('start_date', self::FISCAL_YEAR_OPEN_RACE_MESSAGE);
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

    // ── delete ───────────────────────────────────────────────────────────

    /** Confirmation modal (`data-fetch-get`); says why when the year cannot be deleted. */
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $year = $id > 0 ? $this->fiscalYears()->find($id) : null;
        if ($year === null) {
            return $this->fetchError($this->fiscalYearRefusalMessage(FiscalYearNotDeletableException::NOT_FOUND));
        }
        $refusal = $this->fiscalYearService()->deletionRefusal($year);

        $response = $this->html([
            'year'       => $year,
            'refusal'    => $refusal === null ? null : $this->fiscalYearRefusalMessage($refusal),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('fiscalYear', $id),
            'actionBase' => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function deleteAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'fiscalYear', $id)) {
            return $this->fetchError('Invalid token');
        }
        $code = $this->fiscalYears()->find($id)?->getCode() ?? (string) $id;

        try {
            $this->fiscalYearService()->delete($id);
        } catch (FiscalYearNotDeletableException $e) {
            return $this->fetchError($this->fiscalYearRefusalMessage($e->reason));
        }
        $this->messageService->pushFlashAfterRedirect('success', 'Geschäftsjahr «' . $code . '» gelöscht — mit seinen Perioden und seinem Nummernkreis');

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->fiscalYearListBase() . '/list');
    }
}
