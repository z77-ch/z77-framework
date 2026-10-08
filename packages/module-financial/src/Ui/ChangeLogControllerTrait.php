<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Financial\Entities\EntryChange,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\JournalEntry,
    Z77\Module\Financial\Repositories\EntryChangeRepository,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Finanzen › Änderungsprotokoll — the change log of the journal as a screen
 * of its own (owner 2026-10-08: «gelöschte zeigen macht keinen Sinn — das
 * sind die protokollierten Mutationen, nur ein Protokoll, als eigenständige
 * Nav-Auswahl anzeigen; es geht nur darum, etwas nachvollziehen zu können,
 * Daten gehen keine verloren»). READ-ONLY: every row is an
 * {@see EntryChange} that `ManualEntryService` / `LedgerService::amend()` /
 * `retract()` wrote — an edit (before AND after) or a delete (before only,
 * the number stays a gap, ADR-042 decision 9). Nothing here writes.
 *
 *   - `list` — a STANDARD LIST (listing.md, {@see ChangeLogListing}): the
 *     columns sort and search in the database, 50 per page, the list a fetch
 *     region. The scope is the fiscal year selected at the top of the rail
 *     ({@see FiscalYearSelection} — the same remembered selection as the
 *     journal and the reports), «Alle Jahre» (`?all=1`) its last entry. The
 *     action cell stays empty (the screen writes nothing);
 *   - `detail?id=` — one change: who, when, what kind, the entry before and
 *     after (the lines of both snapshots), a link to the entry while it still
 *     exists. Fetched from the list's state icon it is a WINDOW (ADR-047).
 *
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use ChangeLogControllerTrait` + a one-line layout config delegating
 * to {@see ChangeLogLayout::config()}. No JavaScript of its own. The using
 * class MUST provide (via its host base): `html()`, `redirect()`, `em()`,
 * `$layoutManager`, `$messageService`.
 */
trait ChangeLogControllerTrait
{
    private const CHANGE_LOG_NS = 'Z77\\Module\\Financial';

    /** German display labels — PRESENTATION ONLY. The set is {@see \Z77\Module\Financial\Entities\ChangeAction}. */
    private const CHANGE_LOG_LABELS = [
        'update' => 'geändert',
        'delete' => 'gelöscht',
    ];

    /** URL root of THIS mount — the list's own links are built from it. */
    protected function changeLogListBase(): string
    {
        return '/backend/finance/change-log';
    }

    /** URL root of the journal — a change links the entry it belongs to (while it exists). */
    protected function changeLogJournalBase(): string
    {
        return '/backend/finance/journal';
    }

    private function changeLogRepository(): EntryChangeRepository
    {
        return $this->em()->getRepository(EntryChange::class);
    }

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $selection  = FiscalYearSelection::of($this->em()->getRepository(FiscalYear::class));
        $year       = $selection->resolve($request->getGetParameter('year'));
        $definition = ChangeLogListing::definition();
        $state      = $definition->readRequest($request);
        $search     = ChangeLogListing::search($state, $year?->getId());
        $total      = $year === null ? 0 : $this->changeLogRepository()->countSearch($search);
        $paging     = $state->paging($total);
        $base       = $this->changeLogListBase();

        $response = $this->html([
            'changes'     => $total === 0 ? [] : $this->changeLogRepository()->search($search, $paging->offset(), $paging->pageSize),
            'definition'  => $definition,
            'state'       => $state,
            'paging'      => $paging,
            'year'        => $year,
            'labels'      => self::CHANGE_LOG_LABELS,
            'actionBase'  => $base,
            // The year dropdown at the top of the rail (shared partial with the journal and the
            // reports): a year link drops the search (a fresh log of that year), «Alle Jahre» is the
            // list's `all=1`.
            'fySelection' => $year === null ? null : [
                'years'   => $selection->all(),
                'current' => $year,
                'href'    => static fn(string $code): string => $base . '/list?' . http_build_query(['year' => $code]),
                'allHref' => $base . '/list?all=1',
                'allOn'   => $state->flag('all'),
            ],
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»): only the year
        // selection at the top of the rail. The action cell stays EMPTY — the log writes nothing
        // (ADR-033 rev. 2026-10-08). A FETCH of the list region wants the list alone.
        if ($year !== null && !ListDefinition::isFetch($request)) {
            $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', self::CHANGE_LOG_NS, 'railSelect');
        }

        return $response;
    }

    /**
     * One change — before and after. Fetched (the state icon of a list row,
     * ADR-047) the same page as a WINDOW: the template marks its root.
     */
    protected function detailAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $change  = $id > 0 ? $this->changeLogRepository()->find($id) : null;
        if (!$change instanceof EntryChange) {
            $this->messageService->pushFlashAfterRedirect('error', 'Eintrag im Änderungsprotokoll nicht gefunden');

            return $this->redirect($this->changeLogListBase() . '/list', 303);
        }
        $response = $this->html([
            'change'      => $change,
            'entryExists' => $this->em()->getRepository(JournalEntry::class)->find($change->getEntryId()) !== null,
            'labels'      => self::CHANGE_LOG_LABELS,
            'window'      => ListDefinition::isFetch($request),
            'windowWidth' => '52rem',
            'actionBase'  => $this->changeLogListBase(),
            'journalBase' => $this->changeLogJournalBase(),
        ]);
        // The layout pins the body to the list: the detail swaps it for its own template.
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('detail', 'Backend/ChangeLogController', self::CHANGE_LOG_NS);

        return $response;
    }
}
