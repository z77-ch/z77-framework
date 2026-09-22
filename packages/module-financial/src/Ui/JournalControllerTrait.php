<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Financial\Entities\Account,
    Z77\Module\Financial\Entities\EntryChange,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\JournalEntry,
    Z77\Module\Financial\Entities\PeriodState,
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
 *   - list the journal of ONE fiscal year (`?year=`; the year containing
 *     today by default), newest first, bounded by `journalListLimit`, with
 *     the deleted numbers (gaps) explained from the change log;
 *   - show an entry with its lines, its reversal link (both directions)
 *     and its change log;
 *   - post a MANUAL entry, edit and delete it — with confirmation, each
 *     change logged by `ManualEntryService`. Generated entries are shown
 *     only; there is NO reverse button: the module that posted an entry
 *     reverses it (ADR-042 decision 7).
 *
 * Every write goes through {@see ManualEntryService}; the trait maps the
 * request and renders. A managed entry is never mutated here: the new
 * version is a validated `PostingRequest` the service applies after ITS
 * checks (ADR-039 decision 9).
 *
 * No JavaScript of its own (Rule 7): list, detail and the entry form are
 * PAGES — the form is a plain `<form method="post">` with `#[Csrf]`
 * (`csrf_token` field), «Weitere Zeilen» is a second submit button the
 * server answers with more rows, the balance is shown after every submit.
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

    /** The installation's base currency — what every amount of the ledger is in (ADR-042 decision 4). */
    private function journalCurrency(): string
    {
        return (string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false)
            ->get('baseCurrency', 'CHF');
    }

    /**
     * The year the screen works on: `?year=` when given and known, else the
     * year containing today, else the latest one; null without any year.
     */
    private function journalYear(?string $code): ?FiscalYear
    {
        $code = trim((string) $code);
        if ($code !== '') {
            $year = $this->journalYears()->findOneBy(['code' => $code]);
            if ($year !== null) {
                return $year;
            }
        }

        return $this->journalYears()->findByDate(new \DateTimeImmutable('today')) ?? $this->journalYears()->latest();
    }

    /**
     * A full page other than the list: the layout pins the body to
     * `listAction`, so the section is swapped for the page's own template.
     *
     * @param array<string, mixed> $context
     */
    private function journalPage(string $template, array $context): HtmlResponse
    {
        $context += ['actionBase' => $this->journalListBase(), 'fmt' => static fn(?Money $m) => AmountFormat::of($m)];
        $response = $this->html($context);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/JournalController', self::JOURNAL_NS);

        return $response;
    }

    /** Whether the bookkeeper may still edit / delete an entry — the service decides for real; this drives the buttons and the edit page's guard. */
    private function journalEntryIsEditable(JournalEntry $entry): bool
    {
        return $entry->isManual()
            && $entry->getFiscalYear()->periodOn($entry->getDate())?->getState() !== PeriodState::Closed->value;
    }

    /** Why an entry cannot be edited or deleted — the sentence the detail view, the edit page and the delete modal share. */
    private function journalNotEditableMessage(JournalEntry $entry): string
    {
        return $entry->isManual()
            ? 'Die Periode ist abgeschlossen — keine Änderung mehr möglich.'
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
            EntryNotEditableException::PERIOD_CLOSED      => 'Die Periode ist abgeschlossen — nichts wird mehr gebucht oder geändert; eine Korrektur ist eine Buchung in einer offenen Periode.',
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

    protected function listAction(): HtmlResponse
    {
        $years = $this->journalYears()->allWithPeriods();
        $year  = $this->journalYear(DI::getRequest()->getGetParameter('year'));
        $limit = LedgerService::listLimit();

        return $this->html([
            'years'      => $years,
            'year'       => $year,
            'entries'    => $year === null ? [] : $this->journalEntries()->latestForYear($year, $limit),
            'total'      => $year === null ? 0 : $this->journalEntries()->countForYear($year),
            'deletions'  => $year === null ? [] : $this->entryChanges()->deletionsForYear($year),
            'limit'      => $limit,
            'kindLabels' => self::KIND_LABELS,
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
            'actionBase' => $this->journalListBase(),
        ]);
    }

    // ── detail ───────────────────────────────────────────────────────────

    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $entry = $id ? $this->journalEntries()->withLines($id) : null;
        if ($entry === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Buchung nicht gefunden');

            return $this->redirect($this->journalListBase() . '/list', 303);
        }
        $period = $entry->getFiscalYear()->periodOn($entry->getDate());

        return $this->journalPage('detail', [
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

    // ── add ──────────────────────────────────────────────────────────────

    /**
     * «Buchung erfassen» for the year in `?year=`. GET shows the blank form
     * (date = today when it lies in the year, else the year's first day);
     * POST with `op=more` adds rows, `op=save` posts through the service.
     */
    #[Csrf]
    protected function addAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $year    = $this->journalYear($request->getGetParameter('year'));
        if ($year === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Ohne Geschäftsjahr kann nichts gebucht werden — zuerst eines eröffnen.');

            return $this->redirect('/backend/finance/fiscal-year/list', 303);
        }
        $form = $this->manualEntryForm();

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            $form->fromPost($post);
            if (($post['op'] ?? '') === 'more') {
                $form->addRows(ManualEntryForm::MORE_ROWS);
            } else {
                $posting = $form->toRequest();
                if ($posting !== null) {
                    try {
                        $ref   = $this->manualEntryService()->create($posting);
                        $entry = $this->journalEntries()->findByRef($ref);
                        $this->messageService->pushFlashAfterRedirect('success', 'Buchung ' . $ref->fiscalYear . '/' . $ref->number . ' erfasst');

                        return $this->redirect($this->journalListBase() . '/detail?id=' . (int) $entry?->getId(), 303);
                    } catch (PostingRefusedException $e) {
                        $form->addGeneralError($this->journalRefusalMessage($e));
                    }
                }
            }
        } else {
            $today = new \DateTimeImmutable('today');
            $form->startBlank($year->covers($today) ? $today : $year->getStartDate());
        }

        return $this->journalPage('form', ['form' => $form, 'year' => $year, 'entry' => null]);
    }

    // ── edit ─────────────────────────────────────────────────────────────

    /**
     * «Buchung bearbeiten» (`?id=`), manual entries in a period that is not
     * closed — any other entry gets the same refusal the detail view shows
     * and goes back there. The managed entry is NOT mutated here — the new
     * version goes to `ManualEntryService::update()` as a validated request,
     * with the entry's id and the VERSION the form was rendered from.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|RedirectResponse
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
        $form    = $this->manualEntryForm()->keepFrom($entry);
        $version = $entry->getVersion();

        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'journalEntry', $id)) {
                $this->messageService->pushFlashAfterRedirect('error', 'Ungültiges Formular-Token — bitte erneut versuchen.');

                return $this->redirect($this->journalListBase() . '/edit?id=' . $id, 303);
            }
            $version = (int) ($post['version'] ?? 0);   // what THIS form was rendered from, not what is stored now
            $form->fromPost($post);
            if (($post['op'] ?? '') === 'more') {
                $form->addRows(ManualEntryForm::MORE_ROWS);
            } else {
                $posting = $form->toRequest();
                if ($posting !== null) {
                    $label = $entry->getFiscalYear()->getCode() . '/' . $entry->getNumber();
                    try {
                        $this->manualEntryService()->update($id, $version, $posting);
                        $this->messageService->pushFlashAfterRedirect('success', 'Buchung ' . $label . ' gespeichert (Änderung protokolliert)');

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
            }
        } else {
            $form->startFrom($entry);
        }

        return $this->journalPage('form', [
            'form'       => $form,
            'year'       => $entry->getFiscalYear(),
            'entry'      => $entry,
            'version'    => $version,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('journalEntry', $id),
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
