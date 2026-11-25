<?php
namespace Z77\Module\Financial\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Financial\Entities\EntryChange,
    Z77\Module\Financial\Entities\FiscalYear,
    Z77\Module\Financial\Entities\FiscalYearCloseLog,
    Z77\Module\Financial\Entities\JournalEntry,
    Z77\Module\Financial\Entities\VatReturn,
    Z77\Module\Financial\Repositories\CloseProtocolEntry,
    Z77\Module\Financial\Repositories\CloseProtocolSearch,
    Z77\Module\Financial\Repositories\EntryChangeRepository,
    Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat
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
 *     exists. Fetched from the list's state icon it is a WINDOW (ADR-047);
 *   - `closes-and-vat` — the second tab «Abschlüsse und MWST» (owner
 *     2026-11-25, board S2-9): the fiscal-year close / reopen protocol and the
 *     VAT return protocol (post, lift) as ONE standard list
 *     ({@see CloseProtocolListing}, the two tables joined in SQL), newest
 *     first, read only, the action cell empty; the same year selection.
 *
 * The two lists are TABS in the toolbar (`tabs`, hc2 — «Buchungen» ·
 * «Abschlüsse und MWST»), one page each, like the report tabs.
 *
 * Mounted by a thin host controller in the backend (ADR-018 pattern). Host
 * side: `use ChangeLogControllerTrait` + a one-line layout config delegating
 * to {@see ChangeLogLayout::config()}. No JavaScript of its own. The using
 * class MUST provide (via its host base): `html()`, `redirect()`, `em()`,
 * `$layoutManager`, `$messageService`, `$help` (the list's help, ADR-048).
 */
trait ChangeLogControllerTrait
{
    private const CHANGE_LOG_NS = 'Z77\\Module\\Financial';

    /** German display labels — PRESENTATION ONLY. The set is {@see \Z77\Module\Financial\Entities\ChangeAction}. */
    private const CHANGE_LOG_LABELS = [
        'update' => 'geändert',
        'delete' => 'gelöscht',
    ];

    /** The toolbar tabs: URL action → German label, in reading order. */
    private const CHANGE_LOG_TABS = [
        'list'           => 'Buchungen',
        'closes-and-vat' => 'Abschlüsse und MWST',
    ];

    /** URL root of the VAT returns — «Betrifft» links the return of the period. */
    protected function changeLogVatReturnBase(): string
    {
        return '/backend/finance/vat-return';
    }

    /** URL root of the fiscal years — «Betrifft» of a close / reopen links the list. */
    protected function changeLogFiscalYearBase(): string
    {
        return '/backend/finance/fiscal-year';
    }

    /**
     * The action that answers the archived voucher PDF of ONE VAT return revision (`?id=` the
     * return's id), or null while there is none — then the protocol has no voucher column at all
     * (backend-screen.md §4: the column exists only when the list can have the icon). The VAT
     * return's `voucher` action serves it, its SHA-256 verified.
     */
    protected function changeLogVoucherBase(): ?string
    {
        return '/backend/finance/vat-return/voucher';
    }

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
        // The list's help (ADR-048, HELP-003: at the right end of the toolbar) — not on a fetch of the list region.
        if (!ListDefinition::isFetch($request)) {
            $this->help->attach('Backend/ChangeLogController/list.help', self::CHANGE_LOG_NS, [], 'Hilfe: Änderungsprotokoll', toolbar: true);
        }

        $response = $this->html($this->changeLogTabContext('list', $year, $state->flag('all')) + [
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
        // The fragment owns its header slots (financial.md, «fragment slots»): the tabs in the
        // toolbar and the year selection at the top of the rail. The action cell stays EMPTY — the
        // log writes nothing (ADR-033 rev. 2026-10-08). A FETCH of the list region wants the list alone.
        $this->changeLogSlots('list', $year, $request);

        return $response;
    }

    /**
     * The second tab «Abschlüsse und MWST» (owner 2026-11-25, board S2-9): the close / reopen
     * protocol of the fiscal years and the VAT return protocol (post, lift) as ONE standard list,
     * newest first, read only — the year selection filters as on the first tab (a year's own rows,
     * and the VAT periods ending in it).
     */
    protected function closesAndVatAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $selection  = FiscalYearSelection::of($this->em()->getRepository(FiscalYear::class));
        $year       = $selection->resolve($request->getGetParameter('year'));
        $vouchers   = $this->changeLogVoucherBase() !== null;
        $definition = CloseProtocolListing::definition($vouchers);
        $state      = $definition->readRequest($request);
        $search     = CloseProtocolListing::search($state, $year);
        /** @var FiscalYearCloseLogRepository $protocol */
        $protocol   = $this->em()->getRepository(FiscalYearCloseLog::class);
        $total      = $year === null ? 0 : $protocol->protocolCount($search);
        $paging     = $state->paging($total);
        $base       = $this->changeLogListBase();
        if (!ListDefinition::isFetch($request)) {
            $this->help->attach('Backend/ChangeLogController/closesAndVat.help', self::CHANGE_LOG_NS, [], 'Hilfe: Abschlüsse und MWST', toolbar: true);
        }

        $response = $this->html($this->changeLogTabContext('closes-and-vat', $year, $state->flag('all')) + [
            'rows'        => $total === 0 ? [] : $this->changeLogProtocolRows($protocol->protocolPage($search, $paging->offset(), $paging->pageSize)),
            'definition'  => $definition,
            'state'       => $state,
            'paging'      => $paging,
            'year'        => $year,
            'vouchers'    => $vouchers,
            'actionBase'  => $base,
            'fySelection' => $year === null ? null : [
                'years'   => $selection->all(),
                'current' => $year,
                'href'    => static fn(string $code): string => $base . '/closes-and-vat?' . http_build_query(['year' => $code]),
                'allHref' => $base . '/closes-and-vat?all=1',
                'allOn'   => $state->flag('all'),
            ],
        ]);
        // The layout pins the body to the first tab: this one swaps it for its own list.
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('closesAndVat', 'Backend/ChangeLogController', self::CHANGE_LOG_NS);
        $this->changeLogSlots('closes-and-vat', $year, $request);

        return $response;
    }

    /**
     * The header slots of a tab — not on a fetch of the list region: the tabs (hc2; each link keeps
     * the year or «Alle Jahre»), the year selection at the top of the rail, and on the second tab the
     * crumb naming it.
     */
    private function changeLogSlots(string $tab, ?FiscalYear $year, object $request): void
    {
        if (ListDefinition::isFetch($request)) {
            return;
        }
        $this->layoutManager->addPartials('tabs', 'Backend/ChangeLogController', self::CHANGE_LOG_NS, 'hc2');
        if ($year !== null) {
            $this->layoutManager->addPartials('fiscalYearSelect', 'Backend/partials', self::CHANGE_LOG_NS, 'railSelect');
        }
        if ($tab !== 'list') {
            $this->layoutManager->addPartials('crumb', 'Backend/ChangeLogController', self::CHANGE_LOG_NS, 'hc3');
        }
    }

    /**
     * What the tabs and the crumb read — merged into the page's context: the tabs, the current
     * one, and a tab's link keeping the year or «Alle Jahre».
     *
     * @return array{changeLogTabs: array<string, string>, changeLogTab: string, changeLogTabHref: \Closure(string): string}
     */
    private function changeLogTabContext(string $tab, ?FiscalYear $year, bool $allYears): array
    {
        $base  = $this->changeLogListBase();
        $query = $allYears ? ['all' => '1'] : ($year === null ? [] : ['year' => $year->getCode()]);

        return [
            'changeLogTabs'    => self::CHANGE_LOG_TABS,
            'changeLogTab'     => $tab,
            'changeLogTabHref' => static fn(string $action): string => $base . '/' . $action . ($query === [] ? '' : '?' . http_build_query($query)),
        ];
    }

    /**
     * The protocol rows as the template shows them — presentation only: the kind's label and
     * badge, «Betrifft» (the VAT period by its label with the revision, linked to the return; the
     * fiscal year, linked to the years), «Grund / Angaben» (a reason in quotes; the entry, the
     * payable and the confirmed warnings muted) and the voucher of the revision. The VAT returns of
     * the page are read in one query (payable, the period's label).
     *
     * @param list<CloseProtocolEntry> $entries
     * @return list<array{kind: string, label: string, badge: string, at: string, who: string, subject: string, subjectHref: string, reason: ?string, info: string, title: string, voucher: ?string}>
     */
    private function changeLogProtocolRows(array $entries): array
    {
        $ids     = array_values(array_unique(array_filter(array_map(static fn(CloseProtocolEntry $e): ?int => $e->vatReturnId, $entries))));
        $returns = [];
        foreach ($ids === [] ? [] : $this->em()->getRepository(VatReturn::class)->findBy(['id' => $ids]) as $return) {
            $returns[$return->getId()] = $return;
        }
        $voucherBase = $this->changeLogVoucherBase();
        $confirmed   = static fn(array $w): string => count($w) === 1 ? 'bestätigt: ' . $w[0] : 'bestätigt: ' . count($w) . ' Warnungen';
        $rows        = [];
        foreach ($entries as $e) {
            $return = $e->vatReturnId === null ? null : ($returns[$e->vatReturnId] ?? null);
            $info   = [];
            $title  = '';
            if ($e->isVat()) {
                $subject = ($return !== null ? $return->period()->label() : $e->periodFrom?->format('d.m.Y') . ' – ' . $e->periodTo?->format('d.m.Y'))
                    . ' · Fassung ' . $e->revision;
                $href    = $this->changeLogVatReturnBase() . '/form?' . http_build_query(['from' => $e->periodFrom?->format('Y-m-d')]);
                if ($e->kind === CloseProtocolSearch::KIND_VAT_POSTED) {
                    $info[] = isset($e->detail['entry']) ? 'Buchung ' . $e->detail['entry'] : 'Buchung: keine';
                    if ($return !== null) {
                        $info[] = 'Zahllast ' . AmountFormat::of($return->getPayable());
                    }
                    if ($e->warnings !== []) {
                        $info[] = $confirmed($e->warnings);
                        $title  = implode("\n", $e->warnings);
                    }
                } elseif (isset($e->detail['reversal'])) {
                    $info[] = 'Storno ' . $e->detail['reversal'];
                }
            } else {
                $subject = 'Geschäftsjahr ' . $e->yearCode;
                $href    = $this->changeLogFiscalYearBase() . '/list';
                if ($e->kind === CloseProtocolSearch::KIND_YEAR_CLOSED) {
                    $info[] = $e->warnings === [] ? 'keine Warnung' : $confirmed($e->warnings);
                    $title  = implode("\n", $e->warnings);
                }
            }
            $rows[] = [
                'kind'        => $e->kind,
                'label'       => CloseProtocolListing::KIND_LABELS[$e->kind],
                'badge'       => CloseProtocolListing::KIND_BADGES[$e->kind],
                'at'          => $e->actedAt->format('d.m.Y H:i'),
                'who'         => $e->actor,
                'subject'     => $subject,
                'subjectHref' => $href,
                'reason'      => $e->reason,
                'info'        => implode(' · ', $info),
                'title'       => $title !== '' ? $title : (string) $e->reason,
                'voucher'     => $voucherBase !== null && $e->vatReturnId !== null ? $voucherBase . '?' . http_build_query(['id' => $e->vatReturnId]) : null,
            ];
        }

        return $rows;
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
