<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\FiscalYearCloseLog,
    Z77\Module\Financial\Entities\PeriodState,
    Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Services\FiscalYearCloseRefusedException,
    Z77\Module\Financial\Services\FiscalYearCloseService,
    Z77\Module\Financial\Services\FiscalYearNotDeletableException,
    Z77\Module\Financial\Services\FiscalYearService,
    Z77\Module\Financial\Services\InvalidFiscalYearException,
    Z77\Module\Financial\Services\EntryNotEditableException,
    Z77\Module\Financial\Services\LedgerService,
    Z77\Module\Financial\Services\PostingRefusedException,
    Z77\Module\Financial\Services\YearOpeningRefusedException,
    Z77\Module\Financial\Services\YearOpeningResult,
    Z77\Module\Financial\Services\YearOpeningService,
    Z77\Module\Financial\Entities\Account,
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
 *   - «Jahr abschliessen …» on the year that may be closed next (P5 part 1,
 *     owner decisions 2026-09-30 — only the whole year, in order): a modal
 *     (`confirm-close`, `data-fetch-get`) lists the close check's findings
 *     — blocking ones refuse, warnings need the checkbox — and POSTs
 *     `close` (Fetch, entity token `fiscalYear`);
 *   - «Wieder öffnen …» on a closed year whose successor is not closed —
 *     ADMIN only (the host's access config; the button is shown when
 *     {@see fiscalYearCanReach()} says so), with a mandatory reason
 *     (`confirm-reopen` / `reopen`);
 *   - the protocol of both, per year, the latest rows under its header;
 *   - «Eröffnung erstellen …» / «Eröffnung neu erstellen …» on an OPEN year
 *     with a predecessor (P5, owner decisions 2026-10-22 — its own measure,
 *     not part of the close, repeatable while the year is open): a dialog
 *     (`confirm-opening`) shows the entry it would write, says when the
 *     predecessor is still open (provisional), and POSTs `opening` (Fetch,
 *     entity token `fiscalYear.opening`); the year's status line under its
 *     header says whether the opening exists and is up to date;
 *   - there is NO edit of a year, and no state change of a single period.
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

    /**
     * Entity-token scopes per action (review 2026-09-30): a token issued for
     * the close modal does not authorise a reopen, and neither authorises the
     * delete (which keeps `fiscalYear`).
     */
    private const FISCAL_YEAR_CLOSE_TOKEN  = 'fiscalYear.close';
    private const FISCAL_YEAR_REOPEN_TOKEN = 'fiscalYear.reopen';
    private const FISCAL_YEAR_OPENING_TOKEN = 'fiscalYear.opening';

    /** German names of the two year-opening accounts of the mandator — the refusal names the field. */
    private const OPENING_ACCOUNT_LABELS = [
        LedgerService::OPENING_BALANCE_ACCOUNT => 'Eröffnungsbilanz',
        LedgerService::ANNUAL_RESULT_ACCOUNT   => 'Jahresgewinn/-verlust',
    ];

    /** How many protocol rows the list shows per year (newest first). */
    private const FISCAL_YEAR_LOG_ROWS = 3;

    private function fiscalYearCloseService(): FiscalYearCloseService
    {
        return new FiscalYearCloseService($this->em());
    }

    /**
     * May the current user reach $action of THIS mount? The mount's URL root
     * ({@see fiscalYearListBase()}, `/backend/{group}/{controller}`) names
     * module, group and controller; `AuthService::canReach()` answers from
     * the host's access config (backend.md: a button's visibility is decided
     * with canReach, never with a hard-coded role). A mount of another shape
     * overrides this method. Presentation only — the dispatcher enforces the
     * access config on the action itself.
     */
    protected function fiscalYearCanReach(string $action): bool
    {
        $segments = explode('/', trim($this->fiscalYearListBase(), '/'));
        if (count($segments) !== 3) {
            return false;
        }
        $auth = DI::getAuthService();

        return $auth->canReach($auth->getCurrentUser(), $segments[0], $segments[1], $segments[2], $action);
    }

    private function yearOpeningService(): YearOpeningService
    {
        return new YearOpeningService($this->em());
    }

    /** Why the opening of a year cannot be created, in German — the status line, the dialog and the refused POST share it. */
    private function fiscalYearOpeningMessage(YearOpeningRefusedException $e): string
    {
        $items = $e->items;
        $label = self::OPENING_ACCOUNT_LABELS[$items[0] ?? ''] ?? ($items[0] ?? '');

        return match ($e->reason) {
            YearOpeningRefusedException::NOT_FOUND        => 'Das Geschäftsjahr gibt es nicht mehr — bitte die Liste neu laden.',
            YearOpeningRefusedException::TARGET_CLOSED    => 'Das Geschäftsjahr ist abgeschlossen — seine Eröffnung lässt sich nicht mehr ändern.',
            YearOpeningRefusedException::NO_PREDECESSOR   => 'Das ist das erste Geschäftsjahr — es gibt kein Vorjahr, dessen Salden eröffnet werden.',
            YearOpeningRefusedException::ACCOUNT_MISSING  => ($items[1] ?? '') === ''
                ? 'Kein Konto «' . $label . '» hinterlegt — ' . LedgerService::MANDATOR_HINT . '.'
                : 'Das Konto ' . $items[1] . ' («' . $label . '») gibt es im Kontenplan nicht, es ist eine Gruppe oder inaktiv — im Kontenplan anlegen oder ' . LedgerService::MANDATOR_HINT . '.',
            YearOpeningRefusedException::FOREIGN_OPENING  => 'Eröffnung bereits vorhanden (importiert / von Hand): «' . ($items[0] ?? '') . '» hat im Jahr die Buchungen Nr. '
                . implode(', ', array_slice($items, 1)) . ' — eine erstellte Eröffnung käme doppelt dazu.',
            YearOpeningRefusedException::ACCOUNT_INACTIVE => 'Inaktive Konten haben per Ende Vorjahr einen Saldo: ' . implode(', ', $items) . ' — reaktivieren oder den Saldo im Vorjahr umbuchen.',
            YearOpeningRefusedException::NOTHING_TO_CARRY => 'Das Vorjahr hat keine Salden — es gibt nichts zu eröffnen.',
            default                                       => 'Die Eröffnung lässt sich nicht erstellen.',
        };
    }

    /** The owner's sentence for an opening built while the predecessor is still open (2026-10-22). */
    private static function fiscalYearProvisionalNote(string $predecessorCode): string
    {
        return 'Vorjahr ' . $predecessorCode . ' noch nicht abgeschlossen — Eröffnung provisorisch, nach Änderungen neu erstellen.';
    }

    /**
     * The opening status line of each year under its header, with a state for the harness: `none`
     * (not created), `current`, `provisional` (current, the predecessor open), `outdated` (the
     * predecessor says something else now — re-create), `refused` (a rule says no), `frozen` (a
     * closed year with its opening). Years without a predecessor and closed years without an
     * opening get no line. The plan computes the predecessor's balances (one aggregate) — only
     * for OPEN years with a predecessor, one or two in practice.
     *
     * @param list<FiscalYear> $years
     * @return array<int, array{state: string, text: string}>
     */
    private function fiscalYearOpeningStatus(array $years): array
    {
        $service = $this->yearOpeningService();
        $status  = [];
        foreach ($years as $year) {
            $id      = (int) $year->getId();
            $refusal = $service->refusal($year);
            if ($refusal === YearOpeningRefusedException::NO_PREDECESSOR) {
                continue;
            }
            if ($refusal === YearOpeningRefusedException::TARGET_CLOSED) {
                $ref = $service->existing($year);
                if ($ref !== null) {
                    $status[$id] = ['state' => 'frozen', 'text' => 'Eröffnung: Buchung ' . $ref->fiscalYear . '/' . $ref->number];
                }
                continue;
            }
            try {
                $plan = $service->plan($year);
            } catch (YearOpeningRefusedException $e) {
                $status[$id] = ['state' => 'refused', 'text' => 'Eröffnung: ' . $this->fiscalYearOpeningMessage($e)];
                continue;
            }
            $ref = $plan->existing === null ? '' : $plan->existing->fiscalYear . '/' . $plan->existing->number;
            $status[$id] = match (true) {
                $plan->existing === null => ['state' => 'none', 'text' => 'Eröffnung ' . $plan->targetCode . ' nicht erstellt'
                    . ($plan->provisional ? ' — Vorjahr ' . $plan->predecessorCode . ' noch nicht abgeschlossen' : '')],
                !$plan->upToDate        => ['state' => 'outdated', 'text' => 'Eröffnung (Buchung ' . $ref . ') nicht aktuell — das Vorjahr ' . $plan->predecessorCode . ' hat sich seither geändert, neu erstellen'],
                $plan->provisional      => ['state' => 'provisional', 'text' => 'Eröffnung: Buchung ' . $ref . ' — ' . self::fiscalYearProvisionalNote($plan->predecessorCode)],
                default                 => ['state' => 'current', 'text' => 'Eröffnung: Buchung ' . $ref],
            };
        }

        return $status;
    }

    /** Why a year cannot be closed or reopened, in German — the modals and the refused POSTs share it. */
    private function fiscalYearCloseMessage(string $reason): string
    {
        return match ($reason) {
            FiscalYearCloseRefusedException::NOT_FOUND            => 'Das Geschäftsjahr gibt es nicht mehr — bitte die Liste neu laden.',
            FiscalYearCloseRefusedException::NO_PERIODS           => 'Das Geschäftsjahr hat keine Perioden — es gibt nichts abzuschliessen.',
            FiscalYearCloseRefusedException::ALREADY_CLOSED       => 'Das Geschäftsjahr ist bereits abgeschlossen.',
            FiscalYearCloseRefusedException::PREDECESSOR_OPEN     => 'Zuerst das vorangehende Geschäftsjahr abschliessen — die Jahre werden der Reihe nach abgeschlossen.',
            FiscalYearCloseRefusedException::BLOCKED              => 'Es ist noch etwas offen, das den Abschluss verhindert — siehe die Liste.',
            FiscalYearCloseRefusedException::WARNINGS_UNCONFIRMED => 'Es gibt Warnungen — zum Abschliessen bitte bestätigen.',
            FiscalYearCloseRefusedException::WARNINGS_CHANGED     => 'Die Hinweise haben sich geändert — bitte erneut prüfen.',
            FiscalYearCloseRefusedException::NOT_CLOSED           => 'Das Geschäftsjahr ist nicht abgeschlossen.',
            FiscalYearCloseRefusedException::SUCCESSOR_CLOSED     => 'Zuerst das folgende Geschäftsjahr wieder öffnen — geöffnet wird in umgekehrter Reihenfolge, das letzte abgeschlossene Jahr zuerst.',
            FiscalYearCloseRefusedException::NO_REASON            => 'Bitte einen Grund angeben — er steht im Protokoll.',
            FiscalYearCloseRefusedException::REASON_TOO_LONG      => 'Der Grund ist zu lang — höchstens ' . FiscalYearCloseLog::REASON_LENGTH . ' Zeichen.',
            default                                               => 'Das Geschäftsjahr lässt sich nicht abschliessen oder öffnen.',
        };
    }

    /** One protocol row as a short German line, e.g. «abgeschlossen 30.09.2026 14:05 von peter». */
    private static function fiscalYearLogLine(FiscalYearCloseLog $row): string
    {
        $when = $row->getActedAt()->format('d.m.Y H:i') . ' von ' . $row->getActor();
        if ($row->getAction() === 'reopen') {
            return 'wieder geöffnet ' . $when . ' — Grund: ' . $row->getReason();
        }
        $warnings = count($row->getConfirmedWarnings());

        return 'abgeschlossen ' . $when . ($warnings > 0 ? ' (' . $warnings . ($warnings === 1 ? ' Warnung' : ' Warnungen') . ' bestätigt)' : '');
    }

    /** Why a year cannot be deleted, in German — the modal and the refused POST share it. */
    private function fiscalYearRefusalMessage(string $reason): string
    {
        return match ($reason) {
            FiscalYearNotDeletableException::NOT_FOUND   => 'Das Geschäftsjahr gibt es nicht mehr — bitte die Liste neu laden.',
            FiscalYearNotDeletableException::NOT_LATEST  => 'Nur das erste oder das letzte Geschäftsjahr lässt sich löschen — sonst entstünde eine Lücke zwischen den Jahren.',
            FiscalYearNotDeletableException::HAS_ENTRIES => 'Im Geschäftsjahr gibt es Buchungen — es lässt sich nicht mehr löschen.',
            FiscalYearNotDeletableException::HAD_ENTRIES => 'Im Geschäftsjahr wurde schon gebucht (gelöschte Buchungen sind im Änderungsprotokoll dokumentiert) — es lässt sich nicht mehr löschen.',
            FiscalYearNotDeletableException::RANGE_USED  => 'Aus dem Nummernkreis des Geschäftsjahrs wurde schon eine Nummer bezogen — es lässt sich nicht mehr löschen.',
            FiscalYearNotDeletableException::CLOSED      => 'Das Geschäftsjahr ist abgeschlossen — ein abgeschlossenes Jahr wird nicht gelöscht.',
            default                                      => 'Das Geschäftsjahr lässt sich nicht löschen.',
        };
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        // What each year's ⋮ offers — by fiscalYearActions(), the ONE derivation the hub uses as
        // well (review 2026-10-13: the list derived the same rules a second time).
        $years   = $this->fiscalYears()->allWithPeriods();
        $ends    = $this->fiscalYearEnds();
        $actions = [];
        $closed  = [];
        foreach ($years as $year) {
            if ($year->isClosed()) {
                $closed[] = $year->getId();
            }
            $actions[(int) $year->getId()] = $this->fiscalYearActions($year, $ends);
        }
        /** @var FiscalYearCloseLogRepository $logs */
        $logs     = $this->em()->getRepository(FiscalYearCloseLog::class);
        $logLines = [];
        foreach ($logs->byYear(array_map(fn(FiscalYear $y) => (int) $y->getId(), $years)) as $yearId => $rows) {
            $logLines[$yearId] = array_map(self::fiscalYearLogLine(...), array_slice($rows, 0, self::FISCAL_YEAR_LOG_ROWS));
        }

        $response = $this->html([
            'years'        => $years,
            'stateLabels'  => $this->periodStateLabels(),
            'closedIds'    => $closed,
            'actionsByYear' => $actions,
            'logLines'     => $logLines,
            'openingStatus' => $this->fiscalYearOpeningStatus($years),
            'actionBase'   => $this->fiscalYearListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»). The add action is the
        // entry's most frequent action, so it goes into the action cell (ADR-033 rev. 2026-10-08).
        $this->layoutManager->addPartials('openButton', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS, 'hc1');

        return $response;
    }

    /**
     * The year's ⋮ hub (owner 2026-10-10: the year actions in a ⋮ before the title, as every
     * backend list row has them — not as buttons at the far right). Offers exactly what the
     * list used to show as buttons, by the same rules: «Jahr abschliessen …» when the order
     * rule allows it, «Wieder öffnen …» for who may reach the action, «Löschen …» for the
     * latest / earliest year without postings. Each opens its confirm (unchanged).
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $year = $id > 0 ? $this->fiscalYears()->find($id) : null;
        if ($year === null) {
            return $this->fetchError('Geschäftsjahr nicht gefunden');
        }

        $response = $this->html([
            'year'          => $year,
            'actions'       => $this->fiscalYearActions($year),
            'openingExists' => $this->yearOpeningService()->existing($year) !== null,
            'actionBase'    => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('actions', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }

    /**
     * What may be done with this year now — the list's ⋮ shows only when this is not empty.
     *
     * The rules (lock-free; each confirm and the service decide again): «close» when the order
     * rule allows it, «reopen» for who may reach the action (ADMIN in the backend's access
     * config), «delete» for the latest or the earliest year while nothing was ever posted,
     * «opening» for an open year with a predecessor (P5, owner 2026-10-22 — the dialog says
     * when a finer rule refuses).
     *
     * @param list<int>|null $ends the ids of the latest and the earliest year — the list passes them once for all years
     * @return list<'opening'|'close'|'reopen'|'delete'>
     */
    private function fiscalYearActions(FiscalYear $year, ?array $ends = null): array
    {
        $closer  = $this->fiscalYearCloseService();
        $actions = [];
        if ($this->yearOpeningService()->refusal($year) === null) {
            $actions[] = 'opening';
        }
        if ($closer->closeRefusal($year) === null) {
            $actions[] = 'close';
        }
        if ($this->fiscalYearCanReach('reopen') && $closer->reopenRefusal($year) === null) {
            $actions[] = 'reopen';
        }
        $isEnd = in_array($year->getId(), $ends ?? $this->fiscalYearEnds(), true);
        if ($isEnd && $this->fiscalYearService()->deletionRefusal($year) === null) {
            $actions[] = 'delete';
        }

        return $actions;
    }

    /** @return list<int> the ids of the latest and the earliest year — the only ones that can be deletable */
    private function fiscalYearEnds(): array
    {
        return array_values(array_filter([$this->fiscalYears()->latest()?->getId(), $this->fiscalYears()->earliest()?->getId()], static fn($id) => $id !== null));
    }

    // ── open ─────────────────────────────────────────────────────────────

    /**
     * «Geschäftsjahr eröffnen»: GET shows the proposal — the next year, or with `?prior=1` the
     * year before the earliest (owner 2026-09-29) — POST opens through the service, which
     * accepts either (the validator checks contiguity at both ends).
     */
    protected function openAction(): HtmlResponse|FetchResponse
    {
        $prior     = DI::getRequest()->getGetParameter('prior') === '1' && $this->fiscalYears()->earliest() !== null;
        $year      = $prior ? $this->fiscalYearService()->proposePrior() : $this->fiscalYearService()->proposeNext();
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

                // Not one row: the new year lands at one end of the list and takes «Löschen …» off
                // the year that was at that end (only the end years are deletable) — reload.
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
            'prior'      => $prior,
            'hasYears'   => $this->fiscalYears()->earliest() !== null,
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

        // Not one row: the year now at that end may become deletable — the list is rebuilt.
        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->fiscalYearListBase() . '/list');
    }

    // ── close / reopen (P5 part 1, owner decisions 2026-09-30) ─────────────

    /**
     * The close modal (`data-fetch-get`): the order refusal, or the close
     * check's findings — blocking ones say why it cannot be closed, warnings
     * come with a checkbox the POST must carry.
     */
    protected function confirmCloseAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $year = $id > 0 ? $this->fiscalYears()->find($id) : null;
        if ($year === null) {
            return $this->fetchError($this->fiscalYearCloseMessage(FiscalYearCloseRefusedException::NOT_FOUND));
        }
        $refusal = $this->fiscalYearCloseService()->closeRefusal($year);
        $open    = $refusal === null ? $this->fiscalYearCloseService()->closeCheck($year) : null;

        $response = $this->html([
            'year'       => $year,
            'refusal'    => $refusal === null ? null : $this->fiscalYearCloseMessage($refusal),
            'blocking'   => $open?->blocking() ?? [],
            'warnings'   => $open?->warnings() ?? [],
            'warningsHash' => $open === null ? '' : FiscalYearCloseService::warningsFingerprint($open),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken(self::FISCAL_YEAR_CLOSE_TOKEN, $id),
            'actionBase' => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('confirmClose', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function closeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::FISCAL_YEAR_CLOSE_TOKEN, $id)) {
            return $this->fetchError('Invalid token');
        }
        $code = $this->fiscalYears()->find($id)?->getCode() ?? (string) $id;
        // What the closer confirmed: the fingerprint of the warnings the modal SHOWED — only with the checkbox ticked.
        $confirmed = ($body['confirm_warnings'] ?? false) === true && is_string($body['warnings_hash'] ?? null) ? $body['warnings_hash'] : null;

        try {
            $this->fiscalYearCloseService()->close($id, $confirmed);
        } catch (FiscalYearCloseRefusedException $e) {
            $message = $this->fiscalYearCloseMessage($e->reason);
            if ($e->reason === FiscalYearCloseRefusedException::BLOCKED && $e->openWork !== null) {
                $message .= ' ' . implode(' ', array_map(fn($f) => $f->message, $e->openWork->blocking()));
            }

            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('success', 'Geschäftsjahr «' . $code . '» abgeschlossen — darin wird nichts mehr gebucht oder geändert.');

        // Not one row: closing moves «abschliessen» / «wieder öffnen» between neighbouring years
        // and writes a protocol line under the header — the list is rebuilt.
        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
    }

    /** The reopen modal (`data-fetch-get`): the order refusal, or the form with the mandatory reason. */
    protected function confirmReopenAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $year = $id > 0 ? $this->fiscalYears()->find($id) : null;
        if ($year === null) {
            return $this->fetchError($this->fiscalYearCloseMessage(FiscalYearCloseRefusedException::NOT_FOUND));
        }
        $refusal = $this->fiscalYearCloseService()->reopenRefusal($year);

        $response = $this->html([
            'year'         => $year,
            'refusal'      => $refusal === null ? null : $this->fiscalYearCloseMessage($refusal),
            'reasonLength' => FiscalYearCloseLog::REASON_LENGTH,
            'entityCsrf'   => DI::getCsrfService()->generateEntityToken(self::FISCAL_YEAR_REOPEN_TOKEN, $id),
            'actionBase'   => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('confirmReopen', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function reopenAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::FISCAL_YEAR_REOPEN_TOKEN, $id)) {
            return $this->fetchError('Invalid token');
        }
        $code = $this->fiscalYears()->find($id)?->getCode() ?? (string) $id;

        try {
            $this->fiscalYearCloseService()->reopen($id, is_string($body['reason'] ?? null) ? $body['reason'] : '');
        } catch (FiscalYearCloseRefusedException $e) {
            $message  = $this->fiscalYearCloseMessage($e->reason);
            $response = $this->fetchError($message);
            // The reason's own refusals mark the field (a field error, not only a flash).
            return in_array($e->reason, [FiscalYearCloseRefusedException::NO_REASON, FiscalYearCloseRefusedException::REASON_TOO_LONG], true)
                ? $response->setField('reason', false, $message)
                : $response;
        }
        $this->messageService->pushFlashAfterRedirect('success', 'Geschäftsjahr «' . $code . '» wieder geöffnet — der Grund steht im Protokoll.');

        // Not one row: as with the close — neighbours' actions and the protocol change.
        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
    }

    // ── the year opening (P5, owner decisions 2026-10-22) ────────────────────

    /**
     * The opening dialog (`data-fetch-get`): the entry «Eröffnung erstellen» would write now —
     * its lines with the account names —, whether it replaces an existing opening (same number,
     * logged) or changes nothing, and the owner's note when the predecessor is still open. A
     * refusal shows the sentence and no form. `YearOpeningService::create()` decides again.
     */
    protected function confirmOpeningAction(): HtmlResponse|FetchResponse
    {
        $id   = (int) DI::getRequest()->getGetParameter('id');
        $year = $id > 0 ? $this->fiscalYears()->find($id) : null;
        if ($year === null) {
            return $this->fetchError($this->fiscalYearOpeningMessage(new YearOpeningRefusedException(YearOpeningRefusedException::NOT_FOUND, 'not found')));
        }
        $refusal = null;
        $plan    = null;
        try {
            $plan = $this->yearOpeningService()->plan($year);
            if ($plan->request === null && $plan->existing === null) {
                throw new YearOpeningRefusedException(YearOpeningRefusedException::NOTHING_TO_CARRY, 'nothing to carry');
            }
        } catch (YearOpeningRefusedException $e) {
            $refusal = $this->fiscalYearOpeningMessage($e);
            $plan    = null;
        }

        // The lines as the change log shows a snapshot (`partials/entrySnapshot`), with the account names.
        $lines = [];
        $names = [];
        if ($plan?->request !== null) {
            $numbers = array_map(fn($l) => $l->account, $plan->request->lines);
            foreach ($this->em()->getRepository(Account::class)->findBy(['number' => $numbers]) as $account) {
                $names[$account->getNumber()] = $account->getName();
            }
            foreach ($plan->request->lines as $line) {
                $lines[] = ['account' => $line->account, 'account_name' => $names[$line->account] ?? '', 'text' => null,
                    'debit' => $line->debit->toDecimal(), 'credit' => $line->credit->toDecimal()];
            }
        }

        $response = $this->html([
            'year'        => $year,
            'refusal'     => $refusal,
            'plan'        => $plan,
            'snapshot'    => ['lines' => $lines],
            'total'       => $plan?->request === null ? '' : \Z77\Shared\Money\AmountFormat::of(array_reduce($plan->request->lines, fn($sum, $l) => $sum->add($l->debit), \Z77\Shared\Money\Money::zero($plan->request->currency()))),
            'provisional' => $plan !== null && $plan->provisional ? self::fiscalYearProvisionalNote($plan->predecessorCode) : null,
            'entityCsrf'  => DI::getCsrfService()->generateEntityToken(self::FISCAL_YEAR_OPENING_TOKEN, $id),
            'actionBase'  => $this->fiscalYearListBase(),
        ]);
        $this->layoutManager->addPartials('confirmOpening', 'Backend/FiscalYearController', self::FISCAL_YEAR_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function openingAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $id   = (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), self::FISCAL_YEAR_OPENING_TOKEN, $id)) {
            return $this->fetchError('Invalid token');
        }

        try {
            $result = $this->yearOpeningService()->create($id);
        } catch (YearOpeningRefusedException $e) {
            return $this->fetchError($this->fiscalYearOpeningMessage($e));
        } catch (PostingRefusedException | EntryNotEditableException $e) {
            // The ledger refused under its lock — a close of the year or a chart change in between.
            return $this->fetchError('Die Buchhaltung hat die Eröffnung abgelehnt (' . $e->reason . ') — bitte die Liste neu laden und erneut versuchen.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            // Two openings of the same year at once: the second fails on the idempotency key, nothing written.
            return $this->fetchError('Die Eröffnung wurde gleichzeitig erstellt — bitte die Liste neu laden.');
        }
        $ref     = $result->ref->fiscalYear . '/' . $result->ref->number;
        $message = match ($result->action) {
            YearOpeningResult::CREATED   => 'Eröffnung erstellt — Buchung ' . $ref . '.',
            YearOpeningResult::UPDATED   => 'Eröffnung neu erstellt — Buchung ' . $ref . ' ersetzt (im Änderungsprotokoll).',
            YearOpeningResult::UNCHANGED => 'Eröffnung ist aktuell — Buchung ' . $ref . ' unverändert.',
            default                      => 'Eröffnung entfernt — das Vorjahr hat keine Salden mehr (Buchung ' . $ref . ', im Änderungsprotokoll).',
        };
        if ($result->provisional) {
            $message .= ' ' . self::fiscalYearProvisionalNote($result->predecessorCode);
        }
        $this->messageService->pushFlashAfterRedirect($result->provisional ? 'info' : 'success', $message);

        // Not one row: the year's status line and its ⋮ label change — the list is rebuilt.
        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
    }
}
