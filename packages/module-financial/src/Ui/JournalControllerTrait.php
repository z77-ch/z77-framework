<?php
namespace Z77\Module\Financial\Ui;

use Z77\Shared\Money\AmountFormat;

use Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\EntryChange,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\JournalEntry,
    Z77\Module\Financial\Entities\PeriodState,
    Z77\Module\Financial\Ledger\EntryRef,
    Z77\Module\Financial\Ledger\PostingRequest,
    Z77\Module\Financial\Repositories\EntryChangeRepository,
    Z77\Module\Financial\Repositories\FiscalYearRepository,
    Z77\Module\Financial\Repositories\JournalEntryRepository,
    Z77\Module\Financial\Services\EntryConflictException,
    Z77\Module\Financial\Services\EntryNotEditableException,
    Z77\Module\Financial\Services\LedgerService,
    Z77\Module\Financial\Services\ManualEntryService,
    Z77\Module\Financial\Services\PostingRefusedException,
    Z77\Module\Vat\Entities\TaxCode,
    Z77\Module\Vat\Services\VatRates,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Paging\Paging,
    Z77\Shared\Money\Money
;

/**
 * The journal surface (plan §5.2–§5.3, ADR-042 decision 7; owner
 * 2026-09-22) — mounted by a thin host controller in the backend (ADR-018
 * pattern, like the accounts and the fiscal years). Host side:
 * `use JournalControllerTrait` + a one-line layout config delegating to
 * {@see JournalLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - capture AND list on ONE page (`list`, FIN-JOURNAL-CAPTURE-001, owner
 *     2026-09-28): the capture form open at once (one-line or Sammelbuchung,
 *     `?mode=`), below it the journal of the SELECTED fiscal year
 *     ({@see FiscalYearSelection}: `?year=`, remembered per session, default
 *     the current year — owner 2026-09-29; picked at the top of the rail), newest first, paged by `journalListLimit`, searchable per
 *     column in the database (one year or all), sortable, each row with a
 *     state icon (the deleted numbers and every change are the change log,
 *     a screen of its own — {@see ChangeLogControllerTrait}, owner 2026-10-08);
 *   - show an entry with its lines, its reversal link (both directions)
 *     and its change log;
 *   - post a MANUAL entry, edit and delete it — with confirmation, each
 *     change logged by `ManualEntryService`. The default capture form is
 *     the ONE-LINE entry (posted to `add`, {@see OneLineEntryForm}: Soll, Datum,
 *     Bu-Nr, Text, Haben, gross Betrag, optional MwSt row — owner
 *     2026-09-22); real splits go to the multi-line «Sammelbuchung»
 *     (`add-compound`, {@see ManualEntryForm}). An entry of the one-line
 *     shape is edited one-line, every other in the Sammelbuchung form.
 *     Generated entries are shown only; there is NO reverse button: the
 *     module that posted an entry reverses it (ADR-042 decision 7).
 *
 * Every write goes through {@see ManualEntryService}; the trait maps the
 * request and renders. A managed entry is never mutated here: the new
 * version is a validated `PostingRequest` the service applies after ITS
 * checks (ADR-039 decision 9).
 *
 * No JavaScript of its own (Rule 7): list, detail and the entry forms are
 * PAGES — plain `<form method="post">` with `#[Csrf]` (`csrf_token`
 * field); the one-line form's MwSt row is revealed by a checkbox through
 * CSS (`.be-reveal`); in the Sammelbuchung «Weitere Zeilen» is a second
 * submit button the server answers with more rows, the balance is shown
 * after every submit.
 * Only the delete confirmation is a modal (`data-fetch-get` /
 * `data-fetch-post` with the entity token, like the sibling screens), and
 * its success redirects to the journal.
 *
 * Concurrency (review 2026-09-22): the edit form and the delete modal carry
 * the entry's VERSION; the service re-reads and refuses a stale write with
 * `EntryConflictException`, which this trait answers with a German message
 * and a redirect — never a 500. The using class MUST provide (via its host
 * base): `html()`, `fetch()`, `fetchError()`, `redirect()`, `em()`,
 * `$layoutManager`, `$messageService`.
 */
trait JournalControllerTrait
{
    private const JOURNAL_NS = 'Z77\\Module\\Financial';

    /** German display labels — PRESENTATION ONLY. The sets are {@see \Z77\Module\Financial\Entities\EntryKind} / {@see \Z77\Module\Financial\Entities\ChangeAction}. */
    private const KIND_LABELS = [
        'generated' => 'generiert',
        'manual'    => 'manuell',
    ];
    private const CHANGE_LABELS = [
        'update' => 'geändert',
        'delete' => 'gelöscht',
    ];
    /**
     * The state icon at the start of a list row (owner 2026-09-28): whether
     * the entry can still be edited — German title per state. The service
     * decides for real; {@see journalEntryState()} mirrors its rules.
     */
    private const JOURNAL_STATE_LABELS = [
        'editable'    => 'bearbeitbar',
        'generated'   => 'automatisch gebucht — nur das Modul, das sie gebucht hat, ändert, löscht oder storniert sie',
        'closed'      => 'Geschäftsjahr abgeschlossen — gesperrt',
        'vat-settled' => 'MWST der Periode abgerechnet — die Buchung trägt einen MWST-Code und ist gesperrt',
    ];

    private const JOURNAL_PERIOD_STATE_LABELS = [
        'open'        => 'offen',
        'vat-settled' => 'MWST abgerechnet',
        'closed'      => 'abgeschlossen',
    ];

    /** URL root of THIS mount — every link and form is built from it. */
    protected function journalListBase(): string
    {
        return '/backend/finance/journal';
    }

    private function journalEntries(): JournalEntryRepository
    {
        return $this->em()->getRepository(JournalEntry::class);
    }

    private function entryChanges(): EntryChangeRepository
    {
        return $this->em()->getRepository(EntryChange::class);
    }

    private function journalYears(): FiscalYearRepository
    {
        return $this->em()->getRepository(FiscalYear::class);
    }

    private function manualEntryService(): ManualEntryService
    {
        return new ManualEntryService($this->em());
    }

    private function manualEntryForm(): ManualEntryForm
    {
        return new ManualEntryForm(
            $this->journalCurrency(),
            $this->em()->getRepository(Account::class),
            $this->em()->getRepository(TaxCode::class),
            VatRates::from($this->em())
        );
    }

    private function oneLineEntryForm(): OneLineEntryForm
    {
        return new OneLineEntryForm(
            $this->journalCurrency(),
            $this->em()->getRepository(Account::class),
            $this->em()->getRepository(TaxCode::class),
            VatRates::from($this->em()),
            new LedgerService($this->em())
        );
    }

    /** The installation's base currency — what every amount of the ledger is in (ADR-042 decision 4; read in `LedgerService::baseCurrency()`). */
    private function journalCurrency(): string
    {
        return LedgerService::baseCurrency();
    }

    private function journalYearSelection(): FiscalYearSelection
    {
        return FiscalYearSelection::of($this->journalYears());
    }

    /**
     * The year the screen works on — the selection the reports share
     * ({@see FiscalYearSelection}, owner 2026-09-29): `?year=` when given and
     * known (remembered for the session), else the remembered year, else the
     * year containing today, else the latest one; null without any year.
     */
    private function journalSelectedYear(): ?FiscalYear
    {
        return $this->journalYearSelection()->resolve(DI::getRequest()->getGetParameter('year'));
    }

    /**
     * A full page other than the list: the layout pins the body to
     * `listAction`, so the section is swapped for the page's own template.
     *
     * @param array<string, mixed> $context
     */
    private function journalPage(string $template, array $context): HtmlResponse
    {
        $context += ['actionBase' => $this->journalListBase(), 'fmt' => static fn(?Money $m) => AmountFormat::of($m), 'configNotice' => $this->journalConfigNotice()];
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/JournalController', self::JOURNAL_NS);

        return $response;
    }

    /**
     * The red band every journal page shows while NO VAT account can be
     * resolved — a leftover `vatAccounts` in a project override, the
     * mandator module not registered, its table missing
     * (`LedgerService::vatAccountNotice()`). A German sentence naming the
     * next step; null in the normal state. Review 2026-09-23 (P5/P6): these
     * states used to surface as a 500 when a taxed entry was opened.
     */
    private function journalConfigNotice(): ?string
    {
        return (new LedgerService($this->em()))->vatAccountNotice();
    }

    /** Whether the bookkeeper may still edit / delete an entry — the service decides for real; this drives the buttons and the edit page's guard. */
    private function journalEntryIsEditable(JournalEntry $entry): bool
    {
        return $entry->isManual()
            && $entry->getFiscalYear()->periodOn($entry->getDate())?->getState() !== PeriodState::Closed->value;
    }

    /**
     * The list's state of an entry — a key of {@see JOURNAL_STATE_LABELS}:
     * generated (only its module reverses it), closed (its period is closed),
     * vat-settled (the period's VAT is settled and the entry carries a VAT
     * code — those lines are frozen, EntryNotEditableException::PERIOD_VAT_SETTLED),
     * else editable.
     */
    private function journalEntryState(JournalEntry $entry): string
    {
        if (!$entry->isManual()) {
            return 'generated';
        }
        $state = $entry->getFiscalYear()->periodOn($entry->getDate())?->getState();
        if ($state === PeriodState::Closed->value) {
            return 'closed';
        }
        if ($state === PeriodState::VatSettled->value) {
            foreach ($entry->getLines() as $line) {
                if ($line->getTaxCode() !== null) {
                    return 'vat-settled';
                }
            }
        }

        return 'editable';
    }

    /** Why an entry cannot be edited or deleted — the sentence the detail view, the edit page and the delete modal share. */
    private function journalNotEditableMessage(JournalEntry $entry): string
    {
        return $entry->isManual()
            ? 'Das Geschäftsjahr ist abgeschlossen — keine Änderung mehr möglich.'
            : 'Eine generierte Buchung wird nie geändert oder gelöscht — das Modul, das sie gebucht hat, storniert sie.';
    }

    /** The one German sentence for a stale write (review 2026-09-22). */
    private const JOURNAL_CONFLICT_MESSAGE = 'Die Buchung wurde inzwischen geändert oder gelöscht — bitte neu laden.';

    /** The German sentence for a refusal — the service speaks English (code), the screen German. */
    private function journalRefusalMessage(PostingRefusedException|EntryNotEditableException $e): string
    {
        return match ($e->reason) {
            PostingRefusedException::NO_FISCAL_YEAR       => 'Für dieses Datum ist kein Geschäftsjahr eröffnet.',
            PostingRefusedException::NO_PERIOD            => 'Für dieses Datum gibt es keine Periode.',
            PostingRefusedException::PERIOD_CLOSED,
            EntryNotEditableException::PERIOD_CLOSED      => 'Das Geschäftsjahr ist abgeschlossen — darin wird nichts mehr gebucht oder geändert; eine Korrektur ist eine Buchung in einem offenen Jahr (ein Admin kann das Jahr mit Begründung wieder öffnen).',
            PostingRefusedException::PERIOD_VAT_SETTLED,
            EntryNotEditableException::PERIOD_VAT_SETTLED => 'Die MWST dieser Periode ist abgerechnet — Zeilen mit MWST-Code sind dort eingefroren; nur Buchungen ohne MWST-Code sind noch möglich.',
            PostingRefusedException::ACCOUNT_UNKNOWN      => 'Ein Konto gibt es nicht.',
            PostingRefusedException::ACCOUNT_NOT_POSTABLE => 'Ein Konto ist eine Gruppe und nicht bebuchbar.',
            PostingRefusedException::ACCOUNT_INACTIVE     => 'Ein Konto ist inaktiv.',
            PostingRefusedException::TAX_CODE_UNKNOWN     => 'Einen MWST-Code gibt es nicht.',
            EntryNotEditableException::GENERATED          => 'Eine generierte Buchung wird nie geändert oder gelöscht — das Modul, das sie gebucht hat, storniert sie.',
            EntryNotEditableException::FISCAL_YEAR_CHANGED => 'Das neue Datum liegt in einem anderen Geschäftsjahr — die Nummer gehört zum Jahr. Buchung löschen und im anderen Jahr neu erfassen.',
            default                                        => $e->getMessage(),
        };
    }

    // ── list ─────────────────────────────────────────────────────────────

    /**
     * The journal — capture AND list on one page (FIN-JOURNAL-CAPTURE-001,
     * owner 2026-09-28): post at once with as few clicks as possible, find
     * any entry, see the latest ones.
     *
     *   - `?mode=einzel|sammel` — the capture form: the one-line entry
     *     (default) or the Sammelbuchung, open at once;
     *   - `?year=` — the fiscal year the page shows ({@see FiscalYearSelection}:
     *     remembered per session, default the current year; the dropdown at the
     *     top of the rail sets it — owner 2026-09-29 / 2026-10-08);
     *   - `?date=` — the date the empty form starts with (kept after a save),
     *     when it lies in the shown year; else today clamped into that year;
     *   - the column search `f_*`, `all`, `sort` / `dir`, `page` —
     *     {@see JournalFilter}; a search runs in the database.
     */
    protected function listAction(): HtmlResponse
    {
        $mode = DI::getRequest()->getGetParameter('mode') === 'sammel' ? 'sammel' : 'einzel';
        $year = $this->journalSelectedYear();
        $date = $this->journalCaptureDate(DI::getRequest()->getGetParameter('date'), $year);
        if ($mode === 'sammel') {
            $form = $this->manualEntryForm();
            $form->startBlank($date);
        } else {
            $form = $this->oneLineEntryForm();
            $form->startBlank($date);
        }

        return $this->journalListPage($form, $mode, $year);
    }

    /**
     * Renders the journal page around a capture form — blank, or posted with
     * its errors. The list below shows the SELECTED year (owner 2026-09-29) —
     * a refused form keeps its own date, whatever year that falls in.
     */
    private function journalListPage(OneLineEntryForm|ManualEntryForm $form, string $mode, ?FiscalYear $year = null): HtmlResponse
    {
        $request = DI::getRequest();
        $year  ??= $this->journalSelectedYear();
        $query   = [];
        foreach (array_merge(array_keys(JournalFilter::FIELDS), ['all', 'sort', 'dir', 'page']) as $key) {
            $query[$key] = $request->getGetParameter($key);
        }
        $filter  = JournalFilter::fromQuery($query, $this->journalCurrency());
        $search  = $filter->search($year?->getId());
        $total   = $year === null ? 0 : $this->journalEntries()->countSearch($search);
        $paging  = new Paging($filter->page, LedgerService::listLimit(), $total);
        $entries = $total === 0 ? [] : $this->journalEntries()->search($search, $paging->offset(), $paging->pageSize);
        $rows    = array_map(fn(JournalEntry $e) => ['entry' => $e, 'state' => $this->journalEntryState($e), 'number' => $e->getNumber()], $entries);

        $keep = array_filter(['mode' => $mode === 'sammel' ? 'sammel' : '', 'date' => $form->date()]);
        $base = $this->journalListBase();
        // The year dropdown (top of the rail): a year link keeps the capture mode, drops the date (it
        // belongs to the old year) and the search (a fresh list of the new year). Its last entry
        // «Alle Jahre» is the list's `all=1` state (owner 2026-10-08 — was the «alle Geschäftsjahre»
        // toggle in the list header); the selected year stays remembered, the capture form keeps it.
        $fySelection = $year === null ? null : [
            'years'   => $this->journalYearSelection()->all(),
            'current' => $year,
            'href'    => static fn(string $code): string => $base . '/list?' . http_build_query(array_filter(['mode' => $mode === 'sammel' ? 'sammel' : '', 'year' => $code])),
            'allHref' => $base . '/list?' . http_build_query(array_filter(['mode' => $mode === 'sammel' ? 'sammel' : '', 'all' => '1'])),
            'allOn'   => $filter->allYears,
        ];
        if ($year !== null && !$this->journalIsFetch()) {
            $this->journalAttachHelp($form, $year, null);
        }
        $response = $this->html([
            'form'        => $form,
            'mode'        => $mode,
            'year'        => $year,
            'entry'       => null,
            'capture'     => true,
            'rows'        => $rows,
            'filter'      => $filter,
            'paging'      => $paging,
            'keep'        => $keep,
            'fySelection' => $fySelection,
            'states'      => self::JOURNAL_STATE_LABELS,
            'kindLabels'  => self::KIND_LABELS,
            'fmt'         => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase'  => $this->journalListBase(),
            'configNotice' => $this->journalConfigNotice(),
        ]);
        // The page is capture + list: the pinned body section is rebuilt. A FETCH of the page
        // (sort, page, search — core.js «fetch regions») wants the list alone: the
        // capture form above it keeps what is typed into it.
        $this->layoutManager->removeSection('main');
        if ($this->journalIsFetch()) {
            $this->layoutManager->addPartials('listAction', 'Backend/JournalController', self::JOURNAL_NS);

            return $response;
        }
        if ($year !== null) {
            $this->layoutManager->addPartials($mode === 'sammel' ? 'form' : 'oneLine', 'Backend/JournalController', self::JOURNAL_NS);
            // The fragment owns its header slots (financial.md, «fragment slots»): the year
            // selection at the top of the rail (section `railSelect`, owner 2026-10-08, ADR-033 rev.; shared with the reports),
            // «Buchen» in the action cell (the entry's most frequent action, ADR-033 rev. 2026-10-08), the
            // capture tools (Einzel | Sammel, MwSt) in the toolbar — they act on the form on the right —
            // and the crumb line with the year and month of the date.
            $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', self::JOURNAL_NS, 'railSelect');
            $this->layoutManager->addPartials('captureAct', 'Backend/JournalController', self::JOURNAL_NS, 'hc1');
            $this->layoutManager->addPartials('captureTools', 'Backend/JournalController', self::JOURNAL_NS, 'hc2');
            $this->layoutManager->addPartials('crumb', 'Backend/JournalController', self::JOURNAL_NS, 'hc3');
        }
        $this->layoutManager->addPartials('listAction', 'Backend/JournalController', self::JOURNAL_NS);

        return $response;
    }

    /**
     * The date a blank capture form starts with (owner 2026-09-29): `?date=`
     * when it lies in the shown year; else today clamped into that year —
     * before its start its first day, after its end its last day. Without any
     * year: today.
     */
    private function journalCaptureDate(mixed $requested, ?FiscalYear $year): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today');
        if ($year === null) {
            return $today;
        }
        $date = is_string($requested) ? ManualEntryForm::parseDate($requested) : null;
        if ($date !== null && $year->covers($date)) {
            return $date;
        }

        return match (true) {
            $today < $year->getStartDate() => $year->getStartDate(),
            $today > $year->getEndDate()   => $year->getEndDate(),
            default                        => $today,
        };
    }

    /**
     * After a posting the journal shows the year it went into: that year
     * becomes the selection (owner 2026-09-29) — remembered when it is not the
     * default, the choice cleared when it is.
     */
    private function journalSelectPostedYear(EntryRef $ref): void
    {
        $year = $this->journalYears()->findOneBy(['code' => $ref->fiscalYear]);
        if ($year !== null) {
            $this->journalYearSelection()->remember($year);
        }
    }

    /**
     * The help for a capture or edit form (ADR-048): the rules the form no longer spells out
     * (owner 2026-09-29, «no text in the form») and — edit — the entry's computed VAT. Opened
     * with «? Hilfe» in the top bar (page), the «i» in the window's title bar, or F1 in a field —
     * at the field's `data-help-field` section (ADR-048 addendum 2026-10-08).
     */
    private function journalAttachHelp(OneLineEntryForm|ManualEntryForm $form, FiscalYear $year, ?JournalEntry $entry): void
    {
        $oneLine = $form instanceof OneLineEntryForm;
        $this->help->attach(
            'Backend/JournalController/' . ($oneLine ? 'oneLine' : 'form') . '.help',
            self::JOURNAL_NS,
            ['form' => $form, 'year' => $year, 'entry' => $entry],
            $oneLine ? 'Hilfe: Einzelbuchung' : 'Hilfe: Sammelbuchung',
        );
    }

    /**
     * The answer to a save made in a WINDOW (ADR-047): the controller says what happens —
     * the window shows the entry's read view again, and when it was opened from the journal
     * list, that list reloads (numbers, text, amount may have changed). Other origins get
     * the window only; a screen that opens this window elsewhere adds its branch here.
     */
    private function journalWindowSaved(int $id, string $origin): FetchResponse
    {
        $response = $this->fetch()->setStatus('success')
            ->addCommand('open-window', ['url' => $this->journalListBase() . '/detail?id=' . $id, 'replace' => true]);
        if ($origin === 'region:journal-list') {
            $response->addCommand('refresh-region', ['name' => 'journal-list']);
        }

        return $response;
    }

    /** Whether this request is a fetch (the list reloading alone) — the Request decides by `Sec-Fetch-Mode`. */
    private function journalIsFetch(): bool
    {
        $request = DI::getRequest();

        return method_exists($request, 'getMode') && $request->getMode() === RequestMode::Fetch;
    }

    /** Back to the journal after a save: the same mode, the date kept for the next voucher (its year is the selection — {@see journalSelectPostedYear()}). */
    private function journalBackToCapture(string $mode, \DateTimeImmutable $date): RedirectResponse
    {
        return $this->redirect($this->journalListBase() . '/list?' . http_build_query(array_filter([
            'mode' => $mode === 'sammel' ? 'sammel' : '',
            'date' => $date->format('Y-m-d'),
        ])), 303);
    }

    // ── detail ───────────────────────────────────────────────────────────

    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $id = (int) DI::getRequest()->getGetParameter('id');
        if ($id === 0) {
            // `?ref={fiscal-year}/{number}` — how a posting module stores the entry it posted
            // (EntryRef as one string, e.g. debtor's `invoice.ledger_entry_ref`, P3 part 3): the
            // module links the journal without knowing an id.
            $ref = self::journalParseRef(DI::getRequest()->getGetParameter('ref'));
            $id  = $ref === null ? 0 : (int) $this->journalEntries()->findByRef($ref)?->getId();
        }
        $entry = $id ? $this->journalEntries()->withLines($id) : null;
        if ($entry === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Buchung nicht gefunden');

            return $this->redirect($this->journalListBase() . '/list', 303);
        }
        $period = $entry->getFiscalYear()->periodOn($entry->getDate());

        // Fetched (a click on an entry in the list, ADR-047): the same page as a WINDOW —
        // the template marks its root, «Bearbeiten» loads into the window.
        return $this->journalPage('detail', [
            'window'         => $this->journalIsFetch(),
            'windowWidth'    => '52rem',
            'origin'         => WindowOrigin::of(DI::getRequest()),
            'entry'          => $entry,
            'reversedBy'     => $this->journalEntries()->findReversalOf($entry),
            'changes'        => $this->entryChanges()->forEntry((int) $entry->getId()),
            'editable'       => $this->journalEntryIsEditable($entry),
            'notEditableWhy' => $this->journalNotEditableMessage($entry),
            'periodState'    => $period === null ? '' : (self::JOURNAL_PERIOD_STATE_LABELS[$period->getState()] ?? $period->getState()),
            'kindLabels'     => self::KIND_LABELS,
            'changeLabels'   => self::CHANGE_LABELS,
        ]);
    }

    /** `2026/12`, `2030-31/7` → the EntryRef; null for anything else (the year code may carry a dash, the number follows the LAST slash). */
    private static function journalParseRef(mixed $value): ?EntryRef
    {
        if (!is_string($value) || !preg_match('#^([0-9A-Za-z-]{1,16})/([1-9]\d{0,8})$#', $value, $m)) {
            return null;
        }

        return new EntryRef($m[1], (int) $m[2]);
    }

    // ── add ──────────────────────────────────────────────────────────────

    /**
     * Posts the ONE-LINE entry of the journal page (FIN-JOURNAL-CAPTURE-001).
     * A GET lands on the journal page itself — the capture form lives there
     * now; the address stays valid for old links and bookmarks. POST posts
     * through the service; on success back to the journal with the SAME date
     * and a flash naming the new number, so the next voucher is typed straight
     * away; on a refusal the journal page again, with the form and its errors.
     */
    #[Csrf]
    protected function addAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if ($this->journalYears()->currentOrLatest() === null) {
            return $this->journalNoYear();
        }
        if (!$request->isPost()) {
            return $this->journalBackToCapture('einzel', $this->journalCaptureDate($request->getGetParameter('date'), $this->journalSelectedYear()));
        }
        $form    = $this->oneLineEntryForm();
        $posting = $form->fromPost($request->getPostParameters())->toRequest();
        if ($posting !== null) {
            $ref = $this->journalCreate($posting, $form);
            if ($ref instanceof RedirectResponse) {
                return $ref;
            }
            if ($ref !== null) {
                $this->messageService->pushFlashAfterRedirect('success', 'Buchung ' . $ref->fiscalYear . '/' . $ref->number . ' erfasst');
                $this->journalSelectPostedYear($ref);

                return $this->journalBackToCapture('einzel', $posting->date);
            }
        }

        return $this->journalListPage($form, 'einzel');
    }

    /**
     * Posts the SAMMELBUCHUNG of the journal page (`mode=sammel`). A GET lands
     * on the journal page in that mode. POST with `op=more` comes back with
     * more rows, `op=save` posts through the service and returns to the
     * journal in the same mode, the date kept.
     */
    #[Csrf]
    protected function addCompoundAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if ($this->journalYears()->currentOrLatest() === null) {
            return $this->journalNoYear();
        }
        if (!$request->isPost()) {
            return $this->journalBackToCapture('sammel', $this->journalCaptureDate($request->getGetParameter('date'), $this->journalSelectedYear()));
        }
        $form = $this->manualEntryForm();
        $post = $request->getPostParameters();
        $form->fromPost($post);
        if (($post['op'] ?? '') === 'more') {
            $form->addRows(ManualEntryForm::MORE_ROWS);
        } else {
            $posting = $form->toRequest();
            if ($posting !== null) {
                $ref = $this->journalCreate($posting, $form);
                if ($ref instanceof RedirectResponse) {
                    return $ref;
                }
                if ($ref !== null) {
                    $this->messageService->pushFlashAfterRedirect('success', 'Buchung ' . $ref->fiscalYear . '/' . $ref->number . ' erfasst');
                    $this->journalSelectPostedYear($ref);

                    return $this->journalBackToCapture('sammel', $posting->date);
                }
            }
        }

        return $this->journalListPage($form, 'sammel');
    }

    /** No fiscal year at all: nothing can be posted — to the fiscal years. */
    private function journalNoYear(): RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect('error', 'Ohne Geschäftsjahr kann nichts gebucht werden — zuerst eines eröffnen.');

        return $this->redirect('/backend/finance/fiscal-year/list', 303);
    }

    /**
     * Posts a new manual entry through the service. The ref when it went
     * through; null when the ledger refused (the form then carries the
     * German message); a redirect when the year was deleted in the meantime.
     */
    private function journalCreate(PostingRequest $posting, ManualEntryForm|OneLineEntryForm $form): EntryRef|RedirectResponse|null
    {
        try {
            return $this->manualEntryService()->create($posting);
        } catch (PostingRefusedException $e) {
            $form->addGeneralError($this->journalRefusalMessage($e));

            return null;
        } catch (\Throwable $e) {
            // The year was deleted (FIN-FY-002) between the ledger's checks and
            // the commit: only THIS foreign key is answered, everything else stays loud.
            if (!RaceFailure::isFiscalYearGone($e)) {
                throw $e;
            }
            $this->messageService->pushFlashAfterRedirect('error', 'Das Geschäftsjahr wurde inzwischen gelöscht — die Buchung wurde nicht erfasst.');

            return $this->redirect('/backend/finance/fiscal-year/list', 303);
        }
    }

    // ── edit ─────────────────────────────────────────────────────────────

    /**
     * «Buchung bearbeiten» (`?id=`), manual entries in a period that is not
     * closed — any other entry gets the same refusal the detail view shows
     * and goes back there. An entry of the one-line shape
     * ({@see OneLineEntryForm::startFrom()}) is edited in the one-line form
     * unless `?form=compound` asks for the Sammelbuchung; every other entry
     * in the Sammelbuchung form. A posted form names itself (`form` field).
     * The managed entry is NOT mutated here — the new version goes to
     * `ManualEntryService::update()` as a validated request, with the entry's
     * id and the VERSION the form was rendered from.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $entry   = $id ? $this->journalEntries()->withLines($id) : null;
        if ($entry === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Buchung nicht gefunden');

            return $this->redirect($this->journalListBase() . '/list', 303);
        }
        if (!$this->journalEntryIsEditable($entry)) {
            $this->messageService->pushFlashAfterRedirect('error', $this->journalNotEditableMessage($entry));

            return $this->redirect($this->journalListBase() . '/detail?id=' . $id, 303);
        }
        $version = $entry->getVersion();
        $post    = null;
        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'journalEntry', $id)) {
                $this->messageService->pushFlashAfterRedirect('error', 'Ungültiges Formular-Token — bitte erneut versuchen.');

                return $this->redirect($this->journalListBase() . '/edit?id=' . $id, 303);
            }
            $version = (int) ($post['version'] ?? 0);   // what THIS form was rendered from, not what is stored now
        }

        $oneLine    = $this->oneLineEntryForm();
        $fits       = $oneLine->startFrom($entry);   // also keeps the entry's own tax code selectable
        $useOneLine = $post !== null ? ($post['form'] ?? '') === 'one-line' : $fits && $request->getGetParameter('form') !== 'compound';
        $posting    = null;
        if ($useOneLine) {
            $form = $oneLine;
            if ($post !== null) {
                $posting = $form->fromPost($post)->toRequest();
            }
        } else {
            $form = $this->manualEntryForm()->keepFrom($entry);
            if ($post === null) {
                $form->startFrom($entry);
            } elseif (($post['op'] ?? '') === 'more') {
                $form->fromPost($post)->addRows(ManualEntryForm::MORE_ROWS);
            } else {
                $posting = $form->fromPost($post)->toRequest();
            }
        }

        if ($posting !== null) {
            $label = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
            try {
                $changed = $this->manualEntryService()->update($id, $version, $posting);
                // Nothing changed: the service wrote no change row and kept the version (the log records changes, not saves).
                [$type, $text] = $changed
                    ? ['success', 'Buchung ' . $label . ' gespeichert (Änderung protokolliert)']
                    : ['info', 'Keine Änderung — Buchung ' . $label . ' bleibt wie sie war'];
                if ($this->journalIsFetch()) {
                    $this->messageService->pushFlash($type, $text);

                    return $this->journalWindowSaved($id, WindowOrigin::of($request));
                }
                $this->messageService->pushFlashAfterRedirect($type, $text);

                return $this->redirect($this->journalListBase() . '/detail?id=' . $id, 303);
            } catch (EntryConflictException) {
                $this->messageService->pushFlashAfterRedirect('error', self::JOURNAL_CONFLICT_MESSAGE);

                return $this->redirect($this->journalListBase() . '/detail?id=' . $id, 303);
            } catch (PostingRefusedException | EntryNotEditableException $e) {
                $form->addGeneralError($this->journalRefusalMessage($e));
                // The refused unit of work rolled back and replaced the EntityManager; the
                // entry shown next to the form is re-read (DOCTRINE-TX-004), display only.
                $entry = $this->journalEntries()->withLines($id);
                if ($entry === null) {
                    $this->messageService->pushFlashAfterRedirect('error', self::JOURNAL_CONFLICT_MESSAGE);

                    return $this->redirect($this->journalListBase() . '/list', 303);
                }
            }
        }

        $this->journalAttachHelp($form, $entry->getFiscalYear(), $entry);

        return $this->journalPage($useOneLine ? 'oneLine' : 'form', [
            'window'      => $this->journalIsFetch(),
            // Wide enough for the one-line row (six fields side by side) — the window must not scroll sideways.
            'windowWidth' => $useOneLine ? '64rem' : '60rem',
            'origin'      => WindowOrigin::of($request),
            'form'        => $form,
            'year'        => $entry->getFiscalYear(),
            'entry'       => $entry,
            'version'     => $version,
            'entityCsrf'  => DI::getCsrfService()->generateEntityToken('journalEntry', $id),
            'oneLineFits' => $fits,
            'recent'      => [],
        ]);
    }

    // ── delete ───────────────────────────────────────────────────────────

    /** Confirmation modal (`data-fetch-get`); says why when the entry cannot be deleted. */
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $entry = $id ? $this->journalEntries()->withLines($id) : null;
        if ($entry === null) {
            return $this->fetchError('Buchung nicht gefunden');
        }

        $response = $this->html([
            'entry'          => $entry,
            'editable'       => $this->journalEntryIsEditable($entry),
            'notEditableWhy' => $this->journalNotEditableMessage($entry),
            'entityCsrf'     => DI::getCsrfService()->generateEntityToken('journalEntry', $id),
            'fmt'            => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase'     => $this->journalListBase(),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Backend/JournalController', self::JOURNAL_NS);

        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function deleteAction(): FetchResponse
    {
        $body    = DI::getRequest()->getJsonBody();
        $id      = (int) ($body['id'] ?? 0);
        $version = (int) ($body['version'] ?? 0);   // what the modal was rendered from
        if ($id <= 0) {
            return $this->fetchError('Missing id');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'journalEntry', $id)) {
            return $this->fetchError('Invalid token');
        }
        $entry = $this->journalEntries()->withLines($id);
        if ($entry === null) {
            return $this->fetchError(self::JOURNAL_CONFLICT_MESSAGE);
        }
        $label = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
        $year  = $entry->getFiscalYear()->getCode();

        try {
            $this->manualEntryService()->delete($id, $version);
        } catch (EntryConflictException) {
            return $this->fetchError(self::JOURNAL_CONFLICT_MESSAGE);
        } catch (EntryNotEditableException $e) {
            return $this->fetchError($this->journalRefusalMessage($e));
        }
        $this->messageService->pushFlashAfterRedirect('success', 'Buchung ' . $label . ' gelöscht — die Nummer bleibt als Lücke im Journal dokumentiert');

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->setRedirect($this->journalListBase() . '/list?year=' . rawurlencode($year));
    }
}
